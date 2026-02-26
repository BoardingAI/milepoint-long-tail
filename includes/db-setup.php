<?php
if (!defined("ABSPATH")) {
  exit();
}

function mp_lt_install_database()
{
  global $wpdb;

  $table_name = $wpdb->prefix . "mp_query_stats";
  $charset_collate = $wpdb->get_charset_collate();

  // SQL to create the time-series stats table
  // UNIQUE KEY ensures we only have one row per post per day for easy "upserting"
  $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        view_date date NOT NULL,
        view_count int(11) DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY post_date (post_id, view_date)
    ) $charset_collate;";

  require_once ABSPATH . "wp-admin/includes/upgrade.php";

  // dbDelta handles the "if not exists" logic automatically and safely
  dbDelta($sql);

  // Optional: Add a version flag to options in case we need to run migrations later
  add_option("mp_lt_db_version", "1.0.0");
}
