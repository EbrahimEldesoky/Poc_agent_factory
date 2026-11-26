<?php
declare(strict_types=1);

const WEAK_SECRET_VALUES = [
    'changeme',
    'change-me',
    'password',
    'secret',
    'default',
    'agentfactory',
    'replace-me',
    'placeholder',
    'your-secret-here',
    'your-token-here',
    '12345678',
    'test',
    'example',
];

function is_weak_secret(?string $value, int $minLen = 16): bool
{
    if ($value === null) {
        return true;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || strlen($trimmed) < $minLen) {
        return true;
    }
    $lower = strtolower($trimmed);
    if (in_array($lower, WEAK_SECRET_VALUES, true)) {
        return true;
    }
    if (str_starts_with($lower, 'changeme') || str_starts_with($lower, 'replace-me')) {
        return true;
    }
    return false;
}

/**
 * Validates required environment variables without ever including their values in errors.
 *
 * @param array<string, string|false> $env
 * @return array<int, string>
 */
function validate_api_env(array $env, bool $requireSessionSecret = true): array
{
    $errors = [];
    $get = static fn(string $k): string => is_string($env[$k] ?? null) ? $env[$k] : '';

    if (is_weak_secret($get('BROKER_TOKEN'), 16)) {
        $errors[] = 'BROKER_TOKEN must be set to a non-default secret of at least 16 characters';
    }
    if ($requireSessionSecret && is_weak_secret($get('SESSION_SECRET'), 16)) {
        $errors[] = 'SESSION_SECRET must be set to a non-default secret of at least 16 characters';
    }
    if (is_weak_secret($get('OWNER_PASSWORD'), 8)) {
        $errors[] = 'OWNER_PASSWORD must be set to a non-default password of at least 8 characters';
    }

    $dbUrl = trim($get('DATABASE_URL'));
    $dbParts = $dbUrl !== '' ? parse_url($dbUrl) : false;
    if (!is_array($dbParts) || empty($dbParts['host']) || empty($dbParts['pass'])) {
        $errors[] = 'DATABASE_URL must be a valid PostgreSQL URL with host and password';
    }

    $brokerUrl = trim($get('BROKER_URL'));
    $brokerParts = $brokerUrl !== '' ? parse_url($brokerUrl) : false;
    if (!is_array($brokerParts) || !in_array($brokerParts['scheme'] ?? '', ['http', 'https'], true) || empty($brokerParts['host'])) {
        $errors[] = 'BROKER_URL must be a valid http(s) URL';
    }

    return $errors;
}

/** @return array<string, string|false> */
function current_api_env(): array
{
    return [
        'BROKER_TOKEN' => getenv('BROKER_TOKEN'),
        'SESSION_SECRET' => getenv('SESSION_SECRET'),
        'OWNER_PASSWORD' => getenv('OWNER_PASSWORD'),
        'DATABASE_URL' => getenv('DATABASE_URL'),
        'BROKER_URL' => getenv('BROKER_URL'),
    ];
}

function assert_api_config(bool $requireSessionSecret = true): void
{
    $errors = validate_api_env(current_api_env(), $requireSessionSecret);
    if ($errors !== []) {
        fwrite(STDERR, "Configuration error:\n - " . implode("\n - ", $errors) . "\n");
        exit(1);
    }
}
