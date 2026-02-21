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

    // it gets to here ok but then there is some problem and it fails to set the tags / category

    if ($data && !empty($data['category'])) {

      // 1. Ensure category is a string (in case AI returned an array)
      $category_name = is_array($data['category']) ? $data['category'][0] : $data['category'];

      // 2. Load admin functions if they are missing (for REST API / Gutenberg)
      if (! function_exists('wp_create_category')) {
        require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
      }

      // 3. Create or find the category
      // wp_create_category is smart: it checks if it exists, creates it if not, and returns the ID.
      $cat_id = wp_create_category($category_name);

      if ($cat_id && !is_wp_error($cat_id)) {
        // wp_set_post_categories requires an array of IDs
        wp_set_post_categories($ID, [(int)$cat_id]);
      }

      // 4. Set Tags
      if (!empty($data['tags']) && is_array($data['tags'])) {
        // wp_set_post_tags is very forgiving; it accepts an array of strings
        wp_set_post_tags($ID, $data['tags']);
      }

      // Mark as processed
      update_post_meta($ID, '_mp_ai_processed', true);
    }
  }

  private function get_ai_suggestions($api_key, $title, $content)
    {
        // 1. Fetch existing taxonomy to nudge the AI
        $existing_cats = get_mp_terms_with_counts('category');
        $existing_tags = get_mp_terms_with_counts('post_tag');

        // Convert to comma-separated strings
        $cat_list = !empty($existing_cats) ? implode(', ', array_column($existing_cats, 'name')) : 'None yet';
        $tag_list = !empty($existing_tags) ? implode(', ', array_column($existing_tags, 'name')) : 'None yet';

        // 2. Updated prompt with "Prefer existing" instructions
        $prompt = "You are a travel rewards expert. Categorize this Q&A into 1 category and up to 5 tags.

Title: {$title}
Content Sample: {$content}

---
EXISTING CATEGORIES: {$cat_list}
EXISTING TAGS: {$tag_list}

INSTRUCTIONS:
1. If one of the EXISTING CATEGORIES fits this content, you MUST use it. Only create a new category if none of the existing ones are relevant.
2. Favor EXISTING TAGS where applicable to maintain taxonomy consistency.
3. Return ONLY a JSON object with keys 'category' (string) and 'tags' (array of strings).";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
            'body'    => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object']
            ])
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['choices'][0]['message']['content'] ?? false;
    }
}
