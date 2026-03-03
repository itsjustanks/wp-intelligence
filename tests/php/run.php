<?php
declare(strict_types=1);

$tests = [
  __DIR__ . '/test-provider-readiness.php',
  __DIR__ . '/test-syndication-url-validation.php',
];

$failures = [];

foreach ($tests as $test_file) {
  try {
    require $test_file;
    echo "PASS: " . basename($test_file) . PHP_EOL;
  } catch (Throwable $exception) {
    $failures[] = [
      'file'    => basename($test_file),
      'message' => $exception->getMessage(),
    ];
    echo "FAIL: " . basename($test_file) . PHP_EOL;
    echo "  " . $exception->getMessage() . PHP_EOL;
  }
}

if (! empty($failures)) {
  exit(1);
}

echo "All PHP smoke tests passed." . PHP_EOL;
