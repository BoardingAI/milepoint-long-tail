<?php
$file = 'includes/class-rest-handler.php';
$content = file_get_contents($file);

$search = <<<'SEARCH'
                if ($reason) update_post_meta($followup_id, "_mp_classification_reason", $reason);
                if ($confidence) update_post_meta($followup_id, "_mp_classification_confidence", $confidence);
                if ($classification_failed) update_post_meta($followup_id, "_mp_classification_failed", true);
                if ($rewrite_failed) update_post_meta($followup_id, "_mp_rewrite_failed", true);
SEARCH;

$replace = <<<'REPLACE'
                if ($reason) update_post_meta($followup_id, "_mp_classification_reason", $reason);
                if ($confidence) update_post_meta($followup_id, "_mp_classification_confidence", $confidence);

                if ($classification_failed) {
                    update_post_meta($followup_id, "_mp_classification_failed", true);
                } else {
                    delete_post_meta($followup_id, "_mp_classification_failed");
                }

                if ($rewrite_failed) {
                    update_post_meta($followup_id, "_mp_rewrite_failed", true);
                } else {
                    delete_post_meta($followup_id, "_mp_rewrite_failed");
                }
REPLACE;

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);
echo "Added failure flag cleanup.\n";
