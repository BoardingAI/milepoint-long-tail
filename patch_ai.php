<?php
$file = 'includes/class-ai-handler.php';
$content = file_get_contents($file);

$ai_method = <<<'CODE'

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
1. Classify the follow-up question into ONE of three buckets: 'ready_as_is', 'needs_rewrite_review', or 'hold'.
   - 'ready_as_is': The question stands alone cleanly without needing prior context.
   - 'needs_rewrite_review': The question is valuable but needs prior context injected to stand alone.
   - 'hold': The question is too vague, conversational, duplicate, or requires guessing.
2. If 'needs_rewrite_review', provide a 'rewritten_question' that preserves intent but stands alone. Also provide a 'rewritten_answer' that is faithful to the original but makes sense with the rewritten question.
3. Return ONLY a JSON object with keys:
   - classification (string)
   - reason (string)
   - confidence (string: high/medium/low)
   - rewritten_question (string, only if needs_rewrite_review, otherwise empty string)
   - rewritten_answer (string, only if needs_rewrite_review, otherwise empty string)";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
            'body'    => wp_json_encode([
                'model' => 'gpt-4o-mini',
                'temperature' => 0.4,
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
CODE;

$content = preg_replace('/}\s*$/', $ai_method, $content);
file_put_contents($file, $content);

$file2 = 'includes/class-rest-handler.php';
$content2 = file_get_contents($file2);

// Remove get_ai_classification from REST Handler
$content2 = preg_replace('/private function get_ai_classification.*?return \$data;\n\s*}\n/s', '', $content2);

// Use MP_AI_Handler instead
$content2 = str_replace(
    '$ai_res = $this->get_ai_classification($api_key, $first_question_text, $prior_context, wp_strip_all_tags($q_text), wp_strip_all_tags($a_text));',
    '$ai_handler = new MP_AI_Handler();
           $ai_res = $ai_handler->get_followup_classification($api_key, $first_question_text, $prior_context, wp_strip_all_tags($q_text), wp_strip_all_tags($a_text));',
    $content2
);

file_put_contents($file2, $content2);
echo "Refactored OpenAI integration.\n";
