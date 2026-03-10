<?php

class MP_QA_Settings
{
  public function __construct()
  {
    add_action("admin_menu", [$this, "add_settings_menu"]);
    add_action("admin_init", [$this, "register_settings"]);
    add_action("admin_enqueue_scripts", [$this, "enqueue_admin_assets"]);
  }

  public function add_settings_menu()
  {
    add_submenu_page(
      "edit.php?post_type=milepoint_qa",
      "Q&A Settings",
      "Settings",
      "manage_options",
      "mp-qa-settings",
      [$this, "render_settings_page"],
    );
  }

  public function register_settings()
  {
    register_setting("mp_qa_settings_group", "mp_openai_api_key", [
      "sanitize_callback" => "sanitize_text_field",
    ]);
    register_setting("mp_qa_settings_group", "mp_cold_start_enabled", [
      "type" => "boolean",
      "sanitize_callback" => "rest_sanitize_boolean",
    ]);
  }

  public function enqueue_admin_assets($hook)
  {
    if ($hook !== "milepoint_qa_page_mp-qa-settings") {
      return;
    }

    wp_enqueue_style(
      "mp-qa-admin-css",
      plugin_dir_url(__DIR__) . "assets/css/mp-qa.css",
    );
    wp_enqueue_script(
      "mp-qa-admin-js",
      plugin_dir_url(__DIR__) . "assets/js/admin-settings.js",
      [],
      null,
      true,
    );
  }

  public function render_settings_page()
  {
    $key = get_option("mp_openai_api_key");
    $cold_start_enabled = get_option("mp_cold_start_enabled");

    // Sludge tracking metrics
    $cleaned_count = (int) get_option("mp_sludge_cleaned_count", 0);
    $quarantined_count = (int) get_option("mp_sludge_quarantined_count", 0);
    ?>
        <div class="wrap">
            <h1>Milepoint Q&A Settings</h1>

            <div class="notice notice-info inline" style="margin-top:20px; padding:15px; border-left-color: #007cba;">
                <h3 style="margin-top:0;">Ad-Blocker & Sludge Tracking</h3>
                <p>The listener automatically detects and removes injected CSS (ad-blocker sludge) from incoming chat transcripts.</p>
                <ul style="list-style:disc; margin-left: 20px;">
                    <li><strong>Transcripts Cleaned:</strong> <?php echo esc_html($cleaned_count); ?> <em>(Minor sludge removed successfully)</em></li>
                    <li><strong>Posts Quarantined:</strong> <?php echo esc_html($quarantined_count); ?> <em>(Major sludge detected, post status forced to Draft)</em></li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields("mp_qa_settings_group");
                do_settings_sections("mp_qa_settings_group");
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mp_openai_api_key">OpenAI API Key</label></th>
                        <td>
                            <div class="mp-api-key-container">
                                <input
                                    type="password"
                                    id="mp_openai_api_key"
                                    name="mp_openai_api_key"
                                    value="<?php echo esc_attr($key); ?>"
                                    class="regular-text"
                                >
                                <button type="button" class="button mp-toggle-visibility" title="Toggle Visibility">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <button type="button" class="button mp-copy-key" title="Copy Key">
                                    <span class="dashicons dashicons-copy"></span>
                                </button>
                            </div>
                            <p class="description">Enter your OpenAI <code>sk-...</code> key here.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mp_cold_start_enabled">Enable Cold Start Counts</label></th>
                        <td>
                            <label>
                                <input type="hidden" name="mp_cold_start_enabled" value="0">
                                <input
                                    type="checkbox"
                                    id="mp_cold_start_enabled"
                                    name="mp_cold_start_enabled"
                                    value="1"
                                    <?php checked(1, $cold_start_enabled, true); ?>
                                >
                                Artificially boost category and tag counts on the frontend.
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
  }
}
