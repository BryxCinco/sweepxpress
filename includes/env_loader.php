<?php
// includes/env_loader.php — minimal .env loader (no Composer)
// Reads KEY=VALUE lines from the project-root .env into $_ENV and getenv().
// Existing values are never overwritten (so config_oauth.php's Dotenv still works).

if (!function_exists('env')) {
    (function () {
        $envFile = dirname(__DIR__) . '/.env';
        if (!is_readable($envFile)) return;

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $q = $value[0] ?? '';
            if (($q === '"' || $q === "'") && strlen($value) > 1 && substr($value, -1) === $q) {
                $value = substr($value, 1, -1); // strip surrounding quotes
            }
            if (!isset($_ENV[$key]) && getenv($key) === false) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    })();

    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}
