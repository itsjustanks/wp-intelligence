<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * WP Intelligence settings page.
 *
 * Provides a tabbed admin UI:
 *  - Modules tab:        feature flag toggles for every registered module.
 *  - AI Composer tab:    API key, model, block library, prompting.
 *  - Per-module tabs:    rendered by each module when active.
 */
class AI_Composer_Settings {

  private const OPTION_GROUP = 'ai_composer_settings_group';
  private const OPTION_NAME  = 'ai_composer_settings';
  private const PAGE_SLUG    = 'wp-intelligence';
  private const WELCOME_TRANSIENT = 'wpi_welcome_redirect';

  private const ALWAYS_ALLOWED_BLOCKS = [
    'core/group',
    'core/columns',
    'core/column',
  ];

  private static bool $initialized = false;

  /* ──────────────────────────────────────────────
   *  Lifecycle
   * ────────────────────────────────────────────── */

  public static function has_native_ai_client(): bool {
    return function_exists('wp_ai_client_prompt');
  }

  public static function handle_activation(bool $network_wide): void {
    if ($network_wide) {
      return;
    }
    set_transient(self::WELCOME_TRANSIENT, '1', 30 * MINUTE_IN_SECONDS);
  }

  public static function init(): void {
    if (self::$initialized) {
      return;
    }
    self::$initialized = true;

    add_action('admin_menu', [self::class, 'add_menu']);
    add_action('admin_init', [self::class, 'register_settings']);
    add_action('admin_init', [self::class, 'maybe_redirect_to_welcome'], 1);
    add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
  }

  public static function maybe_redirect_to_welcome(): void {
    if (! is_admin() || ! current_user_can('manage_options')) {
      return;
    }
    if (wp_doing_ajax() || wp_doing_cron()) {
      return;
    }
    if ((defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST)) {
      return;
    }
    if (is_network_admin()) {
      return;
    }
    if (! get_transient(self::WELCOME_TRANSIENT)) {
      return;
    }
    if (! empty($_GET['activate-multi'])) {
      delete_transient(self::WELCOME_TRANSIENT);
      return;
    }

    $is_plugin_screen = isset($_GET['page']) && sanitize_key((string) $_GET['page']) === self::PAGE_SLUG;
    $tab              = sanitize_key((string) ($_GET['tab'] ?? ''));
    delete_transient(self::WELCOME_TRANSIENT);

    if ($is_plugin_screen && $tab === 'welcome') {
      return;
    }

    wp_safe_redirect(self::tab_url('welcome'));
    exit;
  }

  public static function enqueue_admin_assets(string $hook): void {
    if ($hook !== self::settings_hook_suffix()) {
      return;
    }
    if (! defined('WPI_URL')) {
      return;
    }

    $css_path = WPI_DIR . '/assets/admin/settings.css';
    if (file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-settings-admin',
        WPI_URL . 'assets/admin/settings.css',
        [],
        (string) filemtime($css_path)
      );
    }

    $js_path = WPI_DIR . '/assets/admin/settings.js';
    if (file_exists($js_path)) {
      wp_enqueue_script(
        'wpi-settings-admin',
        WPI_URL . 'assets/admin/settings.js',
        [],
        (string) filemtime($js_path),
        true
      );

      wp_localize_script('wpi-settings-admin', 'wpiSettingsL10n', [
        'blockCountTemplate' => __('%1$d / %2$d blocks enabled', 'wp-intelligence'),
      ]);
    }
  }

  /* ──────────────────────────────────────────────
   *  Menu
   * ────────────────────────────────────────────── */

  public static function add_menu(): void {
    if (! current_user_can('manage_options')) {
      return;
    }

    add_menu_page(
      __('WP Intelligence', 'wp-intelligence'),
      __('Intelligence', 'wp-intelligence'),
      'manage_options',
      self::PAGE_SLUG,
      [self::class, 'render_page'],
      'dashicons-lightbulb',
      81
    );
  }

  /* ──────────────────────────────────────────────
   *  Settings API registration
   * ────────────────────────────────────────────── */

  public static function register_settings(): void {
    register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
      'sanitize_callback' => [self::class, 'sanitize'],
    ]);

    register_setting(self::OPTION_GROUP, 'wpi_modules', [
      'sanitize_callback' => [self::class, 'sanitize_modules'],
    ]);

    foreach (['wpi_security', 'wpi_performance', 'wpi_woocommerce', 'wpi_resource_hints'] as $opt) {
      register_setting(self::OPTION_GROUP, $opt, [
        'sanitize_callback' => [self::class, 'sanitize_module_sub_options'],
      ]);
    }
  }

  /* ──────────────────────────────────────────────
   *  Sanitization
   * ────────────────────────────────────────────── */

  public static function sanitize(mixed $input): array {
    if (! is_array($input)) {
      $input = [];
    }
    $clean = [
      'api_key'                => sanitize_text_field($input['api_key'] ?? ''),
      'model'                  => sanitize_text_field($input['model'] ?? 'gpt-4.1'),
      'block_selection_mode'   => sanitize_text_field($input['block_selection_mode'] ?? 'all'),
      'theme_strategy_enabled' => ! empty($input['theme_strategy_enabled']) ? '1' : '0',
      'system_prompt_prepend'  => isset($input['system_prompt_prepend']) ? wp_kses_post((string) $input['system_prompt_prepend']) : '',
      'system_prompt_append'   => isset($input['system_prompt_append']) ? wp_kses_post((string) $input['system_prompt_append']) : '',
    ];

    if (isset($input['enabled_blocks']) && is_array($input['enabled_blocks'])) {
      $selected = array_map('sanitize_text_field', $input['enabled_blocks']);
    } else {
      $selected = [];
    }

    $clean['enabled_blocks'] = array_values(array_unique(array_merge($selected, self::get_forced_enabled_blocks())));

    // Syndication sub-settings.
    if (isset($input['syndication']) && is_array($input['syndication'])) {
      $clean['syndication'] = self::sanitize_syndication($input['syndication']);
    }

    return $clean;
  }

  public static function sanitize_modules(mixed $input): array {
    if (! is_array($input)) {
      $input = [];
    }
    $clean = [];
    foreach (WPI_Module_Manager::all() as $id => $config) {
      $clean[$id] = ! empty($input[$id]);
    }
    return $clean;
  }

  public static function sanitize_module_sub_options(mixed $input): array {
    if (! is_array($input)) {
      return [];
    }

    // Resource hints uses its own sanitizer (origins array, not booleans).
    if (isset($input['origins'])) {
      return class_exists('WPI_Resource_Hints') ? WPI_Resource_Hints::sanitize($input) : [];
    }

    $clean = [];
    foreach ($input as $k => $v) {
      $clean[sanitize_key($k)] = ! empty($v);
    }
    return $clean;
  }

  private static function sanitize_syndication(array $input): array {
    $clean = [
      'enabled_post_types' => [],
      'system_prompt'      => '',
      'source_taxonomy'    => '',
    ];

    if (! empty($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
      $clean['enabled_post_types'] = array_map('sanitize_text_field', $input['enabled_post_types']);
    }

    $prompt = $input['system_prompt'] ?? $input['default_prompt'] ?? '';
    if ($prompt !== '') {
      $clean['system_prompt'] = wp_kses_post((string) $prompt);
    }

    if (isset($input['source_taxonomy'])) {
      $clean['source_taxonomy'] = sanitize_key((string) $input['source_taxonomy']);
    }

    return $clean;
  }

  /* ──────────────────────────────────────────────
   *  Getters
   * ────────────────────────────────────────────── */

  public static function get_settings(): array {
    $settings = get_option(self::OPTION_NAME, []);
    return is_array($settings) ? $settings : [];
  }

  public static function get_syndication_settings(): array {
    $settings = self::get_settings();
    $syndication = is_array($settings['syndication'] ?? null) ? $settings['syndication'] : [];

    if (! isset($syndication['system_prompt']) && isset($syndication['default_prompt'])) {
      $syndication['system_prompt'] = (string) $syndication['default_prompt'];
    }

    return $syndication;
  }

  public static function get_structural_blocks(): array {
    $registry = WP_Block_Type_Registry::get_instance();
    $always   = apply_filters('ai_composer_always_allowed_blocks', self::ALWAYS_ALLOWED_BLOCKS);
    if (! is_array($always)) {
      $always = self::ALWAYS_ALLOWED_BLOCKS;
    }
    return array_values(array_filter($always, static fn(string $name): bool => $registry->is_registered($name)));
  }

  public static function get_forced_enabled_blocks(): array {
    if (! class_exists('AI_Composer_Block_Catalog')) {
      return self::get_structural_blocks();
    }
    $registry = WP_Block_Type_Registry::get_instance();
    $forced   = self::get_structural_blocks();

    foreach ($registry->get_all_registered() as $name => $block_type) {
      if (! is_string($name) || ! str_starts_with($name, 'core/')) {
        continue;
      }
      if (in_array($name, AI_Composer_Block_Catalog::EXCLUDED_BLOCKS, true)) {
        continue;
      }
      $forced[] = $name;
    }
    return array_values(array_unique($forced));
  }

  public static function get_enabled_blocks(): array {
    $settings = self::get_settings();
    $mode     = $settings['block_selection_mode'] ?? 'all';

    if ($mode === 'all') {
      return [];
    }

    $selected = isset($settings['enabled_blocks']) && is_array($settings['enabled_blocks'])
      ? array_map('sanitize_text_field', $settings['enabled_blocks'])
      : [];

    return array_values(array_unique(array_merge($selected, self::get_forced_enabled_blocks())));
  }

  public static function is_theme_strategy_enabled(): bool {
    return (self::get_settings()['theme_strategy_enabled'] ?? '1') === '1';
  }

  public static function get_system_prompt_prepend(): string {
    return (string) (self::get_settings()['system_prompt_prepend'] ?? '');
  }

  public static function get_system_prompt_append(): string {
    return (string) (self::get_settings()['system_prompt_append'] ?? '');
  }

  /* ──────────────────────────────────────────────
   *  Page rendering
   * ────────────────────────────────────────────── */

  private static function current_tab(): string {
    $tab = sanitize_key($_GET['tab'] ?? 'modules');
    return $tab ?: 'modules';
  }

  public static function render_page(): void {
    $tab     = self::current_tab();
    $modules = WPI_Module_Manager::all();
    $flags   = WPI_Module_Manager::get_flags();

    $tabs = [
      'welcome'     => __('Welcome', 'wp-intelligence'),
      'modules'     => __('Modules', 'wp-intelligence'),
      'ai_composer' => __('AI Composer', 'wp-intelligence'),
      'syndication' => __('Syndication', 'wp-intelligence'),
    ];

    $module_tabs = [
      'security'       => ['class' => 'WPI_Security',       'label' => __('Security', 'wp-intelligence')],
      'performance'    => ['class' => 'WPI_Performance',     'label' => __('Performance', 'wp-intelligence')],
      'resource_hints' => ['class' => 'WPI_Resource_Hints',  'label' => __('Resource Hints', 'wp-intelligence')],
      'woocommerce'    => ['class' => 'WPI_WooCommerce',     'label' => __('WooCommerce', 'wp-intelligence')],
    ];

    foreach ($module_tabs as $mod_id => $meta) {
      if (WPI_Module_Manager::is_active($mod_id) && class_exists($meta['class'])) {
        $tabs[$mod_id] = $meta['label'];
      }
    }

    $base_url = self::tab_url('');

    ?>
    <div class="wrap wpi-settings-page">
      <header class="wpi-page-header">
        <div>
          <h1><?php esc_html_e('WP Intelligence', 'wp-intelligence'); ?></h1>
          <p class="description"><?php esc_html_e('Modular AI and site optimization toolkit for WordPress.', 'wp-intelligence'); ?></p>
        </div>
        <span class="wpi-version-pill"><?php echo esc_html(sprintf(__('Version %s', 'wp-intelligence'), WPI_VERSION)); ?></span>
      </header>

      <nav class="nav-tab-wrapper wpi-nav-tabs">
        <?php foreach ($tabs as $slug => $label) : ?>
          <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
             class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php
      if ($tab === 'welcome') {
        self::render_welcome_tab();
        echo '</div>';
        return;
      }
      ?>

      <form action="options.php" method="post" class="wpi-settings-form">
        <?php settings_fields(self::OPTION_GROUP); ?>

        <div class="wpi-tab-content">
          <?php
          switch ($tab) {
            case 'modules':
              self::render_modules_tab($modules, $flags);
              break;
            case 'ai_composer':
              self::render_ai_composer_tab();
              break;
            case 'syndication':
              self::render_syndication_tab();
              break;
            case 'security':
              if (class_exists('WPI_Security')) {
                self::render_card_start(__('Security Hardening', 'wp-intelligence'));
                WPI_Security::render_fields();
                self::render_card_end();
              }
              break;
            case 'performance':
              if (class_exists('WPI_Performance')) {
                self::render_card_start(__('Performance', 'wp-intelligence'));
                WPI_Performance::render_fields();
                self::render_card_end();
              }
              break;
            case 'resource_hints':
              if (class_exists('WPI_Resource_Hints')) {
                self::render_card_start(__('Resource Hints', 'wp-intelligence'));
                WPI_Resource_Hints::render_fields();
                self::render_card_end();
              }
              break;
            case 'woocommerce':
              if (class_exists('WPI_WooCommerce')) {
                self::render_card_start(__('WooCommerce Optimization', 'wp-intelligence'));
                WPI_WooCommerce::render_fields();
                self::render_card_end();
              }
              break;
            default:
              do_action('wpi_settings_tab_' . $tab);
          }
          ?>
        </div>

        <?php submit_button(__('Save Changes', 'wp-intelligence')); ?>
      </form>
    </div>
    <?php
  }

  private static function render_welcome_tab(): void {
    ?>
    <div class="wpi-card wpi-welcome">
      <h2><?php esc_html_e('Welcome to WP Intelligence', 'wp-intelligence'); ?></h2>
      <p><?php esc_html_e('Thanks for installing WP Intelligence. Start with this quick setup checklist to get the most from the plugin.', 'wp-intelligence'); ?></p>
      <ol class="wpi-checklist">
        <li>
          <strong><?php esc_html_e('Choose your modules', 'wp-intelligence'); ?></strong>
          <p><?php esc_html_e('Enable only the features your site needs for a clean, lean setup.', 'wp-intelligence'); ?></p>
          <a class="button button-secondary" href="<?php echo esc_url(self::tab_url('modules')); ?>"><?php esc_html_e('Configure Modules', 'wp-intelligence'); ?></a>
        </li>
        <li>
          <strong><?php esc_html_e('Configure AI Composer', 'wp-intelligence'); ?></strong>
          <p><?php esc_html_e('Select your model, prompting strategy, and block library policy.', 'wp-intelligence'); ?></p>
          <a class="button button-secondary" href="<?php echo esc_url(self::tab_url('ai_composer')); ?>"><?php esc_html_e('Open AI Composer Settings', 'wp-intelligence'); ?></a>
        </li>
        <li>
          <strong><?php esc_html_e('Set up content workflows', 'wp-intelligence'); ?></strong>
          <p><?php esc_html_e('Optional: enable Syndication and define post types for AI-assisted rewriting.', 'wp-intelligence'); ?></p>
          <a class="button button-secondary" href="<?php echo esc_url(self::tab_url('syndication')); ?>"><?php esc_html_e('Review Syndication', 'wp-intelligence'); ?></a>
        </li>
      </ol>
      <p class="description"><?php esc_html_e('Tip: Open the block editor and launch the WP Intelligence sidebar to generate your first page layout.', 'wp-intelligence'); ?></p>
    </div>
    <?php
  }

  /* ──────────────────────────────────────────────
   *  Tab: Modules
   * ────────────────────────────────────────────── */

  private static function render_modules_tab(array $modules, array $flags): void {
    $forced = apply_filters('wpi_force_active_modules', []);
    if (! is_array($forced)) {
      $forced = [];
    }

    $grouped_modules = [];
    foreach ($modules as $id => $config) {
      $category = sanitize_text_field((string) ($config['category'] ?? 'General'));
      if ($category === '') {
        $category = 'General';
      }
      if (! isset($grouped_modules[$category])) {
        $grouped_modules[$category] = [];
      }
      $grouped_modules[$category][$id] = $config;
    }
    ksort($grouped_modules);

    ?>
    <div class="wpi-card">
      <h2><?php esc_html_e('Feature Modules', 'wp-intelligence'); ?></h2>
      <p class="description"><?php esc_html_e('Toggle features on or off. Disabled modules have zero runtime overhead.', 'wp-intelligence'); ?></p>

      <?php foreach ($grouped_modules as $category => $category_modules) : ?>
        <section class="wpi-module-category">
          <h3><?php echo esc_html($category); ?></h3>
          <div class="wpi-module-grid">
            <?php foreach ($category_modules as $id => $config) :
              $active    = $flags[$id] ?? $config['default'];
              $dep_ok    = WPI_Module_Manager::dependency_met($id);
              $is_forced = in_array($id, $forced, true);
              ?>
              <article class="wpi-module-card <?php echo ! $dep_ok ? 'is-disabled' : ''; ?>">
                <header>
                  <h4>
                    <span class="dashicons dashicons-<?php echo esc_attr($config['icon']); ?>"></span>
                    <?php echo esc_html($config['title']); ?>
                  </h4>
                </header>
                <p><?php echo esc_html($config['description']); ?></p>

                <?php if (! $dep_ok) : ?>
                  <p class="wpi-status-error">
                    <?php
                    printf(
                      /* translators: %s: required dependency class/function name */
                      wp_kses_post(__('Requires %s (not detected).', 'wp-intelligence')),
                      '<code>' . esc_html((string) $config['requires']) . '</code>'
                    );
                    ?>
                  </p>
                <?php elseif ($is_forced) : ?>
                  <input type="hidden" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="1">
                  <label class="wpi-toggle">
                    <input type="checkbox" checked disabled>
                    <span><?php esc_html_e('Enabled (required by theme)', 'wp-intelligence'); ?></span>
                  </label>
                <?php else : ?>
                  <label class="wpi-toggle">
                    <input type="hidden" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="0">
                    <input type="checkbox" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="1" <?php checked($active); ?>>
                    <span><?php esc_html_e('Enable module', 'wp-intelligence'); ?></span>
                  </label>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
  }

  /* ──────────────────────────────────────────────
   *  Tab: AI Composer
   * ────────────────────────────────────────────── */

  private static function render_ai_composer_tab(): void {
    if (! WPI_Module_Manager::is_active('ai_composer')) {
      echo '<div class="notice notice-warning inline"><p>' . esc_html__('The AI Composer module is disabled. Enable it on the Modules tab.', 'wp-intelligence') . '</p></div>';
      return;
    }

    if (self::has_native_ai_client()) {
      echo '<div class="notice notice-info inline wpi-inline-notice"><p>' . esc_html__('Your site has the native WordPress AI Client. API credentials are managed in Settings → AI Credentials.', 'wp-intelligence') . '</p></div>';
    }

    $option = esc_attr(self::OPTION_NAME);
    $settings = self::get_settings();

    if (! self::has_native_ai_client()) {
      self::render_provider_fields($option, $settings);
    }

    self::render_block_selector_fields($option, $settings);
    self::render_prompting_fields($option, $settings);
  }

  private static function render_provider_fields(string $option, array $settings): void {
    $api_key = $settings['api_key'] ?? '';
    $model   = $settings['model'] ?? 'gpt-4.1';
    $models  = apply_filters('ai_composer_available_models', [
      'gpt-4.1'      => 'GPT-4.1',
      'gpt-4.1-mini' => 'GPT-4.1 Mini',
      'gpt-4o'       => 'GPT-4o',
      'gpt-4o-mini'  => 'GPT-4o Mini',
    ]);

    ?>
    <?php self::render_card_start(__('AI Provider', 'wp-intelligence'), __('Enter your AI provider credentials. On WordPress 7.0+, this is managed in Settings → AI Credentials.', 'wp-intelligence')); ?>
    <table class="form-table" role="presentation"><tbody>
    <tr>
      <th scope="row"><label for="wpi_api_key"><?php esc_html_e('OpenAI API Key', 'wp-intelligence'); ?></label></th>
      <td>
        <input type="password" id="wpi_api_key" name="<?php echo $option; ?>[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off">
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpi_model"><?php esc_html_e('Model', 'wp-intelligence'); ?></label></th>
      <td>
        <select id="wpi_model" name="<?php echo $option; ?>[model]" class="regular-text">
          <?php foreach ($models as $val => $label) : ?>
            <option value="<?php echo esc_attr($val); ?>" <?php selected($model, $val); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
    </tr>
    </tbody></table>
    <?php self::render_card_end(); ?>
    <?php
  }

  private static function render_prompting_fields(string $option, array $settings): void {
    $strategy = ($settings['theme_strategy_enabled'] ?? '1') === '1';
    $prepend  = $settings['system_prompt_prepend'] ?? '';
    $append   = $settings['system_prompt_append'] ?? '';

    ?>
    <?php self::render_card_start(__('Prompting & Strategy', 'wp-intelligence')); ?>
    <table class="form-table" role="presentation"><tbody>
      <tr>
        <th scope="row"><?php esc_html_e('Theme-aware strategy', 'wp-intelligence'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="<?php echo $option; ?>[theme_strategy_enabled]" value="1" <?php checked($strategy); ?>>
            <?php esc_html_e('Enable automatic theme-aware prompting (recommended).', 'wp-intelligence'); ?>
          </label>
          <p class="description"><?php esc_html_e('Adds strategy hints based on active theme and available block libraries (e.g. Nectar).', 'wp-intelligence'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label><?php esc_html_e('System Prompt (prepend)', 'wp-intelligence'); ?></label></th>
        <td>
          <textarea name="<?php echo $option; ?>[system_prompt_prepend]" rows="5" class="large-text code" placeholder="<?php esc_attr_e('Instructions inserted before base prompt rules.', 'wp-intelligence'); ?>"><?php echo esc_textarea($prepend); ?></textarea>
        </td>
      </tr>
      <tr>
        <th scope="row"><label><?php esc_html_e('System Prompt (append)', 'wp-intelligence'); ?></label></th>
        <td>
          <textarea name="<?php echo $option; ?>[system_prompt_append]" rows="5" class="large-text code" placeholder="<?php esc_attr_e('Instructions appended after base prompt rules.', 'wp-intelligence'); ?>"><?php echo esc_textarea($append); ?></textarea>
        </td>
      </tr>
    </tbody></table>
    <?php self::render_card_end(); ?>
    <?php
  }

  private static function render_block_selector_fields(string $option, array $settings): void {
    $mode    = $settings['block_selection_mode'] ?? 'all';
    $enabled = self::get_enabled_blocks();
    $forced  = self::get_forced_enabled_blocks();

    $registry   = WP_Block_Type_Registry::get_instance();
    $all_blocks = $registry->get_all_registered();
    ksort($all_blocks);

    $excluded = class_exists('AI_Composer_Block_Catalog') ? AI_Composer_Block_Catalog::EXCLUDED_BLOCKS : [];

    $grouped = [];
    foreach ($all_blocks as $name => $block_type) {
      if (in_array($name, $excluded, true)) {
        continue;
      }
      $prefix = explode('/', $name)[0] ?? 'other';
      $grouped[$prefix][$name] = $block_type;
    }
    ksort($grouped);

    ?>
    <?php self::render_card_start(__('Block Library', 'wp-intelligence'), __('Choose which blocks the AI is allowed to use when composing pages.', 'wp-intelligence')); ?>
    <fieldset class="wpi-block-mode">
      <label>
        <input type="radio" name="<?php echo $option; ?>[block_selection_mode]" value="all" <?php checked($mode, 'all'); ?> class="wpi-mode-radio">
        <?php esc_html_e('All blocks (auto-discover)', 'wp-intelligence'); ?>
      </label><br>
      <label>
        <input type="radio" name="<?php echo $option; ?>[block_selection_mode]" value="selected" <?php checked($mode, 'selected'); ?> class="wpi-mode-radio">
        <?php esc_html_e('Only selected blocks', 'wp-intelligence'); ?>
      </label>
    </fieldset>

    <?php if (! empty($forced)) : ?>
      <p class="description wpi-forced-note">
        <?php esc_html_e('Core composable blocks are always enabled:', 'wp-intelligence'); ?>
        <code><?php echo esc_html(implode(', ', array_slice($forced, 0, 8))); ?><?php echo count($forced) > 8 ? ' …' : ''; ?></code>
      </p>
    <?php endif; ?>

    <div class="wpi-block-tools">
      <input type="search" id="wpi-block-search" class="regular-text" placeholder="<?php esc_attr_e('Filter blocks…', 'wp-intelligence'); ?>">
      <button type="button" class="button button-small" id="wpi-sel-all"><?php esc_html_e('Select All', 'wp-intelligence'); ?></button>
      <button type="button" class="button button-small" id="wpi-sel-none"><?php esc_html_e('Select None', 'wp-intelligence'); ?></button>
      <span id="wpi-count" class="wpi-block-count"></span>
    </div>

    <div id="wpi-block-list" class="wpi-block-list <?php echo $mode === 'all' ? 'is-disabled' : ''; ?>">
      <?php foreach ($grouped as $prefix => $blocks) : ?>
        <details <?php echo in_array($prefix, ['core', 'acf', 'nectar-blocks'], true) ? 'open' : ''; ?>>
          <summary>
            <?php echo esc_html($prefix); ?>
            <span>(<?php echo count($blocks); ?>)</span>
          </summary>
          <div class="wpi-block-prefix-group">
            <?php foreach ($blocks as $name => $block_type) :
              $is_forced = in_array($name, $forced, true);
              $checked   = ($mode === 'all') || in_array($name, $enabled, true) || $is_forced;
              $title     = $block_type->title ?: $name;
            ?>
              <label class="wpi-block-candidate" data-block-search="<?php echo esc_attr(strtolower($name . ' ' . $title)); ?>" title="<?php echo esc_attr($block_type->description ?? ''); ?>">
                <input type="checkbox" name="<?php echo $option; ?>[enabled_blocks][]" value="<?php echo esc_attr($name); ?>"
                  <?php checked($checked); ?>
                  class="wpi-block-cb<?php echo $is_forced ? ' is-forced' : ''; ?>"
                  <?php echo $is_forced ? 'data-forced="1" disabled' : ''; ?>
                >
                <code><?php echo esc_html($name); ?></code>
                <?php if ($title !== $name) : ?>
                  <span> — <?php echo esc_html($title); ?></span>
                <?php endif; ?>
                <?php if ($is_forced) : ?>
                  <span class="wpi-forced-block-label"> (<?php esc_html_e('always enabled', 'wp-intelligence'); ?>)</span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
    <?php self::render_card_end(); ?>
    <?php
  }

  /* ──────────────────────────────────────────────
   *  Tab: Syndication
   * ────────────────────────────────────────────── */

  private static function render_syndication_tab(): void {
    if (! WPI_Module_Manager::is_active('syndication')) {
      echo '<div class="notice notice-warning inline"><p>' . esc_html__('The Syndication module is disabled. Enable it on the Modules tab.', 'wp-intelligence') . '</p></div>';
      return;
    }

    $option    = esc_attr(self::OPTION_NAME);
    $syn       = self::get_syndication_settings();
    $types     = $syn['enabled_post_types'] ?? [];
    $prompt    = $syn['system_prompt'] ?? '';
    $taxonomy  = sanitize_key((string) ($syn['source_taxonomy'] ?? ''));
    $all_types = get_post_types(['public' => true], 'objects');

    $taxonomies = get_taxonomies(['public' => true], 'objects');

    ?>
    <?php self::render_card_start(__('Content Syndication', 'wp-intelligence')); ?>
    <table class="form-table" role="presentation"><tbody>
      <tr>
        <th scope="row"><?php esc_html_e('Enabled post types', 'wp-intelligence'); ?></th>
        <td>
          <?php foreach ($all_types as $pt) :
            if ($pt->name === 'attachment') continue;
          ?>
            <label class="wpi-checkbox-row">
              <input type="checkbox" name="<?php echo $option; ?>[syndication][enabled_post_types][]" value="<?php echo esc_attr($pt->name); ?>"
                <?php checked(in_array($pt->name, $types, true)); ?>>
              <?php echo esc_html($pt->label); ?> <code>(<?php echo esc_html($pt->name); ?>)</code>
            </label>
          <?php endforeach; ?>
        </td>
      </tr>
      <tr>
        <th scope="row"><label><?php esc_html_e('Default rewrite prompt', 'wp-intelligence'); ?></label></th>
        <td>
          <textarea name="<?php echo $option; ?>[syndication][system_prompt]" rows="4" class="large-text code"
            placeholder="<?php esc_attr_e('Optional default instructions for how the AI should rewrite syndicated articles.', 'wp-intelligence'); ?>"
          ><?php echo esc_textarea($prompt); ?></textarea>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="wpi_source_taxonomy"><?php esc_html_e('Source taxonomy (optional)', 'wp-intelligence'); ?></label></th>
        <td>
          <select id="wpi_source_taxonomy" name="<?php echo $option; ?>[syndication][source_taxonomy]" class="regular-text">
            <option value=""><?php esc_html_e('Do not assign taxonomy terms', 'wp-intelligence'); ?></option>
            <?php foreach ($taxonomies as $tax_obj) : ?>
              <option value="<?php echo esc_attr($tax_obj->name); ?>" <?php selected($taxonomy, $tax_obj->name); ?>>
                <?php echo esc_html($tax_obj->labels->singular_name . ' (' . $tax_obj->name . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description"><?php esc_html_e('When set, the rewritten source name will be assigned to this taxonomy if it applies to the edited post type.', 'wp-intelligence'); ?></p>
        </td>
      </tr>
    </tbody></table>
    <?php self::render_card_end(); ?>
    <?php
  }

  private static function render_card_start(string $title, string $description = ''): void {
    echo '<section class="wpi-card">';
    echo '<h2>' . esc_html($title) . '</h2>';
    if ($description !== '') {
      echo '<p class="description">' . esc_html($description) . '</p>';
    }
  }

  private static function render_card_end(): void {
    echo '</section>';
  }

  private static function settings_hook_suffix(): string {
    return 'toplevel_page_' . self::PAGE_SLUG;
  }

  private static function tab_url(string $tab): string {
    $args = ['page' => self::PAGE_SLUG];
    if ($tab !== '') {
      $args['tab'] = $tab;
    }
    return add_query_arg($args, admin_url('admin.php'));
  }
}
