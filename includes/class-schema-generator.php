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

                $post_id = get_the_ID();
        $single_turn = get_post_meta($post_id, '_mp_single_turn_content', true);

        // Fallback for legacy posts that haven't been migrated
        if (empty($single_turn)) {
            $transcript = get_post_meta($post_id, '_raw_transcript', true);
            if (is_array($transcript) && !empty($transcript)) {
                $single_turn = $transcript[0];
            } else {
                return;
            }
        }

        // Setup common vars
        $url = get_permalink();
        $headline = get_the_title();
        $datePublished = get_the_date('c');
        $dateModified = get_the_modified_date('c');

        // Safe excerpt stripping
        $description = get_the_excerpt();
        if (!empty($description)) {
            $description = html_entity_decode(wp_strip_all_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Fallback description if excerpt is empty
        if (empty($description) && !empty($single_turn['answer'])) {
            $description = wp_trim_words($this->to_plain_text($single_turn['answer']), 20);
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
            $imageUrl = get_the_post_thumbnail_url($post_id, 'full');
        }

        // --- Main Entity: First Question ---
        $mainQuestionText = !empty($single_turn['question'])
            ? $this->to_plain_text($single_turn['question'])
            : '';

        // --- Comments: Answers and Subsequent Questions ---
        $comments = [];

        // First Answer (AI)
        if (!empty($single_turn['answer'])) {
            $comments[] = [
                '@type' => 'Comment',
                '@id' => $url . '#comment-a-0',
                'name' => 'Answer 1',
                'text' => $this->to_plain_text($single_turn['answer']),
                'datePublished' => $datePublished,
                'url' => $url . '#mp-a-0',
                'author' => [
                    '@type' => 'Person',
                    '@id' => $url . '#ai-assistant',
                    'name' => 'AI Assistant'
                ]
            ];
        }

        $interactionCount = count($comments);

        // --- Assemble Schema Graph ---
        $graph = [];

        // 1. Publisher Logo Node (Standalone ImageObject)
        $logo_id = home_url('/#logo');
        if (!empty($publisherLogoUrl)) {
            $graph[] = [
                '@type' => 'ImageObject',
                '@id'   => $logo_id,
                'url'   => $publisherLogoUrl,
                'inLanguage' => get_bloginfo('language')
            ];
        }

        // 2. Publisher Node (Organization)
        $publisher_id = home_url('/#organization');
        $publisher_node = [
            '@type' => 'Organization',
            '@id'   => $publisher_id,
            'name'  => $publisherName,
            'url'   => home_url('/')
        ];
        if (!empty($publisherLogoUrl)) {
            $publisher_node['logo'] = ['@id' => $logo_id]; // Pointer!
        }
        $graph[] = $publisher_node;

        // 3. WebSite Node
        $website_id = home_url('/#website');
        $graph[] = [
            '@type' => 'WebSite',
            '@id'   => $website_id,
            'url'   => home_url('/'),
            'name'  => $publisherName,
            'publisher' => ['@id' => $publisher_id],
            'inLanguage' => get_bloginfo('language'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => home_url('/?s={search_term_string}'),
                'query-input' => 'required name=search_term_string'
            ]
        ];

        // 4. Questions Archive Node
        $archive_url = get_post_type_archive_link('milepoint_qa');
        $archive_id = $archive_url . '#collection';
        $graph[] = [
            '@type' => 'CollectionPage',
            '@id'   => $archive_id,
            'url'   => $archive_url,
            'name'  => 'Questions Archive',
            'isPartOf' => ['@id' => $website_id]
        ];

        // 5. BreadcrumbList Node
        $breadcrumb_id = $url . '#breadcrumb';
        $graph[] = [
            '@type' => 'BreadcrumbList',
            '@id'   => $breadcrumb_id,
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'item' => [
                        '@id' => $website_id,
                        'name' => 'Home'
                    ]
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'item' => [
                        '@id' => $archive_id,
                        'name' => 'Questions'
                    ]
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'item' => [
                        '@id' => $url,
                        'name' => $headline
                    ]
                ]
            ]
        ];

        // 6. Main DiscussionForumPosting ID
        $posting_id = $url . '#posting';

        // 5. WebPage Node
        $webpage_id = $url;
        $graph[] = [
            '@type' => 'WebPage',
            '@id'   => $webpage_id,
            'url'   => $url,
            'name'  => $headline,
            'isPartOf' => ['@id' => $website_id],
            'breadcrumb' => ['@id' => $breadcrumb_id],
            'mainEntity' => ['@id' => $posting_id],
            'datePublished' => $datePublished,
            'dateModified' => $dateModified
        ];

        // 6. Author Node
        $author_id = $url . '#author';
        $graph[] = [
            '@type' => 'Person',
            '@id'   => $author_id,
            'name'  => $authorName
        ];

        // 7. AI Assistant Node (Conditional)
        $ai_id = $url . '#ai-assistant';
        $has_ai_comment = false;
        foreach ($comments as $comment_data) {
            if ($comment_data['author']['name'] === 'AI Assistant') {
                $has_ai_comment = true;
                break;
            }
        }
        if ($has_ai_comment) {
            $graph[] = [
                '@type' => 'Person',
                '@id'   => $ai_id,
                'name'  => 'AI Assistant'
            ];
        }

        // 8. Comment Nodes
        $comment_refs = [];
        foreach ($comments as $comment_data) {
            $comment_author_ref = ($comment_data['author']['name'] === 'AI Assistant') ? $ai_id : $author_id;

            $graph[] = [
                '@type'         => 'Comment',
                '@id'           => $comment_data['@id'],
                'name'          => $comment_data['name'],
                'text'          => $comment_data['text'],
                'datePublished' => $comment_data['datePublished'],
                'url'           => $comment_data['url'],
                'author'        => ['@id' => $comment_author_ref]
            ];

            $comment_refs[] = ['@id' => $comment_data['@id']];
        }

        // 9. ImageObject Node
        $image_ref = null;
        if (!empty($imageUrl)) {
            $image_id = $url . '#primaryimage';
            $graph[] = [
                '@type'      => 'ImageObject',
                '@id'        => $image_id,
                'inLanguage' => get_bloginfo('language'),
                'url'        => $imageUrl
            ];
            $image_ref = ['@id' => $image_id];
        }

        // 10. Main DiscussionForumPosting Node
        $posting_node = [
            '@type' => 'DiscussionForumPosting',
            '@id'   => $posting_id,
            'headline' => $headline,
            'text' => $mainQuestionText,
            'datePublished' => $datePublished,
            'dateModified' => $dateModified,
            'author' => ['@id' => $author_id],
            'publisher' => ['@id' => $publisher_id],
            'mainEntityOfPage' => ['@id' => $webpage_id],
            'interactionStatistic' => [
                '@type' => 'InteractionCounter',
                'interactionType' => 'https://schema.org/CommentAction',
                'userInteractionCount' => $interactionCount
            ],
            'comment' => $comment_refs
        ];

        if (!empty($description)) {
            $posting_node['description'] = $description;
        }
        if ($image_ref) {
            $posting_node['image'] = $image_ref;
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
