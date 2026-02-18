<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class MP_Content_Template {

    public function __construct() {
        add_filter( 'the_content', array( $this, 'render_qa_view' ) );
    }

    private function clean_lit_comments( $string ) {
        if ( empty( $string ) ) return '';
        // Strips any remaining HTML comments
        return preg_replace('/<!--(.*?)-->/s', '', $string);
    }

    public function render_qa_view( $content ) {
        if ( get_post_type() !== 'milepoint_qa' || ! is_main_query() ) {
            return $content;
        }

        $transcript = get_post_meta( get_the_ID(), '_raw_transcript', true );
        $related    = get_post_meta( get_the_ID(), '_related_suggestions', true );
        $post_title = get_the_title();

        if ( ! is_array( $transcript ) ) {
            return $content;
        }

        // fix hierarchy and style sources
        $html = '<style>
            .single-milepoint_qa .entry-title { display: none !important; }
            .mp-qa-container { margin-top: 50px; }
            .mp-a h1, .mp-a h2, .mp-a h3, .mp-a h4 {
                font-size: 1.35rem !important;
                margin: 30px 0 15px 0 !important;
                color: #111 !important;
                font-weight: 700 !important;
            }
            .mp-qa-row:first-child .mp-q { margin-top: 0; }

            .mp-source-card {
                margin-top: 20px;
                padding: 15px 20px;
                background: #fdfdfd;
                border-left: 3px solid #ddd;
                font-size: 0.95rem;
            }
            .mp-source-title {
                display: block;
                font-weight: bold;
                color: #0073aa;
                text-decoration: none;
                margin-bottom: 5px;
            }
            .mp-source-excerpt {
                color: #666;
                line-height: 1.5;
                font-style: italic;
            }
        </style>';

        $html .= '<div class="mp-qa-container" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif;">';

        foreach ( $transcript as $item ) {
            $question = $this->clean_lit_comments( $item['question'] );
            $answer   = $this->clean_lit_comments( $item['answer'] );
            $sources  = isset($item['sources']) ? $item['sources'] : array();

            $html .= '<div class="mp-qa-row" style="margin-bottom: 60px;">';


            // MAIN HEADER: The Question
            $html .= '  <h2 class="mp-q" style="color: #00457c; font-size: 2.1rem; font-weight: 800; margin: 0 0 20px 0; line-height: 1.2; letter-spacing: -0.03em;">' . $question . '</h2>';

            // ANSWER BOX
            $html .= '  <div class="mp-a" style="border-left: 4px solid #0073aa; padding: 0 0 0 30px; margin-left: 2px; color: #444; line-height: 1.8; font-size: 1.15rem;">';
            $html .=      $answer;

            // ???
            // $html .= 'sources: ' . count($sources)  ;

            // Sources list
            if ( ! empty( $sources ) ) {
                $html .= '<div class="mp-sources-wrapper" style="margin-top: 30px;">';
                foreach ( $sources as $source ) {
                    $html .= '<div class="mp-source-card">';
                    $html .= '  <a class="mp-source-title" href="' . esc_url($source['url']) . '" target="_blank">' . esc_html($source['title']) . '</a>';
                    if ( ! empty( $source['excerpt'] ) ) {
                        $html .= '  <div class="mp-source-excerpt">"' . esc_html($source['excerpt']) . '..."</div>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }

            $html .= '  </div>';
            $html .= '</div>';
        }

        // Related Questions
        if ( ! empty( $related ) && is_array( $related ) ) {
            $html .= '<div class="mp-related-box" style="margin-top: 80px; padding: 35px; background: #fdfdfd; border: 1px solid #eee; border-radius: 12px;">';
            $html .= '  <h4 style="margin: 0 0 25px 0; font-size: 0.95rem; color: #888; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em;">Related Questions</h4>';
            $html .= '  <div class="mp-related-list" style="display: flex; flex-direction: column; gap: 12px;">';

            foreach ( $related as $q ) {
                $clean_q = $this->clean_lit_comments($q);
                $html .= '<div style="color: #0073aa; font-size: 1.1rem; padding: 16px 20px; background: #fff; border: 1px solid #f0f0f0; border-radius: 8px;">';
                $html .= '  <span style="margin-right: 12px; color: #0073aa; opacity: 0.4; font-weight: bold;">→</span> ' . $clean_q;
                $html .= '</div>';
            }

            $html .= '  </div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}