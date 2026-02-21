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
    $key = get_option("mp_openai_api_key"); ?>
        <div class="wrap">
            <h1>Milepoint Q&A Settings</h1>
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
  }
}
