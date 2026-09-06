<?php
/**
 * Re-pushes members whose Sender sync never succeeded (provider outage, token
 * added after signup). Safe to run on a daily cron.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\Notifier\SenderClient;
use Zomunk\SubscriberService;

Db::migrate();

$config = zomunk_config();
$service = new SubscriberService(new SenderClient($config['sender']['token']), $config);
$results = $service->resyncPending();

if ($results === []) {
    echo "Nothing to resync.\n";
    exit(0);
}

foreach ($results as $result) {
    printf("  %-32s %-8s %s\n", $result['email'], $result['tier'], $result['sender']);
}
printf("%d subscriber(s) processed.\n", count($results));
