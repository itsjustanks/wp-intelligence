<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/features/content-intelligence/class-content-intelligence.php';

wpi_test_assert_true(
  AI_Composer_Syndication::validate_url('https://example.com/news/story'),
  'Public HTTPS URLs should pass syndication validation.'
);

wpi_test_assert_same(
  false,
  AI_Composer_Syndication::validate_url('http://127.0.0.1/internal'),
  'Loopback hosts should be rejected by syndication URL safety policy.'
);

wpi_test_assert_same(
  false,
  AI_Composer_Syndication::validate_url('file:///etc/passwd'),
  'Non-HTTP schemes should be rejected by syndication URL validation.'
);
