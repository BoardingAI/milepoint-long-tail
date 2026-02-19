<?php

if (! defined('ABSPATH')) exit;

class MP_REST_Handler
{

  /**
   * Register the REST API route
   */
  public function register_routes()
  {
    register_rest_route('milepoint-v1', '/generate-post', array(
      'methods'             => 'POST',
      'callback'            => array($this, 'handle_ingest'),
      'permission_callback' => array($this, 'check_permissions'),
    ));
  }

  /**
   * Only allow users who can edit posts
   */
  public function check_permissions()
  {
    return current_user_can('edit_posts');
  }

  /**
   * Handle the incoming JSON from the JS listener
   */
  public function handle_ingest($request)
  {
    $params = $request->get_json_params();
    $thread_id = sanitize_text_field($params['thread_id'] ?? '');

    // THE FIX: We must explicitly map the 'sources' array so it isn't discarded
    $transcript = array_map(function ($item) {
      // Sanitize the internal sources array if it exists
      $sources = isset($item['sources']) ? array_map(function ($source) {
        return array(
          'url'     => esc_url_raw($source['url']),
          'title'   => sanitize_text_field($source['title']),
          'excerpt' => wp_kses_post($source['excerpt'])
        );
      }, $item['sources']) : array();

      return array(
        'question' => wp_kses_post($item['question']),
        'answer'   => wp_kses_post($item['answer']),
        'sources'  => $sources // <--- This allows it to be saved to Post Meta
      );
    }, $params['full_transcript'] ?? array());

    // Fix: Sanitize each related suggestion individually
    $related = isset($params['related_suggestions']) && is_array($params['related_suggestions'])
        ? array_map('sanitize_text_field', $params['related_suggestions'])
        : array();

    // Check for valid sources before proceeding
    $total_sources = 0;
    foreach ($transcript as $item) {
      if (!empty($item['sources']) && is_array($item['sources'])) {
        foreach ($item['sources'] as $source) {
          if (!empty($source['url'])) {
            $total_sources++;
          }
        }
      }
    }

    if ($total_sources === 0) {
      return new WP_REST_Response(array('message' => 'Skipped: No sources'), 200);
    }

    // 1. Check if post already exists
    $existing_id = $this->get_post_id_by_thread($thread_id);

    if ($existing_id) {
      update_post_meta($existing_id, '_raw_transcript', $transcript);
      update_post_meta($existing_id, '_related_suggestions', $related);

      // Attempt to set a featured image if missing
      $this->process_featured_image($existing_id, $transcript);

      return new WP_REST_Response(array('message' => 'Post updated with sources.'), 200);
    }

    // 2. CREATE NEW post
    $first_question = wp_strip_all_tags($transcript[0]['question'] ?? 'New Q&A');
    $post_id = wp_insert_post(array(
      'post_title'   => $first_question,
      'post_content' => '<!-- MILEPOINT_LONG_TAIL -->',
      'post_status'  => 'draft',
      'post_type'    => 'milepoint_qa',
    ));

    update_post_meta($post_id, '_gist_thread_id', $thread_id);
    update_post_meta($post_id, '_raw_transcript', $transcript);
    update_post_meta($post_id, '_related_suggestions', $related);

    // Attempt to set a featured image
    $this->process_featured_image($post_id, $transcript);

    return new WP_REST_Response(array('message' => 'New draft created. ID: ' . $post_id), 200);
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
    $sources = array();
    foreach ($transcript as $block) {
      if (!empty($block['sources']) && is_array($block['sources'])) {
        foreach ($block['sources'] as $source) {
          if (!empty($source['url'])) {
            $sources[] = $source['url'];
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
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    foreach ($sources as $url) {
      $image_url = $this->scrape_featured_image($url);
      if ($image_url) {
        // Attempt to sideload
        // The last argument 'id' makes it return the attachment ID
        $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');

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
    $response = wp_remote_get($url, array(
      'timeout'    => 10,
      'user-agent' => 'Mozilla/5.0 (compatible; MilePointBot/1.0; +http://milepoint.com)'
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
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
    if (empty($thread_id) || $thread_id === 'unknown') {
      return false;
    }

    $posts = get_posts(array(
      'post_type'      => 'milepoint_qa',
      'meta_key'       => '_gist_thread_id',
      'meta_value'     => $thread_id,
      'posts_per_page' => 1,
      'fields'         => 'ids',
      // Check ALL statuses!!
      'post_status'    => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'),
    ));

    return ! empty($posts) ? $posts[0] : false;
  }
}
