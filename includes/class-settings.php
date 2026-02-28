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

  private const ALWAYS_ALLOWED_BLOCKS = [
    'core/group',
    'core/columns',
    'core/column',
  ];

  /* ──────────────────────────────────────────────
   *  Lifecycle
   * ────────────────────────────────────────────── */

  public static function has_native_ai_client(): bool {
    return function_exists('wp_ai_client_prompt');
  }

  public static function init(): void {
    add_action('admin_menu', [self::class, 'add_menu']);
    add_action('admin_init', [self::class, 'register_settings']);
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
      'default_prompt'     => '',
    ];

    if (! empty($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
      $clean['enabled_post_types'] = array_map('sanitize_text_field', $input['enabled_post_types']);
    }

    if (isset($input['default_prompt'])) {
      $clean['default_prompt'] = wp_kses_post((string) $input['default_prompt']);
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
    return is_array($settings['syndication'] ?? null) ? $settings['syndication'] : [];
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

    $base_url = admin_url('admin.php?page=' . self::PAGE_SLUG);

    ?>
    <div class="wrap">
      <h1><?php esc_html_e('WP Intelligence', 'wp-intelligence'); ?></h1>

      <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label) : ?>
          <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
             class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <form action="options.php" method="post" style="margin-top:16px;">
        <?php settings_fields(self::OPTION_GROUP); ?>

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
              WPI_Security::render_fields();
            }
            break;
          case 'performance':
            if (class_exists('WPI_Performance')) {
              WPI_Performance::render_fields();
            }
            break;
          case 'resource_hints':
            if (class_exists('WPI_Resource_Hints')) {
              WPI_Resource_Hints::render_fields();
            }
            break;
          case 'woocommerce':
            if (class_exists('WPI_WooCommerce')) {
              WPI_WooCommerce::render_fields();
            }
            break;
          default:
            do_action('wpi_settings_tab_' . $tab);
        }
        ?>

        <?php submit_button(); ?>
      </form>
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
    ?>
    <p class="description"><?php esc_html_e('Toggle features on or off. Disabled modules have zero runtime overhead.', 'wp-intelligence'); ?></p>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ($modules as $id => $config) :
      $active    = $flags[$id] ?? $config['default'];
      $dep_ok    = WPI_Module_Manager::dependency_met($id);
      $is_forced = in_array($id, $forced, true);
    ?>
      <tr>
        <th scope="row">
          <span class="dashicons dashicons-<?php echo esc_attr($config['icon']); ?>" style="margin-right:6px;color:#3c434a;"></span>
          <?php echo esc_html($config['title']); ?>
        </th>
        <td>
          <?php if (! $dep_ok) : ?>
            <span style="color:#b32d2e;">
              <?php printf(
                /* translators: %s: required dependency class/function name */
                esc_html__('Requires %s (not detected).', 'wp-intelligence'),
                '<code>' . esc_html($config['requires']) . '</code>'
              ); ?>
            </span>
          <?php elseif ($is_forced) : ?>
            <input type="hidden" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="1">
            <label>
              <input type="checkbox" checked disabled>
              <?php esc_html_e('Enable', 'wp-intelligence'); ?>
            </label>
            <span style="color:#7a4f01; margin-left:6px;">(<?php esc_html_e('required by theme', 'wp-intelligence'); ?>)</span>
          <?php else : ?>
            <label>
              <input type="hidden" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="0">
              <input type="checkbox" name="wpi_modules[<?php echo esc_attr($id); ?>]" value="1" <?php checked($active); ?>>
              <?php esc_html_e('Enable', 'wp-intelligence'); ?>
            </label>
          <?php endif; ?>
          <p class="description"><?php echo esc_html($config['description']); ?></p>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
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
      echo '<div class="notice notice-info inline" style="margin:0 0 16px;"><p>' . esc_html__('Your site has the native WordPress AI Client. API credentials are managed in Settings → AI Credentials.', 'wp-intelligence') . '</p></div>';
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
    <h2><?php esc_html_e('AI Provider', 'wp-intelligence'); ?></h2>
    <p class="description"><?php esc_html_e('Enter your AI provider credentials. On WordPress 7.0+, this is managed in Settings → AI Credentials.', 'wp-intelligence'); ?></p>
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
    <?php
  }

  private static function render_prompting_fields(string $option, array $settings): void {
    $strategy = ($settings['theme_strategy_enabled'] ?? '1') === '1';
    $prepend  = $settings['system_prompt_prepend'] ?? '';
    $append   = $settings['system_prompt_append'] ?? '';

    ?>
    <h2><?php esc_html_e('Prompting & Strategy', 'wp-intelligence'); ?></h2>
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
    <h2><?php esc_html_e('Block Library', 'wp-intelligence'); ?></h2>
    <p class="description"><?php esc_html_e('Choose which blocks the AI is allowed to use when composing pages.', 'wp-intelligence'); ?></p>

    <fieldset style="margin:12px 0;">
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
      <p class="description">
        <?php esc_html_e('Core composable blocks are always enabled:', 'wp-intelligence'); ?>
        <code><?php echo esc_html(implode(', ', array_slice($forced, 0, 8))); ?><?php echo count($forced) > 8 ? ' …' : ''; ?></code>
      </p>
    <?php endif; ?>

    <div id="wpi-block-list" style="margin-top:12px;max-height:500px;overflow-y:auto;border:1px solid #ddd;padding:12px;background:#fafafa;<?php echo $mode === 'all' ? 'opacity:.5;pointer-events:none;' : ''; ?>">
      <p>
        <button type="button" class="button button-small" id="wpi-sel-all"><?php esc_html_e('Select All', 'wp-intelligence'); ?></button>
        <button type="button" class="button button-small" id="wpi-sel-none"><?php esc_html_e('Select None', 'wp-intelligence'); ?></button>
        <span style="margin-left:12px;color:#666;" id="wpi-count"></span>
      </p>
      <?php foreach ($grouped as $prefix => $blocks) : ?>
        <details <?php echo in_array($prefix, ['core', 'acf', 'nectar-blocks'], true) ? 'open' : ''; ?> style="margin-bottom:8px;">
          <summary style="cursor:pointer;font-weight:600;padding:4px 0;">
            <?php echo esc_html($prefix); ?>
            <span style="font-weight:400;color:#888;">(<?php echo count($blocks); ?>)</span>
          </summary>
          <div style="padding:4px 0 4px 20px;">
            <?php foreach ($blocks as $name => $block_type) :
              $is_forced = in_array($name, $forced, true);
              $checked   = ($mode === 'all') || in_array($name, $enabled, true) || $is_forced;
              $title     = $block_type->title ?: $name;
            ?>
              <label style="display:block;margin:3px 0;" title="<?php echo esc_attr($block_type->description ?? ''); ?>">
                <input type="checkbox" name="<?php echo $option; ?>[enabled_blocks][]" value="<?php echo esc_attr($name); ?>"
                  <?php checked($checked); ?>
                  class="wpi-block-cb<?php echo $is_forced ? ' is-forced' : ''; ?>"
                  <?php echo $is_forced ? 'data-forced="1" disabled' : ''; ?>
                >
                <code style="font-size:12px;"><?php echo esc_html($name); ?></code>
                <?php if ($title !== $name) : ?>
                  <span style="color:#555;"> — <?php echo esc_html($title); ?></span>
                <?php endif; ?>
                <?php if ($is_forced) : ?>
                  <span style="color:#7a4f01;"> (<?php esc_html_e('always enabled', 'wp-intelligence'); ?>)</span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>

    <script>
    (function(){
      var radios=document.querySelectorAll('.wpi-mode-radio'),
          list=document.getElementById('wpi-block-list'),
          cbs=document.querySelectorAll('.wpi-block-cb'),
          cnt=document.getElementById('wpi-count');
      function uc(){var c=document.querySelectorAll('.wpi-block-cb:checked').length;cnt.textContent=c+' / '+cbs.length+' blocks';}
      radios.forEach(function(r){r.addEventListener('change',function(){var a=this.value==='all';list.style.opacity=a?'.5':'1';list.style.pointerEvents=a?'none':'auto';});});
      document.getElementById('wpi-sel-all')?.addEventListener('click',function(){cbs.forEach(function(c){if(!c.disabled)c.checked=true;});uc();});
      document.getElementById('wpi-sel-none')?.addEventListener('click',function(){cbs.forEach(function(c){if(!c.disabled)c.checked=false;});uc();});
      cbs.forEach(function(c){c.addEventListener('change',uc);});uc();
    })();
    </script>
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
    $prompt    = $syn['default_prompt'] ?? '';
    $all_types = get_post_types(['public' => true], 'objects');

    ?>
    <h2><?php esc_html_e('Content Syndication', 'wp-intelligence'); ?></h2>
    <table class="form-table" role="presentation"><tbody>
      <tr>
        <th scope="row"><?php esc_html_e('Enabled post types', 'wp-intelligence'); ?></th>
        <td>
          <?php foreach ($all_types as $pt) :
            if ($pt->name === 'attachment') continue;
          ?>
            <label style="display:block;margin:3px 0;">
              <input type="checkbox" name="<?php echo $option; ?>[syndication][enabled_post_types][]" value="<?php echo esc_attr($pt->name); ?>"
                <?php checked(in_array($pt->name, $types, true)); ?>>
              <?php echo esc_html($pt->label); ?> <code style="font-size:11px;">(<?php echo esc_html($pt->name); ?>)</code>
            </label>
          <?php endforeach; ?>
        </td>
      </tr>
      <tr>
        <th scope="row"><label><?php esc_html_e('Default rewrite prompt', 'wp-intelligence'); ?></label></th>
        <td>
          <textarea name="<?php echo $option; ?>[syndication][default_prompt]" rows="4" class="large-text code"
            placeholder="<?php esc_attr_e('Optional default instructions for how the AI should rewrite syndicated articles.', 'wp-intelligence'); ?>"
          ><?php echo esc_textarea($prompt); ?></textarea>
        </td>
      </tr>
    </tbody></table>
    <?php
  }
}
