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
function get_posts($args) { return []; } // Simulate no existing post
function wp_insert_post($args) {
    echo "wp_insert_post called with post_status: " . $args['post_status'] . "\n";
    return 123;
}
function wp_update_post($args) { return 1; }
function get_post_status($id) { return 'future'; }
function update_post_meta($id, $key, $val) {
    if ($key === '_raw_transcript') {
        echo "Saved Transcript:\n";
        print_r($val);
    }
}
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

$dirty_payload = [
    "thread_id" => "test_thread",
    "full_transcript" => [
        [
            "question" => "What is the answer?",
            "answer" => "<p>Here is some normal text.</p><style>#Ads_BA_BS, #Ads_BA_BUT, #Ads_BA_BUT2, #Ads_BA_CAD, #Ads_BA_FLB, #Ads_BA_SKY, #Ads_BA_VID, #Ads_BA_BOX, #Ads_BA_TEST, #Ads_BA_10, #Ads_BA_11 { display: none; }</style><p>More normal text.</p> #Ad1, #Ad2, #Ad3, #Ad4, #Ad5, #Ad6, #Ad7, #Ad8, #Ad9, #Ad10, #Ad11",
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
echo "Testing handle_ingest with dirty payload...\n";
$response = $handler->handle_ingest($request);
echo "Response Status: " . $response->status . "\n";
print_r($response->data);
