<?php
// milepoint-long-tail.php
/*
Plugin Name: MilePoint Long-Tail DEV
Description: Captures Gist AI chats and transforms them into SEO posts.
Version: 1.0.12
Author: pguardiario@gmail.com
*/

if (!defined("ABSPATH")) {
  exit();
}

define("MP_LT_URL", plugin_dir_url(__FILE__));

require_once plugin_dir_path(__FILE__) . "includes/class-rest-handler.php";
require_once plugin_dir_path(__FILE__) . "includes/class-content-template.php";
require_once plugin_dir_path(__FILE__) . "includes/class-ai-handler.php";
require_once plugin_dir_path(__FILE__) . "includes/class-qa-cpt.php";
require_once plugin_dir_path(__FILE__) . "includes/class-qa-dashboard.php";
require_once plugin_dir_path(__FILE__) . "includes/class-conversation-cpt.php";
require_once plugin_dir_path(__FILE__) . "includes/class-schema-generator.php";

// Hooks that need to work for both Admin and REST API (like publishing hooks)
new MP_AI_Handler();

if (is_admin()) {
  require_once plugin_dir_path(__FILE__) . "includes/class-admin-settings.php";
  new MP_QA_Settings();
  new MP_QA_Dashboard();
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

// Initialize schema generator
new MP_Schema_Generator();

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
      "milepoint_nonce" => wp_create_nonce("milepoint_public_chat"),
    ]);

    wp_enqueue_script(
      "mp-chat-prefill",
      plugins_url("/assets/js/chat-prefill.js", __FILE__),
      [],
      time(), // Cache busting
      true,
    );
  }
});

// let's put this here, we will use it for our facets as well as to nudge the ai classifier to choose already existing taxonomies
function get_mp_terms_with_counts($taxonomy, $hide_empty = true)
{
  global $wpdb;

  if ($hide_empty) {
    // Original query: only terms with published milepoint_qa posts
    $query = "
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
    ";
  } else {
    // Show all terms for the taxonomy, calculating count for milepoint_qa posts (0 if none)
    $query = "
        SELECT t.term_id, t.name, t.slug, COUNT(p.ID) as post_count
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        LEFT JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            AND p.post_type = 'milepoint_qa'
            AND p.post_status = 'publish'
        WHERE tt.taxonomy = %s
        GROUP BY t.term_id
        ORDER BY post_count DESC, t.name ASC
    ";
  }

  $results = $wpdb->get_results(
    $wpdb->prepare($query, $taxonomy)
  );

  $is_frontend = !is_admin() || wp_doing_ajax();
  if (get_option('mp_cold_start_enabled') && $is_frontend) {
    foreach ($results as $key => $term) {
      $new_term = clone $term;
      $new_term->real_post_count = $new_term->post_count;
      $boosted_int = mp_get_boosted_count($new_term->post_count, $new_term->term_id);
      $new_term->post_count = mp_format_number_abbreviated($boosted_int);
      $results[$key] = $new_term;
    }
  }

  return $results;
}

function mp_get_boosted_count($count, $term_id) {
    if (!$count) return 0;

    // Pseudo-random deterministic boost based on term_id
    // Ensure the base relationship (highest counts stay highest) by multiplying
    $base_boost = $count * 1500;

    // Add a smaller pseudo-random factor using term_id so it doesn't look too perfect
    // Ensure the random factor is small enough not to overtake the next highest post count
    // Let's use term_id * 13 % 1000
    $random_factor = ($term_id * 13) % 1000;

    return $base_boost + $random_factor;
}

// Hook into get_terms to globally apply the cold start boost on the frontend
add_filter('get_terms', 'mp_apply_cold_start_boost_to_terms', 10, 4);
function mp_apply_cold_start_boost_to_terms($terms, $taxonomies, $args, $term_query) {
    $is_frontend = !is_admin() || wp_doing_ajax();
    if (get_option('mp_cold_start_enabled') && $is_frontend && is_array($terms)) {
        foreach ($terms as $key => $term) {
            if (is_object($term) && isset($term->count) && $term->count > 0) {
                $new_term = clone $term;
                // We use a separate property to store the real count if needed elsewhere
                $new_term->real_count = $new_term->count;
                $new_term->count = mp_get_boosted_count($new_term->count, $new_term->term_id);
                // Assign the formatted count to a custom property so frontend templates can use it
                $new_term->formatted_boosted_count = mp_format_number_abbreviated($new_term->count);
                $terms[$key] = $new_term;
            }
        }
    }
    return $terms;
}

// Hook into single term retrieval to globally apply the cold start boost
add_filter('get_term', 'mp_apply_cold_start_boost_to_single_term', 10, 2);
function mp_apply_cold_start_boost_to_single_term($term, $taxonomy) {
    $is_frontend = !is_admin() || wp_doing_ajax();
    if (get_option('mp_cold_start_enabled') && $is_frontend && is_object($term) && isset($term->count) && $term->count > 0) {
        $new_term = clone $term;
        $new_term->real_count = $new_term->count;
        $new_term->count = mp_get_boosted_count($new_term->count, $new_term->term_id);
        $new_term->formatted_boosted_count = mp_format_number_abbreviated($new_term->count);
        return $new_term;
    }
    return $term;
}

// Format counts in standard wp_list_categories output without breaking float casting
add_filter('wp_list_categories', 'mp_format_category_counts_html', 10, 2);
function mp_format_category_counts_html($output, $args) {
    $is_frontend = !is_admin() || wp_doing_ajax();
    if (get_option('mp_cold_start_enabled') && $is_frontend && !empty($args['show_count'])) {
        // wp_list_categories outputs counts either wrapped in <span class="count">(1,234)</span> or just &nbsp;(1,234)
        $output = preg_replace_callback('/<span class="count">\(([0-9,]+)\)<\/span>/', function($matches) {
            $num = (int) str_replace(',', '', $matches[1]);
            return '<span class="count">(' . mp_format_number_abbreviated($num) . ')</span>';
        }, $output);

        $output = preg_replace_callback('/&nbsp;\(([0-9,]+)\)/', function($matches) {
            $num = (int) str_replace(',', '', $matches[1]);
            return '&nbsp;(' . mp_format_number_abbreviated($num) . ')';
        }, $output);
    }
    return $output;
}

function mp_format_number_abbreviated($number) {
    if ($number < 1000) {
        return $number;
    }

    if ($number < 1000000) {
        $formatted = number_format($number / 1000, 1);
        // Remove .0 if it exists
        return str_replace('.0', '', $formatted) . 'K';
    }

    $formatted = number_format($number / 1000000, 1);
    return str_replace('.0', '', $formatted) . 'M';
}
