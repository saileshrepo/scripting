<?php
/**
 * Price the watchlist and record any deal found. Run on a cron:
 *
 *   0 0,4,8,12,16,20 * * *  php /path/to/zomunk-lite/bin/scan.php >> /var/log/zomunk-scan.log 2>&1
 *
 * Runs in two stages when a wide-sweep provider is configured: a cheap calendar
 * sweep finds price candidates, then only those get a metered shopping call to
 * fetch the real itinerary the quality rules need. With no sweep provider it
 * falls back to pricing a spread of dates directly, which costs far more calls.
 *
 * Options:
 *   --provider=amadeus|sample   override ZOMUNK_PROVIDER (stage 2)
 *   --sweep=travelpayouts|sample|none   override ZOMUNK_CANDIDATE_PROVIDER (stage 1)
 *   --months=3                  only look this many months ahead
 *   --verify-budget=20          cap stage 2 shopping calls
 *   --max-requests=50           cap calls in single-stage mode
 *   --routes=DEL-LHR,BOM-DXB    scan only these routes
 *   --trust-unverified          save candidates as deals without stage 2
 *                               (itinerary rules cannot run; use with care)
 *   --dry-run                   evaluate and print, write nothing
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;
use Zomunk\ProviderFactory;
use Zomunk\Scanner;
use Zomunk\TwoStageScanner;

$options = getopt('', [
    'provider::', 'sweep::', 'months::', 'max-requests::', 'verify-budget::',
    'routes::', 'dry-run', 'trust-unverified',
]);
$dryRun = isset($options['dry-run']);

Db::migrate();

$config = zomunk_config();
$engine = DealEngine::fromConfig();
$repository = new DealRepository($engine);
$routes = $repository->syncRoutes(require $config['root'] . '/config/routes.php');

if (!empty($options['routes'])) {
    $wanted = array_map('strtoupper', array_map('trim', explode(',', $options['routes'])));
    $routes = array_values(array_filter(
        $routes,
        static fn(array $route) => in_array($route['origin'] . '-' . $route['destination'], $wanted, true),
    ));
    if ($routes === []) {
        fwrite(STDERR, "No watchlist route matched --routes={$options['routes']}\n");
        exit(1);
    }
}

$sweep = ProviderFactory::makeCandidateProvider($options['sweep'] ?? null);
$expired = $repository->expireStaleDeals();

if ($sweep !== null) {
    // Two-stage: the verifier may legitimately be absent, in which case
    // candidates are reported but only saved with --trust-unverified.
    $verifier = ($options['provider'] ?? $config['provider']) === 'none'
        ? null
        : ProviderFactory::make($options['provider'] ?? null);

    echo sprintf(
        "Scan starting: sweep=%s verify=%s routes=%d dry-run=%s (expired %d stale deal(s))\n",
        $sweep->name(), $verifier?->name() ?? 'none', count($routes), $dryRun ? 'yes' : 'no', $expired,
    );

    $runId = $dryRun ? 0 : $repository->startScanRun($sweep->name() . '+' . ($verifier?->name() ?? 'none'));
    $scanner = new TwoStageScanner($sweep, $verifier, $engine, $repository);

    $result = $scanner->run($routes, [
        'dry_run'          => $dryRun,
        'months'           => isset($options['months']) ? (int) $options['months'] : 6,
        'verify_budget'    => isset($options['verify-budget'])
            ? (int) $options['verify-budget'] : $config['verify_budget'],
        'trust_unverified' => isset($options['trust-unverified']),
    ]);
} else {
    $provider = ProviderFactory::make($options['provider'] ?? null);

    echo sprintf(
        "Scan starting: provider=%s (single stage) routes=%d dry-run=%s (expired %d stale deal(s))\n",
        $provider->name(), count($routes), $dryRun ? 'yes' : 'no', $expired,
    );

    $runId = $dryRun ? 0 : $repository->startScanRun($provider->name());
    $scanner = new Scanner($provider, $engine, $repository);

    $result = $scanner->run($routes, [
        'dry_run'      => $dryRun,
        'months'       => isset($options['months']) ? (int) $options['months'] : null,
        'max_requests' => isset($options['max-requests']) ? (int) $options['max-requests'] : PHP_INT_MAX,
    ]);
}

if (!$dryRun) {
    $repository->finishScanRun($runId, $result['stats'], $result['errors']);
}

$stats = $result['stats'];
echo "\nDone. " . implode('  ', array_map(
    static fn(string $key, $value) => "$key=$value",
    array_keys($stats),
    array_values($stats),
)) . '  errors=' . count($result['errors']) . "\n";

// A route that failed to price is logged, not fatal: the rest of the pass is
// still worth keeping, and a non-zero exit would mail the operator every time a
// single date pair 500s.
exit(0);
