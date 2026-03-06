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
          $new_term->formatted_post_count = mp_format_number_abbreviated($boosted_int);
      } else {
          $new_term->post_count = 0;
          $new_term->formatted_post_count = '0';
      }

      $results[$key] = $new_term;
    }

    usort($results, function($a, $b) {
      return $b->post_count <=> $a->post_count;
    });
  }

  return $results;
}

function mp_get_global_rank_data() {
    static $rank_map = null;

    if ($rank_map !== null) {
        return $rank_map;
    }

    global $wpdb;

    // Run ONE optimized $wpdb query that fetches term_id and the published milepoint_qa count
    // for all terms where that count is > 0.
    $query = "
        SELECT t.term_id, COUNT(p.ID) as true_count
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
        WHERE p.post_type = 'milepoint_qa'
        AND p.post_status = 'publish'
        GROUP BY t.term_id
        HAVING true_count > 0
    ";

    $results = $wpdb->get_results($query, ARRAY_A);

    if (empty($results)) {
        $rank_map = [
            'total_terms' => 0,
            'ranks' => []
        ];
        return $rank_map;
    }

    // Sort in PHP memory: true_count DESC, term_id ASC
    usort($results, function($a, $b) {
        if ((int)$a['true_count'] === (int)$b['true_count']) {
            return (int)$a['term_id'] <=> (int)$b['term_id'];
        }
        return (int)$b['true_count'] <=> (int)$a['true_count'];
    });

    $ranks = [];
    foreach ($results as $index => $row) {
        $ranks[$row['term_id']] = $index + 1; // Rank is 1-based index
    }

    $rank_map = [
        'total_terms' => count($results),
        'ranks' => $ranks
    ];

    return $rank_map;
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

    $global_data = mp_get_global_rank_data();

    // If the term is not in the ranks (true count is 0), return 0 early.
    // However, if $count passed in is > 0 but not in rank, that means it's a new or uncounted term.
    // In strict case, true_count = 0 means exclude.
    if (!isset($global_data['ranks'][$term_id])) {
        return (int)$count;
    }

    $rank = $global_data['ranks'][$term_id];
    $total_terms = $global_data['total_terms'];

    $config = [
        'Scalar' => 50,
        'Jitter_Range' => 500,
        'Buffer' => 1
    ];

    $base_offset = ($total_terms - $rank) * ($config['Jitter_Range'] + $config['Buffer']);
    $jitter = $term_id % $config['Jitter_Range'];

    // Final Display = ((Base Offset + Jitter) * Scalar) + True_Count
    $final_display = (($base_offset + $jitter) * $config['Scalar']) + $count;

    return (int)$final_display;
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
                    $new_term->formatted_boosted_count = mp_format_number_abbreviated($boosted_int);
                } else {
                    $new_term->count = 0;
                    $new_term->formatted_boosted_count = '0';
                }

                $terms[$key] = $new_term;
            }
        }

        usort($terms, function($a, $b) {
            $count_a = is_object($a) && isset($a->count) ? (int)$a->count : 0;
            $count_b = is_object($b) && isset($b->count) ? (int)$b->count : 0;
            return $count_b <=> $count_a;
        });
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
            $new_term->formatted_boosted_count = mp_format_number_abbreviated($boosted_int);
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
            return '<span class="count">(' . mp_format_number_abbreviated($num) . ')</span>';
        }, $output);

        $output = preg_replace_callback('/&nbsp;\(([^)]+)\)/u', function($matches) {
            $num = (int) preg_replace('/[^\d]/u', '', $matches[1]);
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
