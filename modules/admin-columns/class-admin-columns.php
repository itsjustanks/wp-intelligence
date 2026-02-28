<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Admin list-table enhancements: permalink column, template column,
 * post type switcher (metabox, quick edit, bulk edit).
 *
 * Theme-agnostic. Hooks into all public post types automatically.
 */
class WP_Intelligence_Admin_Columns {

  private const COLUMN_PERMALINK = 'wpi_permalink';
  private const COLUMN_TEMPLATE  = 'wpi_template';
  private const NONCE_ACTION     = 'wpi_switch_post_type';
  private const NONCE_FIELD      = 'wpi_switch_post_type_nonce';
  private const POST_FIELD       = 'wpi_target_post_type';
  private const METABOX_ID       = 'wpi_post_type_switcher';

  public static function init(): void {
    add_action('admin_init', [self::class, 'register_column_hooks']);
    add_action('admin_head', [self::class, 'column_styles']);
    add_action('add_meta_boxes', [self::class, 'register_post_type_switcher']);
    add_action('quick_edit_custom_box', [self::class, 'quick_edit_field'], 10, 2);
    add_action('bulk_edit_custom_box', [self::class, 'bulk_edit_field'], 10, 2);
    add_action('save_post', [self::class, 'handle_post_type_switch'], 20, 2);
  }

  /**
   * @return array<int,string>
   */
  public static function get_enhanced_post_types(): array {
    $post_types = get_post_types([
      'public'  => true,
      'show_ui' => true,
    ], 'names');

    $excluded = apply_filters('wpi_admin_columns_excluded_post_types', ['attachment']);

    return array_values(array_diff($post_types, $excluded));
  }

  /**
   * @return array<string,WP_Post_Type>
   */
  public static function get_switchable_post_types(): array {
    $result = [];

    foreach (self::get_enhanced_post_types() as $pt) {
      $obj = get_post_type_object($pt);
      if (! $obj || ! isset($obj->cap)) {
        continue;
      }

      $cap = $obj->cap->create_posts ?? $obj->cap->edit_posts;
      if (! current_user_can($cap)) {
        continue;
      }

      $result[$pt] = $obj;
    }

    return $result;
  }

  public static function register_column_hooks(): void {
    foreach (self::get_enhanced_post_types() as $pt) {
      $filter = ($pt === 'page') ? 'manage_pages_columns' : sprintf('manage_%s_posts_columns', $pt);
      $action = sprintf('manage_%s_posts_custom_column', $pt);

      add_filter($filter, [self::class, 'add_columns']);
      add_action($action, [self::class, 'render_column'], 10, 2);
    }
  }

  /**
   * @param array<string,string> $columns
   * @return array<string,string>
   */
  public static function add_columns(array $columns): array {
    if (isset($columns[self::COLUMN_PERMALINK]) && isset($columns[self::COLUMN_TEMPLATE])) {
      return $columns;
    }

    $new = [];

    foreach ($columns as $key => $label) {
      $new[$key] = $label;

      if ($key === 'title') {
        if (! isset($columns[self::COLUMN_PERMALINK])) {
          $new[self::COLUMN_PERMALINK] = __('Permalink', 'wp-intelligence');
        }
        if (! isset($columns[self::COLUMN_TEMPLATE])) {
          $new[self::COLUMN_TEMPLATE] = __('Template', 'wp-intelligence');
        }
      }
    }

    if (! isset($new[self::COLUMN_PERMALINK])) {
      $new[self::COLUMN_PERMALINK] = __('Permalink', 'wp-intelligence');
    }
    if (! isset($new[self::COLUMN_TEMPLATE])) {
      $new[self::COLUMN_TEMPLATE] = __('Template', 'wp-intelligence');
    }

    return $new;
  }

  public static function render_column(string $column, int $post_id): void {
    if ($column === self::COLUMN_PERMALINK) {
      $permalink = get_permalink($post_id);
      if (! $permalink) {
        echo '&mdash;';
        return;
      }

      $relative = wp_make_link_relative($permalink);
      $display  = untrailingslashit($relative) ?: '/';

      printf(
        '<a href="%1$s" target="_blank" rel="noopener noreferrer"><code>%2$s</code></a>',
        esc_url($permalink),
        esc_html($display)
      );
      return;
    }

    if ($column !== self::COLUMN_TEMPLATE) {
      return;
    }

    $label = self::get_template_label($post_id);
    echo $label !== '' ? esc_html($label) : '&mdash;';
  }

  public static function get_template_label(int $post_id): string {
    $post = get_post($post_id);
    if (! $post instanceof WP_Post) {
      return '';
    }

    $slug      = get_page_template_slug($post_id);
    $templates = wp_get_theme()->get_page_templates($post, $post->post_type);
    $has       = is_array($templates) && ! empty($templates);

    if (! $has && ($slug === '' || $slug === false)) {
      return '';
    }

    if ($slug === '' || $slug === false || $slug === 'default') {
      return 'Default';
    }

    if ($has && isset($templates[$slug])) {
      return (string) $templates[$slug];
    }

    return (string) $slug;
  }

  public static function column_styles(): void {
    $screen = get_current_screen();
    if (! $screen || $screen->base !== 'edit') {
      return;
    }

    $p = self::COLUMN_PERMALINK;
    $t = self::COLUMN_TEMPLATE;
    echo "<style>.column-{$p}{width:22%;word-break:break-all;}.column-{$t}{width:14%;}</style>";
  }

  public static function register_post_type_switcher(): void {
    foreach (self::get_enhanced_post_types() as $pt) {
      add_meta_box(
        self::METABOX_ID,
        __('Post Type', 'wp-intelligence'),
        [self::class, 'render_switcher_metabox'],
        $pt,
        'side',
        'high'
      );
    }
  }

  public static function render_switcher_metabox(WP_Post $post): void {
    wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

    $types = self::get_switchable_post_types();

    echo '<p><label for="' . esc_attr(self::POST_FIELD) . '" class="screen-reader-text">'
       . esc_html__('Post Type', 'wp-intelligence') . '</label>';
    echo '<select id="' . esc_attr(self::POST_FIELD) . '" name="' . esc_attr(self::POST_FIELD) . '" style="width:100%;">';

    foreach ($types as $pt => $obj) {
      printf(
        '<option value="%1$s" %2$s>%3$s</option>',
        esc_attr($pt),
        selected($post->post_type, $pt, false),
        esc_html($obj->labels->singular_name)
      );
    }

    echo '</select></p>';
    echo '<p class="description">' . esc_html__('Choose another type and update to move this content.', 'wp-intelligence') . '</p>';
  }

  public static function quick_edit_field(string $column_name, string $post_type): void {
    if ($column_name !== self::COLUMN_PERMALINK) {
      return;
    }
    if (! in_array($post_type, self::get_enhanced_post_types(), true)) {
      return;
    }

    self::render_inline_edit_field($post_type, false);
  }

  public static function bulk_edit_field(string $column_name, string $post_type): void {
    if ($column_name !== self::COLUMN_PERMALINK) {
      return;
    }
    if (! in_array($post_type, self::get_enhanced_post_types(), true)) {
      return;
    }

    self::render_inline_edit_field($post_type, true);
  }

  private static function render_inline_edit_field(string $post_type, bool $is_bulk): void {
    $types = self::get_switchable_post_types();
    if (empty($types)) {
      return;
    }

    echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col">';
    echo '<label class="alignleft"><span class="title">' . esc_html__('Post Type', 'wp-intelligence') . '</span>';
    echo '<select name="' . esc_attr(self::POST_FIELD) . '">';

    if ($is_bulk) {
      echo '<option value="">&mdash; ' . esc_html__('No Change', 'wp-intelligence') . ' &mdash;</option>';
    }

    foreach ($types as $pt => $obj) {
      if ($is_bulk) {
        printf('<option value="%1$s">%2$s</option>', esc_attr($pt), esc_html($obj->labels->singular_name));
      } else {
        printf(
          '<option value="%1$s" %2$s>%3$s</option>',
          esc_attr($pt),
          selected($post_type, $pt, false),
          esc_html($obj->labels->singular_name)
        );
      }
    }

    echo '</select></label>';
    $hint = $is_bulk
      ? __('Apply a new type to all selected posts.', 'wp-intelligence')
      : __('Change type and click Update.', 'wp-intelligence');
    echo '<p class="description">' . esc_html($hint) . '</p>';
    echo '</div></fieldset>';
  }

  public static function handle_post_type_switch(int $post_id, WP_Post $post): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }
    if (wp_is_post_revision($post_id)) {
      return;
    }

    $valid_nonce = false;

    if (isset($_POST[self::NONCE_FIELD])) {
      $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));
      $valid_nonce = (bool) wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    if (! $valid_nonce && isset($_POST['_inline_edit'])) {
      $inline_nonce = sanitize_text_field(wp_unslash($_POST['_inline_edit']));
      $valid_nonce = (bool) wp_verify_nonce($inline_nonce, 'inlineeditnonce');
    }

    if (! $valid_nonce) {
      return;
    }
    if (! current_user_can('edit_post', $post_id)) {
      return;
    }
    if (! isset($_POST[self::POST_FIELD])) {
      return;
    }

    $target = sanitize_key(wp_unslash($_POST[self::POST_FIELD]));
    if ($target === '' || $target === $post->post_type) {
      return;
    }

    $allowed = self::get_switchable_post_types();
    if (! array_key_exists($target, $allowed)) {
      return;
    }

    remove_action('save_post', [self::class, 'handle_post_type_switch'], 20);
    wp_update_post(['ID' => $post_id, 'post_type' => $target]);
    add_action('save_post', [self::class, 'handle_post_type_switch'], 20, 2);
  }
}
