<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Performance module.
 *
 * Provides two independent features:
 *  1. WordPress performance constants (revisions, trash, GZIP, etc.).
 *  2. Output-buffer HTML compression (minifies whitespace, strips comments).
 *
 * @since 0.3.0
 */
class WPI_Performance {

  private const OPTION = 'wpi_performance';

  private const DEFAULTS = [
    'constants_enabled'    => true,
    'compression_enabled'  => true,
  ];

  private const PERFORMANCE_CONSTANTS = [
    'EMPTY_TRASH_DAYS'     => 7,
    'WP_POST_REVISIONS'    => 30,
    'IMAGE_EDIT_OVERWRITE' => false,
    'CONCATENATE_SCRIPTS'  => true,
    'SCRIPT_DEBUG'         => false,
    'COMPRESS_SCRIPTS'     => true,
    'COMPRESS_CSS'         => true,
    'ENFORCE_GZIP'         => true,
  ];

  public static function boot(): void {
    $opts = self::get_options();

    if ($opts['constants_enabled']) {
      add_action('init', [self::class, 'define_constants'], 1);
    }

    if ($opts['compression_enabled']) {
      add_action('get_header', [self::class, 'start_compression']);
    }
  }

  public static function define_constants(): void {
    $values = apply_filters('wpi_performance_constants', self::PERFORMANCE_CONSTANTS);
    foreach ($values as $name => $value) {
      if (! defined($name)) {
        define($name, $value);
      }
    }
  }

  public static function start_compression(): void {
    ob_start([self::class, 'compress_callback']);
  }

  public static function compress_callback(string $html): string {
    if ($html === '') {
      return '';
    }
    return (new WPI_HTML_Compressor($html))->output();
  }

  /** @return array<string,bool> */
  public static function get_options(): array {
    $saved = get_option(self::OPTION, []);
    if (! is_array($saved)) {
      $saved = [];
    }
    return wp_parse_args($saved, self::DEFAULTS);
  }

  /** @param array<string,mixed> $input */
  public static function sanitize(array $input): array {
    return [
      'constants_enabled'   => ! empty($input['constants_enabled']),
      'compression_enabled' => ! empty($input['compression_enabled']),
    ];
  }

  public static function render_fields(): void {
    $opts   = self::get_options();
    $option = esc_attr(self::OPTION);

    $features = [
      'constants_enabled' => [
        'label' => __('Performance constants', 'wp-intelligence'),
        'desc'  => __('Sets EMPTY_TRASH_DAYS, WP_POST_REVISIONS, CONCATENATE_SCRIPTS, COMPRESS_SCRIPTS, COMPRESS_CSS, ENFORCE_GZIP. Values can be overridden with the <code>wpi_performance_constants</code> filter.', 'wp-intelligence'),
      ],
      'compression_enabled' => [
        'label' => __('HTML compression', 'wp-intelligence'),
        'desc'  => __('Minifies HTML output by stripping whitespace, comments, and redundant attributes. Preserves &lt;pre&gt;, &lt;textarea&gt; and conditional comments.', 'wp-intelligence'),
      ],
    ];

    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ($features as $key => $meta) {
      echo '<tr><th scope="row">' . esc_html($meta['label']) . '</th><td>';
      printf(
        '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
        $option,
        esc_attr($key),
        checked($opts[$key], true, false),
        esc_html__('Enable', 'wp-intelligence')
      );
      echo '<p class="description">' . wp_kses_post($meta['desc']) . '</p>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }
}

/**
 * Lightweight HTML minifier.
 *
 * Strips whitespace, comments, and empty attributes from HTML while
 * preserving content inside <pre>, <textarea>, <script>, and <style>.
 */
class WPI_HTML_Compressor {

  private string $html = '';

  public function __construct(string $raw) {
    if ($raw !== '') {
      $this->html = $this->minify($raw);
    }
  }

  public function output(): string {
    return $this->html;
  }

  private function minify(string $html): string {
    $pattern = '/<(?<script>script).*?<\/script\s*>'
      . '|<(?<style>style).*?<\/style\s*>'
      . '|<!(?<comment>--).*?-->'
      . '|<(?<tag>[\/\w.:-]*)(?:".*?"|\'.*?\'|[^\'">]+)*>'
      . '|(?<text>((<[^!\/\w.:-])?[^<]*)+)|/si';

    preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

    $overriding = false;
    $raw_tag    = false;
    $out        = '';

    foreach ($matches as $token) {
      $tag     = isset($token['tag']) ? strtolower($token['tag']) : null;
      $content = $token[0];

      if ($tag === null) {
        if (! empty($token['script'])) {
          $strip = true;
        } elseif (! empty($token['style'])) {
          $strip = true;
        } elseif ($content === '<!--wp-html-compression no compression-->') {
          $overriding = ! $overriding;
          continue;
        } else {
          if (! $overriding && $raw_tag !== 'textarea') {
            $content = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $content);
          }
          $strip = false;
        }
      } else {
        if ($tag === 'pre' || $tag === 'textarea') {
          $raw_tag = $tag;
        } elseif ($tag === '/pre' || $tag === '/textarea') {
          $raw_tag = false;
        }

        if ($raw_tag || $overriding) {
          $strip = false;
        } else {
          $strip   = true;
          $content = preg_replace('/(\s+)(\w++(?<!\baction|\balt|\bcontent|\bsrc)="")/', '$1', $content);
          $content = str_replace(' />', '/>', $content);
        }
      }

      if ($strip) {
        $content = $this->strip_whitespace($content);
      }

      $out .= $content;
    }

    return $out;
  }

  private function strip_whitespace(string $str): string {
    $str = str_replace(["\t", "\n", "\r"], ['  ', '', ''], $str);
    while (str_contains($str, '  ')) {
      $str = str_replace('  ', ' ', $str);
    }
    return $str;
  }
}
