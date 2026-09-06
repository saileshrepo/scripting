<?php
/** One deal, with the itinerary and the numbers behind the discount. */

require __DIR__ . '/_init.php';

use Zomunk\Money;
use Zomunk\Offer;

$viewer = zomunk_viewer();
$deal = $repository->findDeal((int) ($_GET['id'] ?? 0));

if ($deal === null) {
    http_response_code(404);
    $message = 'That deal does not exist.';
} elseif ($viewer['tier'] !== 'premium' && !zomunk_released_to_free($deal)) {
    // Premium-only deals, and free deals still inside their head-start window,
    // stay closed even to someone who guesses the id.
    http_response_code(403);
    $deal = null;
    $message = $viewer['email'] === null
        ? 'This deal is for members. Sign up on the deal board to get it.'
        : 'Premium members see this one first. It opens up to free members shortly.';
} elseif ($deal['status'] !== 'active') {
    $message = 'This fare has expired. Airlines rarely leave these up for long.';
} else {
    $message = null;
}

$offer = $deal !== null ? json_decode((string) $deal['offer_json'], true) : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $deal !== null ? e($deal['label']) : 'Deal not available' ?> - Zomunk Lite</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
<header class="site"><h1><a href="index.php" style="text-decoration:none;color:inherit;">Zomunk Lite</a></h1></header>

<?php if ($message !== null): ?>
  <div class="flash err"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($deal !== null): ?>
<section class="panel">
  <h2>
    <?= e($deal['label']) ?>
    <?php if ((int) $deal['is_mistake'] === 1): ?><span class="badge hot">MISTAKE FARE</span><?php endif; ?>
  </h2>
  <p>
    <span class="price" style="font-size:30px;font-weight:700;">
      <?= e(Money::format((float) $deal['price'], $deal['currency'])) ?>
    </span>
    <span class="was"><?= e(Money::format((float) $deal['typical_fare'], $deal['currency'])) ?></span>
    <span class="badge"><?= (int) round((float) $deal['discount'] * 100) ?>% off</span>
  </p>

  <dl class="spec">
    <dt>Dates</dt>
    <dd><?= e($deal['depart_date']) ?><?= $deal['return_date'] ? ' &rarr; ' . e($deal['return_date']) : '' ?></dd>
    <dt>Airline</dt><dd><?= e($deal['carrier_name'] ?: $deal['carrier_code']) ?></dd>
    <dt>Cabin</dt><dd><?= e($deal['cabin']) ?></dd>
    <dt>Stops</dt>
    <dd><?= (int) $deal['stops'] === 0 ? 'Non-stop' : e($deal['stops'] . ' via ' . $deal['layovers']) ?></dd>
    <dt>Longest direction</dt><dd><?= e(Offer::formatMinutes((int) $deal['duration_minutes'])) ?></dd>
    <dt>Checked bag</dt><dd><?= (int) $deal['bag_included'] === 1 ? 'Included' : 'Not included' ?></dd>
    <dt>Typical fare from</dt>
    <dd><?= $deal['baseline_source'] === 'observed' ? 'our observed price history for this route' : 'the configured seed estimate' ?></dd>
    <dt>Found</dt><dd><?= e($deal['found_at']) ?> UTC</dd>
    <dt>Expires from board</dt><dd><?= e($deal['expires_at']) ?> UTC</dd>
  </dl>

  <p style="margin-top:20px;">
    <a class="btn" href="<?= e($deal['booking_url']) ?>" target="_blank" rel="noopener">Open in Google Flights</a>
    <a class="btn ghost" href="index.php">Back to deals</a>
  </p>
  <p class="note">
    Prices move fast and this fare may already be gone. Book with the airline or an OTA &mdash;
    Zomunk Lite never takes a booking or a payment.
  </p>
</section>

<?php if (is_array($offer)): ?>
<section class="panel">
  <h2>Itinerary</h2>
  <?php foreach ($offer['itineraries'] as $index => $segments): ?>
    <p class="note"><strong><?= $index === 0 ? 'Outbound' : 'Return' ?></strong></p>
    <dl class="spec">
      <?php foreach ($segments as $segment): ?>
        <dt><?= e($segment['from']) ?> &rarr; <?= e($segment['to']) ?></dt>
        <dd>
          <?= e(date('D d M, H:i', strtotime($segment['depart_at']))) ?>
          &rarr; <?= e(date('D d M, H:i', strtotime($segment['arrive_at']))) ?>
          &middot; <?= e($segment['carrier'] . ' ' . $segment['number']) ?>
        </dd>
      <?php endforeach; ?>
    </dl>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
