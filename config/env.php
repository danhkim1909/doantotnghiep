<?php

/** Load local .env values without replacing server-provided environment variables. */
function load_app_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if ($key === '' || getenv($key) !== false) continue;
        if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) $value = substr($value, 1, -1);
        putenv($key . '=' . $value);
    }
}

load_app_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}
