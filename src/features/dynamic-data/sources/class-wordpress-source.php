<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * WordPress Data Source — provides merge tags for post, user, and site data.
 *
 * Tags:
 *   {{wp.post.title}}          — current post title
 *   {{wp.post.excerpt}}        — current post excerpt
 *   {{wp.post.date}}           — post publish date
 *   {{wp.post.author}}         — post author display name
 *   {{wp.post.type}}           — post type
 *   {{wp.post.id}}             — post ID
 *   {{wp.post.url}}            — post permalink
 *   {{wp.post.slug}}           — post slug
 *   {{wp.post.status}}         — post status
 *   {{wp.post.meta.KEY}}       — post meta value
 *   {{wp.user.display_name}}   — current user display name
 *   {{wp.user.email}}          — current user email
 *   {{wp.user.login}}          — current user login
 *   {{wp.user.id}}             — current user ID
 *   {{wp.user.role}}           — current user primary role
 *   {{wp.user.meta.KEY}}       — user meta value
 *   {{wp.site.name}}           — site name
 *   {{wp.site.description}}    — site tagline
 *   {{wp.site.url}}            — site URL
 *   {{wp.site.admin_email}}    — admin email
 *   {{wp.site.language}}       — site language
 *   {{wp.acf.FIELD_NAME}}      — ACF field (if ACF active)
 */
class WPI_WordPress_Source implements WPI_Data_Source_Interface {

  public function get_label(): string {
    return __('WordPress', 'wp-intelligence');
  }

  public function get_type(): string {
    return 'wordpress';
  }

  public function is_client_side(): bool {
    return false;
  }

  public function fetch(array $context = []): array {
    $post_id = $context['post_id'] ?? get_the_ID();
    $post    = $post_id ? get_post($post_id) : null;
    $user    = wp_get_current_user();

    $data = [
      'post' => $this->get_post_data($post),
      'user' => $this->get_user_data($user),
      'site' => $this->get_site_data(),
    ];

    if (function_exists('get_field') && $post_id) {
      $data['acf'] = $this->get_acf_data($post_id);
    }

    return $data;
  }

  private function get_post_data(?\WP_Post $post): array {
    if (! $post) {
      return [];
    }

    $data = [
      'id'      => $post->ID,
      'title'   => get_the_title($post),
      'excerpt' => get_the_excerpt($post),
      'date'    => get_the_date('', $post),
      'author'  => get_the_author_meta('display_name', $post->post_author),
      'type'    => $post->post_type,
      'url'     => get_permalink($post),
      'slug'    => $post->post_name,
      'status'  => $post->post_status,
      'meta'    => [],
    ];

    $meta_keys = apply_filters('wpi_dynamic_data_post_meta_keys', [], $post->ID);
    foreach ($meta_keys as $key) {
      $data['meta'][$key] = get_post_meta($post->ID, $key, true);
    }

    return $data;
  }

  private function get_user_data(?\WP_User $user): array {
    if (! $user || ! $user->exists()) {
      return [
        'id'           => 0,
        'display_name' => '',
        'email'        => '',
        'login'        => '',
        'role'         => '',
        'meta'         => [],
      ];
    }

    $roles = $user->roles;
    $data = [
      'id'           => $user->ID,
      'display_name' => $user->display_name,
      'email'        => $user->user_email,
      'login'        => $user->user_login,
      'role'         => ! empty($roles) ? reset($roles) : '',
      'meta'         => [],
    ];

    $meta_keys = apply_filters('wpi_dynamic_data_user_meta_keys', [], $user->ID);
    foreach ($meta_keys as $key) {
      $data['meta'][$key] = get_user_meta($user->ID, $key, true);
    }

    return $data;
  }

  private function get_site_data(): array {
    return [
      'name'        => get_bloginfo('name'),
      'description' => get_bloginfo('description'),
      'url'         => home_url(),
      'admin_email' => get_option('admin_email'),
      'language'    => get_locale(),
    ];
  }

  private function get_acf_data(int $post_id): array {
    $data = [];
    if (! function_exists('get_fields')) {
      return $data;
    }

    $fields = get_fields($post_id);
    if (is_array($fields)) {
      foreach ($fields as $name => $value) {
        if (is_scalar($value) || is_null($value)) {
          $data[$name] = $value;
        } elseif (is_array($value)) {
          $data[$name] = $value;
        }
      }
    }

    return $data;
  }

  public function get_available_tags(): array {
    $tags = [
      ['tag' => 'wp.post.title',       'label' => __('Post Title', 'wp-intelligence'),          'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.excerpt',     'label' => __('Post Excerpt', 'wp-intelligence'),        'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.date',        'label' => __('Post Date', 'wp-intelligence'),           'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.author',      'label' => __('Post Author', 'wp-intelligence'),         'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.type',        'label' => __('Post Type', 'wp-intelligence'),           'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.id',          'label' => __('Post ID', 'wp-intelligence'),             'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.url',         'label' => __('Post URL', 'wp-intelligence'),            'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.slug',        'label' => __('Post Slug', 'wp-intelligence'),           'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.status',      'label' => __('Post Status', 'wp-intelligence'),         'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.post.meta.KEY',    'label' => __('Post Meta (specify key)', 'wp-intelligence'), 'group' => __('Post', 'wp-intelligence')],
      ['tag' => 'wp.user.display_name','label' => __('User Display Name', 'wp-intelligence'),   'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.user.email',       'label' => __('User Email', 'wp-intelligence'),          'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.user.login',       'label' => __('User Login', 'wp-intelligence'),          'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.user.id',          'label' => __('User ID', 'wp-intelligence'),             'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.user.role',        'label' => __('User Role', 'wp-intelligence'),           'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.user.meta.KEY',    'label' => __('User Meta (specify key)', 'wp-intelligence'), 'group' => __('User', 'wp-intelligence')],
      ['tag' => 'wp.site.name',        'label' => __('Site Name', 'wp-intelligence'),           'group' => __('Site', 'wp-intelligence')],
      ['tag' => 'wp.site.description', 'label' => __('Site Description', 'wp-intelligence'),    'group' => __('Site', 'wp-intelligence')],
      ['tag' => 'wp.site.url',         'label' => __('Site URL', 'wp-intelligence'),            'group' => __('Site', 'wp-intelligence')],
      ['tag' => 'wp.site.admin_email', 'label' => __('Admin Email', 'wp-intelligence'),         'group' => __('Site', 'wp-intelligence')],
      ['tag' => 'wp.site.language',    'label' => __('Site Language', 'wp-intelligence'),        'group' => __('Site', 'wp-intelligence')],
    ];

    if (function_exists('get_field')) {
      $tags[] = ['tag' => 'wp.acf.FIELD_NAME', 'label' => __('ACF Field (specify name)', 'wp-intelligence'), 'group' => __('ACF', 'wp-intelligence')];
    }

    return $tags;
  }
}
