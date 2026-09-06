<?php
/**
 * Loads .env (if present) and exposes typed configuration.
 * Real environment variables always win over .env, so the same code runs
 * unchanged under cron, a container or a shared host.
 */

function zomunk_load_env(string $path): void
{
    static $loaded = [];
    if (isset($loaded[$path]) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

function zomunk_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function zomunk_env_float(string $key, float $default): float
{
    $value = zomunk_env($key);
    return $value === null ? $default : (float) $value;
}

function zomunk_env_int(string $key, int $default): int
{
    $value = zomunk_env($key);
    return $value === null ? $default : (int) $value;
}

function zomunk_env_bool(string $key, bool $default): bool
{
    $value = zomunk_env($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function zomunk_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__);
    zomunk_load_env($root . '/.env');

    $config = [
        'root'     => $root,
        'provider' => zomunk_env('ZOMUNK_PROVIDER', 'sample'),
        'base_url' => rtrim(zomunk_env('ZOMUNK_BASE_URL', 'http://localhost:8000'), '/'),
        'amadeus'  => [
            'client_id'     => zomunk_env('AMADEUS_CLIENT_ID', ''),
            'client_secret' => zomunk_env('AMADEUS_CLIENT_SECRET', ''),
            'base'          => zomunk_env('AMADEUS_ENV', 'test') === 'production'
                ? 'https://api.amadeus.com'
                : 'https://test.api.amadeus.com',
        ],
        'sender'   => [
            'token'         => zomunk_env('SENDER_API_TOKEN', ''),
            'group_free'    => zomunk_env('SENDER_GROUP_FREE', ''),
            'group_premium' => zomunk_env('SENDER_GROUP_PREMIUM', ''),
            'from_email'    => zomunk_env('SENDER_FROM_EMAIL', 'deals@example.com'),
            'from_name'     => zomunk_env('SENDER_FROM_NAME', 'Zomunk Lite'),
            'reply_to'      => zomunk_env('SENDER_REPLY_TO', zomunk_env('SENDER_FROM_EMAIL', 'deals@example.com')),
        ],
        'db'       => [
            'dsn'      => zomunk_env('ZOMUNK_DB_DSN', 'sqlite:' . $root . '/var/zomunk.sqlite'),
            'user'     => zomunk_env('ZOMUNK_DB_USER', ''),
            'password' => zomunk_env('ZOMUNK_DB_PASSWORD', ''),
        ],
        'rules'    => [
            'min_discount'       => zomunk_env_float('ZOMUNK_MIN_DISCOUNT', 0.40),
            'premium_discount'   => zomunk_env_float('ZOMUNK_PREMIUM_DISCOUNT', 0.70),
            'free_delay_hours'   => zomunk_env_int('ZOMUNK_FREE_DELAY_HOURS', 24),
            'max_stops'          => zomunk_env_int('ZOMUNK_MAX_STOPS', 2),
            'min_layover'        => zomunk_env_int('ZOMUNK_MIN_LAYOVER_MINUTES', 45),
            'max_layover'        => zomunk_env_int('ZOMUNK_MAX_LAYOVER_MINUTES', 300),
            'require_bag'        => zomunk_env_bool('ZOMUNK_REQUIRE_CHECKED_BAG', true),
            'passport'           => zomunk_env('ZOMUNK_PASSPORT', 'IN'),
            'realert_drop'       => zomunk_env_float('ZOMUNK_REALERT_DROP', 0.10),
            'realert_days'       => zomunk_env_int('ZOMUNK_REALERT_DAYS', 14),
            // A typical fare is only trusted once we have this many observations.
            'min_history_points' => zomunk_env_int('ZOMUNK_MIN_HISTORY_POINTS', 5),
            'history_window_days'=> zomunk_env_int('ZOMUNK_HISTORY_WINDOW_DAYS', 180),
        ],
    ];

    return $config;
}
