<?php
// Mock WP core functions to test handle_ingest
define('ABSPATH', true);
define('DAY_IN_SECONDS', 86400);

function sanitize_text_field($str) { return strip_tags($str); }
function esc_url_raw($str) { return $str; }
function wp_kses_post($str) { return strip_tags($str, '<p><br><a><strong><em><ul><li><ol><blockquote>'); }
function current_time($type, $gmt) { return time(); }
function get_date_from_gmt($date) { return $date; }
function wp_strip_all_tags($str) { return strip_tags($str); }
function get_posts($args) { return [ (object)['ID' => 123] ]; } // Simulate existing post
function wp_insert_post($args) { return 123; }
function wp_update_post($args) {
    echo "wp_update_post called with post_status: " . $args['post_status'] . "\n";
    return 1;
}
function get_post_status($id) { return 'future'; }
function update_post_meta($id, $key, $val) { }
function has_post_thumbnail($id) { return true; } // skip image scrape
function is_wp_error($thing) { return false; }

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct($data, $status) {
        $this->data = $data;
        $this->status = $status;
    }
}

class MockRequest {
    public $params;
    public function __construct($params) { $this->params = $params; }
    public function get_json_params() { return $this->params; }
    public function get_header($key) { return '123'; }
}

require_once 'includes/class-rest-handler.php';

$handler = new MP_REST_Handler();

// Payload with pollution that doesn't get caught by the pre_clean regex (only 5 commas)
$dirty_payload = [
    "thread_id" => "test_thread",
    "full_transcript" => [
        [
            "question" => "What is the answer?",
            "answer" => "<p>Here is some normal text.</p> #Ad1, #Ad2, #Ad3, #Ad4, #Ad5, #Ad6",
            "sources" => [
                [
                    "url" => "http://example.com",
                    "title" => "Example Source",
                    "source" => "Example",
                    "favicon" => "http://example.com/favicon.ico",
                    "excerpt" => "Some excerpt with <script>alert(1)</script> text."
                ]
            ]
        ]
    ]
];

$request = new MockRequest($dirty_payload);
echo "Testing handle_ingest update with quarantine payload...\n";
$response = $handler->handle_ingest($request);
echo "Response Status: " . $response->status . "\n";
print_r($response->data);
