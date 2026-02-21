<?php
// milepoint-long-tail.php
/*
Plugin Name: MilePoint Long-Tail DEV
Description: Captures Gist AI chats and transforms them into SEO posts.
Version: 1.0.5
Author: pguardiario@gmail.com
*/

if (!defined("ABSPATH")) {
  exit();
}

define('MP_LT_URL', plugin_dir_url(__FILE__));

require_once plugin_dir_path(__FILE__) . "includes/class-rest-handler.php";
require_once plugin_dir_path(__FILE__) . "includes/class-content-template.php";
require_once plugin_dir_path(__FILE__) . "includes/class-ai-handler.php";
require_once plugin_dir_path(__FILE__) . "includes/class-qa-cpt.php";

// Hooks that need to work for both Admin and REST API (like publishing hooks)
new MP_AI_Handler();

if (is_admin()) {
  require_once plugin_dir_path(__FILE__) . "includes/class-admin-settings.php";
  new MP_QA_Settings();
}

/**
 * Plugin Activation Hook
 */
register_activation_hook(__FILE__, "mp_lt_activate_plugin");

function mp_lt_activate_plugin()
{
  // 1. Include the DB setup file
  require_once plugin_dir_path(__FILE__) . "includes/db-setup.php";

  // 2. Run the table creation logic
  mp_lt_install_database();
}

// Initialize display template
new MP_Content_Template();

// Initialize the REST API
add_action("rest_api_init", function () {
  $rest_handler = new MP_REST_Handler();
  $rest_handler->register_routes();
});

// Enqueue JS listener on the chat page
add_action("wp_enqueue_scripts", function () {
  // Check if we are on the page with the slug 'chat'
  if (is_page("chat")) {
    wp_enqueue_script(
      "mp-longtail-bridge",
      plugins_url("/assets/js/bridge-listener.js", __FILE__),
      [],
      time(), // Cache busting for now
      true,
    );

    // localize the url and the nonce
    wp_localize_script("mp-longtail-bridge", "mpData", [
      "rest_url" => esc_url_raw(rest_url("milepoint-v1/generate-post")),
      "nonce" => wp_create_nonce("wp_rest"),
    ]);
  }
});

// let's put this here, we will use it for our facets as well as to nudge the ai classifier to choose already existing taxonomies
function get_mp_terms_with_counts($taxonomy)
{
  global $wpdb;

  // This query finds terms used by 'milepoint_qa' and calculates the specific count
  $results = $wpdb->get_results($wpdb->prepare("
            SELECT t.term_id, t.name, t.slug, COUNT(tr.object_id) as post_count
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            WHERE p.post_type = 'milepoint_qa'
            AND p.post_status = 'publish'
            AND tt.taxonomy = %s
            GROUP BY t.term_id
            ORDER BY post_count DESC
        ", $taxonomy));

  return $results;
}
