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
        "1.0.12", // try to bump these every time
      );
      wp_enqueue_script(
        "mp-qa-hover",
        plugins_url("../assets/js/mp-qa-hover.js", __FILE__),
        [],
        "1.0.12",
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

    $transcript = get_post_meta(get_the_ID(), "_raw_transcript", true);
    $related = get_post_meta(get_the_ID(), "_related_suggestions", true);
    $breakdown_data = get_post_meta(get_the_ID(), "_breakdown", true);
    // Removed unused $post_title

    if (!is_array($transcript)) {
      return $content;
    }

    // fix hierarchy and style sources

    $html = '<div id="mp-hover-card"></div>';

    $html .= '<div class="mp-qa-container">';
    // let's add the content as a json object so we can inspect it later
    $html .=
      '<script type="application/json" id="mp-qa-content">' .
      json_encode($transcript) .
      "</script>";

      // includes/class-content-template.php
    $index = 0;
    foreach ($transcript as $item) {
      $question = $this->clean_lit_comments($item["question"]);
      $answer = $this->clean_lit_comments($item["answer"]);
      $sources = isset($item["sources"]) ? $item["sources"] : [];

      $html .= '<div class="mp-qa-row">';

      // MAIN HEADER: The Question
      // Skip the first H2 because it duplicates the main H1 title
      if (is_singular('milepoint_qa') && $index > 0) {
        // ID for anchor linking (schema)
        $q_id = 'mp-q-' . $index;

        $html .=
          '  <h2 id="' . esc_attr($q_id) . '" class="mp-q">' .
         esc_html($question) .
          "</h2>";
      }

      // is there a way to skip this section in hub cards?
      if (!empty($breakdown_data) && is_singular('milepoint_qa')) {
        // Main container for the whole bar system
        $html .= '<div class="mp-attribution-wrapper">';

        foreach ($breakdown_data as $bd_index => $bd_item) {
          $color_index = $bd_index % 4; // Using modulus 4 to cycle through 4 color variants
          $raw_percentage = isset($bd_item["percentage"]) ? (float) $bd_item["percentage"] : 0.0;
          $clamped_percentage = max(0, min(100, $raw_percentage));
          $width = $clamped_percentage . "%";

          $html .=
            '<div class="mp-breakdown-segment" style="width: ' . esc_attr($width) . ';">';

          // 1. THE COLORED BAR PIECE
          $html .=
            '<div class="mp-breakdown-bar mp-bar-color-' . $color_index . '"></div>';

          // 2. THE LABEL
          $html .=
            '<div class="mp-breakdown-label">';
          $html .=
            '<strong class="mp-breakdown-percentage mp-text-color-' . $color_index . '">' .
            esc_html($bd_item["percentage"]) .
            "%</strong> ";
          $html .=
            '<span class="mp-breakdown-source">' .
            esc_html($bd_item["source"]) .
            "</span>";
          $html .= "</div>";

          $html .= "</div>"; // Close segment column
        }

        $html .= "</div>"; // Close Wrapper
      }

      // ANSWER BOX
      // ID for anchor linking (schema)
      $a_id = 'mp-a-' . $index;

      $html .=
        '  <div id="' . esc_attr($a_id) . '" class="mp-a">';
      $html .= $answer;
      $html .= "  </div>"; // Close Answer Box

      // Sources carousel
      // TODO: Rewrite to use captured favicon / source (no google favicon hack)
      if (!empty($sources)) {
        $html .= '<div class="mp-sources-wrapper">';
        foreach ($sources as $source) {
          // Defensive guard: Ensure required keys exist
          if (!isset($source["url"]) || !isset($source["title"])) {
            continue;
          }
          $original_url = $source["url"];
          // Resolve the real URL for display purposes (favicons, hostname)
          $real_url = $this->resolve_source_url($original_url);

          $host = $this->get_hostname($real_url);
          $favicon =
            "https://www.google.com/s2/favicons?domain=" . $host . "&sz=32";

          // Note: We still link to the original URL (which might be the redirect)
          // unless the requirement is to bypass the redirect link entirely.
          // Usually keeping the tracking link is preferred, but for display we want the real info.
          // If the user wants the link to be direct, we can change href to $real_url.
          // Assuming for now they just want the *display* fixed as per "where the source name should be... must always be showing the destination source"

          $html .=
            '<a class="mp-source-card" href="' .
            esc_url($original_url) .
            '" target="_blank" rel="noopener noreferrer">';

          // Header with Icon + Site Name
          $html .= '  <div class="mp-source-header">';
          $html .=
            '    <img src="' .
            esc_url($favicon) .
            '" class="mp-source-icon" alt="">';
          $html .=
            '    <span class="mp-source-site-name">' .
            esc_html($host) .
            "</span>";
          $html .= "  </div>";

          // Title
          $html .=
            '  <div class="mp-source-title">' .
            esc_html($source["title"]) .
            "</div>";

          // Excerpt
          if (!empty($source["excerpt"])) {
            $html .=
              '  <div class="mp-source-excerpt">' .
              esc_html($source["excerpt"]) .
              "</div>";
          }
          $html .= "</a>";
        }
        $html .= "</div>";
      }

      $html .= "</div>"; // Close Row
      $index++;
    }

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
