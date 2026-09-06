<?php
/** Deal dashboard. What a member sees depends on their tier. */

require __DIR__ . '/_init.php';

use Zomunk\Money;
use Zomunk\Offer;

$viewer = zomunk_viewer();
$origin = isset($_GET['origin']) ? strtoupper(trim($_GET['origin'])) : '';
$repository->expireStaleDeals();

$deals = $repository->visibleDeals($viewer['tier'], $origin ?: null);
$origins = $repository->originAirports();
$rules = $engine->rules();
$flash = $_GET['joined'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zomunk Lite - flight deals from India</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">

<header class="site">
  <h1>Zomunk Lite</h1>
  <div class="who">
    <?php if ($viewer['email'] !== null): ?>
      <?= e($viewer['email']) ?> &middot; <span class="badge tier"><?= e(strtoupper($viewer['tier'])) ?></span>
      &middot; <a href="subscribe.php?logout=1">sign out</a>
    <?php else: ?>
      Browsing as a guest &middot; <a href="#join">get deal alerts</a>
    <?php endif; ?>
  </div>
</header>

<p class="lede">
  We price a watchlist of routes out of Indian airports every few hours and keep only fares at least
  <?= (int) round($rules['min_discount'] * 100) ?>% below what that route normally costs &mdash; non-stop or one stop,
  sane layovers, checked bag included, no transit visa needed. We are not a booking site: every deal links
  out to Google Flights.
</p>

<?php if ($flash === '1'): ?>
  <div class="flash ok">You are on the list. Deals will land in your inbox as we find them.</div>
<?php endif; ?>

<?php if ($origins !== []): ?>
<nav class="filters">
  <a href="index.php" class="<?= $origin === '' ? 'on' : '' ?>">All origins</a>
  <?php foreach ($origins as $code): ?>
    <a href="index.php?origin=<?= e($code) ?>" class="<?= $origin === $code ? 'on' : '' ?>"><?= e($code) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($deals === []): ?>
  <div class="empty">
    No live deals right now.<br>
    <span class="note">Run <code>php bin/scan.php</code> to price the watchlist.</span>
  </div>
<?php else: ?>
<div class="deals">
  <?php foreach ($deals as $deal): ?>
    <?php
      $discount = (int) round((float) $deal['discount'] * 100);
      $stops = (int) $deal['stops'] === 0
          ? 'Non-stop'
          : sprintf('%d stop%s via %s', (int) $deal['stops'], (int) $deal['stops'] > 1 ? 's' : '', $deal['layovers']);
    ?>
    <article class="deal">
      <div>
        <h2>
          <?= e($deal['label']) ?>
          <?php if ((int) $deal['is_mistake'] === 1): ?><span class="badge hot">MISTAKE FARE</span><?php endif; ?>
          <?php if ($deal['tier'] === 'premium'): ?><span class="badge tier">PREMIUM</span><?php endif; ?>
        </h2>
        <div class="meta">
          <?= e($deal['depart_date']) ?><?= $deal['return_date'] ? ' &rarr; ' . e($deal['return_date']) : '' ?>
          &middot; <?= e($deal['carrier_name'] ?: $deal['carrier_code']) ?>
          &middot; <?= e($stops) ?>
          &middot; <?= e(Offer::formatMinutes((int) $deal['duration_minutes'])) ?>
          &middot; <?= (int) $deal['bag_included'] === 1 ? 'checked bag included' : 'cabin bag only' ?>
        </div>
        <div class="meta" style="margin-top:6px;">
          Typical fare <?= e(Money::format((float) $deal['typical_fare'], $deal['currency'])) ?>
          <?= $deal['baseline_source'] === 'seed' ? '(seed estimate)' : '(from our price history)' ?>
        </div>
      </div>
      <div class="right">
        <div class="price"><?= e(Money::format((float) $deal['price'], $deal['currency'])) ?></div>
        <div><span class="badge"><?= $discount ?>% off</span></div>
        <div style="margin-top:12px;">
          <a class="btn" href="<?= e($deal['booking_url']) ?>" target="_blank" rel="noopener">See fare</a>
          <a class="btn ghost" href="deal.php?id=<?= (int) $deal['id'] ?>">Details</a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($viewer['tier'] !== 'premium'): ?>
<section class="panel" id="join">
  <h2><?= $viewer['email'] === null ? 'Get deals by email' : 'Upgrade to premium' ?></h2>
  <p class="note">
    Free members get a slice of economy deals, <?= (int) $rules['free_delay_hours'] ?> hours after premium members.
    Premium sees every deal the moment we find it &mdash; mistake fares, premium cabins and peak-season drops included.
  </p>
  <form class="signup" method="post" action="subscribe.php">
    <input type="hidden" name="csrf" value="<?= e(zomunk_csrf_token()) ?>">
    <div>
      <label for="email">Email</label>
      <input id="email" type="email" name="email" required placeholder="you@example.com"
             value="<?= e($viewer['email'] ?? '') ?>">
    </div>
    <div>
      <label for="name">First name</label>
      <input id="name" type="text" name="name" placeholder="optional" value="<?= e($viewer['name'] ?? '') ?>">
    </div>
    <div>
      <label for="airports">Home airports</label>
      <input id="airports" type="text" name="home_airports" placeholder="DEL, BOM">
    </div>
    <div>
      <label for="tier">Plan</label>
      <select id="tier" name="tier">
        <option value="free">Free</option>
        <option value="premium" <?= ($_GET['tier'] ?? '') === 'premium' ? 'selected' : '' ?>>Premium</option>
      </select>
    </div>
    <div><button class="btn" type="submit">Send me deals</button></div>
  </form>
</section>
<?php endif; ?>

</div>
</body>
</html>
