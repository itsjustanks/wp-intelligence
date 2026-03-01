<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * WP Intelligence settings page.
 *
 * Always registered so admins can manage the block library and prompting behavior.
 * API key fields are only shown when the native WP AI Client is absent.
 */
class AI_Composer_Settings {

  private const OPTION_GROUP = 'ai_composer_settings_group';
  private const OPTION_NAME  = 'ai_composer_settings';
  private const PAGE_SLUG    = 'wp-intelligence';

  /**
   * Structural blocks are always allowed in selected mode to prevent composition dead-ends.
   */
  private const ALWAYS_ALLOWED_BLOCKS = [
    'core/group',
    'core/columns',
    'core/column',
  ];

  public static function has_native_ai_client(): bool {
    return function_exists('wp_ai_client_prompt');
  }

  public static function init(): void {
    add_action('admin_menu', [self::class, 'add_menu']);
    add_action('admin_init', [self::class, 'register_settings']);
  }

  public static function add_menu(): void {
    if (! current_user_can('manage_options')) {
      return;
    }

    add_options_page(
      __('WP Intelligence', 'wp-intelligence'),
      __('WP Intelligence', 'wp-intelligence'),
      'manage_options',
      self::PAGE_SLUG,
      [self::class, 'render_page']
    );
  }

  public static function register_settings(): void {
    register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
      'sanitize_callback' => [self::class, 'sanitize'],
    ]);

    if (! self::has_native_ai_client()) {
      add_settings_section(
        'ai_composer_provider',
        __('AI Provider Configuration', 'wp-intelligence'),
        [self::class, 'provider_section_callback'],
        self::PAGE_SLUG
      );

      add_settings_field('api_key', __('OpenAI API Key', 'wp-intelligence'), [self::class, 'render_api_key_field'], self::PAGE_SLUG, 'ai_composer_provider');
      add_settings_field('model', __('Model', 'wp-intelligence'), [self::class, 'render_model_field'], self::PAGE_SLUG, 'ai_composer_provider');
    }

    add_settings_section(
      'ai_composer_blocks',
      __('Block Library', 'wp-intelligence'),
      [self::class, 'blocks_section_callback'],
      self::PAGE_SLUG
    );

    add_settings_field('enabled_blocks', __('Enabled Blocks', 'wp-intelligence'), [self::class, 'render_block_selector'], self::PAGE_SLUG, 'ai_composer_blocks');

    add_settings_section(
      'ai_composer_prompting',
      __('Prompting & Strategy', 'wp-intelligence'),
      [self::class, 'prompting_section_callback'],
      self::PAGE_SLUG
    );

    add_settings_field('theme_strategy_enabled', __('Theme-aware strategy', 'wp-intelligence'), [self::class, 'render_theme_strategy_field'], self::PAGE_SLUG, 'ai_composer_prompting');
    add_settings_field('system_prompt_prepend', __('System Prompt (prepend)', 'wp-intelligence'), [self::class, 'render_system_prompt_prepend_field'], self::PAGE_SLUG, 'ai_composer_prompting');
    add_settings_field('system_prompt_append', __('System Prompt (append)', 'wp-intelligence'), [self::class, 'render_system_prompt_append_field'], self::PAGE_SLUG, 'ai_composer_prompting');

    add_settings_section(
      'ai_composer_syndication',
      __('Content Syndication', 'wp-intelligence'),
      [self::class, 'syndication_section_callback'],
      self::PAGE_SLUG
    );
    add_settings_field('syndication_post_types', __('Enabled Post Types', 'wp-intelligence'), [self::class, 'render_syndication_post_types_field'], self::PAGE_SLUG, 'ai_composer_syndication');
    add_settings_field('syndication_taxonomy', __('Source Taxonomy', 'wp-intelligence'), [self::class, 'render_syndication_taxonomy_field'], self::PAGE_SLUG, 'ai_composer_syndication');
    add_settings_field('syndication_prompt', __('System Prompt Override', 'wp-intelligence'), [self::class, 'render_syndication_prompt_field'], self::PAGE_SLUG, 'ai_composer_syndication');

    add_settings_section(
      'ai_composer_admin_experience',
      __('Admin Experience', 'wp-intelligence'),
      [self::class, 'admin_experience_section_callback'],
      self::PAGE_SLUG
    );
    add_settings_field('admin_remove_wp_logo', __('Remove WP Logo', 'wp-intelligence'), [self::class, 'render_admin_remove_wp_logo_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
    add_settings_field('admin_custom_login_logo', __('Custom Login Logo', 'wp-intelligence'), [self::class, 'render_admin_login_logo_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
    add_settings_field('admin_hide_update_notices', __('Hide Update Notices', 'wp-intelligence'), [self::class, 'render_admin_hide_notices_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
    add_settings_field('admin_custom_footer', __('Custom Footer', 'wp-intelligence'), [self::class, 'render_admin_footer_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
    add_settings_field('admin_menu_reorg', __('Menu Reorganization', 'wp-intelligence'), [self::class, 'render_admin_menu_reorg_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
    add_settings_field('admin_editor_enhancements', __('Editor Enhancements', 'wp-intelligence'), [self::class, 'render_admin_editor_enhancements_field'], self::PAGE_SLUG, 'ai_composer_admin_experience');
  }

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

    $clean['syndication'] = self::sanitize_syndication($input['syndication'] ?? []);
    $clean['admin_experience'] = self::sanitize_admin_experience($input['admin_experience'] ?? []);

    return $clean;
  }

  /**
   * @param mixed $input Raw syndication sub-array.
   * @return array<string, mixed>
   */
  private static function sanitize_syndication(mixed $input): array {
    if (! is_array($input)) {
      $input = [];
    }

    $clean = [
      'source_taxonomy' => sanitize_key($input['source_taxonomy'] ?? ''),
      'system_prompt'   => isset($input['system_prompt']) ? wp_kses_post((string) $input['system_prompt']) : '',
    ];

    if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
      $clean['enabled_post_types'] = array_values(array_unique(array_map('sanitize_key', $input['enabled_post_types'])));
    } else {
      $clean['enabled_post_types'] = [];
    }

    return $clean;
  }

  private static function sanitize_admin_experience(mixed $input): array {
    if (! is_array($input)) {
      $input = [];
    }

    return [
      'remove_wp_logo'       => ! empty($input['remove_wp_logo']) ? '1' : '0',
      'custom_login_logo'    => ! empty($input['custom_login_logo']) ? '1' : '0',
      'hide_update_notices'  => ! empty($input['hide_update_notices']) ? '1' : '0',
      'custom_footer'        => ! empty($input['custom_footer']) ? '1' : '0',
      'menu_reorganization'  => ! empty($input['menu_reorganization']) ? '1' : '0',
      'editor_enhancements'  => ! empty($input['editor_enhancements']) ? '1' : '0',
      'class_autocomplete'   => ! empty($input['class_autocomplete']) ? '1' : '0',
      'force_fullscreen'     => ! empty($input['force_fullscreen']) ? '1' : '0',
      'auto_open_list_view'  => ! empty($input['auto_open_list_view']) ? '1' : '0',
      'list_view_label'      => sanitize_text_field($input['list_view_label'] ?? ''),
    ];
  }

  /**
   * Get full settings array.
   */
  public static function get_settings(): array {
    $settings = get_option(self::OPTION_NAME, []);
    if (! is_array($settings)) {
      $settings = [];
    }
    return $settings;
  }

  /**
   * @return array<string, mixed>
   */
  public static function get_syndication_settings(): array {
    $settings = self::get_settings();
    $synd = $settings['syndication'] ?? [];
    return is_array($synd) ? $synd : [];
  }

  /**
   * @return array<string, mixed>
   */
  public static function get_admin_experience_settings(): array {
    $settings = self::get_settings();
    $ae = $settings['admin_experience'] ?? [];
    return is_array($ae) ? $ae : [];
  }

  /**
   * Structural blocks that actually exist on this site.
   *
   * @return array<int,string>
   */
  public static function get_structural_blocks(): array {
    $registry = WP_Block_Type_Registry::get_instance();
    $always = apply_filters('ai_composer_always_allowed_blocks', self::ALWAYS_ALLOWED_BLOCKS);
    if (! is_array($always)) {
      $always = self::ALWAYS_ALLOWED_BLOCKS;
    }

    return array_values(array_filter($always, static function (string $name) use ($registry): bool {
      return $registry->is_registered($name);
    }));
  }

  /**
   * Core blocks are always enabled so users can't accidentally disable foundational
   * primitives the model relies on for composition.
   *
   * @return array<int,string>
   */
  public static function get_forced_enabled_blocks(): array {
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

  /**
   * Get the list of admin-enabled block names.
   * Empty = all blocks.
   * In selected mode, forced blocks (all composable core blocks) are always appended.
   *
   * @return array<int,string>
   */
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
    $settings = self::get_settings();
    return ($settings['theme_strategy_enabled'] ?? '1') === '1';
  }

  public static function get_system_prompt_prepend(): string {
    $settings = self::get_settings();
    return (string) ($settings['system_prompt_prepend'] ?? '');
  }

  public static function get_system_prompt_append(): string {
    $settings = self::get_settings();
    return (string) ($settings['system_prompt_append'] ?? '');
  }

  public static function provider_section_callback(): void {
    echo '<p>' . esc_html__('Enter your AI provider credentials. On WordPress 7.0+, this is managed in Settings → AI Credentials.', 'wp-intelligence') . '</p>';
  }

  public static function blocks_section_callback(): void {
    echo '<p>' . esc_html__('Choose which blocks the AI is allowed to use when composing pages. By default all registered blocks are available.', 'wp-intelligence') . '</p>';
  }

  public static function prompting_section_callback(): void {
    echo '<p>' . esc_html__('Add project-specific system prompt instructions and theme-aware strategy behavior.', 'wp-intelligence') . '</p>';
  }

  public static function render_api_key_field(): void {
    $options = self::get_settings();
    $value   = $options['api_key'] ?? '';
    printf(
      '<input type="password" id="ai_composer_api_key" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off">',
      esc_attr(self::OPTION_NAME),
      esc_attr($value)
    );
  }

  public static function render_model_field(): void {
    $options  = self::get_settings();
    $current  = $options['model'] ?? 'gpt-4.1';
    $models   = apply_filters('ai_composer_available_models', [
      'gpt-4.1'      => 'GPT-4.1',
      'gpt-4.1-mini' => 'GPT-4.1 Mini',
      'gpt-4o'       => 'GPT-4o',
      'gpt-4o-mini'  => 'GPT-4o Mini',
    ]);

    echo '<select id="ai_composer_model" name="' . esc_attr(self::OPTION_NAME) . '[model]" class="regular-text">';
    foreach ($models as $value => $label) {
      printf(
        '<option value="%s"%s>%s</option>',
        esc_attr($value),
        selected($current, $value, false),
        esc_html($label)
      );
    }
    echo '</select>';
  }

  public static function render_theme_strategy_field(): void {
    $enabled = self::is_theme_strategy_enabled();
    printf(
      '<label><input type="checkbox" name="%s[theme_strategy_enabled]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($enabled, true, false),
      esc_html__('Enable automatic theme-aware prompting (recommended).', 'wp-intelligence')
    );
    echo '<p class="description">' . esc_html__('When enabled, WP Intelligence adds strategy hints based on active theme and available block libraries (e.g. Nectar).', 'wp-intelligence') . '</p>';
  }

  public static function render_system_prompt_prepend_field(): void {
    $value = self::get_system_prompt_prepend();
    printf(
      '<textarea name="%s[system_prompt_prepend]" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
      esc_attr(self::OPTION_NAME),
      esc_attr__('Instructions inserted before base prompt rules. Use this for hard constraints.', 'wp-intelligence'),
      esc_textarea($value)
    );
  }

  public static function render_system_prompt_append_field(): void {
    $value = self::get_system_prompt_append();
    printf(
      '<textarea name="%s[system_prompt_append]" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
      esc_attr(self::OPTION_NAME),
      esc_attr__('Instructions appended after base prompt rules. Use this for soft preferences.', 'wp-intelligence'),
      esc_textarea($value)
    );
  }

  public static function render_block_selector(): void {
    $options    = self::get_settings();
    $mode       = $options['block_selection_mode'] ?? 'all';
    $enabled    = self::get_enabled_blocks();
    $option     = esc_attr(self::OPTION_NAME);
    $forced = self::get_forced_enabled_blocks();

    $registry   = WP_Block_Type_Registry::get_instance();
    $all_blocks = $registry->get_all_registered();
    ksort($all_blocks);

    $grouped = [];
    foreach ($all_blocks as $name => $block_type) {
      if (in_array($name, AI_Composer_Block_Catalog::EXCLUDED_BLOCKS, true)) {
        continue;
      }
      $prefix = explode('/', $name)[0] ?? 'other';
      $grouped[$prefix][$name] = $block_type;
    }
    ksort($grouped);

    ?>
    <fieldset>
      <label>
        <input type="radio" name="<?php echo $option; ?>[block_selection_mode]" value="all" <?php checked($mode, 'all'); ?> class="ai-composer-mode-radio">
        <?php esc_html_e('All blocks (auto-discover from registry)', 'wp-intelligence'); ?>
      </label>
      <br>
      <label>
        <input type="radio" name="<?php echo $option; ?>[block_selection_mode]" value="selected" <?php checked($mode, 'selected'); ?> class="ai-composer-mode-radio">
        <?php esc_html_e('Only selected blocks', 'wp-intelligence'); ?>
      </label>
    </fieldset>

    <p class="description" style="margin-top:8px;">
      <?php esc_html_e('Note: Composable core blocks are always enabled and cannot be toggled off.', 'wp-intelligence'); ?>
      <?php if (! empty($forced)) : ?>
        <code><?php echo esc_html(implode(', ', $forced)); ?></code>
      <?php endif; ?>
    </p>

    <div id="ai-composer-block-list" style="margin-top:16px; max-height:500px; overflow-y:auto; border:1px solid #ddd; padding:12px; background:#fafafa; <?php echo $mode === 'all' ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
      <p>
        <button type="button" class="button button-small" id="ai-composer-select-all"><?php esc_html_e('Select All', 'wp-intelligence'); ?></button>
        <button type="button" class="button button-small" id="ai-composer-select-none"><?php esc_html_e('Select None', 'wp-intelligence'); ?></button>
        <span style="margin-left:12px;color:#666;" id="ai-composer-count"></span>
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
              $checked       = ($mode === 'all') || in_array($name, $enabled, true) || $is_forced;
              $title         = $block_type->title ?: $name;
              $desc          = $block_type->description ?: '';
            ?>
              <label style="display:block;margin:3px 0;" title="<?php echo esc_attr($desc); ?>">
                <input
                  type="checkbox"
                  name="<?php echo $option; ?>[enabled_blocks][]"
                  value="<?php echo esc_attr($name); ?>"
                  <?php checked($checked); ?>
                  class="ai-composer-block-cb <?php echo $is_forced ? 'is-forced' : ''; ?>"
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
    (function() {
      var radios = document.querySelectorAll('.ai-composer-mode-radio');
      var list   = document.getElementById('ai-composer-block-list');
      var cbs    = document.querySelectorAll('.ai-composer-block-cb');
      var count  = document.getElementById('ai-composer-count');

      function updateCount() {
        var checked = document.querySelectorAll('.ai-composer-block-cb:checked').length;
        var total   = cbs.length;
        count.textContent = checked + ' / ' + total + ' blocks enabled';
      }

      radios.forEach(function(r) {
        r.addEventListener('change', function() {
          var isAll = this.value === 'all';
          list.style.opacity = isAll ? '0.5' : '1';
          list.style.pointerEvents = isAll ? 'none' : 'auto';
        });
      });

      var selectAll = document.getElementById('ai-composer-select-all');
      if (selectAll) {
        selectAll.addEventListener('click', function() {
          cbs.forEach(function(cb) {
            if (cb.disabled) return;
            cb.checked = true;
          });
          updateCount();
        });
      }

      var selectNone = document.getElementById('ai-composer-select-none');
      if (selectNone) {
        selectNone.addEventListener('click', function() {
          cbs.forEach(function(cb) {
            if (cb.disabled) return;
            cb.checked = false;
          });
          updateCount();
        });
      }

      cbs.forEach(function(cb) { cb.addEventListener('change', updateCount); });
      updateCount();
    })();
    </script>
    <?php
  }

  public static function syndication_section_callback(): void {
    echo '<p>' . esc_html__('Configure content syndication — fetch, rewrite, and import external articles into your posts.', 'wp-intelligence') . '</p>';
  }

  public static function render_syndication_post_types_field(): void {
    $synd    = self::get_syndication_settings();
    $enabled = (array) ($synd['enabled_post_types'] ?? []);
    $option  = esc_attr(self::OPTION_NAME);

    $post_types = get_post_types(['public' => true], 'objects');
    foreach ($post_types as $slug => $pt) {
      if ($slug === 'attachment') {
        continue;
      }
      $checked = in_array($slug, $enabled, true);
      printf(
        '<label style="display:block;margin:3px 0;"><input type="checkbox" name="%s[syndication][enabled_post_types][]" value="%s" %s> %s <code>(%s)</code></label>',
        $option,
        esc_attr($slug),
        checked($checked, true, false),
        esc_html($pt->labels->singular_name ?? $slug),
        esc_html($slug)
      );
    }
    echo '<p class="description">' . esc_html__('The syndication panel will appear in the editor for these post types. Leave all unchecked to disable.', 'wp-intelligence') . '</p>';
  }

  public static function render_syndication_taxonomy_field(): void {
    $synd    = self::get_syndication_settings();
    $current = (string) ($synd['source_taxonomy'] ?? '');
    $option  = esc_attr(self::OPTION_NAME);

    $taxonomies = get_taxonomies(['public' => true], 'objects');

    echo '<select name="' . $option . '[syndication][source_taxonomy]" class="regular-text">';
    echo '<option value="">' . esc_html__('— None —', 'wp-intelligence') . '</option>';
    foreach ($taxonomies as $slug => $tax) {
      printf(
        '<option value="%s"%s>%s (%s)</option>',
        esc_attr($slug),
        selected($current, $slug, false),
        esc_html($tax->labels->singular_name ?? $slug),
        esc_html($slug)
      );
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('When a source name is detected, a term will be created/assigned in this taxonomy.', 'wp-intelligence') . '</p>';
  }

  public static function render_syndication_prompt_field(): void {
    $synd  = self::get_syndication_settings();
    $value = (string) ($synd['system_prompt'] ?? '');
    $option = esc_attr(self::OPTION_NAME);

    printf(
      '<textarea name="%s[syndication][system_prompt]" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
      $option,
      esc_attr__('Leave blank to use the default syndication prompt. Override here if you want custom tone, language, or formatting.', 'wp-intelligence'),
      esc_textarea($value)
    );
  }

  public static function admin_experience_section_callback(): void {
    echo '<p>' . esc_html__('White-label and streamline the WordPress admin. Themes can extend these via filters.', 'wp-intelligence') . '</p>';
  }

  public static function render_admin_remove_wp_logo_field(): void {
    $ae = self::get_admin_experience_settings();
    $checked = ($ae['remove_wp_logo'] ?? '0') === '1';
    printf(
      '<label><input type="checkbox" name="%s[admin_experience][remove_wp_logo]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($checked, true, false),
      esc_html__('Remove the WordPress logo from the admin bar.', 'wp-intelligence')
    );
  }

  public static function render_admin_login_logo_field(): void {
    $ae = self::get_admin_experience_settings();
    $checked = ($ae['custom_login_logo'] ?? '0') === '1';
    printf(
      '<label><input type="checkbox" name="%s[admin_experience][custom_login_logo]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($checked, true, false),
      esc_html__('Enable custom login logo (provide via the ai_composer_login_logo filter).', 'wp-intelligence')
    );
  }

  public static function render_admin_hide_notices_field(): void {
    $ae = self::get_admin_experience_settings();
    $checked = ($ae['hide_update_notices'] ?? '0') === '1';
    printf(
      '<label><input type="checkbox" name="%s[admin_experience][hide_update_notices]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($checked, true, false),
      esc_html__('Hide update notices for non-admin users.', 'wp-intelligence')
    );
  }

  public static function render_admin_footer_field(): void {
    $ae = self::get_admin_experience_settings();
    $checked = ($ae['custom_footer'] ?? '0') === '1';
    printf(
      '<label><input type="checkbox" name="%s[admin_experience][custom_footer]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($checked, true, false),
      esc_html__('Replace admin footer text (provide via the ai_composer_admin_footer_text filter).', 'wp-intelligence')
    );
  }

  public static function render_admin_menu_reorg_field(): void {
    $ae = self::get_admin_experience_settings();
    $checked = ($ae['menu_reorganization'] ?? '0') === '1';
    printf(
      '<label><input type="checkbox" name="%s[admin_experience][menu_reorganization]" value="1" %s> %s</label>',
      esc_attr(self::OPTION_NAME),
      checked($checked, true, false),
      esc_html__('Enable admin menu reorganization (provide config via ai_composer_admin_menu_config and ai_composer_admin_submenu_moves filters).', 'wp-intelligence')
    );
  }

  public static function render_admin_editor_enhancements_field(): void {
    $ae = self::get_admin_experience_settings();
    $option = esc_attr(self::OPTION_NAME);

    $enhancements = ($ae['editor_enhancements'] ?? '0') === '1';
    $autocomplete = ($ae['class_autocomplete'] ?? '0') === '1';
    $fullscreen   = ($ae['force_fullscreen'] ?? '0') === '1';
    $listview     = ($ae['auto_open_list_view'] ?? '0') === '1';
    $label        = (string) ($ae['list_view_label'] ?? '');

    printf(
      '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%s[admin_experience][editor_enhancements]" value="1" %s> %s</label>',
      $option, checked($enhancements, true, false),
      esc_html__('Enable block editor enhancements (master toggle).', 'wp-intelligence')
    );
    printf(
      '<label style="display:block;margin:3px 0 3px 24px;"><input type="checkbox" name="%s[admin_experience][class_autocomplete]" value="1" %s> %s</label>',
      $option, checked($autocomplete, true, false),
      esc_html__('CSS class autocomplete in Advanced panel (provide class list via ai_composer_class_autocomplete_source filter).', 'wp-intelligence')
    );
    printf(
      '<label style="display:block;margin:3px 0 3px 24px;"><input type="checkbox" name="%s[admin_experience][force_fullscreen]" value="1" %s> %s</label>',
      $option, checked($fullscreen, true, false),
      esc_html__('Force fullscreen mode on editor load.', 'wp-intelligence')
    );
    printf(
      '<label style="display:block;margin:3px 0 3px 24px;"><input type="checkbox" name="%s[admin_experience][auto_open_list_view]" value="1" %s> %s</label>',
      $option, checked($listview, true, false),
      esc_html__('Auto-open the list view panel.', 'wp-intelligence')
    );
    printf(
      '<div style="margin:6px 0 0 24px;"><label>%s<br><input type="text" name="%s[admin_experience][list_view_label]" value="%s" class="regular-text" placeholder="e.g. Elements"></label></div>',
      esc_html__('List view tab label override:', 'wp-intelligence'),
      $option,
      esc_attr($label)
    );
  }

  public static function render_page(): void {
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('WP Intelligence Settings', 'wp-intelligence'); ?></h1>
      <?php if (self::has_native_ai_client()) : ?>
        <div class="notice notice-info inline" style="margin:12px 0;">
          <p><?php esc_html_e('Your site has the native WordPress AI Client. API credentials are managed in Settings → AI Credentials.', 'wp-intelligence'); ?></p>
        </div>
      <?php endif; ?>
      <form action="options.php" method="post">
        <?php
          settings_fields(self::OPTION_GROUP);
          do_settings_sections(self::PAGE_SLUG);
          submit_button();
        ?>
      </form>
    </div>
    <?php
  }
}
