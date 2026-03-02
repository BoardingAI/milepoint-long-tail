<?php
// includes/class-ai-handler.php
if (!defined('ABSPATH')) exit;

class MP_AI_Handler
{

  public function __construct()
  {
    // Trigger only when our specific post type is published
    add_action('publish_milepoint_qa', [$this, 'categorize_on_publish'], 10, 2);
  }

  public function categorize_on_publish($ID, $post)
  {
    // 1. Check if already processed to avoid infinite loops/double billing
    if (get_post_meta($ID, '_mp_ai_processed', true)) return;

    $api_key = get_option('mp_openai_api_key');
    if (empty($api_key)) return;

    // 2. Prepare content for AI
    $title = $post->post_title;
    $transcript = get_post_meta($ID, '_raw_transcript', true);
    $breakdown = get_post_meta($ID, "_breakdown", true); // maybe tag with these??

    $sample_content = is_array($transcript) ? $transcript[0]['answer'] : '';

    // 3. Call OpenAI
    $response = $this->get_ai_suggestions($api_key, $title, $sample_content);

    /**
     * XDEBUG BREAKPOINT:
     * Set a breakpoint on the line below ($data) to inspect the AI response
     */
    $data = json_decode($response, true);

    // Early return if no data
    if (!$data) {
        return;
    }

    // 1. Process Categories
    if (!empty($data['categories']) && is_array($data['categories'])) {
        if (! function_exists('wp_create_category')) {
            require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
        }

        $cat_ids = [];
        // Limit to 3 items max
        foreach (array_slice($data['categories'], 0, 3) as $cat_name) {
            // Protect against AI returning nested arrays or objects
            if (!is_scalar($cat_name)) {
                continue;
            }

            $clean_name = sanitize_text_field(trim((string) $cat_name));

            if (!empty($clean_name)) {
                $cat_id = wp_create_category($clean_name);
                if (!is_wp_error($cat_id)) {
                    $cat_ids[] = (int)$cat_id;
                }
            }
        }

        if (!empty($cat_ids)) {
            // Deduplicate IDs just in case the AI repeated itself
            wp_set_post_categories($ID, array_unique($cat_ids));
        }
    }

    // 2. Process Tags
    if (!empty($data['tags']) && is_array($data['tags'])) {
        $clean_tags = [];

        // Limit to 5 items max
        foreach (array_slice($data['tags'], 0, 5) as $tag_name) {
            if (!is_scalar($tag_name)) {
                continue;
            }

            $tag = sanitize_text_field(trim((string) $tag_name));
            if ($tag !== '') {
                $clean_tags[] = $tag;
            }
        }

        if (!empty($clean_tags)) {
            // Deduplicate the tags array
            wp_set_post_tags($ID, array_values(array_unique($clean_tags)));
        }
    }

    // 3. Mark as processed
    update_post_meta($ID, '_mp_ai_processed', true);
  }

  private function get_ai_suggestions($api_key, $title, $content)
    {
        // 1. Fetch existing taxonomy to nudge the AI
        $existing_cats = array_slice((array) get_mp_terms_with_counts('category', false), 0, 200);
        $existing_tags = array_slice((array) get_mp_terms_with_counts('post_tag', false), 0, 300);

        // Convert to comma-separated strings
        $cat_list = !empty($existing_cats) ? implode(', ', array_column($existing_cats, 'name')) : 'None yet';
        $tag_list = !empty($existing_tags) ? implode(', ', array_column($existing_tags, 'name')) : 'None yet';

        // 2. Updated prompt with "Prefer existing" instructions
        $prompt = "You are a travel rewards expert. Categorize this Q&A into 1 to 3 categories and up to 5 tags.

Title: {$title}
Content Sample: {$content}

---
EXISTING CATEGORIES: {$cat_list}
EXISTING TAGS: {$tag_list}

INSTRUCTIONS:
1. Evaluate the EXISTING CATEGORIES. You may use broad existing categories (like 'Travel'), but you MUST also create specific NEW categories (like 'Cruises' or 'Credit Cards') if the topic warrants it.
2. Favor EXISTING TAGS where applicable to maintain taxonomy consistency.
3. Return ONLY a JSON object with keys 'categories' (array of strings, max 3) and 'tags' (array of strings).";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
            'body'    => json_encode([
                'model' => 'gpt-4o-mini',
                'temperature' => 0.7,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object']
            ])
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['choices'][0]['message']['content'] ?? false;
    }
}
