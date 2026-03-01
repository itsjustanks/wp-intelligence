<?php
if (! defined('ABSPATH')) {
  exit;
}

class AI_Composer {

  private static ?self $instance = null;

  private AI_Composer_Block_Catalog   $blocks;
  private AI_Composer_Pattern_Catalog $patterns;
  private AI_Composer_Prompt_Engine   $prompt_engine;
  private AI_Composer_Provider        $provider;
  private AI_Composer_Manifest_Compiler $compiler;

  private function __construct() {
    $this->blocks        = new AI_Composer_Block_Catalog();
    $this->patterns      = new AI_Composer_Pattern_Catalog();
    $this->prompt_engine = new AI_Composer_Prompt_Engine($this->blocks, $this->patterns);
    $this->provider      = new AI_Composer_Provider();
    $this->compiler      = new AI_Composer_Manifest_Compiler($this->blocks, $this->patterns);
  }

  public static function get_instance(): self {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public static function init(): void {
    $instance = self::get_instance();

    AI_Composer_REST_Controller::register_routes();
    AI_Composer_Abilities_Bridge::maybe_register();
    AI_Composer_Settings::init();

    add_action('enqueue_block_editor_assets', [$instance, 'enqueue_editor_assets']);

    do_action('ai_composer_init', $instance);
  }

  public function enqueue_editor_assets(): void {
    if (! current_user_can('edit_posts')) {
      return;
    }

    $mod_dir = AI_COMPOSER_DIR;
    $mod_url = AI_COMPOSER_URL;

    $js_path  = $mod_dir . '/editor/sidebar.js';
    $css_path = $mod_dir . '/editor/sidebar.css';

    wp_enqueue_script(
      'ai-composer-sidebar',
      $mod_url . 'editor/sidebar.js',
      ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n', 'wp-notices'],
      file_exists($js_path) ? (string) filemtime($js_path) : WPI_VERSION,
      true
    );

    wp_enqueue_style(
      'ai-composer-sidebar',
      $mod_url . 'editor/sidebar.css',
      ['wp-components'],
      file_exists($css_path) ? (string) filemtime($css_path) : WPI_VERSION
    );

    wp_localize_script('ai-composer-sidebar', 'aiComposerConfig', [
      'restNamespace'  => 'ai-composer/v1',
      'nonce'          => wp_create_nonce('wp_rest'),
      'providerReady'  => $this->provider->is_available(),
      'version'        => AI_COMPOSER_VERSION,
    ]);
  }

  /**
   * Main composition entry point.
   *
   * @param string $prompt   Natural-language description of the desired page.
   * @param array  $options  Optional overrides: template, block_allowlist, insert_mode.
   * @return array{blocks: string, blockTree: array, manifest: array, summary: string}|WP_Error
   */
  public function compose(string $prompt, array $options = []): array|WP_Error {
    $options = wp_parse_args($options, [
      'template'               => '',
      'compose_mode'           => 'new_content',
      'insert_mode'            => 'append',
      'selected_block_context' => null,
      'page_context'           => null,
    ]);

    if (! $this->provider->is_available()) {
      return new WP_Error(
        'ai_composer_no_provider',
        __('No AI provider is configured. Add an API key in Settings or WordPress AI Credentials.', 'wp-intelligence')
      );
    }

    do_action('ai_composer_before_compose', $prompt, $options);

    $system_prompt = $this->prompt_engine->build_system_prompt($options);
    $output_schema = $this->prompt_engine->get_output_schema();

    $raw = $this->provider->generate($system_prompt, $prompt, $output_schema);
    if (is_wp_error($raw)) {
      do_action('ai_composer_composition_error', $raw, $prompt);
      return $raw;
    }

    $manifest = json_decode($raw, true);
    if (! is_array($manifest) || empty($manifest['blocks'])) {
      return new WP_Error(
        'ai_composer_invalid_manifest',
        __('The AI returned an invalid composition manifest.', 'wp-intelligence')
      );
    }

    $manifest = apply_filters('ai_composer_manifest', $manifest, $prompt, $options);

    $validation = $this->compiler->validate($manifest);
    if (is_wp_error($validation)) {
      return $validation;
    }

    $block_tree = $this->compiler->to_block_tree($manifest);
    if (is_wp_error($block_tree)) {
      return $block_tree;
    }

    $block_grammar = $this->compiler->compile($manifest);
    if (is_wp_error($block_grammar)) {
      return $block_grammar;
    }

    $result = [
      'blocks'    => $block_grammar,
      'blockTree' => $block_tree,
      'manifest'  => $manifest,
      'summary'   => $manifest['summary'] ?? '',
    ];

    $result = apply_filters('ai_composer_result', $result, $prompt, $options);

    do_action('ai_composer_after_compose', $result, $prompt);

    return $result;
  }

  public function blocks(): AI_Composer_Block_Catalog {
    return $this->blocks;
  }

  public function patterns(): AI_Composer_Pattern_Catalog {
    return $this->patterns;
  }

  public function provider(): AI_Composer_Provider {
    return $this->provider;
  }
}
