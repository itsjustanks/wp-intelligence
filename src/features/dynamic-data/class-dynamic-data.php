<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Dynamic Data — orchestrator.
 *
 * Coordinates pre-fetch data sources, merge tag resolution,
 * block visibility integration, and editor assets.
 *
 * Merge tag syntax:
 *   {{wp.post.title}}                                    — WordPress post title
 *   {{wp.user.display_name}}                             — current user name
 *   {{wp.site.name}}                                     — site name
 *   {{wp.acf.field_name}}                                — ACF field value
 *   {{url.param_name}}                                   — URL query parameter
 *   {{cookie.cookie_name}}                               — cookie value
 *   {{storage.key}}                                      — localStorage / sessionStorage
 *   {{webhook_name.field.path}}                          — pre-fetched webhook data
 *   {{source.field|fallback}}                            — with fallback value
 *
 * Conditional syntax:
 *   {{#if source.field}}...{{/if}}                       — truthy check
 *   {{#if source.field}}...{{#else}}...{{/if}}           — if/else
 *   {{#if source.field == "value"}}...{{/if}}            — equality
 *   {{#if source.field != "value"}}...{{/if}}            — inequality
 *   {{#if source.field > "42"}}...{{/if}}                — numeric comparison
 *   {{#if source.field contains "text"}}...{{/if}}       — substring check
 */
class WPI_Dynamic_Data {

  private static bool $booted = false;
  private static bool $frontend_enqueued = false;

  public static function boot(): void {
    if (self::$booted) {
      return;
    }
    self::$booted = true;

    add_action('init', [self::class, 'register_sources'], 5);
    add_action('rest_api_init', ['WPI_Dynamic_Data_REST_Controller', 'register_routes']);
    add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
    add_filter('render_block', [self::class, 'resolve_merge_tags_in_block'], 8, 2);

    WPI_Dynamic_Data_Visibility::init();

    do_action('wpi_dynamic_data_init');
  }

  /**
   * Register built-in data sources and load saved webhooks.
   */
  public static function register_sources(): void {
    $registry = WPI_Data_Source_Registry::instance();

    $registry->register('wp', new WPI_WordPress_Source());
    $registry->register('url', new WPI_URL_Params_Source());
    $registry->register('cookie', new WPI_Cookie_Source());
    $registry->register('storage', new WPI_Storage_Source());

    WPI_Webhook_Source::register_all();

    do_action('wpi_dynamic_data_register_sources', $registry);
  }

  /**
   * Resolve merge tags in block content during frontend rendering.
   *
   * Hooked at priority 8 so it runs before block visibility (priority 10).
   * Server-side tags are resolved immediately. Client-side tags (storage)
   * are wrapped in placeholder elements for frontend JS resolution.
   */
  public static function resolve_merge_tags_in_block(string $block_content, array $block): string {
    if ($block_content === '' || strpos($block_content, '{{') === false) {
      return $block_content;
    }

    if (is_admin() && ! wp_doing_ajax()) {
      return $block_content;
    }

    $context = [
      'post_id' => get_the_ID() ?: 0,
    ];

    $context = apply_filters('wpi_dynamic_data_render_context', $context, $block);

    $resolved = WPI_Merge_Tag_Engine::resolve($block_content, $context, true);

    if (WPI_Merge_Tag_Engine::has_client_side_content($resolved)) {
      self::enqueue_frontend_assets();
    }

    return $resolved;
  }

  /**
   * Enqueue frontend JS and anti-FOUC CSS for client-side resolution.
   *
   * Uses a static flag to ensure assets are only enqueued once per request.
   */
  public static function enqueue_frontend_assets(): void {
    if (self::$frontend_enqueued) {
      return;
    }
    self::$frontend_enqueued = true;

    if (! defined('WPI_URL') || ! defined('WPI_DIR')) {
      return;
    }

    $js_path = WPI_DIR . '/src/features/dynamic-data/editor/dynamic-data-frontend.js';
    if (file_exists($js_path)) {
      wp_enqueue_script(
        'wpi-dynamic-data-frontend',
        WPI_URL . 'src/features/dynamic-data/editor/dynamic-data-frontend.js',
        [],
        filemtime($js_path),
        ['strategy' => 'defer']
      );
    }

    wp_register_style('wpi-dynamic-data-frontend', false, [], '1.0');
    wp_enqueue_style('wpi-dynamic-data-frontend');
    wp_add_inline_style('wpi-dynamic-data-frontend',
      '.wpi-dd-vis-pending { display: none !important; }'
    );
  }

  /**
   * Enqueue editor assets for the merge tag picker.
   */
  public static function enqueue_editor_assets(): void {
    if (! defined('WPI_URL') || ! defined('WPI_DIR')) {
      return;
    }

    $js_path = WPI_DIR . '/src/features/dynamic-data/editor/dynamic-data.js';
    if (! file_exists($js_path)) {
      return;
    }

    wp_enqueue_script(
      'wpi-dynamic-data',
      WPI_URL . 'src/features/dynamic-data/editor/dynamic-data.js',
      [
        'wp-plugins',
        'wp-edit-post',
        'wp-element',
        'wp-components',
        'wp-data',
        'wp-block-editor',
        'wp-api-fetch',
        'wp-i18n',
        'wp-rich-text',
        'wp-compose',
      ],
      filemtime($js_path),
      true
    );

    $css_path = WPI_DIR . '/src/features/dynamic-data/editor/dynamic-data.css';
    if (file_exists($css_path)) {
      wp_enqueue_style(
        'wpi-dynamic-data',
        WPI_URL . 'src/features/dynamic-data/editor/dynamic-data.css',
        [],
        filemtime($css_path)
      );
    }

    $registry = WPI_Data_Source_Registry::instance();

    wp_localize_script('wpi-dynamic-data', 'wpiDynamicDataConfig', [
      'restNamespace' => 'wpi-dynamic-data/v1',
      'nonce'         => wp_create_nonce('wp_rest'),
      'sources'       => $registry->get_source_descriptions(),
      'tags'          => WPI_Merge_Tag_Engine::get_available_tags(),
    ]);
  }

  /**
   * Sanitize dynamic data settings from the admin form.
   */
  public static function sanitize(array $input): array {
    $clean = [];

    if (isset($input['webhooks']) && is_array($input['webhooks'])) {
      $webhooks = [];
      foreach ($input['webhooks'] as $name => $config) {
        $name = sanitize_key($name);
        if ($name === '' || in_array($name, ['wp', 'url', 'cookie', 'storage'], true)) {
          continue;
        }
        if (is_array($config)) {
          $webhooks[$name] = WPI_Webhook_Source::sanitize_config($config);
        }
      }
      update_option('wpi_dynamic_data_webhooks', $webhooks, false);
    }

    return $clean;
  }

  /**
   * Render the settings tab content.
   */
  public static function render_settings_tab(): void {
    $webhooks = WPI_Webhook_Source::get_all_configs();
    $tags     = WPI_Merge_Tag_Engine::get_available_tags();

    ?>
    <div class="wpi-dynamic-data-settings">

      <div class="wpi-settings-card">
        <div class="wpi-card-header">
          <span class="dashicons dashicons-cloud"></span>
          <h3><?php esc_html_e('Webhook Data Sources', 'wp-intelligence'); ?></h3>
        </div>
        <div class="wpi-card-body">
          <p class="description">
            <?php esc_html_e('Define external API endpoints to pre-fetch data. The fetched data can be used in merge tags and block visibility conditions.', 'wp-intelligence'); ?>
          </p>

          <div id="wpi-webhooks-list">
            <?php if (empty($webhooks)) : ?>
              <p class="wpi-empty-state">
                <?php esc_html_e('No webhook data sources configured yet.', 'wp-intelligence'); ?>
              </p>
            <?php else : ?>
              <table class="wp-list-table widefat striped">
                <thead>
                  <tr>
                    <th><?php esc_html_e('Name', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('Label', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('URL', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('Method', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('Auth', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('Cache', 'wp-intelligence'); ?></th>
                    <th><?php esc_html_e('Actions', 'wp-intelligence'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($webhooks as $name => $config) : ?>
                    <tr>
                      <td><code><?php echo esc_html($name); ?></code></td>
                      <td><?php echo esc_html($config['label'] ?? $name); ?></td>
                      <td><code><?php echo esc_html(mb_substr($config['url'] ?? '', 0, 60)); ?></code></td>
                      <td><?php echo esc_html($config['method'] ?? 'GET'); ?></td>
                      <td><?php echo esc_html($config['auth_type'] ?? 'none'); ?></td>
                      <td><?php echo esc_html(($config['cache_ttl'] ?? 300) . 's'); ?></td>
                      <td>
                        <button type="button" class="button button-small wpi-test-webhook" data-name="<?php echo esc_attr($name); ?>">
                          <?php esc_html_e('Test', 'wp-intelligence'); ?>
                        </button>
                        <button type="button" class="button button-small wpi-delete-webhook" data-name="<?php echo esc_attr($name); ?>">
                          <?php esc_html_e('Delete', 'wp-intelligence'); ?>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <div class="wpi-webhook-form" style="margin-top: 20px;">
            <h4><?php esc_html_e('Add Webhook Data Source', 'wp-intelligence'); ?></h4>

            <table class="form-table">
              <tr>
                <th><label for="wpi-webhook-name"><?php esc_html_e('Name (slug)', 'wp-intelligence'); ?></label></th>
                <td>
                  <input type="text" id="wpi-webhook-name" class="regular-text" placeholder="my_api" pattern="[a-z0-9_]+" />
                  <p class="description"><?php esc_html_e('Lowercase letters, numbers, underscores. Used in merge tags as {{name.field}}.', 'wp-intelligence'); ?></p>
                </td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-label"><?php esc_html_e('Label', 'wp-intelligence'); ?></label></th>
                <td><input type="text" id="wpi-webhook-label" class="regular-text" placeholder="My API" /></td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-url"><?php esc_html_e('Endpoint URL', 'wp-intelligence'); ?></label></th>
                <td>
                  <input type="url" id="wpi-webhook-url" class="large-text" placeholder="https://api.example.com/data" />
                  <p class="description"><?php esc_html_e('Supports {{url.param}} for dynamic URL parameters.', 'wp-intelligence'); ?></p>
                </td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-method"><?php esc_html_e('Method', 'wp-intelligence'); ?></label></th>
                <td>
                  <select id="wpi-webhook-method">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-auth-type"><?php esc_html_e('Authentication', 'wp-intelligence'); ?></label></th>
                <td>
                  <select id="wpi-webhook-auth-type">
                    <option value="none"><?php esc_html_e('None', 'wp-intelligence'); ?></option>
                    <option value="bearer"><?php esc_html_e('Bearer Token', 'wp-intelligence'); ?></option>
                    <option value="basic"><?php esc_html_e('Basic Auth', 'wp-intelligence'); ?></option>
                    <option value="api_key"><?php esc_html_e('API Key Header', 'wp-intelligence'); ?></option>
                  </select>
                </td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-auth-value"><?php esc_html_e('Auth Credential', 'wp-intelligence'); ?></label></th>
                <td>
                  <input type="password" id="wpi-webhook-auth-value" class="regular-text" />
                  <p class="description"><?php esc_html_e('Token, user:password, or API key depending on auth type.', 'wp-intelligence'); ?></p>
                </td>
              </tr>
              <tr>
                <th><label for="wpi-webhook-cache-ttl"><?php esc_html_e('Cache Duration', 'wp-intelligence'); ?></label></th>
                <td>
                  <input type="number" id="wpi-webhook-cache-ttl" value="300" min="0" max="86400" class="small-text" />
                  <span><?php esc_html_e('seconds (0 = no cache)', 'wp-intelligence'); ?></span>
                </td>
              </tr>
            </table>

            <p>
              <button type="button" id="wpi-add-webhook" class="button button-primary">
                <?php esc_html_e('Add Webhook', 'wp-intelligence'); ?>
              </button>
              <button type="button" id="wpi-test-new-webhook" class="button">
                <?php esc_html_e('Test Connection', 'wp-intelligence'); ?>
              </button>
              <span id="wpi-webhook-status" style="margin-left: 10px;"></span>
            </p>
          </div>
        </div>
      </div>

      <div class="wpi-settings-card" style="margin-top: 20px;">
        <div class="wpi-card-header">
          <span class="dashicons dashicons-tag"></span>
          <h3><?php esc_html_e('Available Merge Tags', 'wp-intelligence'); ?></h3>
        </div>
        <div class="wpi-card-body">
          <p class="description">
            <?php esc_html_e('Use these merge tags in your block content. They will be replaced with dynamic values on the frontend.', 'wp-intelligence'); ?>
          </p>

          <?php
          $groups = [];
          foreach ($tags as $tag) {
            $group = $tag['group'] ?? __('Other', 'wp-intelligence');
            $groups[$group][] = $tag;
          }
          ?>

          <div class="wpi-merge-tags-reference">
            <?php foreach ($groups as $group_name => $group_tags) : ?>
              <div class="wpi-tag-group">
                <h4><?php echo esc_html($group_name); ?></h4>
                <table class="wp-list-table widefat">
                  <tbody>
                    <?php foreach ($group_tags as $tag) : ?>
                      <tr>
                        <td><code>{{<?php echo esc_html($tag['tag']); ?>}}</code></td>
                        <td>
                          <?php echo esc_html($tag['label']); ?>
                          <?php if (! empty($tag['clientSide'])) : ?>
                            <span class="wpi-client-badge"><?php esc_html_e('client-side', 'wp-intelligence'); ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>

    <script>
    (function() {
      var restUrl = '<?php echo esc_url(rest_url('wpi-dynamic-data/v1')); ?>';
      var nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';

      function apiRequest(method, endpoint, data) {
        var opts = {
          method: method,
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        };
        if (data) opts.body = JSON.stringify(data);
        return fetch(restUrl + endpoint, opts).then(function(r) { return r.json(); });
      }

      var addBtn = document.getElementById('wpi-add-webhook');
      var testBtn = document.getElementById('wpi-test-new-webhook');
      var status = document.getElementById('wpi-webhook-status');

      function getFormData() {
        return {
          name: document.getElementById('wpi-webhook-name').value,
          label: document.getElementById('wpi-webhook-label').value,
          url: document.getElementById('wpi-webhook-url').value,
          method: document.getElementById('wpi-webhook-method').value,
          auth_type: document.getElementById('wpi-webhook-auth-type').value,
          auth_value: document.getElementById('wpi-webhook-auth-value').value,
          cache_ttl: parseInt(document.getElementById('wpi-webhook-cache-ttl').value, 10) || 300,
        };
      }

      if (addBtn) {
        addBtn.addEventListener('click', function() {
          var data = getFormData();
          if (!data.name || !data.url) {
            status.textContent = '<?php echo esc_js(__('Name and URL are required.', 'wp-intelligence')); ?>';
            return;
          }
          status.textContent = '<?php echo esc_js(__('Creating...', 'wp-intelligence')); ?>';
          apiRequest('POST', '/webhooks', data).then(function(res) {
            status.textContent = res.message || (res.success ? 'Created!' : 'Error');
            if (res.success) location.reload();
          }).catch(function() { status.textContent = 'Error'; });
        });
      }

      if (testBtn) {
        testBtn.addEventListener('click', function() {
          var data = getFormData();
          if (!data.url) {
            status.textContent = '<?php echo esc_js(__('URL is required.', 'wp-intelligence')); ?>';
            return;
          }
          status.textContent = '<?php echo esc_js(__('Testing...', 'wp-intelligence')); ?>';
          apiRequest('POST', '/test', data).then(function(res) {
            if (res.success) {
              status.textContent = '<?php echo esc_js(__('Success! Found ', 'wp-intelligence')); ?>' + (res.fields ? res.fields.length : 0) + ' fields.';
              console.log('Webhook test response:', res.data);
              console.log('Discovered fields:', res.fields);
            } else {
              status.textContent = res.error || 'Error';
            }
          }).catch(function() { status.textContent = 'Connection error'; });
        });
      }

      document.querySelectorAll('.wpi-delete-webhook').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var name = this.dataset.name;
          if (!confirm('<?php echo esc_js(__('Delete webhook "', 'wp-intelligence')); ?>' + name + '"?')) return;
          apiRequest('DELETE', '/webhooks/' + name).then(function() { location.reload(); });
        });
      });

      document.querySelectorAll('.wpi-test-webhook').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var name = this.dataset.name;
          this.textContent = '<?php echo esc_js(__('Testing...', 'wp-intelligence')); ?>';
          var self = this;
          apiRequest('POST', '/test', { name: name, url: '' }).then(function(res) {
            self.textContent = res.success ? '✓' : '✗';
            if (res.success) console.log('Test response for ' + name + ':', res.data);
          }).catch(function() { self.textContent = '✗'; });
        });
      });
    })();
    </script>
    <?php
  }
}
