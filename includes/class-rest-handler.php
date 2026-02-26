<?php
// includes/class-rest-handler.php
if (!defined("ABSPATH")) {
  exit();
}

// we have an api key for when we need it
// $api_key = get_option('mp_openai_api_key');

class MP_REST_Handler
{
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
   * Handle the incoming JSON from the JS listener
   */
  public function handle_ingest($request)
  {
    $params = $request->get_json_params();
    $thread_id = sanitize_text_field($params["thread_id"] ?? "");

    // THE FIX: We must explicitly map the 'sources' array so it isn't discarded
    $transcript = array_map(function ($item) {
      // Sanitize the internal sources array if it exists
      $sources = isset($item["sources"])
        ? array_map(function ($source) {
          // Defensive guard: Ensure required fields exist
          if (empty($source["url"]) || empty($source["title"])) {
            return null;
          }
          return [
            "url" => esc_url_raw($source["url"]),
            "title" => sanitize_text_field($source["title"]),
            "source" => sanitize_text_field($source["source"]),
            "favicon" => esc_url_raw($source["favicon"]),
            "excerpt" => wp_kses_post($source["excerpt"]),
          ];
        }, $item["sources"])
        : [];

      // Filter out null values from incomplete entries
      $sources = array_filter($sources);

      return [
        "question" => wp_kses_post($item["question"]),
        "answer" => wp_kses_post($item["answer"]),
        "sources" => $sources, // <--- This allows it to be saved to Post Meta
      ];
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

    // 1. Check if post already exists
    $existing_id = $this->get_post_id_by_thread($thread_id);

    if ($existing_id) {
      // Rolling hold strategy: If post is scheduled ('future'), reset the 24h timer
      $status = get_post_status($existing_id);
      if ($status === "future") {
        $future_ts_gmt = current_time("timestamp", true) + DAY_IN_SECONDS;
        $post_date_gmt = gmdate("Y-m-d H:i:s", $future_ts_gmt);
        $post_date = get_date_from_gmt($post_date_gmt);

        $update_result = wp_update_post([
          "ID" => $existing_id,
          "post_date" => $post_date,
          "post_date_gmt" => $post_date_gmt,
        ]);

        if ($update_result === 0) {
          error_log("MilePoint: Failed to reschedule post " . $existing_id);
          return new WP_REST_Response(
            ["message" => "Failed to reschedule post."],
            500,
          );
        }
      }

      update_post_meta($existing_id, "_raw_transcript", $transcript);
      update_post_meta($existing_id, "_related_suggestions", $related);
      update_post_meta($existing_id, "_breakdown", $breakdown);

      // Attempt to set a featured image if missing
      $this->process_featured_image($existing_id, $transcript);

      return new WP_REST_Response(
        ["message" => "Post updated with sources."],
        200,
      );
    }

    // 2. CREATE NEW post
    $first_question = wp_strip_all_tags(
      $transcript[0]["question"] ?? "New Q&A",
    );

    $future_ts_gmt = current_time("timestamp", true) + DAY_IN_SECONDS;
    $post_date_gmt = gmdate("Y-m-d H:i:s", $future_ts_gmt);
    $post_date = get_date_from_gmt($post_date_gmt);

    $post_id = wp_insert_post([
      "post_title" => $first_question,
      "post_content" => "<!-- MILEPOINT_LONG_TAIL -->",
      "post_status" => "future",
      "post_type" => "milepoint_qa",
      "post_date" => $post_date,
      "post_date_gmt" => $post_date_gmt,
    ]);

    if (is_wp_error($post_id) || empty($post_id)) {
      error_log("MilePoint: Failed to create scheduled post for thread " . $thread_id);
      return new WP_REST_Response(
        ["message" => "Failed to create scheduled post."],
        500,
      );
    }

    update_post_meta($post_id, "_gist_thread_id", $thread_id);
    update_post_meta($post_id, "_raw_transcript", $transcript);
    update_post_meta($post_id, "_related_suggestions", $related);
    update_post_meta($post_id, "_breakdown", $breakdown);

    // Attempt to set a featured image
    $this->process_featured_image($post_id, $transcript);

    return new WP_REST_Response(
      ["message" => "New scheduled post created. ID: " . $post_id],
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

  private function get_post_id_by_thread($thread_id)
  {
    if (empty($thread_id) || $thread_id === "unknown") {
      return false;
    }

    $posts = get_posts([
      "post_type" => "milepoint_qa",
      "meta_key" => "_gist_thread_id",
      "meta_value" => $thread_id,
      "posts_per_page" => 1,
      "fields" => "ids",
      // Check ALL statuses!!
      "post_status" => [
        "publish",
        "pending",
        "draft",
        "auto-draft",
        "future",
        "private",
        "inherit",
        "trash",
      ],
    ]);

    return !empty($posts) ? $posts[0] : false;
  }
}
