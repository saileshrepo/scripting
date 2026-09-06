<?php
/**
 * Preflight check. Run this after filling in .env and before trusting a cron:
 *
 *   php bin/doctor.php
 *
 * It checks the runtime, the database, the fare provider and Sender, and tells
 * you exactly what is wrong with each rather than failing later inside a scan.
 * Exits non-zero if anything required is broken.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;
use Zomunk\Notifier\SenderClient;
use Zomunk\ProviderFactory;

$failures = 0;
$warnings = 0;

function ok(string $label, string $detail = ''): void
{
    printf("  [ ok ] %-28s %s\n", $label, $detail);
}

function warn(string $label, string $detail): void
{
    global $warnings;
    $warnings++;
    printf("  [warn] %-28s %s\n", $label, $detail);
}

function bad(string $label, string $detail): void
{
    global $failures;
    $failures++;
    printf("  [FAIL] %-28s %s\n", $label, $detail);
}

$config = zomunk_config();

// --- runtime ---------------------------------------------------------------

echo "Runtime\n";

PHP_VERSION_ID >= 80100
    ? ok('PHP version', PHP_VERSION)
    : bad('PHP version', PHP_VERSION . ' — this project needs PHP 8.1 or newer');

foreach (['pdo', 'curl', 'json'] as $extension) {
    extension_loaded($extension)
        ? ok("ext/$extension", 'loaded')
        : bad("ext/$extension", 'missing — install it or the app cannot run');
}

is_file($config['root'] . '/.env')
    ? ok('.env', 'found')
    : warn('.env', 'not found — running entirely on defaults, copy .env.example to .env');

$varDir = $config['root'] . '/var';
is_writable($varDir)
    ? ok('var/ writable', $varDir)
    : bad('var/ writable', "$varDir is not writable — the database and previews go here");

// --- database --------------------------------------------------------------

echo "\nDatabase\n";

try {
    Db::migrate();
    ok('connection', Db::driver() . ' via ' . preg_replace('/(password|pwd)=[^;]*/i', '$1=***', $config['db']['dsn']));

    $repository = new DealRepository(DealEngine::fromConfig());
    $routes = Db::query('SELECT COUNT(*) AS c FROM routes WHERE active = 1')[0]['c'] ?? 0;
    $routes > 0
        ? ok('watchlist', "$routes active route(s)")
        : warn('watchlist', 'no routes yet — run php bin/init-db.php');

    $deals = Db::query("SELECT COUNT(*) AS c FROM deals WHERE status = 'active'")[0]['c'] ?? 0;
    $history = Db::query('SELECT COUNT(*) AS c FROM fare_history')[0]['c'] ?? 0;
    ok('data', "$deals live deal(s), $history fare observation(s)");

    if ($history > 0 && $history < 50) {
        warn('baselines', "only $history observations — deals will use seed estimates until history builds up");
    }
} catch (Throwable $e) {
    bad('connection', $e->getMessage());
}

// --- stage 1: wide sweep ---------------------------------------------------

echo "\nStage 1 sweep (ZOMUNK_CANDIDATE_PROVIDER={$config['candidate_provider']})\n";

if ($config['candidate_provider'] === 'none') {
    warn('sweep', 'disabled — every date is priced with a metered call, which burns a free tier fast');
} elseif ($config['candidate_provider'] === 'sample') {
    warn('sweep', 'sample = synthetic offline prices. Set ZOMUNK_CANDIDATE_PROVIDER=travelpayouts for real data.');
} elseif ($config['candidate_provider'] === 'travelpayouts') {
    if ($config['travelpayouts']['token'] === '') {
        bad('token', 'TRAVELPAYOUTS_TOKEN is empty');
    } else {
        try {
            $sweep = ProviderFactory::makeCandidateProvider('travelpayouts');
            $month = (new DateTimeImmutable('+2 months'))->format('Y-m');
            $found = $sweep->scanMonth(
                ['origin' => 'DEL', 'destination' => 'DXB', 'currency' => 'INR'], $month, 7
            );
            $found === []
                ? warn('live sweep', "DEL-DXB $month returned no cached prices")
                : ok('live sweep', sprintf('DEL-DXB %s: %d date(s), cheapest %s',
                    $month, count($found),
                    number_format(min(array_map(static fn($c) => $c->price, $found)))));
        } catch (Throwable $e) {
            bad('live sweep', $e->getMessage());
        }
    }
} else {
    bad('sweep', "unknown candidate provider '{$config['candidate_provider']}'");
}

// --- stage 2: verification -------------------------------------------------

echo "\nStage 2 verification (ZOMUNK_PROVIDER={$config['provider']})\n";

if ($config['provider'] === 'none') {
    warn('provider', 'disabled — candidates cannot be checked against a real itinerary, '
        . 'so the layover, duration, transit-visa and bag rules never run');
} elseif ($config['provider'] === 'sample') {
    warn('provider', 'sample = synthetic offline fares. Set ZOMUNK_PROVIDER=amadeus for real data.');
} elseif ($config['provider'] === 'amadeus') {
    if ($config['amadeus']['client_id'] === '' || $config['amadeus']['client_secret'] === '') {
        bad('credentials', 'AMADEUS_CLIENT_ID / AMADEUS_CLIENT_SECRET are empty');
    } else {
        ok('credentials', 'present, host ' . $config['amadeus']['base']);
        try {
            // A real search: proves the token, the host and the quota all work.
            $provider = ProviderFactory::make('amadeus');
            $departure = (new DateTimeImmutable('+45 days'))->format('Y-m-d');
            $return = (new DateTimeImmutable('+52 days'))->format('Y-m-d');
            $offers = $provider->search([
                'origin' => 'DEL', 'destination' => 'DXB', 'cabin' => 'ECONOMY',
                'currency' => 'INR', 'adults' => 1,
            ], $departure, $return, 3);

            if ($offers === []) {
                warn('live search', "DEL-DXB $departure returned no offers (the test host has sparse data)");
            } else {
                $cheapest = min(array_map(static fn($offer) => $offer->price, $offers));
                ok('live search', sprintf('DEL-DXB %s: %d offer(s), cheapest %s %s',
                    $departure, count($offers), $offers[0]->currency, number_format($cheapest)));
            }
        } catch (Throwable $e) {
            bad('live search', $e->getMessage());
        }
    }
} else {
    bad('provider', "unknown provider '{$config['provider']}'");
}

// --- notifications ---------------------------------------------------------

echo "\nNotifications (Sender)\n";

$sender = new SenderClient($config['sender']['token']);

if (!$sender->isConfigured()) {
    warn('token', 'SENDER_API_TOKEN is empty — deals are found but no email goes out');
} else {
    try {
        $groups = $sender->listGroups();
        ok('token', count($groups) . ' group(s) visible');

        $ids = array_column($groups, 'id');
        foreach (['free' => 'group_free', 'premium' => 'group_premium'] as $tier => $key) {
            $configured = $config['sender'][$key];
            if ($configured === '') {
                warn("$tier group", 'not set — run php bin/sender-check.php --create');
            } elseif (!in_array((string) $configured, $ids, true)) {
                bad("$tier group", "id '$configured' is not in this Sender account");
            } else {
                ok("$tier group", $configured);
            }
        }

        filter_var($config['sender']['from_email'], FILTER_VALIDATE_EMAIL)
            ? ok('from address', $config['sender']['from_email'])
            : bad('from address', "SENDER_FROM_EMAIL '{$config['sender']['from_email']}' is not a valid address");
    } catch (Throwable $e) {
        bad('token', $e->getMessage());
    }
}

// --- summary ---------------------------------------------------------------

echo "\n";
if ($failures > 0) {
    echo "$failures failure(s), $warnings warning(s). Fix the failures before running a scan.\n";
    exit(1);
}
echo $warnings > 0
    ? "Ready, with $warnings warning(s) above.\n"
    : "Everything checks out. php bin/scan.php && php bin/notify.php\n";
exit(0);
