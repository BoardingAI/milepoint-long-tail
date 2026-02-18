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

    $related = $params['related_suggestions'] ?? array();

    // 1. Check if post already exists
    $existing_id = $this->get_post_id_by_thread($thread_id);

    if ($existing_id) {
      update_post_meta($existing_id, '_raw_transcript', $transcript);
      update_post_meta($existing_id, '_related_suggestions', $related);
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

    return new WP_REST_Response(array('message' => 'New draft created. ID: ' . $post_id), 200);
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
