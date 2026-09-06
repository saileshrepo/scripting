<?php
/**
 * Email pending deals through Sender. Run after the scan:
 *
 *   30 0,4,8,12,16,20 * * *  php /path/to/zomunk-lite/bin/notify.php >> /var/log/zomunk-notify.log 2>&1
 *
 * Options:
 *   --tier=free|premium   only this tier (default: both)
 *   --dry-run             build the email and write var/preview-<tier>.html, send nothing
 *   --limit=25            most deals to put in one digest
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;
use Zomunk\Notifier\DealMailer;
use Zomunk\Notifier\SenderClient;

$options = getopt('', ['tier::', 'dry-run', 'limit::']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 25;
$tiers = isset($options['tier']) ? [$options['tier']] : ['premium', 'free'];

Db::migrate();

$config = zomunk_config();
$repository = new DealRepository(DealEngine::fromConfig());
$repository->expireStaleDeals();

$mailer = new DealMailer(new SenderClient($config['sender']['token']), $repository, $config);

$failed = false;
foreach ($tiers as $tier) {
    if (!in_array($tier, ['free', 'premium'], true)) {
        fwrite(STDERR, "Unknown tier '$tier'\n");
        exit(1);
    }
    $result = $mailer->sendTier($tier, $dryRun, $limit);
    $failed = $failed || $result['status'] === 'failed';
}

exit($failed ? 1 : 0);
