<?php
/** Creates the schema and loads the watchlist from config/routes.php. */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;

Db::migrate();
echo 'Schema applied (' . Db::driver() . ").\n";

$repository = new DealRepository(DealEngine::fromConfig());
$routes = $repository->syncRoutes(require zomunk_config()['root'] . '/config/routes.php');

echo 'Watchlist synced: ' . count($routes) . " routes.\n";
foreach ($routes as $route) {
    printf("  #%-3d %s-%s  %s (seed typical %s)\n",
        $route['id'], $route['origin'], $route['destination'], $route['label'],
        number_format((float) $route['typical_fare_inr']));
}
