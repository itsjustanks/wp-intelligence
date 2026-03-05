<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Lightweight MCP (Model Context Protocol) client for Streamable HTTP transport.
 *
 * Uses JSON-RPC 2.0 over HTTP to communicate with an MCP server.
 * Handles initialization handshake, session management, and SSE response parsing.
 */
class AI_Composer_MCP_Client {

  private string $server_url;
  private ?string $session_id = null;
  private bool $initialized = false;
  private int $request_id = 0;

  public function __construct(string $server_url) {
    $this->server_url = rtrim($server_url, '/');
  }

  /**
   * Call an MCP tool and return the concatenated text content.
   *
   * @param string $tool_name  MCP tool name (e.g. 'get_context', 'get_brand_voice').
   * @param array  $arguments  Tool arguments.
   * @return string|WP_Error   Concatenated text content or error.
   */
  public function call_tool(string $tool_name, array $arguments = []): string|WP_Error {
    $init = $this->ensure_initialized();
    if (is_wp_error($init)) {
      return $init;
    }

    $result = $this->jsonrpc('tools/call', [
      'name'      => $tool_name,
      'arguments' => empty($arguments) ? new \stdClass() : $arguments,
    ]);

    if (is_wp_error($result)) {
      return $result;
    }

    return $this->extract_text_content($result);
  }

  private function ensure_initialized(): true|WP_Error {
    if ($this->initialized) {
      return true;
    }

    $result = $this->jsonrpc('initialize', [
      'protocolVersion' => '2024-11-05',
      'capabilities'    => new \stdClass(),
      'clientInfo'      => [
        'name'    => 'wp-intelligence',
        'version' => defined('WPI_VERSION') ? WPI_VERSION : '0.1.0',
      ],
    ]);

    if (is_wp_error($result)) {
      return $result;
    }

    $this->initialized = true;

    $this->send_notification('notifications/initialized');

    return true;
  }

  /**
   * @return array|WP_Error Decoded JSON-RPC result or error.
   */
  private function jsonrpc(string $method, array $params): array|WP_Error {
    $this->request_id++;

    $body = wp_json_encode([
      'jsonrpc' => '2.0',
      'method'  => $method,
      'params'  => $params,
      'id'      => $this->request_id,
    ]);

    if ($body === false) {
      return new WP_Error('wpi_mcp_encode', __('Failed to encode MCP request.', 'wp-intelligence'));
    }

    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json, text/event-stream',
    ];
    if ($this->session_id !== null) {
      $headers['Mcp-Session-Id'] = $this->session_id;
    }

    $response = wp_remote_post($this->server_url, [
      'headers' => $headers,
      'body'    => $body,
      'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error(
        'wpi_mcp_http',
        sprintf(__('MCP connection failed: %s', 'wp-intelligence'), $response->get_error_message())
      );
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
      return new WP_Error(
        'wpi_mcp_http_status',
        sprintf(__('MCP server returned HTTP %d.', 'wp-intelligence'), $code)
      );
    }

    $session_header = wp_remote_retrieve_header($response, 'mcp-session-id');
    if (is_string($session_header) && $session_header !== '') {
      $this->session_id = $session_header;
    }

    $raw_body     = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');

    if (is_string($content_type) && str_contains($content_type, 'text/event-stream')) {
      return $this->parse_sse_response($raw_body);
    }

    $decoded = json_decode($raw_body, true);
    if (! is_array($decoded)) {
      return new WP_Error('wpi_mcp_json', __('Invalid JSON response from MCP server.', 'wp-intelligence'));
    }

    if (isset($decoded['error'])) {
      $msg = $decoded['error']['message'] ?? __('Unknown MCP error.', 'wp-intelligence');
      return new WP_Error('wpi_mcp_rpc_error', $msg);
    }

    return $decoded['result'] ?? [];
  }

  private function send_notification(string $method): void {
    $body = wp_json_encode([
      'jsonrpc' => '2.0',
      'method'  => $method,
    ]);

    $headers = ['Content-Type' => 'application/json'];
    if ($this->session_id !== null) {
      $headers['Mcp-Session-Id'] = $this->session_id;
    }

    wp_remote_post($this->server_url, [
      'headers'  => $headers,
      'body'     => $body,
      'timeout'  => 5,
      'blocking' => false,
    ]);
  }

  /**
   * Parse an SSE (Server-Sent Events) response to extract the JSON-RPC result.
   */
  private function parse_sse_response(string $raw): array|WP_Error {
    $events = preg_split('/\r?\n\r?\n/', $raw);

    foreach ($events as $event) {
      $data = '';
      foreach (explode("\n", $event) as $line) {
        if (str_starts_with($line, 'data: ')) {
          $data .= substr($line, 6) . "\n";
        }
      }

      if ($data !== '') {
        $data = substr($data, 0, -1);
      }

      if ($data === '') {
        continue;
      }

      $decoded = json_decode($data, true);
      if (is_array($decoded) && isset($decoded['id'])) {
        if (isset($decoded['error'])) {
          $msg = $decoded['error']['message'] ?? __('Unknown MCP error.', 'wp-intelligence');
          return new WP_Error('wpi_mcp_rpc_error', $msg);
        }
        return $decoded['result'] ?? [];
      }
    }

    return new WP_Error('wpi_mcp_sse_empty', __('No result found in MCP SSE response.', 'wp-intelligence'));
  }

  /**
   * Extract concatenated text from MCP tool result content array.
   */
  private function extract_text_content(array $result): string {
    if (! isset($result['content']) || ! is_array($result['content'])) {
      return '';
    }

    $texts = [];
    foreach ($result['content'] as $item) {
      if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text'])) {
        $text = trim($item['text']);
        if ($text !== '') {
          $texts[] = $text;
        }
      }
    }

    return implode("\n\n", $texts);
  }
}
