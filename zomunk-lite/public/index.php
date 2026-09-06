<?php
/** The deal board. What a viewer sees depends on their tier. */

require __DIR__ . '/_init.php';
require __DIR__ . '/_layout.php';

use Zomunk\Money;
use Zomunk\Offer;

$viewer = zomunk_viewer();
$origin = isset($_GET['origin']) ? strtoupper(trim($_GET['origin'])) : '';
$repository->expireStaleDeals();

$deals   = $repository->visibleDeals($viewer['tier'], $origin ?: null);
$locked  = $viewer['tier'] === 'premium' ? [] : $repository->lockedDeals();
$origins = $repository->originAirports();
$stats   = $repository->stats();
$rules   = $engine->rules();

zomunk_head('Flight Deal Alerts - cheap international fares from India');
?>

<section class="hero">
  <h1>International fares from India, only when they are actually cheap.</h1>
  <p>
    We price a watchlist of routes out of Indian airports several times a day and keep only fares at least
    <?= (int) round($rules['min_discount'] * 100) ?>% below what that route normally costs. Every deal is
    checked on a real itinerary before it reaches you: <?= (int) $rules['max_stops'] ?> stop<?= (int) $rules['max_stops'] === 1 ? '' : 's' ?> maximum,
    layovers between <?= (int) $rules['min_layover'] ?> and <?= (int) $rules['max_layover'] ?> minutes,
    <?= $rules['require_bag'] ? 'checked bag included, ' : '' ?>and nothing that needs a transit visa.
  </p>

  <div class="stats">
    <div><div class="n"><?= (int) $stats['live'] ?></div><div class="l">Live deals</div></div>
    <div><div class="n"><?= $stats['avg_discount'] > 0 ? (int) round($stats['avg_discount'] * 100) . '%' : '&mdash;' ?></div><div class="l">Average off</div></div>
    <div><div class="n"><?= $stats['best_discount'] > 0 ? (int) round($stats['best_discount'] * 100) . '%' : '&mdash;' ?></div><div class="l">Best right now</div></div>
    <div><div class="n"><?= (int) $stats['routes'] ?></div><div class="l">Routes watched</div></div>
    <div><div class="n"><?= number_format($stats['observations']) ?></div><div class="l">Fares recorded</div></div>
  </div>
</section>

<?php if (($_GET['joined'] ?? null) === '1'): ?>
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
    <?php if ($stats['live'] > 0 && $viewer['tier'] !== 'premium'): ?>
      Nothing open to you right now &mdash; premium members are seeing
      <?= (int) $stats['live'] ?> live deal<?= $stats['live'] === 1 ? '' : 's' ?>.<br>
      <span class="note">Free deals unlock <?= (int) $rules['free_delay_hours'] ?> hours after we find them.</span>
    <?php else: ?>
      No live deals right now.<br>
      <span class="note">Run <code>php bin/scan.php</code> to price the watchlist.</span>
    <?php endif; ?>
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
        <h3 class="route">
          <?= e($deal['label']) ?>
          <?php if ((int) $deal['is_mistake'] === 1): ?> <span class="badge hot">MISTAKE FARE</span><?php endif; ?>
          <?php if ($deal['tier'] === 'premium'): ?> <span class="badge lock">PREMIUM</span><?php endif; ?>
        </h3>
        <div class="meta">
          <span><?= e($deal['depart_date']) ?><?= $deal['return_date'] ? ' &rarr; ' . e($deal['return_date']) : '' ?></span>
          <span><?= e($deal['carrier_name'] ?: $deal['carrier_code']) ?></span>
          <span><?= e($stops) ?></span>
          <span><?= e(Offer::formatMinutes((int) $deal['duration_minutes'])) ?></span>
          <span><?= (int) $deal['bag_included'] === 1 ? 'Checked bag' : 'Cabin bag only' ?></span>
        </div>
        <div class="baseline">
          Typical <?= e(Money::format((float) $deal['typical_fare'], $deal['currency'])) ?>
          <?= $deal['baseline_source'] === 'seed' ? '(seed estimate)' : '(from ' . (int) $stats['observations'] . ' recorded fares)' ?>
        </div>
      </div>
      <div class="buy">
        <div class="price"><?= e(Money::format((float) $deal['price'], $deal['currency'])) ?></div>
        <div class="was"><?= e(Money::format((float) $deal['typical_fare'], $deal['currency'])) ?></div>
        <div style="margin:8px 0 12px;"><span class="badge"><?= $discount ?>% off</span></div>
        <a class="btn" href="deal.php?id=<?= (int) $deal['id'] ?>">See deal</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($locked !== []): ?>
<div class="section-head">
  <h2>Locked right now</h2>
  <span class="note">Premium members can already book these</span>
</div>
<div class="deals">
  <?php foreach ($locked as $deal): ?>
    <article class="locked">
      <div>
        <div class="route">
          <?= e($deal['label']) ?>
          <?php if ((int) $deal['is_mistake'] === 1): ?> <span class="badge hot">MISTAKE FARE</span><?php endif; ?>
        </div>
        <div class="blur">
          <b>&#8377;00,000</b> &middot; <b>00 Xxx &rarr; 00 Xxx</b> &middot;
          <span class="badge lock"><?= (int) round((float) $deal['discount'] * 100) ?>% off</span>
        </div>
      </div>
      <a class="btn sm" href="index.php#join">Unlock</a>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($viewer['tier'] !== 'premium'): ?>
<section class="panel upsell" id="join">
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

<?php zomunk_foot(); ?>
