<?php
if (! defined('ABSPATH')) {
  exit;
}

/**
 * Image generation provider.
 *
 * Reuses the same API key resolution as AI_Composer_Provider:
 *   constant → env → settings → filter.
 *
 * Two-step generation:
 *   1. GPT crafts a DALL-E prompt from post content + style guidelines.
 *   2. DALL-E 3 generates the image from that prompt.
 */
class WPI_Image_Provider {

  private const OPENAI_CHAT_ENDPOINT   = 'https://api.openai.com/v1/chat/completions';
  private const OPENAI_IMAGES_ENDPOINT = 'https://api.openai.com/v1/images/generations';
  private const DEFAULT_MODEL          = 'gpt-5.2';
  private const IMAGE_MODEL            = 'dall-e-3';
  private const TIMEOUT                = 120;

  private const SIZE_MAP = [
    'landscape' => '1792x1024',
    'portrait'  => '1024x1792',
    'square'    => '1024x1024',
  ];

  public function is_available(): bool {
    if ($this->has_native_client()) {
      return true;
    }
    return $this->get_api_key() !== '';
  }

  /**
   * @return array{can_generate:bool, runtime:string, message:string}
   */
  public function get_readiness(): array {
    if ($this->has_native_client()) {
      return [
        'can_generate' => true,
        'runtime'      => 'wp-ai-client',
        'message'      => __('WordPress native AI runtime detected.', 'wp-intelligence'),
      ];
    }

    $has_key = $this->get_api_key() !== '';
    return [
      'can_generate' => $has_key,
      'runtime'      => $has_key ? 'openai-direct' : 'none',
      'message'      => $has_key
        ? __('Using OpenAI for image generation.', 'wp-intelligence')
        : __('No API key configured. Add one in WP Intelligence → AI settings.', 'wp-intelligence'),
    ];
  }

  /**
   * Generate a featured image for a post.
   *
   * @param string $title   Post title.
   * @param string $content Post content (truncated to keep prompt compact).
   * @param array  $style   Style guidelines from settings + per-post overrides.
   * @return string|WP_Error URL of the generated image.
   */
  public function generate_image(string $title, string $content, array $style = []): string|WP_Error {
    $prompt_result = $this->craft_dalle_prompt($title, $content, $style);
    if (is_wp_error($prompt_result)) {
      return $prompt_result;
    }

    return $this->call_dalle($prompt_result, $style);
  }

  /**
   * Step 1: Use GPT to craft an optimized DALL-E prompt.
   */
  private function craft_dalle_prompt(string $title, string $content, array $style): string|WP_Error {
    $content = mb_substr(wp_strip_all_tags($content), 0, 3000);

    $style_desc   = $style['image_style'] ?? 'photo-realistic';
    $brand_colors = $style['brand_colors'] ?? '';
    $custom_instr = $style['custom_instructions'] ?? '';

    $system = "You are an expert visual designer who creates image prompts for blog featured images.\n"
      . "Given a blog post title and content excerpt, create a single, detailed DALL-E 3 prompt.\n\n"
      . "Style: {$style_desc}\n"
      . ($brand_colors !== '' ? "Brand colors to incorporate: {$brand_colors}\n" : '')
      . ($custom_instr !== '' ? "Additional guidelines: {$custom_instr}\n" : '')
      . "\nRules:\n"
      . "- Output ONLY the image prompt, nothing else.\n"
      . "- The image should be visually striking and suitable as a blog header.\n"
      . "- Do NOT include any text, words, letters, or typography in the image.\n"
      . "- Avoid faces of real people. Use abstract, symbolic, or illustrative representations.\n"
      . "- The prompt should be under 500 characters.";

    $user = "Title: {$title}\n\nContent excerpt:\n{$content}";

    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new WP_Error(
        'wpi_no_api_key',
        __('No OpenAI API key configured.', 'wp-intelligence'),
        ['status' => 400]
      );
    }

    $body = [
      'model'       => $this->get_model(),
      'messages'    => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
      ],
      'temperature' => 0.7,
      'max_tokens'  => 300,
    ];

    $response = wp_remote_post(self::OPENAI_CHAT_ENDPOINT, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode($body),
      'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('wpi_prompt_request_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $data   = json_decode(wp_remote_retrieve_body($response), true);

    if ($status !== 200) {
      $msg = $data['error']['message'] ?? __('Failed to generate image prompt.', 'wp-intelligence');
      return new WP_Error('wpi_prompt_api_error', $msg, ['status' => $status]);
    }

    $prompt = trim($data['choices'][0]['message']['content'] ?? '');
    if ($prompt === '') {
      return new WP_Error('wpi_empty_prompt', __('AI returned an empty image prompt.', 'wp-intelligence'), ['status' => 502]);
    }

    return $prompt;
  }

  /**
   * Step 2: Call DALL-E 3 to generate the image.
   *
   * @return string|WP_Error Image URL on success.
   */
  private function call_dalle(string $prompt, array $style): string|WP_Error {
    $api_key = $this->get_api_key();
    $aspect  = $style['aspect_ratio'] ?? 'landscape';
    $size    = self::SIZE_MAP[$aspect] ?? self::SIZE_MAP['landscape'];

    $body = [
      'model'   => self::IMAGE_MODEL,
      'prompt'  => $prompt,
      'n'       => 1,
      'size'    => $size,
      'quality' => 'standard',
    ];

    $response = wp_remote_post(self::OPENAI_IMAGES_ENDPOINT, [
      'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body'    => wp_json_encode($body),
      'timeout' => self::TIMEOUT,
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('wpi_image_request_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = wp_remote_retrieve_response_code($response);
    $data   = json_decode(wp_remote_retrieve_body($response), true);

    if ($status !== 200) {
      $msg = $data['error']['message'] ?? __('Image generation failed.', 'wp-intelligence');
      return new WP_Error('wpi_image_api_error', $msg, ['status' => $status]);
    }

    $url = $data['data'][0]['url'] ?? '';
    if ($url === '') {
      return new WP_Error('wpi_no_image_url', __('No image URL in the response.', 'wp-intelligence'), ['status' => 502]);
    }

    return $url;
  }

  /**
   * Apply text overlay to a local image file.
   *
   * Composites a semi-transparent band with the post title on the image
   * using PHP GD, producing a branded OG-style image.
   *
   * @param string $file_path  Absolute path to the image file.
   * @param string $title      Post title to render.
   * @param array  $overlay    Overlay settings (position, bg_color, text_color, opacity).
   * @return bool True on success.
   */
  public function apply_text_overlay(string $file_path, string $title, array $overlay): bool {
    if (! function_exists('imagecreatefrompng') || trim($title) === '') {
      return false;
    }

    $mime = wp_check_filetype($file_path)['type'] ?? '';
    $image = match (true) {
      str_contains($mime, 'png')  => @imagecreatefrompng($file_path),
      str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($file_path),
      default                     => @imagecreatefromjpeg($file_path),
    };

    if (! $image) {
      return false;
    }

    $width  = imagesx($image);
    $height = imagesy($image);

    $bg_hex     = ltrim((string) ($overlay['bg_color'] ?? '#000000'), '#');
    $text_hex   = ltrim((string) ($overlay['text_color'] ?? '#ffffff'), '#');
    $opacity    = max(0, min(100, (int) ($overlay['opacity'] ?? 70)));
    $position   = $overlay['position'] ?? 'bottom';
    $show_title = ($overlay['show_title'] ?? '1') === '1';

    if (! $show_title) {
      imagedestroy($image);
      return true;
    }

    $bg_r = hexdec(substr($bg_hex, 0, 2));
    $bg_g = hexdec(substr($bg_hex, 2, 2));
    $bg_b = hexdec(substr($bg_hex, 4, 2));

    $txt_r = hexdec(substr($text_hex, 0, 2));
    $txt_g = hexdec(substr($text_hex, 2, 2));
    $txt_b = hexdec(substr($text_hex, 4, 2));

    $alpha = (int) round(127 * (1 - $opacity / 100));

    imagesavealpha($image, true);
    imagealphablending($image, true);

    $font_path = $this->find_ttf_font();
    $band_height_ratio = 0.22;
    $band_h = (int) round($height * $band_height_ratio);
    $padding_x = (int) round($width * 0.05);
    $padding_y = (int) round($band_h * 0.15);

    $band_y = match ($position) {
      'top'    => 0,
      'center' => (int) round(($height - $band_h) / 2),
      default  => $height - $band_h,
    };

    $overlay_color = imagecolorallocatealpha($image, $bg_r, $bg_g, $bg_b, $alpha);
    imagefilledrectangle($image, 0, $band_y, $width, $band_y + $band_h, $overlay_color);

    $text_color = imagecolorallocate($image, $txt_r, $txt_g, $txt_b);

    $usable_w = $width - ($padding_x * 2);
    $usable_h = $band_h - ($padding_y * 2);
    $text_x = $padding_x;
    $text_y_start = $band_y + $padding_y;

    if ($font_path !== '') {
      $font_size = $this->fit_font_size($font_path, $title, $usable_w, $usable_h);
      $bbox = imagettfbbox($font_size, 0, $font_path, $title);
      $lines = $this->wrap_text_ttf($font_path, $font_size, $title, $usable_w);
      $line_height = (int) round($font_size * 1.35);

      $total_text_h = count($lines) * $line_height;
      $text_y = $text_y_start + (int) round(($usable_h - $total_text_h) / 2) + $font_size;

      foreach ($lines as $line) {
        imagettftext($image, $font_size, 0, $text_x, $text_y, $text_color, $font_path, $line);
        $text_y += $line_height;
      }
    } else {
      $gd_font = 5;
      $char_w = imagefontwidth($gd_font);
      $char_h = imagefontheight($gd_font);
      $max_chars = (int) floor($usable_w / $char_w);

      if ($max_chars < 5) {
        imagedestroy($image);
        return false;
      }

      $wrapped = wordwrap($title, $max_chars, "\n", true);
      $lines = explode("\n", $wrapped);
      $total_h = count($lines) * ($char_h + 4);
      $cur_y = $text_y_start + (int) round(($usable_h - $total_h) / 2);

      foreach ($lines as $line) {
        imagestring($image, $gd_font, $text_x, $cur_y, $line, $text_color);
        $cur_y += $char_h + 4;
      }
    }

    $result = match (true) {
      str_contains($mime, 'png')  => imagepng($image, $file_path),
      str_contains($mime, 'webp') && function_exists('imagewebp') => imagewebp($image, $file_path, 90),
      default                     => imagejpeg($image, $file_path, 92),
    };

    imagedestroy($image);
    return (bool) $result;
  }

  /**
   * Auto-size font to fit within the available space.
   */
  private function fit_font_size(string $font_path, string $text, int $max_w, int $max_h): float {
    $size = 48.0;
    $min  = 14.0;

    while ($size > $min) {
      $lines = $this->wrap_text_ttf($font_path, $size, $text, $max_w);
      $total_h = count($lines) * $size * 1.35;
      if ($total_h <= $max_h && count($lines) <= 4) {
        return $size;
      }
      $size -= 2;
    }

    return $min;
  }

  /**
   * Word-wrap text using TTF font metrics.
   *
   * @return string[]
   */
  private function wrap_text_ttf(string $font_path, float $size, string $text, int $max_w): array {
    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
      $test = $current === '' ? $word : $current . ' ' . $word;
      $bbox = imagettfbbox($size, 0, $font_path, $test);
      $line_w = abs($bbox[2] - $bbox[0]);

      if ($line_w > $max_w && $current !== '') {
        $lines[] = $current;
        $current = $word;
      } else {
        $current = $test;
      }
    }
    if ($current !== '') {
      $lines[] = $current;
    }

    return $lines;
  }

  /**
   * Locate a TTF font on the server.
   */
  private function find_ttf_font(): string {
    $custom = apply_filters('wpi_featured_image_font_path', '');
    if ($custom !== '' && file_exists($custom)) {
      return $custom;
    }

    $candidates = [
      '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
      '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
      '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
      '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
      '/Library/Fonts/Arial Bold.ttf',
      '/System/Library/Fonts/Helvetica.ttc',
      'C:/Windows/Fonts/arialbd.ttf',
    ];

    foreach ($candidates as $path) {
      if (file_exists($path)) {
        return $path;
      }
    }

    return '';
  }

  private function has_native_client(): bool {
    return function_exists('wp_ai_client_prompt');
  }

  /**
   * Same model resolution as AI_Composer_Provider.
   */
  private function get_model(): string {
    $settings = get_option('ai_composer_settings', []);
    $model    = $settings['model'] ?? self::DEFAULT_MODEL;
    return apply_filters('ai_composer_model', $model);
  }

  /**
   * Same resolution order as AI_Composer_Provider.
   */
  private function get_api_key(): string {
    if (defined('AI_COMPOSER_OPENAI_API_KEY') && AI_COMPOSER_OPENAI_API_KEY !== '') {
      return AI_COMPOSER_OPENAI_API_KEY;
    }

    $env = getenv('AI_COMPOSER_OPENAI_API_KEY');
    if (is_string($env) && $env !== '') {
      return $env;
    }

    $settings = get_option('ai_composer_settings', []);
    if (! empty($settings['api_key'])) {
      return (string) $settings['api_key'];
    }

    return apply_filters('ai_composer_openai_api_key', '');
  }
}
