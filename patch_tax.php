<?php
$file = 'includes/class-qa-cpt.php';
$content = file_get_contents($file);

$admin_code = <<<'CODE'
    // Admin Columns
    add_filter("manage_milepoint_qa_posts_columns", [$this, "set_custom_columns"]);
    add_action("manage_milepoint_qa_posts_custom_column", [$this, "custom_column_data"], 10, 2);
    add_filter("manage_edit-milepoint_qa_sortable_columns", [$this, "sortable_columns"]);
    add_action("restrict_manage_posts", [$this, "add_taxonomy_filters"]);
CODE;

$content = str_replace(
    '    // Admin Columns
    add_filter("manage_milepoint_qa_posts_columns", [$this, "set_custom_columns"]);
    add_action("manage_milepoint_qa_posts_custom_column", [$this, "custom_column_data"], 10, 2);
    add_filter("manage_edit-milepoint_qa_sortable_columns", [$this, "sortable_columns"]);',
    $admin_code,
    $content
);

$methods_code = <<<'CODE'
  public function add_taxonomy_filters() {
    global $typenow;
    if ($typenow == "milepoint_qa") {
      $taxonomy = "mp_workflow_status";
      $tax_obj = get_taxonomy($taxonomy);
      wp_dropdown_categories([
        "show_option_all" => "All Workflow Buckets",
        "taxonomy"        => $taxonomy,
        "name"            => $taxonomy,
        "orderby"         => "name",
        "selected"        => isset($_GET[$taxonomy]) ? $_GET[$taxonomy] : "",
        "show_count"      => true,
        "hide_empty"      => false,
        "value_field"     => "slug",
      ]);
    }
  }

  public function set_custom_columns($columns) {
CODE;

$content = str_replace(
    '  public function set_custom_columns($columns) {',
    $methods_code,
    $content
);

file_put_contents($file, $content);
echo "Patched admin filtering.\n";
