<?php
// includes/class-qa-cpt.php

/**
 * MP_QA_CPT_Handler
 * Handles CPT Registration, Hub Template Hijacking, and Trending Logic
 */

if (!defined("ABSPATH")) {
  exit();
}

class MP_QA_CPT_Handler
{
  public function __construct()
  {
    // Register CPT
    add_action("init", [$this, "register_cpt"]);
    add_action("pre_get_posts", [$this, "handle_hub_sorting"]);

    // Admin Columns
    add_filter("manage_milepoint_qa_posts_columns", [$this, "set_custom_columns"]);
    add_action("manage_milepoint_qa_posts_custom_column", [$this, "custom_column_data"], 10, 2);
    add_filter("manage_edit-milepoint_qa_sortable_columns", [$this, "sortable_columns"]);

    // Metaboxes
    add_action("add_meta_boxes", [$this, "add_metaboxes"]);


    // Hub Template Hijacking
    add_filter("template_include", [$this, "force_hub_layout"]);

    // TRACKING HOOK
    add_action(
      "transition_post_status",
      [$this, "track_status_transitions"],
      10,
      3,
    );
  }

  /**
   * Logs every time a milepoint_qa post changes status
   */

  public function set_custom_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $title) {
      if ($key === "date") {
        $new_columns["mp_workflow_bucket"] = "Workflow Bucket";
        $new_columns["mp_source_thread"] = "Source Thread";
        $new_columns["mp_turn_index"] = "Turn";
        $new_columns["mp_type"] = "Type";
        $new_columns["mp_rewritten"] = "Rewritten?";
        $new_columns["mp_original_preview"] = "Original Preview";
      }
      $new_columns[$key] = $title;
    }
    return $new_columns;
  }

  public function custom_column_data($column, $post_id) {
    switch ($column) {
      case "mp_workflow_bucket":
        $terms = get_the_terms($post_id, "mp_workflow_status");
        if ($terms && !is_wp_error($terms)) {
          $term_names = wp_list_pluck($terms, "name");
          echo esc_html(implode(", ", $term_names));
        } else {
          echo "—";
        }
        break;
      case "mp_source_thread":
        $thread_id = get_post_meta($post_id, "_mp_source_thread_id", true);
        if ($thread_id) {
          echo esc_html(substr($thread_id, 0, 12)) . "...";
        } else {
          echo "—";
        }
        break;
      case "mp_turn_index":
        $turn = get_post_meta($post_id, "_mp_source_turn_index", true);
        echo $turn !== "" ? esc_html($turn) : "—";
        break;
      case "mp_type":
        $is_primary = get_post_meta($post_id, "_mp_is_primary_turn", true);
        echo $is_primary ? "<strong>Primary</strong>" : "Follow-up";
        break;
      case "mp_rewritten":
        $rewritten = get_post_meta($post_id, "_mp_rewritten_question", true);
        echo $rewritten ? "Yes" : "No";
        break;
      case "mp_original_preview":
        $orig = get_post_meta($post_id, "_mp_original_question", true);
        if ($orig) {
          echo esc_html(wp_trim_words($orig, 8, "..."));
        } else {
          echo "—";
        }
        break;
    }
  }

  public function sortable_columns($columns) {
    $columns["mp_workflow_bucket"] = "mp_workflow_bucket";
    $columns["mp_turn_index"] = "mp_turn_index";
    $columns["mp_type"] = "mp_type";
    return $columns;
  }

  public function add_metaboxes() {
    add_meta_box(
      "mp_original_turn_metabox",
      "Original Captured Turn",
      [$this, "render_original_metabox"],
      "milepoint_qa",
      "normal",
      "high"
    );

    add_meta_box(
      "mp_rewritten_turn_metabox",
      "Rewritten Standalone Version",
      [$this, "render_rewritten_metabox"],
      "milepoint_qa",
      "normal",
      "high"
    );
  }

  public function render_original_metabox($post) {
    $original_question = get_post_meta($post->ID, "_mp_original_question", true);
    $original_answer = get_post_meta($post->ID, "_mp_original_answer", true);
    $thread_id = get_post_meta($post->ID, "_mp_source_thread_id", true);
    $turn_index = get_post_meta($post->ID, "_mp_source_turn_index", true);
    $parent_id = get_post_meta($post->ID, "_mp_parent_primary_post_id", true);
    $reason = get_post_meta($post->ID, "_mp_classification_reason", true);
    $confidence = get_post_meta($post->ID, "_mp_classification_confidence", true);
    $classification_failed = get_post_meta($post->ID, "_mp_classification_failed", true);
    $rewrite_failed = get_post_meta($post->ID, "_mp_rewrite_failed", true);

    $terms = get_the_terms($post->ID, "mp_workflow_status");
    $bucket = ($terms && !is_wp_error($terms)) ? implode(", ", wp_list_pluck($terms, "name")) : "None";

    echo "<div style='background:#f9f9f9; padding:15px; border:1px solid #ddd; margin-bottom:10px;'>";
    echo "<p><strong>Source Thread / Session ID:</strong> " . esc_html($thread_id) . "</p>";
    echo "<p><strong>Source Turn Index:</strong> " . esc_html($turn_index) . "</p>";

    if ($parent_id) {
      $edit_link = get_edit_post_link($parent_id);
      echo "<p><strong>Parent Primary Post ID:</strong> <a href='" . esc_url($edit_link) . "'>" . esc_html($parent_id) . "</a></p>";
    } else {
      echo "<p><strong>Parent Primary Post ID:</strong> N/A (Is Primary)</p>";
    }

    echo "<p><strong>Workflow Bucket:</strong> " . esc_html($bucket) . "</p>";

    if ($reason) {
      echo "<p><strong>Classification Reason:</strong> " . esc_html($reason) . "</p>";
    }
    if ($confidence) {
      echo "<p><strong>Classification Confidence:</strong> " . esc_html($confidence) . "</p>";
    }
    if ($classification_failed) {
      echo "<p style='color:red;'><strong>Classification Failed:</strong> Yes</p>";
    }
    if ($rewrite_failed) {
      echo "<p style='color:red;'><strong>Rewrite Failed:</strong> Yes</p>";
    }

    echo "<hr/>";
    echo "<h4>Original Question</h4>";
    echo "<blockquote>" . nl2br(esc_html($original_question)) . "</blockquote>";

    echo "<h4>Original Answer</h4>";
    echo "<blockquote>" . wp_kses_post($original_answer) . "</blockquote>";
    echo "</div>";
  }

  public function render_rewritten_metabox($post) {
    $rewritten_question = get_post_meta($post->ID, "_mp_rewritten_question", true);
    $rewritten_answer = get_post_meta($post->ID, "_mp_rewritten_answer", true);

    echo "<div style='background:#f9f9f9; padding:15px; border:1px solid #ddd;'>";
    if ($rewritten_question || $rewritten_answer) {
      if ($rewritten_question) {
        echo "<h4>Rewritten Question</h4>";
        echo "<blockquote>" . nl2br(esc_html($rewritten_question)) . "</blockquote>";
      }
      if ($rewritten_answer) {
        echo "<h4>Rewritten Answer</h4>";
        echo "<blockquote>" . wp_kses_post($rewritten_answer) . "</blockquote>";
      }
    } else {
      echo "<p><em>No rewrite was generated for this item.</em></p>";
    }
    echo "</div>";
  }

  public function track_status_transitions($new_status, $old_status, $post)
  {
    if ($post->post_type !== "milepoint_qa") {
      return;
    }
    if ($new_status === $old_status) {
      return;
    }

    global $wpdb;
    $wpdb->insert($wpdb->prefix . "mp_qa_status_log", [
      "post_id" => $post->ID,
      "old_status" => $old_status,
      "new_status" => $new_status,
      "transition_date" => current_time("mysql"),
    ]);
  }

  /**
   * Register the Reader Q&A Custom Post Type
   */
  public function register_cpt()
  {
    register_post_type("milepoint_qa", [
      "labels" => [
        "name" => "Reader Q&A",
        "singular_name" => "Q&A Article",
        "add_new" => "Add New Q&A",
        "add_new_item" => "Add New Q&A Article",
      ],
      "public" => true,
      "has_archive" => "questions",
      "rewrite" => ["slug" => "questions"],
      "show_in_rest" => true, // Enables Gutenberg and REST API access
      "taxonomies" => ["category", "post_tag"],
      "supports" => [
        "title",
        "editor",
        "excerpt",
        "custom-fields",
        "thumbnail",
        "comments",
      ],
      "menu_icon" => "dashicons-format-chat",
    ]);

    // Register Workflow Status Taxonomy
    register_taxonomy("mp_workflow_status", ["milepoint_qa"], [
      "labels" => [
        "name" => "Workflow Status",
        "singular_name" => "Workflow Status",
      ],
      "public" => false,
      "show_ui" => true,
      "show_admin_column" => false, // We will handle admin columns manually
      "show_in_nav_menus" => false,
      "show_tagcloud" => false,
      "hierarchical" => true, // Better for filtering UI
      "rewrite" => false,
    ]);

    // Ensure terms exist
    $terms = [
      "primary_first_turn" => "Primary First Turn",
      "ready_as_is" => "Ready As Is",
      "needs_rewrite_review" => "Needs Rewrite Review",
      "hold" => "Hold",
    ];

    foreach ($terms as $slug => $name) {
      if (!term_exists($slug, "mp_workflow_status")) {
        wp_insert_term($name, "mp_workflow_status", ["slug" => $slug]);
      }
    }
  }

  /**
   * Handles Hub Sorting (Trending vs Newest)
   */
  public function handle_hub_sorting($query)
  {
    if (
      !is_admin() &&
      $query->is_main_query() &&
      is_post_type_archive("milepoint_qa")
    ) {
      if (isset($_GET["sort"]) && $_GET["sort"] === "trending") {
        global $wpdb;
        $stats_table = $wpdb->prefix . "mp_query_stats";

        // Add filters to the query to join the stats table
        add_filter("posts_join", function ($join) use ($stats_table) {
          global $wpdb;
          $join .= " LEFT JOIN (
            SELECT post_id, SUM(view_count) as total_views
            FROM $stats_table
            WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY post_id
          ) AS trending_stats ON {$wpdb->posts}.ID = trending_stats.post_id ";
          return $join;
        });

        add_filter("posts_orderby", function ($orderby) {
          return " trending_stats.total_views DESC, post_date DESC ";
        });
      }
    }
  }

  public function handle_hub_query($query)
  {
    if (
      is_admin() ||
      !$query->is_main_query() ||
      !$query->is_post_type_archive("milepoint_qa")
    ) {
      return;
    }

    // 1. Handle Sorting
    $sort = $_GET["sort"] ?? "newest";
    if ($sort === "trending") {
      $query->set("orderby", "comment_count");
      $query->set("order", "DESC");
    }

    // 2. Handle Category Filtering via URL param (?category_name=slug)
    if (!empty($_GET["category_name"])) {
      $query->set("category_name", sanitize_text_field($_GET["category_name"]));
    }

    // 3. Handle Tag Filtering via URL param (?tag=slug)
    if (!empty($_GET["tag"])) {
      $query->set("tag", sanitize_text_field($_GET["tag"]));
    }
  }

  public function force_hub_layout($template)
  {
    if (is_post_type_archive("milepoint_qa")) {
      $custom_template =
        plugin_dir_path(__DIR__) . "assets/templates/hub-page.php";
      if (file_exists($custom_template)) {
        return $custom_template;
      }
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
    $table = $wpdb->prefix . "mp_query_stats";

    // Views last 3 days
    $current =
      $wpdb->get_var(
        $wpdb->prepare(
          "SELECT SUM(view_count) FROM $table WHERE post_id = %d AND view_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)",
          $post_id,
        ),
      ) ?:
      0;

    // Views previous 3 days (4-6 days ago)
    $previous =
      $wpdb->get_var(
        $wpdb->prepare(
          "SELECT SUM(view_count) FROM $table WHERE post_id = %d AND view_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND DATE_SUB(CURDATE(), INTERVAL 4 DAY)",
          $post_id,
        ),
      ) ?:
      0;

    if ($current > $previous) {
      return "up";
    }
    if ($current < $previous && $previous > 0) {
      return "down";
    }

    return "neutral";
  }
}

// Initialize the class
new MP_QA_CPT_Handler();
