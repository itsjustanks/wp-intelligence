<?php
if (! defined('ABSPATH')) {
  exit;
}

class WPI_Chat_Storage {

  private const TABLE_SUFFIX = 'wpi_chat_messages';

  public static function get_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SUFFIX;
  }

  public static function ensure_table(): void {
    global $wpdb;

    $table   = self::get_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      conversation_id varchar(36) NOT NULL,
      user_id bigint(20) unsigned NOT NULL,
      role varchar(16) NOT NULL DEFAULT 'user',
      content longtext NOT NULL,
      context_snapshot text DEFAULT NULL,
      created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY conversation_id (conversation_id),
      KEY user_id_created (user_id, created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public function save_message(string $conversation_id, int $user_id, string $role, string $content, ?array $context = null): int {
    global $wpdb;

    $wpdb->insert(self::get_table_name(), [
      'conversation_id' => $conversation_id,
      'user_id'         => $user_id,
      'role'            => $role,
      'content'         => $content,
      'context_snapshot' => $context !== null ? wp_json_encode($context) : null,
      'created_at'      => current_time('mysql', true),
    ], ['%s', '%d', '%s', '%s', '%s', '%s']);

    return (int) $wpdb->insert_id;
  }

  public function get_messages(string $conversation_id, int $user_id, int $limit = 20): array {
    global $wpdb;
    $table = self::get_table_name();

    return $wpdb->get_results($wpdb->prepare(
      "SELECT id, role, content, context_snapshot, created_at
       FROM {$table}
       WHERE conversation_id = %s AND user_id = %d
       ORDER BY created_at ASC
       LIMIT %d",
      $conversation_id,
      $user_id,
      $limit
    ));
  }

  public function get_conversations(int $user_id, int $limit = 20): array {
    global $wpdb;
    $table = self::get_table_name();

    return $wpdb->get_results($wpdb->prepare(
      "SELECT t.conversation_id,
              (SELECT sub.content FROM {$table} sub WHERE sub.conversation_id = t.conversation_id AND sub.user_id = t.user_id AND sub.role = 'user' ORDER BY sub.created_at ASC LIMIT 1) AS title,
              MAX(t.created_at) AS last_message_at,
              COUNT(*) AS message_count
       FROM {$table} t
       WHERE t.user_id = %d
       GROUP BY t.conversation_id
       ORDER BY last_message_at DESC
       LIMIT %d",
      $user_id,
      $limit
    ));
  }

  public function delete_conversation(string $conversation_id, int $user_id): int {
    global $wpdb;

    return (int) $wpdb->delete(self::get_table_name(), [
      'conversation_id' => $conversation_id,
      'user_id'         => $user_id,
    ], ['%s', '%d']);
  }

  public function cleanup_old(int $days = 30): int {
    global $wpdb;
    $table = self::get_table_name();

    $days = apply_filters('ai_composer_chat_retention_days', $days);

    return (int) $wpdb->query($wpdb->prepare(
      "DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
      $days
    ));
  }

  public function generate_conversation_id(): string {
    return wp_generate_uuid4();
  }
}
