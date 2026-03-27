<?php
// includes/class-ai-handler.php
if (!defined('ABSPATH')) exit;

class MP_AI_Handler
{

  public function __construct()
  {
    // Trigger only when our specific post type is published
    add_action('publish_milepoint_qa', [$this, 'categorize_on_publish'], 10, 2);
  }

  public function categorize_on_publish($ID, $post)
  {
    // 1. Check if already processed to avoid infinite loops/double billing
    if (get_post_meta($ID, '_mp_ai_processed', true)) return;

    $api_key = get_option('mp_openai_api_key');
    if (empty($api_key)) return;

    // 2. Prepare content for AI
    $title = $post->post_title;
    $transcript = get_post_meta($ID, '_raw_transcript', true);
    $breakdown = get_post_meta($ID, "_breakdown", true); // maybe tag with these??

    $sample_content = (is_array($transcript) && isset($transcript[0]['answer'])) ? $transcript[0]['answer'] : '';

    // 3. Call OpenAI
    $response = $this->get_ai_suggestions($api_key, $title, $sample_content);

    // 1. Handle Transport/API Errors (Do NOT mark as processed, allow retry)
    if ($response === false) {
        update_post_meta($ID, '_mp_ai_last_error', 'openai_request_failed_or_timeout');
        return;
    }

    /**
     * XDEBUG BREAKPOINT:
     * Set a breakpoint on the line below ($data) to inspect the AI response
     */
    // 2. Handle Parsing Errors / Genuinely Empty Data (Mark as processed, do not retry)
    $data = json_decode($response, true);
    if (!is_array($data)) {
        update_post_meta($ID, '_mp_ai_processed', 'no_data');
        return;
    }

    // 1. Process Categories
    if (!empty($data['categories']) && is_array($data['categories'])) {
        if (! function_exists('wp_create_category')) {
            require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
        }

        $cat_ids = [];
        // Limit to 3 items max
        foreach (array_slice($data['categories'], 0, 3) as $cat_name) {
            // Protect against AI returning nested arrays or objects
            if (!is_scalar($cat_name)) {
                continue;
            }

            $clean_name = sanitize_text_field(trim((string) $cat_name));

            if (!empty($clean_name)) {
                $cat_id = wp_create_category($clean_name);
                if (!is_wp_error($cat_id)) {
                    $cat_ids[] = (int)$cat_id;
                }
            }
        }

        if (!empty($cat_ids)) {
            // Deduplicate IDs just in case the AI repeated itself
            wp_set_post_categories($ID, array_unique($cat_ids));
        }
    }

    // 2. Process Tags
    if (!empty($data['tags']) && is_array($data['tags'])) {
        $clean_tags = [];

        // Limit to 5 items max
        foreach (array_slice($data['tags'], 0, 5) as $tag_name) {
            if (!is_scalar($tag_name)) {
                continue;
            }

            $tag = sanitize_text_field(trim((string) $tag_name));
            if ($tag !== '') {
                $clean_tags[] = $tag;
            }
        }

        if (!empty($clean_tags)) {
            // Deduplicate the tags array
            wp_set_post_tags($ID, array_values(array_unique($clean_tags)));
        }
    }

    // 3. Mark as processed
    update_post_meta($ID, '_mp_ai_processed', true);
  }

  private function get_ai_suggestions($api_key, $title, $content)
    {
        // 1. Fetch existing taxonomy to nudge the AI
        $existing_cats = array_slice((array) get_mp_terms_with_counts('category', false), 0, 200);
        $existing_tags = array_slice((array) get_mp_terms_with_counts('post_tag', false), 0, 300);

        // Convert to comma-separated strings
        $cat_list = !empty($existing_cats) ? implode(', ', array_column($existing_cats, 'name')) : 'None yet';
        $tag_list = !empty($existing_tags) ? implode(', ', array_column($existing_tags, 'name')) : 'None yet';

        // 2. Updated prompt with "Prefer existing" instructions
        $prompt = "You are a travel rewards expert. Categorize this Q&A into 1 to 3 categories and up to 5 tags.

Title: {$title}
Content Sample: {$content}

---
EXISTING CATEGORIES: {$cat_list}
EXISTING TAGS: {$tag_list}

INSTRUCTIONS:
1. Evaluate the EXISTING CATEGORIES. You may use broad existing categories (like 'Travel'), but you MUST also create specific NEW categories (like 'Cruises' or 'Credit Cards') if the topic warrants it.
2. Favor EXISTING TAGS where applicable to maintain taxonomy consistency.
3. Return ONLY a JSON object with keys 'categories' (array of strings, max 3) and 'tags' (array of strings).";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
            'body'    => json_encode([
                'model' => 'gpt-4o-mini',
                'temperature' => 0.7,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object']
            ])
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['choices'][0]['message']['content'])) {
            return false;
        }
        return $body['choices'][0]['message']['content'];
    }

    public function get_followup_classification($api_key, $first_question, $prior_context, $current_question, $current_answer) {
                $prompt = "You are an expert editorial assistant.
Your task is to evaluate a follow-up question in a Q&A session.

FIRST QUESTION:
{$first_question}

PRIOR SESSION CONTEXT:
{$prior_context}

FOLLOW-UP QUESTION TO EVALUATE:
{$current_question}

AI ANSWER TO FOLLOW-UP:
{$current_answer}

INSTRUCTIONS:
1. Classify the follow-up question into ONE of three buckets: 'ready_as_is', 'context_added', or 'hold'.

   CRITICAL CLASSIFICATION RULE:
   A follow-up is ONLY 'ready_as_is' if it already works as a fully standalone public question with NO missing referent, NO unresolved dependency on prior turns, and NO need for additional session context to understand what is being asked. If a normal reader landing directly on that question page would have to guess what the user meant, it must be 'context_added'.

   STRICTLY CLASSIFY AS 'context_added' IF the question contains:
   - Unresolved pronouns or referents (e.g., it, they, that, this, these, those, there, here, them, one, ones).
   - Shorthand, elliptical phrasing, or comparisons that do not restate both sides clearly.
   - Implied subjects, locations, or timeframes carried over from earlier turns.
   - \"What about...\", \"How about...\", \"Which one...\", \"Is that better...\", \"What is the best one...\" where the object is not fully restated.

   EXAMPLES OF 'context_added':
   - \"What about shopping there?\"
   - \"Is that one better?\"
   - \"How does that compare?\"
   - \"What about lounges?\"
   - \"Would that still be worth it?\"
   - \"What about for business class travelers?\"
   - \"Is it good for families?\"
   - \"How about in Tokyo instead?\"
   - \"Which one is cheapest?\"
   - \"What is the best one there?\"

   EXAMPLES OF 'ready_as_is':
   - \"Which Singapore hotels are best for shopping on Orchard Road?\"
   - \"What are the best lounges in Doha for business class travelers?\"
   - \"Which Hyatt properties in Tokyo offer the best value on points?\"
   - \"What is the best credit card for frequent international travelers?\"

   - 'hold': The question is too vague, purely conversational filler, duplicate, or requires completely blind guessing.

2. If 'context_added', provide a 'rewritten_question' that preserves intent but stands alone. DO NOT rewrite the answer.

   CRITICAL REWRITE RULES FOR 'context_added':
   1. ANSWER-GROUNDED REWRITE: The rewritten question MUST accurately fit the preserved AI ANSWER TO FOLLOW-UP. The answer is your semantic anchor. Do not introduce a framing that the answer does not actually support.
   2. NO SEMANTIC DRIFT: Do not broaden a narrow answer into a broader question. Do not inject \"besides,\" \"other options,\" \"alternatives,\" \"compare,\" \"best,\" or similar framing unless the preserved answer materially supports that framing.
   3. EXPLICIT NAMING: If the follow-up refers to a specific object from context (\"that one\", \"that lounge\", \"that hotel\") and the preserved answer clearly resolves what that object is, the rewrite MUST explicitly name that object and ask about the actual topic the answer covers.
   4. PUBLISHABLE QUESTION QUALITY: The rewrite must sound like a clean, strong standalone public-facing question someone would plausibly type into a search engine. It must not sound robotic, filler-heavy, or mechanically reconstructed from pronouns. Keep it concise; do not over-engineer it into an awkward long title.
   5. PRESERVE LIKELY USER INTENT: Use the first question, prior context, and preserved answer together to infer the user's true intent. Stay conservative. Prefer the narrowest accurate rewrite that matches the answer's actual scope.
   6. FALLBACK TO HOLD: If you cannot produce a standalone rewritten question that both reads naturally and accurately matches the scope of the preserved answer without semantic drift, you MUST classify the follow-up as 'hold' instead.

   EXAMPLES OF REWRITES:

   Example 1
   First question: \"What's the best lounge in Doha for a long layover?\"
   Follow-up: \"ok but what about that one\"
   Preserved answer focus: amenities and suitability of the Al Safwa lounge for long layovers
   Bad rewrite: \"What are the other lounge options in Doha besides the Al Safwa Lounge?\"
   Why bad: this broadens the question into alternatives when the answer is still centered on Al Safwa itself.
   Better rewrite: \"What amenities does the Al Safwa First Class Lounge offer for long layovers in Doha?\"

   Example 2
   First question: \"What's a good place to stay in Singapore?\"
   Follow-up: \"What about shopping there?\"
   Preserved answer focus: shopping-friendly areas / neighborhoods
   Bad rewrite: \"What about shopping in Singapore?\"
   Why bad: too close to the original phrasing, still weak and not very publishable.
   Better rewrite: \"Which areas in Singapore are best for shopping?\"

   Example 3
   First question: \"What's the best Hyatt in Tokyo on points?\"
   Follow-up: \"What about for families?\"
   Preserved answer focus: family-suitable Hyatt properties in Tokyo
   Bad rewrite: \"What about Hyatt in Tokyo for families?\"
   Better rewrite: \"Which Hyatt properties in Tokyo are best for families using points?\"

   Example 4
   First question: \"Is the Amex Platinum worth it for airport lounge access?\"
   Follow-up: \"What about if I mostly fly domestic?\"
   Preserved answer focus: whether the Amex Platinum still makes sense for mostly domestic travelers
   Bad rewrite: \"What about if I mostly fly domestic with the Amex Platinum?\"
   Better rewrite: \"Is the Amex Platinum still worth it for travelers who mostly fly domestic routes?\"

3. Return ONLY a JSON object with keys:
   - classification (string)
   - reason (string)
   - confidence (string: high/medium/low)
   - rewritten_question (string, only if context_added, otherwise empty string)";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
            'body'    => wp_json_encode([
                'model' => 'gpt-4o-mini',
                'temperature' => 0.3,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object']
            ])
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['choices'][0]['message']['content'])) {
            return false;
        }

        $data = json_decode($body['choices'][0]['message']['content'], true);
        if (!is_array($data)) {
            return false;
        }

        return $data;
    }
}