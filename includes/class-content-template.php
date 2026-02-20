<?php
// includes/class-content-template.php
if (!defined("ABSPATH")) {
  exit();
}

class MP_Content_Template
{
  public function __construct()
  {
    add_filter("the_content", [$this, "render_qa_view"]);
    add_action("wp_enqueue_scripts", [$this, "enqueue_assets"]);
  }

  public function enqueue_assets()
  {
    // Only load these files on the single Q&A post pages
    if (is_singular("milepoint_qa")) {
      wp_enqueue_style(
        "mp-qa-style",
        plugins_url("../assets/css/mp-qa.css", __FILE__),
        [],
        "1.0.4",
      );
      wp_enqueue_script(
        "mp-qa-hover",
        plugins_url("../assets/js/mp-qa-hover.js", __FILE__),
        [],
        "1.0.4",
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
    // Removed unused $post_title

    if (!is_array($transcript)) {
      return $content;
    }

    // fix hierarchy and style sources

    $html = '<div id="mp-hover-card">Testing 123</div>';

    $html .=
      '<div class="mp-qa-container" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;">';
    // let's add the content as a json object so we can inspect it later
    $html .=
      '<script type="application/json" id="mp-qa-content">' .
      json_encode($transcript) .
      "</script>";

    foreach ($transcript as $item) {
      $question = $this->clean_lit_comments($item["question"]);
      $answer = $this->clean_lit_comments($item["answer"]);
      $sources = isset($item["sources"]) ? $item["sources"] : [];

      $html .= '<div class="mp-qa-row" style="margin-bottom: 60px;">';

      // MAIN HEADER: The Question
      $html .=
        '  <h2 class="mp-q" style="color: #00457c; font-size: 2.1rem; font-weight: 800; margin: 0 0 20px 0; line-height: 1.2; letter-spacing: -0.03em;">' .
        $question .
        "</h2>";

      // ANSWER BOX
      $html .=
        '  <div class="mp-a" style="border-left: 4px solid #0073aa; padding: 0 0 0 30px; margin-left: 2px; color: #444; line-height: 1.8; font-size: 1.15rem;">';
      $html .= $answer;
      $html .= "  </div>"; // Close Answer Box

      // Sources list
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

    // Force load the comments template if comments are open
    if ((comments_open() || get_comments_number()) && post_type_supports(get_post_type(), 'comments')) {
      ob_start();
      comments_template();
      $html .= ob_get_clean();
    }

    $html .= "</div>";

    return $html;
  }
}
