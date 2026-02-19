<?php
// Mock WP functions
define('ABSPATH', true);
function add_filter($tag, $callback) {}
function get_post_type() { return 'milepoint_qa'; }
function is_main_query() { return true; }
function get_the_ID() { return 123; }
function get_post_meta($id, $key, $single) {
    if ($key === '_raw_transcript') {
        return [
            ['question' => 'Main Q', 'answer' => 'Main A', 'sources' => []]
        ];
    }
    if ($key === '_related_suggestions') {
        return ['Related Q1', 'Related Q2?'];
    }
    return [];
}
function esc_html($s) { return htmlspecialchars($s); }
function esc_url($s) { return $s; }

// Simple mock for MP_Content_Template if file not included
require_once __DIR__ . '/../milepoint-long-tail/includes/class-content-template.php';

$template = new MP_Content_Template();
$output = $template->render_qa_view('');

echo "Checking output for Related Q1 link...\n";
if (strpos($output, 'href="/chat/?q=Related+Q1"') !== false) {
    echo "PASS: Related Q1 link found.\n";
} else {
    echo "FAIL: Related Q1 link not found.\n";
    // echo "Output:\n" . $output . "\n";
}

echo "Checking output for Related Q2 link (encoded)...\n";
if (strpos($output, 'href="/chat/?q=Related+Q2%3F"') !== false) {
    echo "PASS: Related Q2 link found (encoded).\n";
} else {
    echo "FAIL: Related Q2 link not found.\n";
}
