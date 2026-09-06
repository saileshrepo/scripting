<?php
/**
 * Verifies the Sender configuration end to end and prints the group ids to put
 * in .env. Run this first after adding SENDER_API_TOKEN.
 *
 *   php bin/sender-check.php                 list groups
 *   php bin/sender-check.php --create        create the two groups if missing
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Notifier\SenderClient;

$options = getopt('', ['create']);
$config = zomunk_config();
$sender = new SenderClient($config['sender']['token']);

if (!$sender->isConfigured()) {
    fwrite(STDERR, "SENDER_API_TOKEN is not set. Sender -> Settings -> API access tokens.\n");
    exit(1);
}

try {
    $groups = $sender->listGroups();
} catch (Throwable $e) {
    fwrite(STDERR, 'Sender check failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Token accepted. Groups in this account:\n";
foreach ($groups as $group) {
    printf("  %-24s %s%s\n", $group['id'], $group['title'],
        $group['subscribers'] !== null ? " ({$group['subscribers']} subscribers)" : '');
}

$wanted = ['Zomunk Lite - Free' => 'SENDER_GROUP_FREE', 'Zomunk Lite - Premium' => 'SENDER_GROUP_PREMIUM'];
$byTitle = array_column($groups, 'id', 'title');

foreach ($wanted as $title => $envKey) {
    if (isset($byTitle[$title])) {
        echo "\n$envKey={$byTitle[$title]}\n";
        continue;
    }
    if (!isset($options['create'])) {
        echo "\nMissing group \"$title\". Create it in Sender, or re-run with --create.\n";
        continue;
    }
    $created = $sender->createGroup($title);
    $id = $created['data']['id'] ?? $created['id'] ?? '?';
    echo "\nCreated \"$title\" -> $envKey=$id\n";
}
