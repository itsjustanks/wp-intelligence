<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Block Visibility Integration — adds a "Dynamic Data" control to block visibility.
 *
 * Allows blocks to be shown/hidden based on values from pre-fetched data sources
 * (webhooks, WordPress data, URL params, cookies).
 *
 * Control attribute structure:
 *   blockVisibility.controlSets[].controls.dynamicData = {
 *     ruleSets: [
 *       {
 *         enable: true,
 *         rules: [
 *           { source: "crm", field: "plan", operator: "equal", value: "pro" },
 *           { source: "url", field: "ref",  operator: "notEmpty", value: "" },
 *         ]
 *       }
 *     ],
 *     hideOnRuleSets: false
 *   }
 */
class WPI_Dynamic_Data_Visibility {

  public static function init(): void {
    add_filter('wpi_visibility_control_set_is_block_visible', [self::class, 'dynamic_data_test'], 15, 3);
    add_filter('wpi_visibility_rest_variables', [self::class, 'add_editor_variables'], 10, 2);
  }

  /**
   * Visibility test: evaluate dynamic data rules.
   *
   * @param bool  $is_visible Current visibility state.
   * @param array $settings   Plugin settings.
   * @param array $controls   Control set controls.
   * @return bool
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

    $rule_sets_results = [];

    foreach ($rule_sets as $rule_set) {
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

      // Within a rule set, ALL rules must pass (AND logic).
      $set_result = in_array('hidden', $rule_results, true) ? 'hidden' : 'visible';

      if ($hide_on_rule_sets) {
        $set_result = ($set_result === 'visible') ? 'hidden' : 'visible';
      }

      $rule_sets_results[] = $set_result;
    }

    if (empty($rule_sets_results)) {
      return true;
    }

    // Across rule sets: at least one must pass (OR logic).
    if (! $hide_on_rule_sets && ! in_array('visible', $rule_sets_results, true)) {
      return false;
    } elseif ($hide_on_rule_sets && in_array('hidden', $rule_sets_results, true)) {
      return false;
    }

    return true;
  }

  /**
   * Evaluate a single dynamic data rule.
   *
   * @param array $rule Rule config with source, field, operator, value.
   * @return string 'visible', 'hidden', or 'error'.
   */
  private static function evaluate_rule(array $rule): string {
    $source_name = $rule['source'] ?? '';
    $field_path  = $rule['field'] ?? '';
    $operator    = $rule['operator'] ?? '';

    if ($source_name === '' || $operator === '') {
      return 'error';
    }

    $tag_path    = $field_path !== '' ? $source_name . '.' . $field_path : $source_name;
    $actual      = WPI_Merge_Tag_Engine::resolve_tag($tag_path, []);
    $expected    = $rule['value'] ?? '';

    if (is_array($actual)) {
      $actual = wp_json_encode($actual);
    }

    $actual   = (string) ($actual ?? '');
    $expected = (string) $expected;

    $test_result = self::compare_values($actual, $operator, $expected);

    return $test_result ? 'visible' : 'hidden';
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
   * Add dynamic data sources to the editor variables endpoint.
   *
   * @param array  $variables Existing variables.
   * @param string $request_type Request type.
   * @return array
   */
  public static function add_editor_variables(array $variables, string $request_type = ''): array {
    $registry = WPI_Data_Source_Registry::instance();
    $sources  = [];

    foreach ($registry->all() as $name => $source) {
      $sources[] = [
        'name'  => $name,
        'label' => $source->get_label(),
        'type'  => $source->get_type(),
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
