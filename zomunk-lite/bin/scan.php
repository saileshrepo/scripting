<?php
/**
 * Price the watchlist and record any deal found. Run on a cron:
 *
 *   0 0,4,8,12,16,20 * * *  php /path/to/zomunk-lite/bin/scan.php >> /var/log/zomunk-scan.log 2>&1
 *
 * Options:
 *   --provider=amadeus|sample   override ZOMUNK_PROVIDER
 *   --months=3                  only look this many months ahead
 *   --max-requests=50           cap provider calls (free API tiers are metered)
 *   --routes=DEL-LHR,BOM-DXB    scan only these routes
 *   --dry-run                   evaluate and print, write nothing
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;
use Zomunk\ProviderFactory;
use Zomunk\Scanner;

$options = getopt('', ['provider::', 'months::', 'max-requests::', 'routes::', 'dry-run']);
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

$provider = ProviderFactory::make($options['provider'] ?? null);
$expired = $repository->expireStaleDeals();

echo sprintf(
    "Scan starting: provider=%s routes=%d dry-run=%s (expired %d stale deal(s))\n",
    $provider->name(), count($routes), $dryRun ? 'yes' : 'no', $expired,
);

$runId = $dryRun ? 0 : $repository->startScanRun($provider->name());
$scanner = new Scanner($provider, $engine, $repository);

$result = $scanner->run($routes, [
    'dry_run'      => $dryRun,
    'months'       => isset($options['months']) ? (int) $options['months'] : null,
    'max_requests' => isset($options['max-requests']) ? (int) $options['max-requests'] : PHP_INT_MAX,
]);

if (!$dryRun) {
    $repository->finishScanRun($runId, $result['stats'], $result['errors']);
}

printf(
    "\nDone. routes=%d requests=%d offers=%d bookable=%d deals=%d errors=%d\n",
    $result['stats']['routes'], $result['stats']['requests'], $result['stats']['offers'],
    $result['stats']['kept'], $result['stats']['deals'], count($result['errors']),
);

// A route that failed to price is logged, not fatal: the rest of the pass is
// still worth keeping, and a non-zero exit would mail the operator every time a
// single date pair 500s.
exit(0);
