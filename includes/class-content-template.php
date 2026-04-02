<?php
// includes/class-content-template.php

// TODO: Move to assets/templates

if (!defined("ABSPATH")) {
  exit();
}

class MP_Content_Template
{
  public function __construct()
  {
    add_filter("the_content", [$this, "render_qa_view"]);
    add_action("wp_enqueue_scripts", [$this, "enqueue_assets"]);
    add_action("wp_enqueue_scripts", [$this, "track_view"]); // track views for trend lines
  }

  public function enqueue_assets()
  {
    // Only load these files on the single Q&A post pages
    if (is_singular('milepoint_qa') || is_post_type_archive('milepoint_qa') || is_tax('category')) {
      wp_enqueue_style(
        "mp-qa-style",
        plugins_url("../assets/css/mp-qa.css", __FILE__),
        [],
        "1.1.0", // try to bump these every time
      );
      wp_enqueue_script(
        "mp-qa-hover",
        plugins_url("../assets/js/mp-qa-hover.js", __FILE__),
        [],
        "1.1.0",
        true, // Load in footer
      );
    }
  }

  private function clean_lit_comments($string)
  {
    if (empty($string)) {
      return "";
    }
    // Strips any remaining HTML comments
    return preg_replace("/<!--(.*?)-->/s", "", $string);
  }

  private function get_hostname($url)
  {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? str_replace("www.", "", $host) : "";
  }

  /**
   * Increments the daily view count for the current Q&A post
   */
  public function track_view()
  {
    if (is_singular("milepoint_qa")) {
      global $wpdb;
      $table_name = $wpdb->prefix . "mp_query_stats";
      $post_id = get_the_ID();
      $today = date("Y-m-d");

      // This query either inserts a new row for today or increments the existing one
      $wpdb->query(
        $wpdb->prepare(
          "INSERT INTO $table_name (post_id, view_date, view_count)
               VALUES (%d, %s, 1)
               ON DUPLICATE KEY UPDATE view_count = view_count + 1",
          $post_id,
          $today,
        ),
      );
    }
  }

  /**
   * Helper to extract the real destination URL if it's a Gist redirect
   */
  private function resolve_source_url($url)
  {
    // If it's a Gist redirect, try to parse the doc_url param
    if (strpos($url, "redirect.gist.ai") !== false) {
      $parsed = parse_url($url);
      if (isset($parsed["query"])) {
        parse_str($parsed["query"], $query_params);
        if (!empty($query_params["doc_url"])) {
          return $query_params["doc_url"];
        }
      }
    }
    return $url;
  }

  public function render_qa_view($content)
  {
    if (get_post_type() !== "milepoint_qa" || !is_main_query()) {
      return $content;
    }

    $post_id = get_the_ID();
    $single_turn = get_post_meta($post_id, "_mp_single_turn_content", true);

    // Fallback for older posts that might not have single_turn_content yet
    if (empty($single_turn)) {
      $transcript = get_post_meta($post_id, "_raw_transcript", true);
      if (is_array($transcript) && !empty($transcript)) {
          $single_turn = $transcript[0]; // just take the first turn
      } else {
          return $content;
      }
    }

    $related = get_post_meta($post_id, "_related_suggestions", true);
    // Prefer turn-specific breakdown if available, fallback to legacy global meta
    $breakdown_data = !empty($single_turn["breakdown"]) ? $single_turn["breakdown"] : get_post_meta($post_id, "_breakdown", true);

    $is_primary_meta = get_post_meta($post_id, "_mp_is_primary_turn", true);
    $is_primary = $is_primary_meta === '' || $is_primary_meta === "1"; // Handle empty (legacy) or explicitly "1" as primary

    $html = '<div id="mp-hover-card"></div>';
    $html .= '<div class="mp-qa-container">';

    $question = $this->clean_lit_comments($single_turn["question"] ?? "");
    $answer = $this->clean_lit_comments($single_turn["answer"] ?? "");
    $sources = $single_turn["sources"] ?? [];

    // Emit only the sources array into the DOM for hover JS dependencies to reduce JSON bloat
    $html .= '<script type="application/json" id="mp-qa-content">' . wp_json_encode([['sources' => $sources]]) . "</script>";

    $html .= '<div class="mp-qa-row">';

    // No need to output H2 for the single turn since the title is already the H1.
    // However, if the user explicitly wants an H2, we could add it.
    // The previous logic skipped H2 for index 0 (which is the single turn).

    // Breakdown bars: render for any post (primary or follow-up) if breakdown data exists
    if (!empty($breakdown_data) && is_singular('milepoint_qa')) {
      $html .= '<div class="mp-attribution-wrapper">';
      foreach ($breakdown_data as $bd_index => $bd_item) {
        $color_index = $bd_index % 4;
        $raw_percentage = isset($bd_item["percentage"]) ? (float) $bd_item["percentage"] : 0.0;
        $clamped_percentage = max(0, min(100, $raw_percentage));
        $width = $clamped_percentage . "%";

        $html .= '<div class="mp-breakdown-segment" style="width: ' . esc_attr($width) . ';">';
        $html .= '<div class="mp-breakdown-bar mp-bar-color-' . $color_index . '"></div>';
        $html .= '<div class="mp-breakdown-label">';
        $html .= '<strong class="mp-breakdown-percentage mp-text-color-' . $color_index . '">' . esc_html($bd_item["percentage"]) . '%</strong> ';
        $html .= '<span class="mp-breakdown-source">' . esc_html($bd_item["source"]) . "</span>";
        $html .= "</div>";
        $html .= "</div>"; // Close segment column
      }
      $html .= "</div>"; // Close Wrapper
    }

    // ANSWER BOX
    $html .= '  <section id="mp-a-0" class="mp-a mp-answer-section">';
    // If post_content has been updated to Gutenberg blocks (i.e. not placeholder/empty)
    // we use $content instead of the meta $answer to reflect manual editor changes.
    if (!empty(trim($content)) && trim($content) !== '<!-- MILEPOINT_LONG_TAIL -->') {
        $html .= $content;
    } else {
        $html .= $answer;
    }
    $html .= "  </section>"; // Close Answer Box

    // Sources carousel
    if (!empty($sources)) {
      $html .= '<aside class="mp-sources-wrapper">';
      foreach ($sources as $source) {
        if (!isset($source["url"]) || !isset($source["title"])) continue;

        $original_url = $source["url"];
        $real_url = $this->resolve_source_url($original_url);
        $host = $this->get_hostname($real_url);
        $favicon = "https://www.google.com/s2/favicons?domain=" . $host . "&sz=32";

        $html .= '<a class="mp-source-card" href="' . esc_url($original_url) . '" target="_blank" rel="noopener noreferrer">';
        $html .= '  <div class="mp-source-header">';
        $html .= '    <img src="' . esc_url($favicon) . '" class="mp-source-icon" alt="">';
        $html .= '    <span class="mp-source-site-name">' . esc_html($host) . "</span>";
        $html .= "  </div>";
        $html .= '  <div class="mp-source-title">' . esc_html($source["title"]) . "</div>";
        if (!empty($source["excerpt"])) {
          $html .= '  <div class="mp-source-excerpt">' . esc_html($source["excerpt"]) . "</div>";
        }
        $html .= "</a>";
      }
      $html .= "</aside>";
    }

    $html .= "</div>"; // Close Row

    // Related Questions
    if (!empty($related) && is_array($related)) {
      $html .=
        '<div class="mp-related-box">';
      $html .=
        '  <h4 class="mp-related-header">Related Questions</h4>';
      $html .=
        '  <div class="mp-related-list">';

      foreach ($related as $q) {
        $clean_q = $this->clean_lit_comments($q);
        $url = home_url('/chat/?q=' . urlencode($clean_q));

        $html .=
          '<a href="' .
          esc_url($url) .
          '" class="mp-related-link">';
        $html .=
          '  <span class="mp-related-arrow">→</span> ' .
          esc_html($clean_q);
        $html .= "</a>";
      }

      $html .= "  </div>";
      $html .= "</div>";
    }

    $html .= "</div>";

    return $html;
  }
}
