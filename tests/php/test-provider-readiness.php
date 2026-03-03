<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/features/ai-composer/class-provider.php';

$GLOBALS['wpi_test_options'] = [
  'ai_composer_settings' => [],
];

$provider = new AI_Composer_Provider();
$status = $provider->get_readiness_status();

wpi_test_assert_same(false, $status['can_compose'], 'Provider should not be ready without native runtime or API key.');
wpi_test_assert_same('none', $status['runtime'], 'Runtime should be "none" when no provider is configured.');
wpi_test_assert_same(true, $status['requires_configuration'], 'Provider should require configuration when API key is absent.');

$GLOBALS['wpi_test_options']['ai_composer_settings'] = [
  'api_key' => 'sk-test-key',
  'model'   => 'gpt-4.1-mini',
];

$status_with_key = $provider->get_readiness_status();

wpi_test_assert_same(true, $status_with_key['can_compose'], 'Provider should be ready when OpenAI API key is present.');
wpi_test_assert_same('openai-direct', $status_with_key['runtime'], 'Runtime should be openai-direct when API key is configured.');
wpi_test_assert_same('gpt-4.1-mini', $status_with_key['model'], 'Provider model should reflect configured fallback model.');
