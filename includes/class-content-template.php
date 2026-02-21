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
        "1.0.5", // try to bump these every time
      );
      wp_enqueue_script(
        "mp-qa-hover",
        plugins_url("../assets/js/mp-qa-hover.js", __FILE__),
        [],
        "1.0.5",
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

    $html .=
      '<div class="mp-qa-container" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;">';
    // let's add the content as a json object so we can inspect it later
    $html .=
      '<script type="application/json" id="mp-qa-content">' .
      json_encode($transcript) .
      "</script>";

      // includes/class-content-template.php
    foreach ($transcript as $item) {
      $question = $this->clean_lit_comments($item["question"]);
      $answer = $this->clean_lit_comments($item["answer"]);
      $sources = isset($item["sources"]) ? $item["sources"] : [];

      $html .= '<div class="mp-qa-row" style="margin-bottom: 60px;">';

      // MAIN HEADER: The Question
      if (is_singular('milepoint_qa')) {

        $html .=
          '  <h2 class="mp-q" style="color: #00457c; font-size: 2.1rem; font-weight: 800; margin: 0 0 20px 0; line-height: 1.2; letter-spacing: -0.03em;">' .
         $question .
          "</h2>";
      }

      // is there a way to skip this section in hub cards?
      if (!empty($breakdown_data) && is_singular('milepoint_qa')) {
        // Main container for the whole bar system
        $html .=
          '<div class="mp-attribution-wrapper" style="margin: 20px 0 30px 0; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif; display: flex; width: 100%;">';

        $colors = ["#00457c", "#0073aa", "#22a1c4", "#a5a5a5"];
        $total_items = count($breakdown_data);

        foreach ($breakdown_data as $index => $item) {
          $color = $colors[$index % count($colors)];
          $width = $item["percentage"] . "%";
          $isFirst = $index === 0;
          $isLast = $index === $total_items - 1;

          // Each segment is a vertical flexbox (Bar on top, Label on bottom)
          // margin-right creates the white gap between segments
          $marginRight = $isLast ? "0" : "3px";

          $html .=
            '<div style="width: ' .
            $width .
            "; margin-right: " .
            $marginRight .
            '; display: flex; flex-direction: column;">';

          // 1. THE COLORED BAR PIECE
          $borderRadius = "";
          if ($isFirst) {
            $borderRadius .=
              "border-top-left-radius: 6px; border-bottom-left-radius: 6px; ";
          }
          if ($isLast) {
            $borderRadius .=
              "border-top-right-radius: 6px; border-bottom-right-radius: 6px; ";
          }

          $html .=
            '<div style="height: 10px; background-color: ' .
            $color .
            "; " .
            $borderRadius .
            ' margin-bottom: 8px;"></div>';

          // 2. THE LABEL (Now perfectly aligned to the start of the bar segment)
          $html .=
            '<div style="font-size: 0.85rem; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 5px;">';
          $html .=
            '<strong style="color: ' .
            $color .
            '; font-weight: 700;">' .
            $item["percentage"] .
            "%</strong> ";
          $html .=
            '<span style="color: #777;">' .
            htmlspecialchars($item["source"]) .
            "</span>";
          $html .= "</div>";

          $html .= "</div>"; // Close segment column
        }

        $html .= "</div>"; // Close Wrapper
      }

      // ANSWER BOX
      $html .=
        '  <div class="mp-a" style="border-left: 4px solid #0073aa; padding: 0 0 0 30px; margin-left: 2px; color: #444; line-height: 1.8; font-size: 1.15rem;">';
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
    }

    // Related Questions
    if (!empty($related) && is_array($related)) {
      $html .=
        '<div class="mp-related-box" style="margin-top: 80px; padding: 35px; background: #fdfdfd; border: 1px solid #eee; border-radius: 12px;">';
      $html .=
        '  <h4 style="margin: 0 0 25px 0; font-size: 0.95rem; color: #888; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em;">Related Questions</h4>';
      $html .=
        '  <div class="mp-related-list" style="display: flex; flex-direction: column; gap: 12px;">';

      foreach ($related as $q) {
        $clean_q = $this->clean_lit_comments($q);
        $html .=
          '<div style="color: #0073aa; font-size: 1.1rem; padding: 16px 20px; background: #fff; border: 1px solid #f0f0f0; border-radius: 8px;">';
        $html .=
          '  <span style="margin-right: 12px; color: #0073aa; opacity: 0.4; font-weight: bold;">→</span> ' .
          esc_html($clean_q);
        $html .= "</div>";
      }

      $html .= "  </div>";
      $html .= "</div>";
    }

    $html .= "</div>";

    return $html;
  }
}
