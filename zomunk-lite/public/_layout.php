<?php
/** Shared chrome so every page carries the same header and footer. */

function zomunk_head(string $title): void
{
    $viewer = zomunk_viewer();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="topbar"><div class="inner">
  <a class="brand" href="index.php"><span class="mark">&#9992;</span> Flight Deal Alerts</a>
  <div class="who">
    <?php if ($viewer['email'] !== null): ?>
      <span class="badge tier"><?= e(strtoupper($viewer['tier'])) ?></span>
      <span><?= e($viewer['email']) ?></span>
      <a href="subscribe.php?logout=1">sign out</a>
    <?php else: ?>
      <a class="btn sm" href="index.php#join">Get deal alerts</a>
    <?php endif; ?>
  </div>
</div></div>
<div class="wrap">
<?php
}

function zomunk_foot(): void
{
    ?>
<footer class="site">
  We are not a booking site. Every deal links out to Google Flights, where you book with the
  airline or an OTA &mdash; we take no commission, so what gets posted is chosen on value alone.
  Fares move fast and can disappear while you are booking.
</footer>
</div>
</body>
</html>
<?php
}
