<?php
// includes/class-conversation-cpt.php

class MP_Conversation_CPT_Handler {

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);

    // TEMPORARY: This forces the "Oops" to go away.
    // Delete this line after the page finally loads once.
    add_action('init', 'flush_rewrite_rules', 999);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_filter('template_include', [$this, 'force_conversation_templates']);
  }

  public function register_cpt() {
    register_post_type("milepoint-chat", [
      "label"               => "Conversations",
      "public"              => true,
      "show_ui"             => true,
      "show_in_rest"        => true,
      "has_archive"         => "conversations", // This creates /conversations/
      "rewrite"             => ["slug" => "conversations"],
      "capability_type"     => "post",
      "map_meta_cap"        => true,
      "supports"            => ["title", "editor", "custom-fields"],
    ]);
  }

  public function enqueue_assets() {
    // Only load CSS on the Hub or Single Conversation pages
    if (is_singular('milepoint-chat') || is_post_type_archive('milepoint-chat')) {
      wp_enqueue_style(
        'milepoint-conversations-css',
        plugin_dir_url(__DIR__) . 'assets/css/conversations.css',
        [],
        '1.0.0'
      );
    }
  }


  public function force_conversation_templates($template) {
    if (is_singular('milepoint-chat')) {
      $file = plugin_dir_path(__DIR__) . 'assets/templates/conversation.php';
      if (file_exists($file)) return $file;
    }

    if (is_post_type_archive('milepoint-chat')) {
      $file = plugin_dir_path(__DIR__) . 'assets/templates/conversation-hub.php';
      if (file_exists($file)) return $file;
    }

    return $template;
  }
}
new MP_Conversation_CPT_Handler();