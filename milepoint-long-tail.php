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

  if (mp_should_apply_cold_start()) {
    foreach ($results as $key => $term) {
      $true_count = (int)$term->post_count;
      if ($true_count === 0) {
        // If true count is 0, keep it unboosted per requirements
        $new_term = clone $term;
        $new_term->real_post_count = 0;
        $new_term->post_count = 0;
        $new_term->formatted_post_count = '0';
        $results[$key] = $new_term;
        continue;
      }

      $new_term = clone $term;
      $new_term->real_post_count = $true_count;
      $boosted_int = mp_get_boosted_count($true_count, $new_term->term_id);

      if ($boosted_int > 0) {
          $new_term->post_count = $boosted_int;
          $new_term->formatted_post_count = number_format_i18n($boosted_int);
      } else {
          $new_term->post_count = 0;
          $new_term->formatted_post_count = '0';
      }

      $results[$key] = $new_term;
    }

    usort($results, function($a, $b) {
      if ($a->post_count === $b->post_count) {
          return strcmp($a->name, $b->name);
      }
      return $b->post_count <=> $a->post_count;
    });
  }

  return $results;
}

function mp_should_apply_cold_start() {
    if (!get_option('mp_cold_start_enabled')) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    return !is_admin();
}

function mp_get_boosted_count($count, $term_id) {
    if (!$count) return 0;

    $min_base = 2000;
    $max_base = 100000;
    $range = $max_base - $min_base;

    // Pure persistent ID-based base using pseudo-random hashing formula
    $base_offset = $min_base + (($term_id * 7331) % $range);

    return (int)($base_offset + $count);
}

// Hook into get_terms to globally apply the cold start boost on the frontend
add_filter('get_terms', 'mp_apply_cold_start_boost_to_terms', 10, 4);
function mp_apply_cold_start_boost_to_terms($terms, $taxonomies, $args, $term_query) {
    if (mp_should_apply_cold_start() && is_array($terms)) {
        foreach ($terms as $key => $term) {
            if (is_object($term) && isset($term->count) && $term->count > 0) {
                $new_term = clone $term;
                $true_count = (int)$new_term->count;
                $new_term->real_count = $true_count;

                $boosted_int = mp_get_boosted_count($true_count, $new_term->term_id);

                if ($boosted_int > 0) {
                    $new_term->count = $boosted_int;
                    $new_term->formatted_boosted_count = number_format_i18n($boosted_int);
                } else {
                    $new_term->count = 0;
                    $new_term->formatted_boosted_count = '0';
                }

                $terms[$key] = $new_term;
            }
        }

        if (isset($args['orderby']) && $args['orderby'] === 'count') {
            usort($terms, function($a, $b) {
                $count_a = is_object($a) && isset($a->count) ? (int)$a->count : 0;
                $count_b = is_object($b) && isset($b->count) ? (int)$b->count : 0;
                if ($count_a === $count_b) {
                    $id_a = is_object($a) && isset($a->term_id) ? (int)$a->term_id : 0;
                    $id_b = is_object($b) && isset($b->term_id) ? (int)$b->term_id : 0;
                    return $id_a <=> $id_b;
                }
                return $count_b <=> $count_a;
            });
        }
    }
    return $terms;
}

// Hook into single term retrieval to globally apply the cold start boost
add_filter('get_term', 'mp_apply_cold_start_boost_to_single_term', 10, 2);
function mp_apply_cold_start_boost_to_single_term($term, $taxonomy) {
    if (mp_should_apply_cold_start() && is_object($term) && isset($term->count) && $term->count > 0) {
        $new_term = clone $term;
        $true_count = (int)$new_term->count;
        $new_term->real_count = $true_count;

        $boosted_int = mp_get_boosted_count($true_count, $new_term->term_id);

        if ($boosted_int > 0) {
            $new_term->count = $boosted_int;
            $new_term->formatted_boosted_count = number_format_i18n($boosted_int);
        } else {
            $new_term->count = 0;
            $new_term->formatted_boosted_count = '0';
        }

        return $new_term;
    }
    return $term;
}

// Format counts in standard wp_list_categories output without breaking float casting
add_filter('wp_list_categories', 'mp_format_category_counts_html', 10, 2);
function mp_format_category_counts_html($output, $args) {
    if (mp_should_apply_cold_start() && !empty($args['show_count'])) {
        // wp_list_categories outputs counts either wrapped in <span class="count">(1,234)</span> or just &nbsp;(1,234)
        $output = preg_replace_callback('/<span class="count">\(([^)]+)\)<\/span>/', function($matches) {
            $num = (int) preg_replace('/[^\d]/u', '', $matches[1]);
            return '<span class="count">(' . number_format_i18n($num) . ')</span>';
        }, $output);

        $output = preg_replace_callback('/&nbsp;\(([^)]+)\)/u', function($matches) {
            $num = (int) preg_replace('/[^\d]/u', '', $matches[1]);
            return '&nbsp;(' . number_format_i18n($num) . ')';
        }, $output);
    }
    return $output;
}
