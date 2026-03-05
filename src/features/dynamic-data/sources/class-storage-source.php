<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Browser Storage Data Source — provides merge tags for localStorage and sessionStorage.
 *
 * Since PHP cannot read browser storage, this source:
 *   1. Returns empty data server-side (fetch returns []).
 *   2. Marks itself as client-side so the engine wraps tags in placeholder elements.
 *   3. A companion frontend JS resolves values from localStorage/sessionStorage.
 *
 * Tags:
 *   {{storage.KEY}}            — reads from localStorage, falls back to sessionStorage
 *   {{storage.KEY|fallback}}   — with a fallback if the key is absent
 *
 * Visibility rules referencing the storage source are deferred to client-side
 * evaluation using the same data-attribute pattern as DataGlue.
 *
 * Examples:
 *   {{storage.user_plan}}              — "pro"
 *   {{storage.onboarding_complete}}    — "true"
 *   {{storage.preferred_language|en}}  — "en" if key not found
 */
class WPI_Storage_Source implements WPI_Data_Source_Interface {

  public function get_label(): string {
    return __('Browser Storage', 'wp-intelligence');
  }

  public function get_type(): string {
    return 'storage';
  }

  public function is_client_side(): bool {
    return true;
  }

  /**
   * Server-side fetch returns empty — browser storage is inaccessible from PHP.
   */
  public function fetch(array $context = []): array {
    return [];
  }

  public function get_available_tags(): array {
    return [
      [
        'tag'   => 'storage.KEY',
        'label' => __('localStorage / sessionStorage value (specify key)', 'wp-intelligence'),
        'group' => __('Browser Storage', 'wp-intelligence'),
      ],
    ];
  }
}
