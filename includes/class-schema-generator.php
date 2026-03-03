<?php
// includes/class-schema-generator.php

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Handles JSON-LD schema generation for MilePoint QA posts.
 */
class MP_Schema_Generator
{
    public function __construct()
    {
        add_action('wp_head', [$this, 'inject_schema']);
    }

    /**
     * Cleans HTML comments from strings (e.g., from lit-html templates).
     */
    private function clean_lit_comments($string)
    {
        if (empty($string)) {
            return "";
        }
        return preg_replace("/<!--(.*?)-->/s", "", $string);
    }

    /**
     * Cleans comments, strips tags, and decodes entities for schema text.
     */
    private function to_plain_text($string)
    {
        $clean = $this->clean_lit_comments($string);
        $stripped = wp_strip_all_tags($clean);
        return html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Injects DiscussionForumPosting schema into the head of singular QA posts.
     */
    public function inject_schema()
    {
        if (!is_singular('milepoint_qa')) {
            return;
        }

        $transcript = get_post_meta(get_the_ID(), "_raw_transcript", true);
        if (empty($transcript) || !is_array($transcript)) {
            return;
        }

        // --- Data Extraction ---
        $headline = html_entity_decode(get_the_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $datePublished = get_the_date('c'); // ISO 8601
        $dateModified = get_the_modified_date('c'); // ISO 8601
        $url = get_permalink();

        $description = get_the_excerpt();
        if (!empty($description)) {
             $description = html_entity_decode(wp_strip_all_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Fallback description if excerpt is empty
        if (empty($description) && !empty($transcript[0]['answer'])) {
            $description = wp_trim_words($this->to_plain_text($transcript[0]['answer']), 20);
        }

        $authorName = "Guest";
        $publisherName = get_bloginfo('name');
        $publisherLogoUrl = "";

        // Try to get custom logo
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_data) {
                $publisherLogoUrl = $logo_data[0];
            }
        }

        $imageUrl = "";
        if (has_post_thumbnail()) {
            $imageUrl = get_the_post_thumbnail_url(get_the_ID(), 'full');
        }

        // --- Main Entity: First Question ---
        $firstItem = $transcript[0];
        $mainQuestionText = !empty($firstItem['question'])
            ? $this->to_plain_text($firstItem['question'])
            : '';

        // --- Comments: Answers and Subsequent Questions ---
        $comments = [];

        // First Answer (AI)
        if (!empty($firstItem['answer'])) {
            $comments[] = [
                '@type' => 'Comment',
                '@id' => $url . '#comment-a-0',
                'name' => 'Answer 1',
                'text' => $this->to_plain_text($firstItem['answer']),
                'datePublished' => $datePublished,
                'url' => $url . '#mp-a-0',
                'author' => [
                    '@type' => 'Person',
                    '@id' => $url . '#ai-assistant',
                    'name' => 'AI Assistant'
                ]
            ];
        }

        // Subsequent items
        $total_items = count($transcript);
        for ($i = 1; $i < $total_items; $i++) {
            $item = $transcript[$i];

            // Question (User)
            if (!empty($item['question'])) {
                $comments[] = [
                    '@type' => 'Comment',
                    '@id' => $url . '#comment-q-' . $i,
                    'name' => 'Follow-up Question ' . $i,
                    'text' => $this->to_plain_text($item['question']),
                    'datePublished' => $datePublished,
                    'url' => $url . '#mp-q-' . $i,
                    'author' => [
                        '@type' => 'Person',
                        '@id' => $url . '#author',
                        'name' => 'Guest'
                    ]
                ];
            }

            // Answer (AI)
            if (!empty($item['answer'])) {
                $comments[] = [
                    '@type' => 'Comment',
                    '@id' => $url . '#comment-a-' . $i,
                    'name' => 'Answer ' . ($i + 1),
                    'text' => $this->to_plain_text($item['answer']),
                    'datePublished' => $datePublished,
                    'url' => $url . '#mp-a-' . $i,
                    'author' => [
                        '@type' => 'Person',
                        '@id' => $url . '#ai-assistant',
                        'name' => 'AI Assistant'
                    ]
                ];
            }
        }

        $interactionCount = count($comments);

        // --- Assemble Schema Graph ---
        $graph = [];

        // 1. Publisher Node
        $publisher_id = home_url('/#organization');
        $publisher_node = [
            '@type' => 'Organization',
            '@id'   => $publisher_id,
            'name'  => $publisherName,
            'url'   => home_url('/')
        ];
        if (!empty($publisherLogoUrl)) {
            $publisher_node['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $publisherLogoUrl
            ];
        }
        $graph[] = $publisher_node;

        // 2. Author Node
        $author_id = $url . '#author';
        $graph[] = [
            '@type' => 'Person',
            '@id'   => $author_id,
            'name'  => $authorName
        ];

        // 3. AI Assistant Node (only if AI answers exist, handled universally)
        $ai_id = $url . '#ai-assistant';
        $graph[] = [
            '@type' => 'Person',
            '@id'   => $ai_id,
            'name'  => 'AI Assistant'
        ];

        // 4. Comment Nodes
        $comment_refs = [];
        foreach ($comments as $comment_data) {
            // Determine the correct author reference for this comment
            $comment_author_ref = ($comment_data['author']['name'] === 'AI Assistant') ? $ai_id : $author_id;

            // Add the standalone comment node to the graph
            $graph[] = [
                '@type'         => 'Comment',
                '@id'           => $comment_data['@id'],
                'name'          => $comment_data['name'],
                'text'          => $comment_data['text'],
                'datePublished' => $comment_data['datePublished'],
                'url'           => $comment_data['url'],
                'author'        => ['@id' => $comment_author_ref] // Pointer!
            ];

            // Save the pointer for the main DiscussionForumPosting
            $comment_refs[] = ['@id' => $comment_data['@id']];
        }

        // 5. Main DiscussionForumPosting Node
        $posting_node = [
            '@type' => 'DiscussionForumPosting',
            '@id'   => $url,
            'headline' => $headline,
            'text' => $mainQuestionText,
            'datePublished' => $datePublished,
            'dateModified' => $dateModified,
            'author' => ['@id' => $author_id], // Pointer!
            'publisher' => ['@id' => $publisher_id], // Pointer!
            'mainEntityOfPage' => $url,
            'interactionStatistic' => [
                '@type' => 'InteractionCounter',
                'interactionType' => 'https://schema.org/CommentAction',
                'userInteractionCount' => $interactionCount
            ],
            'comment' => $comment_refs // Array of Pointers!
        ];

        if (!empty($description)) {
            $posting_node['description'] = $description;
        }
        if (!empty($imageUrl)) {
            $posting_node['image'] = [$imageUrl];
        }

        $graph[] = $posting_node;

        // Final Output
        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph
        ];

        // Output Schema
        echo '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
    }
}
