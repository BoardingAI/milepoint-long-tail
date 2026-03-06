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
    $results = mp_get_boosted_list($results);
  }

  return $results;
}

function mp_get_boosted_count_from_rank($true_count, $term_id, $rank, $total_terms, $config = []) {
    if (!$true_count) return 0;

    $jitter_range = isset($config['jitter_range']) ? $config['jitter_range'] : 500;
    $buffer = isset($config['buffer']) ? $config['buffer'] : 10;
    $scalar = isset($config['scalar']) ? $config['scalar'] : 1;

    // Step must be strictly greater than jitter range
    $step = $jitter_range + $buffer;

    $total_terms = max(1, $total_terms);
    $rank = max(1, min($rank, $total_terms));

    // Base calculation
    $reverse_rank = $total_terms - $rank;

    // Minimum guaranteed distance based on rank
    $base = $reverse_rank * $step;

    // Jitter seed from ID
    $jitter = ($term_id * 431) % $jitter_range;

    // Add true count for linear +1 growth
    return (int) round((($base + $jitter) * $scalar) + $true_count);
}

function mp_get_term_global_rank($taxonomy, $true_count, $term_id) {
    global $wpdb;
    static $total_terms_cache = [];

    // Find total terms with count > 0 to match ranking scope
    if (!isset($total_terms_cache[$taxonomy])) {
        $total_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND count > 0",
            $taxonomy
        );
        $total_terms_cache[$taxonomy] = (int)$wpdb->get_var($total_query);
    }

    $total_terms = $total_terms_cache[$taxonomy];

    // Find rank: count of terms with higher count OR same count but higher term_id
    $query = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND (count > %d OR (count = %d AND term_id > %d))",
        $taxonomy, $true_count, $true_count, $term_id
    );
    $higher_terms = (int)$wpdb->get_var($query);
    $rank = $higher_terms + 1;

    return [
        'rank' => $rank,
        'total_terms' => max($total_terms, $rank) // Ensure total is at least rank
    ];
}

function mp_get_boosted_list($items, $config = []) {
    if (empty($items)) return $items;

    // Apply back to original items array, maintaining original order
    $result = [];
    foreach ($items as $key => $item) {
        $new_item = is_object($item) ? clone $item : $item;

        // Find the true count and term_id
        $true_count = isset($new_item->count) ? (int)$new_item->count : (isset($new_item->post_count) ? (int)$new_item->post_count : 0);
        $term_id = isset($new_item->term_id) ? (int)$new_item->term_id : 0;
        $taxonomy = isset($new_item->taxonomy) ? $new_item->taxonomy : '';

        if ($true_count > 0 && $taxonomy) {
            $rank_data = mp_get_term_global_rank($taxonomy, $true_count, $term_id);
            $rank = $rank_data['rank'];
            $total_terms = $rank_data['total_terms'];

            $boosted_int = mp_get_boosted_count_from_rank($true_count, $term_id, $rank, $total_terms, $config);

            if (isset($new_item->count)) {
                $new_item->real_count = $true_count;
                $new_item->count = $boosted_int;
                $new_item->formatted_boosted_count = mp_format_number_abbreviated($boosted_int);
            }
            if (isset($new_item->post_count)) {
                $new_item->real_post_count = $true_count;
                $new_item->post_count = mp_format_number_abbreviated($boosted_int);
            }
        }
        $result[$key] = $new_item;
    }

    return $result;
}

// Hook into get_terms to globally apply the cold start boost on the frontend
add_filter('get_terms', 'mp_apply_cold_start_boost_to_terms', 10, 4);
function mp_apply_cold_start_boost_to_terms($terms, $taxonomies, $args, $term_query) {
    $is_frontend = !is_admin() || wp_doing_ajax();
    if (get_option('mp_cold_start_enabled') && $is_frontend && is_array($terms)) {
        $terms = mp_get_boosted_list($terms);
    }
    return $terms;
}

// Hook into single term retrieval to globally apply the cold start boost
add_filter('get_term', 'mp_apply_cold_start_boost_to_single_term', 10, 2);
function mp_apply_cold_start_boost_to_single_term($term, $taxonomy) {
    $is_frontend = !is_admin() || wp_doing_ajax();
    if (get_option('mp_cold_start_enabled') && $is_frontend && is_object($term) && isset($term->count) && $term->count > 0) {
        $true_count = (int)$term->count;
        $term_id = (int)$term->term_id;

        $rank_data = mp_get_term_global_rank($taxonomy, $true_count, $term_id);
        $rank = $rank_data['rank'];
        $total_terms = $rank_data['total_terms'];

        $boosted_int = mp_get_boosted_count_from_rank($true_count, $term_id, $rank, $total_terms);

        $new_term = clone $term;
        $new_term->real_count = $true_count;
        $new_term->count = $boosted_int;
        $new_term->formatted_boosted_count = mp_format_number_abbreviated($boosted_int);

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
