<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Block Visibility Integration — adds "Dynamic Data" controls to block visibility.
 *
 * Server-side sources (webhooks, WordPress, URL params, cookies):
 *   Evaluated in PHP via the wpi_visibility_control_set_is_block_visible filter.
 *
 * Client-side sources (localStorage/sessionStorage):
 *   Deferred to frontend JS using the same data-attribute pattern as DataGlue.
 *   The PHP test always passes; rules are serialized into a data attribute
 *   and the companion frontend JS evaluates and hides/reveals blocks.
 *
 * Control attribute structure:
 *   blockVisibility.controlSets[].controls.dynamicData = {
 *     ruleSets: [
 *       {
 *         enable: true,
 *         rules: [
 *           { source: "crm", field: "plan", operator: "equal", value: "pro" },
 *           { source: "storage", field: "user_tier", operator: "equal", value: "premium" },
 *         ]
 *       }
 *     ],
 *     hideOnRuleSets: false
 *   }
 */
class WPI_Dynamic_Data_Visibility {

  public static function init(): void {
    add_filter('wpi_visibility_control_set_is_block_visible', [self::class, 'dynamic_data_test'], 15, 3);
    add_filter('wpi_visibility_control_set_add_custom_classes', [self::class, 'add_pending_classes'], 10, 3);
    add_filter('render_block', [self::class, 'inject_client_side_rules'], 21, 2);
    add_filter('wpi_visibility_rest_variables', [self::class, 'add_editor_variables'], 10, 2);
  }

  /**
   * Visibility test: evaluate server-side dynamic data rules.
   *
   * Rules referencing client-side sources are skipped here (always pass)
   * and deferred to the frontend JS.
   */
  public static function dynamic_data_test(bool $is_visible, array $settings, array $controls): bool {
    if (! $is_visible) {
      return $is_visible;
    }

    if (function_exists('WPI\\Visibility\\Utils\\is_control_enabled')) {
      if (! \WPI\Visibility\Utils\is_control_enabled($settings, 'dynamic_data')) {
        return true;
      }
    }

    $control_atts = $controls['dynamicData'] ?? null;
    if (! $control_atts) {
      return true;
    }

    $rule_sets = $control_atts['ruleSets'] ?? [];
    $hide_on_rule_sets = ! empty($control_atts['hideOnRuleSets']);

    if (! is_array($rule_sets) || count($rule_sets) === 0) {
      return true;
    }

    $server_rule_sets = self::filter_server_side_rule_sets($rule_sets);

    if (empty($server_rule_sets)) {
      return true;
    }

    $rule_sets_results = [];

    foreach ($server_rule_sets as $rule_set) {
      $enable = $rule_set['enable'] ?? true;
      $rules  = $rule_set['rules'] ?? [];

      if (! $enable || ! is_array($rules) || count($rules) === 0) {
        continue;
      }

      $rule_results = [];

      foreach ($rules as $rule) {
        $result = self::evaluate_rule($rule);
        $rule_results[] = ($result === 'error') ? 'visible' : $result;
      }

      $set_result = in_array('hidden', $rule_results, true) ? 'hidden' : 'visible';

      if ($hide_on_rule_sets) {
        $set_result = ($set_result === 'visible') ? 'hidden' : 'visible';
      }

      $rule_sets_results[] = $set_result;
    }

    if (empty($rule_sets_results)) {
      return true;
    }

    if (! $hide_on_rule_sets && ! in_array('visible', $rule_sets_results, true)) {
      return false;
    } elseif ($hide_on_rule_sets && in_array('hidden', $rule_sets_results, true)) {
      return false;
    }

    return true;
  }

  /**
   * Add a pending CSS class for blocks with client-side dynamic data rules.
   * The block starts hidden and is revealed by the frontend JS after evaluation.
   */
  public static function add_pending_classes(array $custom_classes, array $settings, array $controls): array {
    if (function_exists('WPI\\Visibility\\Utils\\is_control_enabled')) {
      if (! \WPI\Visibility\Utils\is_control_enabled($settings, 'dynamic_data')) {
        return $custom_classes;
      }
    }

    $control_atts = $controls['dynamicData'] ?? null;
    if (! $control_atts) {
      return $custom_classes;
    }

    $rule_sets = $control_atts['ruleSets'] ?? [];
    if (! is_array($rule_sets) || empty($rule_sets)) {
      return $custom_classes;
    }

    $client_rules = self::filter_client_side_rule_sets($rule_sets);
    if (! empty($client_rules)) {
      $custom_classes[] = 'wpi-dd-vis-pending';
    }

    return $custom_classes;
  }

  /**
   * Inject client-side dynamic data visibility rules as a data attribute.
   *
   * Runs at priority 21 (after main visibility at 10, after data-glue at 20).
   */
  public static function inject_client_side_rules(string $block_content, array $block): string {
    if (empty($block_content)) {
      return $block_content;
    }

    $attributes = $block['attrs']['blockVisibility'] ?? null;
    if (! $attributes) {
      return $block_content;
    }

    if (function_exists('WPI\\Visibility\\Utils\\is_control_enabled')) {
      $settings = \WPI\Visibility\Frontend\get_visibility_settings_cached();
      if (! \WPI\Visibility\Utils\is_control_enabled($settings, 'dynamic_data')) {
        return $block_content;
      }
    }

    $control_sets = $attributes['controlSets'] ?? [];
    $dd_client_rules = [];

    foreach ($control_sets as $control_set) {
      $enable   = $control_set['enable'] ?? true;
      $controls = $control_set['controls'] ?? [];

      if (! $enable || empty($controls)) {
        continue;
      }

      $control_atts = $controls['dynamicData'] ?? null;
      if (! $control_atts) {
        continue;
      }

      $rule_sets = $control_atts['ruleSets'] ?? [];
      $hide_on   = ! empty($control_atts['hideOnRuleSets']);

      $client_sets = self::filter_client_side_rule_sets($rule_sets);

      if (! empty($client_sets)) {
        $dd_client_rules[] = [
          'ruleSets'       => $client_sets,
          'hideOnRuleSets' => $hide_on,
        ];
      }
    }

    if (empty($dd_client_rules)) {
      return $block_content;
    }

    WPI_Dynamic_Data::enqueue_frontend_assets();

    $tags = new \WP_HTML_Tag_Processor($block_content);
    if ($tags->next_tag()) {
      $tags->set_attribute(
        'data-wpi-dd-visibility',
        wp_json_encode($dd_client_rules)
      );
    }

    return $tags->get_updated_html();
  }

  /**
   * Evaluate a single server-side dynamic data rule.
   */
  private static function evaluate_rule(array $rule): string {
    $source_name = $rule['source'] ?? '';
    $field_path  = $rule['field'] ?? '';
    $operator    = $rule['operator'] ?? '';

    if ($source_name === '' || $operator === '') {
      return 'error';
    }

    $tag_path = $field_path !== '' ? $source_name . '.' . $field_path : $source_name;
    $actual   = WPI_Merge_Tag_Engine::resolve_tag($tag_path, []);

    if (is_array($actual)) {
      $actual = wp_json_encode($actual);
    }

    $actual   = (string) ($actual ?? '');
    $expected = (string) ($rule['value'] ?? '');

    return self::compare_values($actual, $operator, $expected) ? 'visible' : 'hidden';
  }

  /**
   * Compare values using the specified operator.
   */
  private static function compare_values(string $actual, string $operator, string $expected): bool {
    switch ($operator) {
      case 'notEmpty':
        return $actual !== '';
      case 'empty':
        return $actual === '';
      case 'equal':
        return $actual === $expected;
      case 'notEqual':
        return $actual !== $expected;
      case 'contains':
        return $expected !== '' && strpos($actual, $expected) !== false;
      case 'notContain':
        return $expected === '' || strpos($actual, $expected) === false;
      case 'greaterThan':
        return is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected;
      case 'lessThan':
        return is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected;
      case 'matches':
        if ($expected === '') return false;
        $pattern = '@' . preg_quote($expected, '@') . '@i';
        return (bool) preg_match($pattern, $actual);
      default:
        return true;
    }
  }

  /**
   * Filter rule sets to include only rules with server-side sources.
   */
  private static function filter_server_side_rule_sets(array $rule_sets): array {
    $registry = WPI_Data_Source_Registry::instance();
    $filtered = [];

    foreach ($rule_sets as $rule_set) {
      $rules = $rule_set['rules'] ?? [];
      $server_rules = [];

      foreach ($rules as $rule) {
        $source_name = $rule['source'] ?? '';
        $source      = $registry->get($source_name);

        if ($source === null || ! $source->is_client_side()) {
          $server_rules[] = $rule;
        }
      }

      if (! empty($server_rules)) {
        $filtered[] = array_merge($rule_set, ['rules' => $server_rules]);
      }
    }

    return $filtered;
  }

  /**
   * Filter rule sets to include only rules with client-side sources.
   */
  private static function filter_client_side_rule_sets(array $rule_sets): array {
    $registry = WPI_Data_Source_Registry::instance();
    $filtered = [];

    foreach ($rule_sets as $rule_set) {
      $rules = $rule_set['rules'] ?? [];
      $client_rules = [];

      foreach ($rules as $rule) {
        $source_name = $rule['source'] ?? '';
        $source      = $registry->get($source_name);

        if ($source !== null && $source->is_client_side()) {
          $client_rules[] = $rule;
        }
      }

      if (! empty($client_rules)) {
        $filtered[] = array_merge($rule_set, ['rules' => $client_rules]);
      }
    }

    return $filtered;
  }

  /**
   * Add dynamic data sources and operators to the editor variables endpoint.
   */
  public static function add_editor_variables(array $variables, string $request_type = ''): array {
    $registry = WPI_Data_Source_Registry::instance();
    $sources  = [];

    foreach ($registry->all() as $name => $source) {
      $sources[] = [
        'name'       => $name,
        'label'      => $source->get_label(),
        'type'       => $source->get_type(),
        'clientSide' => $source->is_client_side(),
      ];
    }

    $variables['dynamicDataSources'] = $sources;
    $variables['dynamicDataOperators'] = [
      ['value' => 'notEmpty',    'label' => __('is not empty', 'wp-intelligence')],
      ['value' => 'empty',       'label' => __('is empty', 'wp-intelligence')],
      ['value' => 'equal',       'label' => __('equals', 'wp-intelligence')],
      ['value' => 'notEqual',    'label' => __('does not equal', 'wp-intelligence')],
      ['value' => 'contains',    'label' => __('contains', 'wp-intelligence')],
      ['value' => 'notContain',  'label' => __('does not contain', 'wp-intelligence')],
      ['value' => 'greaterThan', 'label' => __('greater than', 'wp-intelligence')],
      ['value' => 'lessThan',    'label' => __('less than', 'wp-intelligence')],
    ];

    return $variables;
  }
}
