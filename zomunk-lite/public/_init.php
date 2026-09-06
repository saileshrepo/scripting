<?php
/** Shared front-end bootstrap: config, db, viewer identity, CSRF, helpers. */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Db::migrate();

$config = zomunk_config();
$engine = DealEngine::fromConfig();
$repository = new DealRepository($engine);

/**
 * Who is looking. Deliberately lightweight: a signed-up email in a cookie is
 * enough to decide free vs premium for a demo. Put a real login in front of
 * this before charging anyone for premium.
 */
function zomunk_viewer(): array
{
    $email = $_COOKIE['zomunk_email'] ?? '';
    if ($email === '') {
        return ['email' => null, 'tier' => 'guest', 'name' => null];
    }
    $row = Db::one('SELECT email, name, tier FROM subscribers WHERE email = ?', [strtolower($email)]);
    if ($row === null) {
        return ['email' => null, 'tier' => 'guest', 'name' => null];
    }
    return ['email' => $row['email'], 'tier' => $row['tier'], 'name' => $row['name']];
}

function zomunk_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function zomunk_csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Is this deal past the free-tier head-start window? */
function zomunk_released_to_free(array $deal): bool
{
    return $deal['tier'] === 'free'
        && $deal['publish_free_at'] !== null
        && $deal['publish_free_at'] <= gmdate('Y-m-d H:i:s');
}
