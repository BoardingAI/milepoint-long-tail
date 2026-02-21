<?php
// includes/class-qa-cpt.php

/**
 * MP_QA_CPT_Handler
 * Handles CPT Registration, Hub Template Hijacking, and Trending Logic
 */

if (!defined('ABSPATH')) exit;

class MP_QA_CPT_Handler
{

  public function __construct()
  {
    // Register CPT
    add_action('init', [$this, 'register_cpt']);
    add_action('pre_get_posts', [$this, 'handle_hub_sorting']);

    // Hub Template Hijacking
    add_filter('template_include', [$this, 'force_hub_layout']);
  }

  /**
   * Register the Reader Q&A Custom Post Type
   */
  public function register_cpt()
  {
    register_post_type("milepoint_qa", [
      "labels" => [
        "name"      => "Reader Q&A",
        "singular_name" => "Q&A Article",
        "add_new"     => "Add New Q&A",
        "add_new_item"  => "Add New Q&A Article",
      ],
      "public"    => true,
      "has_archive" => "q-and-a",
      "rewrite"   => ["slug" => "q-and-a"],
      "show_in_rest" => true, // Enables Gutenberg and REST API access
      "taxonomies"  => ['category', 'post_tag'],
      "supports"  => [
        "title",
        "editor",
        "excerpt",
        "custom-fields",
        "thumbnail",
        "comments",
      ],
      "menu_icon"   => "dashicons-format-chat",
    ]);
  }



  /**
   * Handles Hub Sorting (Trending vs Newest)
   */
  public function handle_hub_sorting($query)
  {
    if (!is_admin() && $query->is_main_query() && is_post_type_archive('milepoint_qa')) {

      if (isset($_GET['sort']) && $_GET['sort'] === 'trending') {
        global $wpdb;
        $stats_table = $wpdb->prefix . 'mp_query_stats';

        // Add filters to the query to join the stats table
        add_filter('posts_join', function ($join) use ($stats_table) {
          global $wpdb;
          $join .= " LEFT JOIN (
            SELECT post_id, SUM(view_count) as total_views
            FROM $stats_table
            WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY post_id
          ) AS trending_stats ON {$wpdb->posts}.ID = trending_stats.post_id ";
          return $join;
        });

        add_filter('posts_orderby', function ($orderby) {
          return " trending_stats.total_views DESC, post_date DESC ";
        });
      }
    }
  }

  public function handle_hub_query($query)
  {
    if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive('milepoint_qa')) {
      return;
    }

    // 1. Handle Sorting
    $sort = $_GET['sort'] ?? 'newest';
    if ($sort === 'trending') {
      $query->set('orderby', 'comment_count');
      $query->set('order', 'DESC');
    }

    // 2. Handle Category Filtering via URL param (?category_name=slug)
    if (!empty($_GET['category_name'])) {
      $query->set('category_name', sanitize_text_field($_GET['category_name']));
    }

    // 3. Handle Tag Filtering via URL param (?tag=slug)
    if (!empty($_GET['tag'])) {
      $query->set('tag', sanitize_text_field($_GET['tag']));
    }
  }

  public function force_hub_layout($template)
  {
    if (is_post_type_archive('milepoint_qa')) {
      $custom_template = plugin_dir_path(__DIR__) . 'assets/templates/hub-page.php';
      if (file_exists($custom_template)) return $custom_template;
    }
    return $template;
  }

  /**
   * Static Helper: Calculate trend direction (3 days vs previous 3 days)
   * Accessible in templates via MP_QA_CPT_Handler::get_trend_direction($id)
   */
  public static function get_trend_direction($post_id)
  {
    global $wpdb;
    $table = $wpdb->prefix . 'mp_query_stats';

    // Views last 3 days
    $current = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(view_count) FROM $table WHERE post_id = %d AND view_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)",
      $post_id
    )) ?: 0;

    // Views previous 3 days (4-6 days ago)
    $previous = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(view_count) FROM $table WHERE post_id = %d AND view_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND DATE_SUB(CURDATE(), INTERVAL 4 DAY)",
      $post_id
    )) ?: 0;

    if ($current > $previous) return 'up';
    if ($current < $previous && $previous > 0) return 'down';

    return 'neutral';
  }
}

// Initialize the class
new MP_QA_CPT_Handler();
