<?php
/** Signup handler: stores the member and pushes them into a Sender group. */

require __DIR__ . '/_init.php';

use Zomunk\Notifier\SenderClient;
use Zomunk\SubscriberService;

if (isset($_GET['logout'])) {
    setcookie('zomunk_email', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#join');
    exit;
}

if (!zomunk_csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(400);
    $error = 'Your session expired. Please try again.';
} else {
    $service = new SubscriberService(new SenderClient($config['sender']['token']), $config);
    try {
        $result = $service->register(
            (string) ($_POST['email'] ?? ''),
            trim((string) ($_POST['name'] ?? '')) ?: null,
            (string) ($_POST['tier'] ?? 'free'),
            array_filter(array_map('trim', explode(',', (string) ($_POST['home_airports'] ?? '')))),
        );
        setcookie('zomunk_email', $result['email'], [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($result['sender'] === 'synced') {
            header('Location: index.php?joined=1');
            exit;
        }
        $error = null;
        $notice = $result['message'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<?php
require __DIR__ . '/_layout.php';
zomunk_head('Signup - Flight Deal Alerts');
?>
<?php if (!empty($error)): ?>
  <div class="flash err" style="margin-top:28px;"><?= e($error) ?></div>
<?php else: ?>
  <div class="flash ok" style="margin-top:28px;"><?= e($notice ?? 'You are on the list.') ?></div>
<?php endif; ?>
<p><a class="btn" href="index.php">Back to the deal board</a></p>
<?php zomunk_foot(); ?>
