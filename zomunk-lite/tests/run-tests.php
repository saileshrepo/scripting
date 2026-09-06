<?php
/**
 * Dependency-free test runner: php tests/run-tests.php
 * Everything here is offline — the sample provider and an in-memory SQLite.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Zomunk\Db;
use Zomunk\DealEngine;
use Zomunk\DealRepository;
use Zomunk\Notifier\EmailTemplate;
use Zomunk\Offer;
use Zomunk\Providers\SampleProvider;
use Zomunk\Scanner;
use Zomunk\Segment;

$passed = 0;
$failed = 0;

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    }
}

function assertTrue($value, string $message = 'expected true'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertContainsMatch(array $haystack, string $needle, string $message = ''): void
{
    foreach ($haystack as $item) {
        if (str_contains((string) $item, $needle)) {
            return;
        }
    }
    throw new RuntimeException($message . ' no entry matching "' . $needle . '" in ' . json_encode($haystack));
}

// --- fixtures ---------------------------------------------------------------

function makeOffer(array $overrides = []): Offer
{
    $defaults = [
        'origin'      => 'DEL',
        'destination' => 'LHR',
        'price'       => 30000.0,
        'currency'    => 'INR',
        'cabin'       => 'ECONOMY',
        'bag'         => true,
        'legs'        => [[['DEL', 'LHR', '2026-11-10 02:00', '2026-11-10 11:00']]],
    ];
    $config = array_merge($defaults, $overrides);

    $itineraries = [];
    foreach ($config['legs'] as $leg) {
        $segments = [];
        foreach ($leg as $segment) {
            $segments[] = new Segment(
                $segment[0],
                $segment[1],
                new DateTimeImmutable($segment[2]),
                new DateTimeImmutable($segment[3]),
                'AI',
                '101',
            );
        }
        $itineraries[] = $segments;
    }

    return new Offer(
        $config['origin'],
        $config['destination'],
        $config['price'],
        $config['currency'],
        $config['cabin'],
        $itineraries,
        $config['bag'],
        'AI',
        'AIR INDIA',
    );
}

function makeEngine(array $ruleOverrides = []): DealEngine
{
    $rules = array_merge([
        'min_discount'        => 0.40,
        'premium_discount'    => 0.70,
        'free_delay_hours'    => 24,
        'max_stops'           => 1,
        'min_layover'         => 45,
        'max_layover'         => 300,
        'require_bag'         => true,
        'passport'            => 'IN',
        'realert_drop'        => 0.10,
        'realert_days'        => 14,
        'min_history_points'  => 5,
        'history_window_days' => 180,
    ], $ruleOverrides);

    return new DealEngine($rules, require dirname(__DIR__) . '/config/transit_visa.php');
}

$route = ['origin' => 'DEL', 'destination' => 'LHR', 'max_duration_hours' => 26, 'cabin' => 'ECONOMY'];

// --- Offer ------------------------------------------------------------------

echo "Offer\n";

test('reads dates and month from the outbound', function () {
    $offer = makeOffer(['legs' => [
        [['DEL', 'LHR', '2026-11-10 02:00', '2026-11-10 11:00']],
        [['LHR', 'DEL', '2026-11-24 12:00', '2026-11-25 02:00']],
    ]]);
    assertSame('2026-11-10', $offer->departDate());
    assertSame('2026-11', $offer->departMonth());
    assertSame('2026-11-24', $offer->returnDate());
});

test('counts stops on the busiest direction', function () {
    $offer = makeOffer(['legs' => [
        [['DEL', 'LHR', '2026-11-10 02:00', '2026-11-10 11:00']],
        [['LHR', 'DXB', '2026-11-24 12:00', '2026-11-24 20:00'], ['DXB', 'DEL', '2026-11-24 22:00', '2026-11-25 03:00']],
    ]]);
    assertSame(1, $offer->maxStops());
});

test('measures layovers between segments', function () {
    $offer = makeOffer(['legs' => [
        [['DEL', 'DXB', '2026-11-10 02:00', '2026-11-10 04:00'], ['DXB', 'LHR', '2026-11-10 06:30', '2026-11-10 11:00']],
    ]]);
    $layovers = $offer->layovers();
    assertSame(1, count($layovers));
    assertSame('DXB', $layovers[0]['airport']);
    assertSame(150, $layovers[0]['minutes']);
});

test('builds a Google Flights link for the exact itinerary', function () {
    $offer = makeOffer(['legs' => [
        [['DEL', 'LHR', '2026-11-10 02:00', '2026-11-10 11:00']],
        [['LHR', 'DEL', '2026-11-24 12:00', '2026-11-25 02:00']],
    ]]);
    $url = $offer->bookingUrl();
    assertTrue(str_contains($url, 'google.com/travel/flights'), 'points at Google Flights');
    assertTrue(str_contains($url, 'DEL'), 'carries the origin');
    assertTrue(str_contains($url, '2026-11-24'), 'carries the return date');
});

// --- DealEngine: pricing ----------------------------------------------------

echo "\nDealEngine: pricing\n";

test('discount is measured against the typical fare', function () {
    $engine = makeEngine();
    assertSame(0.5, $engine->discount(30000, 60000));
    assertSame(0.0, $engine->discount(70000, 60000), 'a fare above typical is not a negative discount');
});

test('typical fare is the median, not the mean', function () {
    $engine = makeEngine();
    // One mistake fare in the history must not drag the baseline down.
    [$fare, $source] = $engine->typicalFare([60000, 62000, 58000, 61000, 9000], 0);
    assertSame(60000.0, $fare);
    assertSame('observed', $source);
});

test('falls back to the seed until there is enough history', function () {
    $engine = makeEngine();
    [$fare, $source] = $engine->typicalFare([60000, 62000], 65000);
    assertSame(65000.0, $fare);
    assertSame('seed', $source);
});

test('a fare 40% below typical is a deal', function () use ($route) {
    [$candidate, $rejections] = makeEngine()->evaluate(makeOffer(['price' => 36000]), $route, 60000, 'observed');
    assertSame([], $rejections);
    assertTrue($candidate !== null, 'candidate created');
    assertSame(40, $candidate->discountPercent());
    assertSame('free', $candidate->tier);
});

test('a fare 39% below typical is not', function () use ($route) {
    [$candidate, $rejections] = makeEngine()->evaluate(makeOffer(['price' => 36600]), $route, 60000, 'observed');
    assertSame(null, $candidate);
    assertContainsMatch($rejections, 'discount_below_threshold');
});

test('a 70%+ drop is a mistake fare and premium only', function () use ($route) {
    [$candidate] = makeEngine()->evaluate(makeOffer(['price' => 15000]), $route, 60000, 'observed');
    assertTrue($candidate->isMistake, 'flagged as a mistake fare');
    assertSame('premium', $candidate->tier);
});

test('non-economy deals are premium only', function () use ($route) {
    [$candidate] = makeEngine()->evaluate(
        makeOffer(['price' => 36000, 'cabin' => 'BUSINESS']),
        $route, 60000, 'observed',
    );
    assertSame('premium', $candidate->tier);
});

test('no baseline means no deal', function () use ($route) {
    [$candidate, $rejections] = makeEngine()->evaluate(makeOffer(['price' => 100]), $route, null, 'seed');
    assertSame(null, $candidate);
    assertContainsMatch($rejections, 'no_baseline');
});

// --- DealEngine: itinerary quality -----------------------------------------

echo "\nDealEngine: itinerary quality\n";

test('rejects more than one stop', function () use ($route) {
    $offer = makeOffer(['legs' => [[
        ['DEL', 'DXB', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['DXB', 'IST', '2026-11-10 06:00', '2026-11-10 09:00'],
        ['IST', 'LHR', '2026-11-10 11:00', '2026-11-10 14:00'],
    ]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, $route);
    assertContainsMatch($rejections, 'too_many_stops');
});

test('a route may raise the stop limit for a tier-2 origin', function () {
    // Raipur has no non-stop international service: one stop reaches the
    // gateway, the second is the international connection.
    $offer = makeOffer(['origin' => 'RPR', 'destination' => 'BKK', 'legs' => [[
        ['RPR', 'DEL', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['DEL', 'BOM', '2026-11-10 06:00', '2026-11-10 08:00'],
        ['BOM', 'BKK', '2026-11-10 10:00', '2026-11-10 16:00'],
    ]]]);
    $metroRules = ['max_duration_hours' => 20];
    [$rejections] = makeEngine()->inspectItinerary($offer, $metroRules);
    assertContainsMatch($rejections, 'too_many_stops', 'rejected under the global limit:');

    [$rejections] = makeEngine()->inspectItinerary($offer, $metroRules + ['max_stops' => 2]);
    assertSame([], $rejections, 'accepted once the route raises the limit:');
});

test('rejects a layover too tight to make', function () use ($route) {
    $offer = makeOffer(['legs' => [[
        ['DEL', 'DXB', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['DXB', 'LHR', '2026-11-10 04:30', '2026-11-10 09:00'],
    ]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, $route);
    assertContainsMatch($rejections, 'layover_too_short');
});

test('rejects an all-day layover', function () use ($route) {
    $offer = makeOffer(['legs' => [[
        ['DEL', 'DXB', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['DXB', 'LHR', '2026-11-10 14:00', '2026-11-10 19:00'],
    ]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, $route);
    assertContainsMatch($rejections, 'layover_too_long');
});

test('rejects an itinerary longer than the route allows', function () {
    $offer = makeOffer(['legs' => [[['DEL', 'LHR', '2026-11-10 02:00', '2026-11-11 12:00']]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, ['max_duration_hours' => 26]);
    assertContainsMatch($rejections, 'too_long');
});

test('rejects fares without a checked bag when required', function () use ($route) {
    [$rejections] = makeEngine()->inspectItinerary(makeOffer(['bag' => false]), $route);
    assertContainsMatch($rejections, 'no_checked_bag');
});

test('keeps hand-baggage fares when the rule is off', function () use ($route) {
    [$rejections] = makeEngine(['require_bag' => false])->inspectItinerary(makeOffer(['bag' => false]), $route);
    assertSame([], $rejections);
});

test('rejects a connection needing a transit visa', function () use ($route) {
    // ORD is in the US: an Indian passport cannot transit airside without a visa.
    $offer = makeOffer(['legs' => [[
        ['DEL', 'ORD', '2026-11-10 02:00', '2026-11-10 08:00'],
        ['ORD', 'LHR', '2026-11-10 10:00', '2026-11-10 14:00'],
    ]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, $route);
    assertContainsMatch($rejections, 'transit_visa_required(ORD)');
});

test('allows a Gulf connection', function () use ($route) {
    $offer = makeOffer(['legs' => [[
        ['DEL', 'DXB', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['DXB', 'LHR', '2026-11-10 06:00', '2026-11-10 11:00'],
    ]]]);
    [$rejections] = makeEngine()->inspectItinerary($offer, $route);
    assertSame([], $rejections);
});

test('an unknown connection airport is flagged, never dropped', function () use ($route) {
    $offer = makeOffer(['legs' => [[
        ['DEL', 'ZZZ', '2026-11-10 02:00', '2026-11-10 04:00'],
        ['ZZZ', 'LHR', '2026-11-10 06:00', '2026-11-10 11:00'],
    ]]]);
    [$rejections, $flags] = makeEngine()->inspectItinerary($offer, $route);
    assertSame([], $rejections);
    assertContainsMatch($flags, 'transit_unknown(ZZZ)');
});

// --- DealEngine: alert suppression ------------------------------------------

echo "\nDealEngine: alert suppression\n";

test('a brand new route deal always alerts', function () use ($route) {
    [$candidate] = makeEngine()->evaluate(makeOffer(['price' => 30000]), $route, 60000, 'observed');
    assertTrue(makeEngine()->shouldAlert($candidate, null));
});

test('the same price again does not re-alert', function () use ($route) {
    $engine = makeEngine();
    [$candidate] = $engine->evaluate(makeOffer(['price' => 30000]), $route, 60000, 'observed');
    $last = ['price' => 30500.0, 'found_at' => gmdate('Y-m-d H:i:s', time() - 86400)];
    assertSame(false, $engine->shouldAlert($candidate, $last));
});

test('a materially cheaper fare re-alerts', function () use ($route) {
    $engine = makeEngine();
    [$candidate] = $engine->evaluate(makeOffer(['price' => 26000]), $route, 60000, 'observed');
    $last = ['price' => 30000.0, 'found_at' => gmdate('Y-m-d H:i:s', time() - 86400)];
    assertSame(true, $engine->shouldAlert($candidate, $last));
});

test('a stale alert lets the route through again', function () use ($route) {
    $engine = makeEngine();
    [$candidate] = $engine->evaluate(makeOffer(['price' => 30000]), $route, 60000, 'observed');
    $last = ['price' => 30000.0, 'found_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)];
    assertSame(true, $engine->shouldAlert($candidate, $last));
});

// --- Scanner date planning --------------------------------------------------

echo "\nScanner\n";

test('plans future date pairs across months', function () {
    $scanner = new Scanner(new SampleProvider(), makeEngine(), new DealRepository(makeEngine()), static fn() => null);
    $pairs = $scanner->datePairs([
        'scan' => ['trip_nights' => [7, 14], 'months_ahead' => 3, 'dates_per_month' => 2],
    ]);
    assertSame(12, count($pairs), '3 months x 2 dates x 2 trip lengths:');
    foreach ($pairs as [$depart, $return]) {
        assertTrue($depart > gmdate('Y-m-d'), "departure $depart is in the future");
        assertTrue($return > $depart, 'return is after departure');
    }
});

// --- End to end over SQLite -------------------------------------------------

echo "\nEnd to end (in-memory SQLite + sample provider)\n";

Db::setPdo(new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]));
Db::migrate();

$engine = makeEngine();
$repository = new DealRepository($engine);
$routes = $repository->syncRoutes([
    'defaults' => [
        'cabin' => 'ECONOMY', 'currency' => 'INR', 'adults' => 1, 'max_duration_hours' => 26,
        'scan' => ['trip_nights' => [7], 'months_ahead' => 4, 'dates_per_month' => 2],
    ],
    'routes' => [
        ['origin' => 'DEL', 'destination' => 'LHR', 'typical_fare_inr' => 65000, 'label' => 'Delhi -> London'],
        ['origin' => 'BOM', 'destination' => 'DXB', 'typical_fare_inr' => 22000, 'label' => 'Mumbai -> Dubai'],
    ],
]);

test('the watchlist lands in the database', function () use ($routes) {
    assertSame(2, count($routes));
    assertTrue($routes[0]['id'] > 0, 'route has an id');
});

$scanner = new Scanner(new SampleProvider(), $engine, $repository, static fn() => null);
$result = $scanner->run($routes, ['sleep_ms' => 0]);

test('a scan prices routes and records fare history', function () use ($result) {
    assertTrue($result['stats']['requests'] > 0, 'made provider calls');
    $history = Db::query('SELECT COUNT(*) AS c FROM fare_history');
    assertTrue((int) $history[0]['c'] > 0, 'recorded observations');
});

test('deals it found clear every rule', function () {
    $deals = Db::query('SELECT * FROM deals');
    foreach ($deals as $deal) {
        assertTrue((float) $deal['discount'] >= 0.40, 'at least 40% off');
        assertTrue((int) $deal['stops'] <= 1, 'one stop at most');
        assertSame(1, (int) $deal['bag_included'], 'checked bag included:');
        assertTrue(in_array($deal['tier'], ['free', 'premium'], true), 'has a tier');
    }
});

test('free deals are held back and premium deals are not', function () {
    foreach (Db::query('SELECT * FROM deals') as $deal) {
        if ($deal['tier'] === 'free') {
            assertTrue($deal['publish_free_at'] > gmdate('Y-m-d H:i:s'), 'free release is in the future');
        } else {
            assertSame(null, $deal['publish_free_at'], 'premium deals never reach the free tier:');
        }
    }
});

test('a guest only sees released free deals', function () use ($repository) {
    foreach ($repository->visibleDeals('guest') as $deal) {
        assertSame('free', $deal['tier']);
    }
    // Nothing is released yet, so the guest board is empty right after a scan.
    assertSame(0, count($repository->visibleDeals('guest')));
});

test('premium sees everything straight away', function () use ($repository) {
    $all = (int) Db::query('SELECT COUNT(*) AS c FROM deals')[0]['c'];
    assertSame($all, count($repository->visibleDeals('premium')));
});

test('the premium queue holds every fresh deal', function () use ($repository) {
    $all = (int) Db::query('SELECT COUNT(*) AS c FROM deals')[0]['c'];
    assertSame($all, count($repository->dealsAwaitingNotification('premium', 100)));
    assertSame(0, count($repository->dealsAwaitingNotification('free', 100)), 'free is still on delay:');
});

test('a notified deal leaves the queue', function () use ($repository) {
    $pending = $repository->dealsAwaitingNotification('premium', 100);
    if ($pending === []) {
        return;
    }
    $before = count($pending);
    $repository->recordNotification((int) $pending[0]['id'], 'premium', 'sent', 'campaign-1');
    assertSame($before - 1, count($repository->dealsAwaitingNotification('premium', 100)));
});

test('a re-scan does not duplicate an already alerted deal', function () use ($scanner, $routes, $repository) {
    $before = (int) Db::query('SELECT COUNT(*) AS c FROM deals')[0]['c'];
    // Mark everything as alerted, then run the identical scan again.
    foreach (Db::query('SELECT id FROM deals') as $deal) {
        Db::execute(
            "INSERT OR IGNORE INTO notifications (deal_id, tier, channel, status, sent_at)
             VALUES (?, 'premium', 'sender', 'sent', ?)",
            [$deal['id'], gmdate('Y-m-d H:i:s')],
        );
    }
    $scanner2 = new Scanner(new SampleProvider(), makeEngine(), $repository, static fn() => null);
    $scanner2->run($routes, ['sleep_ms' => 0]);
    assertSame($before, (int) Db::query('SELECT COUNT(*) AS c FROM deals')[0]['c']);
});

// --- Email ------------------------------------------------------------------

echo "\nEmail\n";

test('a single-deal subject names the route and the discount', function () {
    $subject = EmailTemplate::subject([[
        'origin' => 'DEL', 'destination' => 'LHR', 'price' => 28000, 'currency' => 'INR',
        'discount' => 0.56, 'is_mistake' => 0,
    ]], 'free');
    assertTrue(str_contains($subject, 'DEL'), 'names the origin');
    assertTrue(str_contains($subject, '56%'), 'names the discount');
});

test('a digest subject counts the deals and leads with the best', function () {
    $subject = EmailTemplate::subject([
        ['origin' => 'DEL', 'destination' => 'LHR', 'price' => 28000, 'currency' => 'INR', 'discount' => 0.56, 'is_mistake' => 0],
        ['origin' => 'BOM', 'destination' => 'DXB', 'price' => 9000,  'currency' => 'INR', 'discount' => 0.61, 'is_mistake' => 0],
    ], 'premium');
    assertTrue(str_contains($subject, '2 flight deals'), 'counts them');
    assertTrue(str_contains($subject, '61%'), 'leads with the best discount');
});

test('the email escapes route text and carries the booking link', function () {
    $html = EmailTemplate::html([[
        'id' => 1, 'origin' => 'DEL', 'destination' => 'LHR', 'label' => 'Delhi <script> London',
        'price' => 28000, 'currency' => 'INR', 'typical_fare' => 65000, 'discount' => 0.56,
        'depart_date' => '2026-11-10', 'return_date' => '2026-11-24', 'carrier_name' => 'AIR INDIA',
        'carrier_code' => 'AI', 'stops' => 0, 'layovers' => '', 'duration_minutes' => 540,
        'bag_included' => 1, 'is_mistake' => 0, 'booking_url' => 'https://www.google.com/travel/flights?q=x',
    ]], 'free', 'http://localhost:8000');
    assertTrue(!str_contains($html, '<script>'), 'no raw script tag survives');
    assertTrue(str_contains($html, 'google.com/travel/flights'), 'carries the booking link');
    assertTrue(str_contains($html, '56% off'), 'shows the discount');
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
