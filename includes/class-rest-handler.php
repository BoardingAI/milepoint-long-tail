<?php
// includes/class-rest-handler.php
if (!defined("ABSPATH")) {
  exit();
}

// we have an api key for when we need it
// $api_key = get_option('mp_openai_api_key');

class MP_REST_Handler
{
  // AI Safeguard Thresholds
  const MAX_AI_FOLLOWUPS_PER_SESSION = 5;
  const MAX_CONTEXT_TURNS = 2;
  const MIN_WORD_COUNT = 3;
  const AI_COOLDOWN_SECONDS = 30;

  /**
   * Register the REST API route
   */
  public function register_routes()
  {
    add_action('rest_api_init', [$this, 'setup_cors'], 15);

    // don't mess with existing routes
    register_rest_route("milepoint-v1", "/generate-post", [
      "methods" => "POST",
      "callback" => [$this, "handle_ingest"],
      "permission_callback" => [$this, "check_permissions"],
    ]);

    // New chatbot-post route
    register_rest_route("milepoint-v1", "/chatbot-post", [
      "methods" => "POST",
      "callback" => [$this, "handle_chatbot_ingest"],
      "permission_callback" => "__return_true", // Publicly accessible
      "args" => [
        "source" => [
          "type" => "string",
          "required" => true,
        ],
        "conversationId" => [
          "type" => "string",
          "required" => true,
          "minLength" => 1,
        ],
        "url" => [
          "type" => "string",
          "required" => true,
          "format" => "uri",
        ],
        "timestamp" => [
          "type" => "string",
          "required" => true,
          "format" => "date-time",
          "validate_callback" => function($param, $request, $key) {
            $date = new DateTime($param);
            return $date->format('Y') >= 2025; // Valid post 2025
          }
        ],
        "messages" => [
          "type" => "array",
          "required" => true,
          "minItems" => 1,
          "items" => [
            "type" => "object",
            "properties" => [
              "question" => ["type" => "string", "required" => true, "minLength" => 1],
              "answerHtml" => ["type" => "string", "required" => true, "minLength" => 1],
              "index" => ["type" => "integer"],
            ]
          ]
        ],
        "env" => ["type" => "string"] // Allowed but ignored
      ]
    ]);
  }

  public function setup_cors() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');

        // If it's a preflight request, exit early with success
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit;
        }

        return $value;
    });
  }

public function handle_chatbot_ingest($request) {
    $params = $request->get_params();

    // Extract and sanitize core fields
    $conv_id   = sanitize_text_field($params['conversationId'] ?? '');
    $source    = sanitize_text_field($params['source'] ?? '');
    $url       = esc_url_raw($params['url'] ?? '');
    $timestamp = sanitize_text_field($params['timestamp'] ?? '');
    $messages  = $params['messages'] ?? []; // Array, no sanitization needed yet (handled by WP meta)

    // 1. Find existing by meta to allow "Upsert"
    $existing_posts = get_posts([
        'post_type'      => 'milepoint-chat',
        'meta_key'       => '_conversation_id',
        'meta_value'     => $conv_id,
        'posts_per_page' => 1,
        'post_status'    => 'any'
    ]);

    // Use the first question as the title
    $first_msg = $messages[0] ?? [];
    $post_title = !empty($first_msg['question']) ? $first_msg['question'] : "Conversation " . $conv_id;

    $post_data = [
        'post_type'   => 'milepoint-chat',
        'post_title'  => sanitize_text_field($post_title),
        'post_status' => 'publish',
        'post_date'   => date('Y-m-d H:i:s', strtotime($timestamp ?: 'now')),
    ];

    if (!empty($existing_posts)) {
        $post_data['ID'] = $existing_posts[0]->ID;
        $post_id = wp_update_post($post_data);
        $action = 'updated';
    } else {
        $post_id = wp_insert_post($post_data);
        $action = 'created';
    }

    if (is_wp_error($post_id)) {
        return new WP_REST_Response(['status' => 'error', 'message' => $post_id->get_error_message()], 500);
    }

    // 2. Save ALL fields to postmeta
    update_post_meta($post_id, '_conversation_id', $conv_id);
    update_post_meta($post_id, '_source', $source);           // <--- Added
    update_post_meta($post_id, '_source_url', $url);
    update_post_meta($post_id, '_timestamp', $timestamp);     // <--- Added
    update_post_meta($post_id, '_full_payload', $messages);

    return new WP_REST_Response([
        "status" => "ok",
        "action" => $action,
        "post_id" => $post_id,
        "url" => get_permalink($post_id)
    ], 200);
  }



  /**
   * Only allow users who can edit posts
   */
  public function check_permissions($request)
  {
    $nonce = $request->get_header("x-milepoint-nonce");
    if (wp_verify_nonce($nonce, "milepoint_public_chat")) {
      return true;
    }

    return current_user_can("edit_posts");
  }

  /**
   * Helper function to pre-clean HTML before wp_kses_post
   * Strips entire <style> and <script> blocks (tags + content)
   * and removes long chains of CSS selectors.
   */
  private function pre_clean_html($html, &$has_been_cleaned = null) {
    if (empty($html)) return '';
    $original_html = $html;

    // 1. Strip <style> and <script> blocks entirely, and other risky non-content nodes
    $risky_tags = 'style|script|noscript|template|iframe|object|embed|svg|canvas|meta|link';
    $html = preg_replace('/<(' . $risky_tags . ')[^>]*>.*?<\/\1>/is', '', $html);

    // Also remove self-closing or void versions of these tags just in case
    $html = preg_replace('/<(' . $risky_tags . ')[^>]*\/?>/is', '', $html);

    // 2. Strip narrow selector-dump patterns (10+ comma-separated IDs/Classes like #Ad, div.ad_160)
    // Match optional declaration block (?:\s*\{.*?\})?
    // Requires a comma separator and at least one `#` or `.` per item to prevent matching normal prose.
    $junk_selector_pattern = '/(?:[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+,\s*){10,}[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+(?:\s*\{.*?\})?/is';
    $html = preg_replace($junk_selector_pattern, '', $html);

    if ($html !== $original_html && $has_been_cleaned !== null) {
        $has_been_cleaned = true;
    }

    return trim($html);
  }

  /**
   * Helper function to detect if a payload is still polluted after pre-cleaning.
   * We use a lower threshold here (e.g., 5+) just to be safe and trigger quarantine.
   */
  private function is_polluted($html) {
    if (empty($html)) return false;
    $quarantine_pattern = '/(?:[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+,\s*){5,}[a-zA-Z0-9_-]*[#\.][a-zA-Z0-9_-]+/is';
    return preg_match($quarantine_pattern, $html) === 1;
  }

  /**
   * Handle the incoming JSON from the JS listener
   */
  public function handle_ingest($request)
  {
    $params = $request->get_json_params();
    $thread_id = sanitize_text_field($params["thread_id"] ?? "");

    $has_pollution = false;
    $has_been_cleaned = false;

    // THE FIX: We must explicitly map the 'sources' array so it isn't discarded
    $transcript = array_map(function ($item) use (&$has_pollution, &$has_been_cleaned, $thread_id) {
      // Pre-clean answer and question before kses
      $clean_question = $this->pre_clean_html($item["question"] ?? "", $has_been_cleaned);
      $clean_answer = $this->pre_clean_html($item["answer"] ?? "", $has_been_cleaned);

      // Check for remaining pollution that should trigger a quarantine
      if ($this->is_polluted($clean_answer) || $this->is_polluted($clean_question)) {
          $has_pollution = true;
          error_log("MilePoint: Quarantine triggered - selector dump detected in question/answer in thread " . sanitize_text_field($thread_id ?? 'unknown'));
      }

      // Sanitize the internal sources array if it exists
      $sources = isset($item["sources"])
        ? array_map(function ($source) use (&$has_pollution, &$has_been_cleaned, $thread_id) {
          // Defensive guard: Ensure required fields exist
          if (empty($source["url"]) || empty($source["title"])) {
            return null;
          }
          $clean_excerpt = $this->pre_clean_html($source["excerpt"] ?? "", $has_been_cleaned);

          if ($this->is_polluted($clean_excerpt)) {
              $has_pollution = true;
              error_log("MilePoint: Quarantine triggered - selector dump detected in source excerpt in thread " . sanitize_text_field($thread_id ?? 'unknown'));
          }

          return [
            "url" => esc_url_raw($source["url"]),
            "title" => sanitize_text_field($source["title"]),
            "source" => sanitize_text_field($source["source"]),
            "favicon" => esc_url_raw($source["favicon"]),
            "excerpt" => wp_kses_post($clean_excerpt),
          ];
        }, $item["sources"])
        : [];

      // Filter out null values from incomplete entries
      $sources = array_filter($sources);

      $mapped = [
        "question" => wp_kses_post($clean_question),
        "answer" => wp_kses_post($clean_answer),
        "sources" => $sources, // <--- This allows it to be saved to Post Meta
      ];

      if (isset($item["breakdown"]) && is_array($item["breakdown"])) {
          $mapped["breakdown"] = $item["breakdown"];
      }

      if (isset($item['is_streaming'])) {
          $mapped['is_streaming'] = (bool) $item['is_streaming'];
      }

      return $mapped;
    }, $params["full_transcript"] ?? []);

    // Fix: Sanitize each related suggestion individually
    $related =
      isset($params["related_suggestions"]) &&
      is_array($params["related_suggestions"])
        ? array_map("sanitize_text_field", $params["related_suggestions"])
        : [];

    $breakdown =
      isset($params["breakdown"]) && is_array($params["breakdown"])
        ? $params["breakdown"]
        : ["not found"];

    // Check for valid sources before proceeding
    $total_sources = 0;
    foreach ($transcript as $item) {
      if (!empty($item["sources"]) && is_array($item["sources"])) {
        foreach ($item["sources"] as $source) {
          if (!empty($source["url"])) {
            $total_sources++;
          }
        }
      }
    }

    if ($total_sources === 0) {
      return new WP_REST_Response(["message" => "Skipped: No sources"], 200);
    }

    // Only update counts once per request if post creation/update is actually going to proceed
    if ($has_been_cleaned) {
        $cleaned_count = (int) get_option('mp_sludge_cleaned_count', 0);
        update_option('mp_sludge_cleaned_count', $cleaned_count + 1);
    }

    if ($has_pollution) {
        $quarantine_count = (int) get_option('mp_sludge_quarantined_count', 0);
        update_option('mp_sludge_quarantined_count', $quarantine_count + 1);
    }

    // 1. PRIMARY POST: Check if primary post already exists
    $primary_id = $this->get_post_id_by_thread($thread_id);

    $first_question_text = wp_strip_all_tags($transcript[0]["question"] ?? "New Q&A");
    $first_answer_text = $transcript[0]["answer"] ?? "";

    if (!$primary_id) {
      // Create new primary post
      $future_ts_gmt = current_time("timestamp", true) + DAY_IN_SECONDS;
      $post_date_gmt = gmdate("Y-m-d H:i:s", $future_ts_gmt);
      $post_date = get_date_from_gmt($post_date_gmt);

      $initial_status = $has_pollution ? "draft" : "future";

      $primary_id = wp_insert_post([
        "post_title" => $first_question_text,
        "post_content" => "<!-- MILEPOINT_LONG_TAIL -->",
        "post_status" => $initial_status,
        "post_type" => "milepoint_qa",
        "post_date" => $post_date,
        "post_date_gmt" => $post_date_gmt,
      ]);

      if (is_wp_error($primary_id) || empty($primary_id)) {
        error_log("MilePoint: Failed to create scheduled post for thread " . $thread_id);
        return new WP_REST_Response(["message" => "Failed to create scheduled post."], 500);
      }
    } else {
        // Quarantine: If pollution is detected, downgrade status to draft
        if ($has_pollution) {
            $status = get_post_status($primary_id);
            if ($status !== 'draft') {
                wp_update_post([
                    "ID" => $primary_id,
                    "post_status" => 'draft',
                ]);
            }
        }
        // Do NOT push the publish date forward for follow-up turns
    }

    // Set Primary Post Taxonomy and Meta
    wp_set_object_terms($primary_id, "primary_first_turn", "mp_workflow_status");
    update_post_meta($primary_id, "_gist_thread_id", $thread_id);
    update_post_meta($primary_id, "_mp_source_thread_id", $thread_id);
    update_post_meta($primary_id, "_mp_source_turn_index", 0);
    update_post_meta($primary_id, "_mp_is_primary_turn", "1");

    // Store full raw transcript privately
    update_post_meta($primary_id, "_raw_transcript", $transcript);
    update_post_meta($primary_id, "_related_suggestions", $related);
    update_post_meta($primary_id, "_breakdown", $breakdown);

    // Store Single Turn Content for Primary Post
    $primary_single_turn = [
      "question" => $transcript[0]["question"] ?? "",
      "answer" => $transcript[0]["answer"] ?? "",
      "sources" => $transcript[0]["sources"] ?? [],
      "breakdown" => $transcript[0]["breakdown"] ?? $breakdown, // fallback to overall breakdown if missing per-turn
      "is_rewritten" => false
    ];
    update_post_meta($primary_id, "_mp_single_turn_content", $primary_single_turn);
    update_post_meta($primary_id, "_mp_original_question", $transcript[0]["question"] ?? "");
    update_post_meta($primary_id, "_mp_original_answer", $transcript[0]["answer"] ?? "");

    $this->process_featured_image($primary_id, $transcript);

    // 2. PROCESS FOLLOW-UP TURNS
    $api_key = get_option('mp_openai_api_key');
    $prior_context_arr = [];
    $prior_context_arr[] = "Q: " . $first_question_text . "\nA: " . wp_strip_all_tags($first_answer_text);

    $ai_processed_count = 0;

    // Skip index 0 (Primary)
    for ($i = 1; $i < count($transcript); $i++) {
      // Explicitly initialize the AI budget tracking flag at the start of every iteration
      $did_consume_ai_budget = false;

      $turn = $transcript[$i];
      $q_text = $turn["question"] ?? "";
      $a_text = $turn["answer"] ?? "";
      $clean_q = trim(wp_strip_all_tags($q_text));

      // We only care about user questions, if it's empty, skip
      if (empty($clean_q)) {
        $prior_context_arr[] = "A: " . wp_strip_all_tags($a_text);
        continue;
      }

      $followup_id = $this->get_followup_post_id($thread_id, $i);

      if ($followup_id) {
          // Explicitly check if this post actually consumed an AI API call during a previous ingest pass
          $was_ai_reviewed = get_post_meta($followup_id, "_mp_ai_reviewed", true);
          if ($was_ai_reviewed === "1") {
             $ai_processed_count++;
          }
      }

      // --- UNCHANGED CONTENT PROTECTION ---
      // Include sources and breakdown in the hash so we don't accidentally skip updating newly arriving attribution data
      $turn_sources_json = wp_json_encode($turn["sources"] ?? []);
      $turn_breakdown_json = wp_json_encode($turn["breakdown"] ?? []);
      $current_hash = md5($clean_q . trim(wp_strip_all_tags($a_text)) . $turn_sources_json . $turn_breakdown_json);
      if ($followup_id) {
          $existing_hash = get_post_meta($followup_id, "_mp_turn_content_hash", true);
          if ($existing_hash === $current_hash) {
              $prior_context_arr[] = "Q: " . $clean_q . "\nA: " . wp_strip_all_tags($a_text);
              continue;
          }
      }

      $needs_ai = false;
      $is_currently_streaming = isset($turn['is_streaming']) && $turn['is_streaming'] === true;

      if (!$followup_id) {
          $needs_ai = true;
      } else {
          $failed_prev = get_post_meta($followup_id, "_mp_classification_failed", true);
          $was_streaming_prev = get_post_meta($followup_id, "_mp_is_streaming", true);

          if ($failed_prev || ($was_streaming_prev && !$is_currently_streaming)) {
              $needs_ai = true;
          }
      }

      $classification = "hold";
      $reason = "";
      $confidence = "";
      $rewritten_q = "";
      $rewritten_a = "";
      $classification_failed = false;
      $rewrite_failed = false;
      $skip_ai = false;
      $lock_key = "mp_ai_lock_{$thread_id}_{$i}";

      if ($needs_ai) {
          if ($is_currently_streaming) {
               $skip_ai = true;
               $reason = "Skipped: Turn is still mid-update (streaming)";
          }

          if (!$skip_ai && get_transient($lock_key)) {
              $skip_ai = true;
              $reason = "Skipped: Concurrent processing lock active";
          }

          $last_attempt = get_transient("mp_ai_cooldown_{$thread_id}_{$i}");
          if (!$skip_ai && $last_attempt) {
              $skip_ai = true;
              $reason = "Skipped: Rapid update cooldown active";
          }

          if (!$skip_ai && str_word_count($clean_q) < self::MIN_WORD_COUNT) {
              $skip_ai = true;
              $reason = "Skipped: Follow-up too short (low signal)";
          }

          if (!$skip_ai && $ai_processed_count >= self::MAX_AI_FOLLOWUPS_PER_SESSION) {
              $skip_ai = true;
              $reason = "Skipped: Max AI follow-ups per session exceeded";
          }

          if (!$skip_ai && $api_key) {
              set_transient($lock_key, true, 60);
              set_transient("mp_ai_cooldown_{$thread_id}_{$i}", true, self::AI_COOLDOWN_SECONDS);
          }
      }

      $bounded_context_arr = array_slice($prior_context_arr, -(self::MAX_CONTEXT_TURNS));
      $prior_context = implode("\n\n", $bounded_context_arr);

      if ($api_key && $needs_ai && !$skip_ai) {
         $ai_handler = new MP_AI_Handler();

         // Mark that an actual OpenAI request is being attempted
         $did_consume_ai_budget = true;

         $ai_res = $ai_handler->get_followup_classification($api_key, $first_question_text, $prior_context, wp_strip_all_tags($q_text), wp_strip_all_tags($a_text));

         if ($ai_res && isset($ai_res['classification'])) {
           $classification = $ai_res['classification'];
           $allowed_buckets = ['ready_as_is', 'needs_rewrite_review', 'hold'];
           if (!in_array($classification, $allowed_buckets)) {
               error_log("MilePoint: AI returned unsupported bucket: " . $classification . ". Falling back to hold.");
               $classification = "hold";
               $classification_failed = true;
               $reason = "AI returned invalid bucket (hallucination).";
           }

           $reason = $ai_res['reason'] ?? '';
           $confidence = $ai_res['confidence'] ?? '';

           if ($classification === 'needs_rewrite_review') {
               $rewritten_q = sanitize_text_field($ai_res['rewritten_question'] ?? '');
               $has_been_cleaned_ai = false;
               $clean_ai_answer = $this->pre_clean_html($ai_res['rewritten_answer'] ?? '', $has_been_cleaned_ai);
               $rewritten_a = wp_kses_post($clean_ai_answer);

               if (empty($rewritten_q)) {
                   $rewrite_failed = true;
                   $classification = "hold";
                   $reason = "Rewrite failed or returned empty.";
               }
           }
         } else {
           $classification_failed = true;
           $reason = "AI request failed or returned invalid JSON.";
         }
      } elseif (!$api_key && $needs_ai && !$skip_ai) {
         $classification_failed = true;
         $reason = "API Key missing.";
      }

      if ($needs_ai && !$skip_ai) {
          delete_transient($lock_key);
          if ($did_consume_ai_budget) {
              $ai_processed_count++;
          }
      }

      if ($needs_ai || $skip_ai || !$followup_id) {
        $post_title = wp_strip_all_tags($q_text);
        if ($classification === 'needs_rewrite_review' && !empty($rewritten_q)) {
            $post_title = wp_strip_all_tags($rewritten_q);
        }

        if (!$followup_id) {
            $new_id = wp_insert_post([
              "post_title" => $post_title,
              "post_content" => "<!-- MILEPOINT_LONG_TAIL -->",
              "post_status" => "draft",
              "post_type" => "milepoint_qa",
            ]);

            if (is_wp_error($new_id) || empty($new_id)) {
                error_log("MilePoint: Failed to insert follow-up post for thread " . $thread_id . " turn " . $i);
                $followup_id = false;
            } else {
                $followup_id = $new_id;
            }
        } else {
            $update_res = wp_update_post([
                "ID" => $followup_id,
                "post_title" => $post_title,
            ]);
            if ($update_res === 0) {
                error_log("MilePoint: Failed to update follow-up post title for ID " . $followup_id);
            }
        }
      }

      if ($followup_id) {
          update_post_meta($followup_id, "_mp_turn_content_hash", $current_hash);

          if ($is_currently_streaming) {
              update_post_meta($followup_id, "_mp_is_streaming", true);
          } else {
              delete_post_meta($followup_id, "_mp_is_streaming");
          }

          if ($needs_ai || $skip_ai || !has_term('', 'mp_workflow_status', $followup_id)) {
               wp_set_object_terms($followup_id, $classification, "mp_workflow_status");
          }

          // Explicitly record that AI budget was consumed for this turn, even if the API call failed
          if ($did_consume_ai_budget) {
              update_post_meta($followup_id, "_mp_ai_reviewed", "1");
          }

          update_post_meta($followup_id, "_mp_source_thread_id", $thread_id);
          update_post_meta($followup_id, "_mp_source_turn_index", $i);
          update_post_meta($followup_id, "_mp_parent_primary_post_id", $primary_id);
          update_post_meta($followup_id, "_mp_is_primary_turn", "0");

          update_post_meta($followup_id, "_mp_original_question", $q_text);
          update_post_meta($followup_id, "_mp_original_answer", $a_text);

          if ($needs_ai || $skip_ai) {
              if ($rewritten_q) update_post_meta($followup_id, "_mp_rewritten_question", $rewritten_q);
              if ($rewritten_a) update_post_meta($followup_id, "_mp_rewritten_answer", $rewritten_a);

              if ($reason) {
                  update_post_meta($followup_id, "_mp_classification_reason", $reason);
              } else {
                  delete_post_meta($followup_id, "_mp_classification_reason");
              }

              if ($confidence) update_post_meta($followup_id, "_mp_classification_confidence", $confidence);

              if ($classification_failed) {
                  update_post_meta($followup_id, "_mp_classification_failed", true);
              } else {
                  delete_post_meta($followup_id, "_mp_classification_failed");
              }

              if ($rewrite_failed) {
                  update_post_meta($followup_id, "_mp_rewrite_failed", true);
              } else {
                  delete_post_meta($followup_id, "_mp_rewrite_failed");
              }

              $single_turn = [
                "question" => $rewritten_q ?: $q_text,
                "answer" => $rewritten_a ?: $a_text,
                "sources" => $turn["sources"] ?? [],
                "breakdown" => $turn["breakdown"] ?? [],
                "is_rewritten" => !empty($rewritten_q)
              ];
              update_post_meta($followup_id, "_mp_single_turn_content", $single_turn);
          } else {
              $existing_single = get_post_meta($followup_id, "_mp_single_turn_content", true);
              if (is_array($existing_single) && empty($existing_single["is_rewritten"])) {
                  $existing_single["answer"] = $a_text;
                  $existing_single["sources"] = $turn["sources"] ?? [];
                  if (isset($turn["breakdown"])) {
                      $existing_single["breakdown"] = $turn["breakdown"];
                  }
                  update_post_meta($followup_id, "_mp_single_turn_content", $existing_single);
              }
          }
      }

      $prior_context_arr[] = "Q: " . $clean_q . "\nA: " . wp_strip_all_tags($a_text);    }

    return new WP_REST_Response(
      ["message" => "Post and follow-ups updated with sources.", "primary_id" => $primary_id],
      200,
    );
  }

  /**
   * Process and attach a featured image from sources
   */
  private function process_featured_image($post_id, $transcript)
  {
    // Check if post already has a featured image
    if (has_post_thumbnail($post_id)) {
      return;
    }

    // Extract all source URLs
    $sources = [];
    foreach ($transcript as $block) {
      if (!empty($block["sources"]) && is_array($block["sources"])) {
        foreach ($block["sources"] as $source) {
          if (!empty($source["url"])) {
            $sources[] = $source["url"];
          }
        }
      }
    }

    // Remove duplicates and shuffle
    $sources = array_unique($sources);
    if (empty($sources)) {
      return;
    }
    shuffle($sources);

    // Required for media_sideload_image
    require_once ABSPATH . "wp-admin/includes/media.php";
    require_once ABSPATH . "wp-admin/includes/file.php";
    require_once ABSPATH . "wp-admin/includes/image.php";

    foreach ($sources as $url) {
      $image_url = $this->scrape_featured_image($url);
      if ($image_url) {
        // Attempt to sideload
        // The last argument 'id' makes it return the attachment ID
        $attachment_id = media_sideload_image($image_url, $post_id, null, "id");

        if (!is_wp_error($attachment_id)) {
          set_post_thumbnail($post_id, $attachment_id);
          break; // Stop after one successful image
        }
      }
    }
  }

  /**
   * Scrape a URL for its featured image (og:image)
   */
  private function scrape_featured_image($url)
  {
    $response = wp_remote_get($url, [
      "timeout" => 10,
      "user-agent" =>
        "Mozilla/5.0 (compatible; MilePointBot/1.0; +http://milepoint.com)",
    ]);

    if (
      is_wp_error($response) ||
      wp_remote_retrieve_response_code($response) !== 200
    ) {
      return false;
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
      return false;
    }

    // Use DOMDocument to parse
    $dom = new DOMDocument();
    // Suppress warnings for malformed HTML
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // 1. Try og:image
    $og_image = $xpath->query('//meta[@property="og:image"]/@content');
    if ($og_image->length > 0) {
      return $og_image->item(0)->nodeValue;
    }

    // 2. Try twitter:image
    $twitter_image = $xpath->query('//meta[@name="twitter:image"]/@content');
    if ($twitter_image->length > 0) {
      return $twitter_image->item(0)->nodeValue;
    }

    return false;
  }



  private function get_followup_post_id($thread_id, $turn_index) {
    if (empty($thread_id) || $thread_id === "unknown") return false;
    $posts = get_posts([
      "post_type" => "milepoint_qa",
      "meta_query" => [
        "relation" => "AND",
        [
          "key" => "_mp_source_thread_id",
          "value" => $thread_id,
          "compare" => "="
        ],
        [
          "key" => "_mp_source_turn_index",
          "value" => $turn_index,
          "compare" => "="
        ]
      ],
      "posts_per_page" => 1,
      "fields" => "ids",
      "post_status" => "any"
    ]);
    return !empty($posts) ? $posts[0] : false;
  }
  private function get_post_id_by_thread($thread_id)
  {
    if (empty($thread_id) || $thread_id === "unknown") {
      return false;
    }

    $posts = get_posts([
      "post_type" => "milepoint_qa",
      "meta_query" => [
        "relation" => "AND",
        [
          "key" => "_gist_thread_id",
          "value" => $thread_id,
          "compare" => "="
        ],
        [
          "key" => "_mp_is_primary_turn",
          "value" => "1", // Explicitly require the "1" string
          "compare" => "="
        ]
      ],
      "posts_per_page" => 1,
      "fields" => "ids",
      "post_status" => "any"
    ]);

    // Fallback for older posts that might not have _mp_is_primary_turn yet
    if (empty($posts)) {
        $posts = get_posts([
          "post_type" => "milepoint_qa",
          "meta_query" => [
            "relation" => "AND",
            [
              "key" => "_gist_thread_id",
              "value" => $thread_id,
              "compare" => "="
            ],
            [
              "key" => "_mp_is_primary_turn",
              "compare" => "NOT EXISTS"
            ]
          ],
          "posts_per_page" => 1,
          "fields" => "ids",
          "post_status" => "any",
          "orderby" => "ID", // get the oldest one as primary
          "order" => "ASC"
        ]);
    }

    return !empty($posts) ? $posts[0] : false;
  }
}
