<?php

namespace Zomunk;

/** All database access for routes, fare history and deals. */
final class DealRepository
{
    public function __construct(private DealEngine $engine)
    {
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    // --- routes -------------------------------------------------------------

    /** Upserts the config watchlist into the routes table and returns it with ids. */
    public function syncRoutes(array $routesConfig): array
    {
        $defaults = $routesConfig['defaults'];
        $synced = [];

        foreach ($routesConfig['routes'] as $route) {
            $route = array_merge($defaults, $route);
            unset($route['scan']);
            $route['scan'] = $routesConfig['defaults']['scan'];

            $existing = Db::one(
                'SELECT id FROM routes WHERE origin = ? AND destination = ? AND cabin = ?',
                [$route['origin'], $route['destination'], $route['cabin']],
            );

            if ($existing === null) {
                $id = (int) Db::insert(
                    'INSERT INTO routes (origin, destination, label, cabin, currency, typical_fare_seed,
                                         max_duration_hours, active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                    [
                        $route['origin'], $route['destination'], $route['label'], $route['cabin'],
                        $route['currency'], $route['typical_fare_inr'], $route['max_duration_hours'],
                    ],
                );
            } else {
                $id = (int) $existing['id'];
                Db::execute(
                    'UPDATE routes SET label = ?, currency = ?, typical_fare_seed = ?,
                                       max_duration_hours = ?, active = 1
                     WHERE id = ?',
                    [
                        $route['label'], $route['currency'], $route['typical_fare_inr'],
                        $route['max_duration_hours'], $id,
                    ],
                );
            }

            $route['id'] = $id;
            $synced[] = $route;
        }

        // Routes dropped from config stop being scanned but keep their history.
        $keep = array_column($synced, 'id');
        if ($keep !== []) {
            $placeholders = implode(',', array_fill(0, count($keep), '?'));
            Db::execute("UPDATE routes SET active = 0 WHERE id NOT IN ($placeholders)", $keep);
        }

        return $synced;
    }

    // --- fare history -------------------------------------------------------

    public function recordObservation(int $routeId, Offer $offer): void
    {
        Db::execute(
            'INSERT INTO fare_history (route_id, depart_month, depart_date, return_date, price,
                                       currency, cabin, observed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $routeId, $offer->departMonth(), $offer->departDate(), $offer->returnDate(),
                $offer->price, $offer->currency, $offer->cabin, self::now(),
            ],
        );
    }

    /** A calendar sweep sighting, recorded as a price-history point. */
    public function recordCandidateObservation(int $routeId, FareCandidate $candidate): void
    {
        Db::execute(
            'INSERT INTO fare_history (route_id, depart_month, depart_date, return_date, price,
                                       currency, cabin, observed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $routeId, $candidate->departMonth(), $candidate->departDate, $candidate->returnDate,
                $candidate->price, $candidate->currency, 'ECONOMY', self::now(),
            ],
        );
    }

    /**
     * Typical fare for a route in a given departure month.
     *
     * Prefers same-month history (a July fare should be judged against July),
     * falls back to the whole route, then to the configured seed.
     *
     * @return array{0: float|null, 1: string} [fare, 'observed'|'seed']
     */
    public function typicalFareFor(int $routeId, string $month, float $seed): array
    {
        $window = gmdate('Y-m-d H:i:s', time() - $this->engine->rules()['history_window_days'] * 86400);

        $monthly = array_column(
            Db::query(
                'SELECT price FROM fare_history
                 WHERE route_id = ? AND depart_month = ? AND observed_at >= ?',
                [$routeId, $month, $window],
            ),
            'price',
        );
        [$fare, $source] = $this->engine->typicalFare(array_map('floatval', $monthly), 0.0);
        if ($source === 'observed') {
            return [$fare, 'observed'];
        }

        $all = array_column(
            Db::query(
                'SELECT price FROM fare_history WHERE route_id = ? AND observed_at >= ?',
                [$routeId, $window],
            ),
            'price',
        );
        [$fare, $source] = $this->engine->typicalFare(array_map('floatval', $all), $seed);

        return [$fare, $source];
    }

    // --- deals --------------------------------------------------------------

    public function lastAlertedDeal(string $dedupeKey): ?array
    {
        return Db::one(
            'SELECT d.price, d.found_at
             FROM deals d
             JOIN notifications n ON n.deal_id = d.id
             WHERE d.dedupe_key = ? AND n.status = ?
             ORDER BY d.found_at DESC
             LIMIT 1',
            [$dedupeKey, 'sent'],
        );
    }

    /** Most recent deal for the key regardless of whether it was notified. */
    public function lastDeal(string $dedupeKey): ?array
    {
        return Db::one(
            'SELECT price, found_at FROM deals WHERE dedupe_key = ? ORDER BY found_at DESC LIMIT 1',
            [$dedupeKey],
        );
    }

    public function saveDeal(DealCandidate $candidate, int $routeId): int
    {
        $offer = $candidate->offer;
        $now = self::now();
        $freeDelay = $this->engine->rules()['free_delay_hours'];

        return (int) Db::insert(
            'INSERT INTO deals (route_id, dedupe_key, price, currency, typical_fare, discount,
                                baseline_source, cabin, depart_date, return_date, carrier_code,
                                carrier_name, stops, duration_minutes, layovers, bag_included,
                                is_mistake, tier, booking_url, offer_json, found_at,
                                publish_free_at, expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $routeId,
                $candidate->dedupeKey(),
                $offer->price,
                $offer->currency,
                $candidate->typicalFare,
                $candidate->discount,
                $candidate->baselineSource,
                $offer->cabin,
                $offer->departDate(),
                $offer->returnDate(),
                $offer->carrierCode,
                $offer->carrierName,
                $offer->maxStops(),
                $offer->longestDirectionMinutes(),
                $offer->layoverSummary(),
                $offer->bagIncluded ? 1 : 0,
                $candidate->isMistake ? 1 : 0,
                $candidate->tier,
                $offer->bookingUrl(),
                json_encode($offer->toArray(), JSON_UNESCAPED_SLASHES),
                $now,
                $candidate->tier === 'free' ? gmdate('Y-m-d H:i:s', time() + $freeDelay * 3600) : null,
                // Fares this far below normal rarely survive a day.
                gmdate('Y-m-d H:i:s', time() + 3 * 86400),
                'active',
            ],
        );
    }

    /**
     * Deals still owed a notification for a tier.
     * Premium goes out immediately; free waits until publish_free_at.
     */
    public function dealsAwaitingNotification(string $tier, int $limit = 25): array
    {
        $now = self::now();

        if ($tier === 'premium') {
            // Premium members see everything, free-tier deals included.
            return Db::query(
                "SELECT d.*, r.label, r.origin, r.destination
                 FROM deals d
                 JOIN routes r ON r.id = d.route_id
                 LEFT JOIN notifications n ON n.deal_id = d.id AND n.tier = 'premium'
                 WHERE n.id IS NULL AND d.status = 'active' AND d.expires_at > ?
                 ORDER BY d.discount DESC
                 LIMIT $limit",
                [$now],
            );
        }

        return Db::query(
            "SELECT d.*, r.label, r.origin, r.destination
             FROM deals d
             JOIN routes r ON r.id = d.route_id
             LEFT JOIN notifications n ON n.deal_id = d.id AND n.tier = 'free'
             WHERE n.id IS NULL AND d.status = 'active' AND d.tier = 'free'
               AND d.expires_at > ? AND d.publish_free_at IS NOT NULL AND d.publish_free_at <= ?
             ORDER BY d.discount DESC
             LIMIT $limit",
            [$now, $now],
        );
    }

    public function recordNotification(
        int $dealId,
        string $tier,
        string $status,
        ?string $campaignId = null,
        ?string $error = null,
    ): void {
        Db::execute(
            'INSERT INTO notifications (deal_id, tier, channel, campaign_id, status, error, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$dealId, $tier, 'sender', $campaignId, $status, $error, self::now()],
        );
    }

    /** Deals for the dashboard, gated by what the viewer's tier may see. */
    public function visibleDeals(string $viewerTier, ?string $origin = null, int $limit = 60): array
    {
        $now = self::now();
        $params = [$now];
        $where = "d.status = 'active' AND d.expires_at > ?";

        if ($viewerTier !== 'premium') {
            $where .= " AND d.tier = 'free' AND d.publish_free_at IS NOT NULL AND d.publish_free_at <= ?";
            $params[] = $now;
        }
        if ($origin !== null && $origin !== '') {
            $where .= ' AND r.origin = ?';
            $params[] = strtoupper($origin);
        }

        return Db::query(
            "SELECT d.*, r.label, r.origin, r.destination
             FROM deals d JOIN routes r ON r.id = d.route_id
             WHERE $where
             ORDER BY d.found_at DESC, d.discount DESC
             LIMIT $limit",
            $params,
        );
    }

    /**
     * Premium deals a non-premium viewer cannot open yet, as teasers: the route
     * and the discount, never the price or the dates. Showing that these exist
     * is the whole argument for upgrading; showing the fare would give it away.
     */
    public function lockedDeals(int $limit = 6): array
    {
        $now = self::now();
        return Db::query(
            "SELECT d.id, d.discount, d.is_mistake, d.cabin, d.found_at, r.label, r.origin, r.destination
             FROM deals d JOIN routes r ON r.id = d.route_id
             WHERE d.status = 'active' AND d.expires_at > ?
               AND (d.tier = 'premium' OR d.publish_free_at > ?)
             ORDER BY d.discount DESC
             LIMIT $limit",
            [$now, $now],
        );
    }

    /** Headline numbers for the board. */
    public function stats(): array
    {
        $now = self::now();
        $row = Db::one(
            "SELECT COUNT(*) AS live, AVG(discount) AS avg_discount, MAX(discount) AS best_discount
             FROM deals WHERE status = 'active' AND expires_at > ?",
            [$now],
        ) ?? [];
        $routes = Db::one('SELECT COUNT(*) AS c FROM routes WHERE active = 1');
        $observations = Db::one('SELECT COUNT(*) AS c FROM fare_history');

        return [
            'live'          => (int) ($row['live'] ?? 0),
            'avg_discount'  => (float) ($row['avg_discount'] ?? 0),
            'best_discount' => (float) ($row['best_discount'] ?? 0),
            'routes'        => (int) ($routes['c'] ?? 0),
            'observations'  => (int) ($observations['c'] ?? 0),
        ];
    }

    public function findDeal(int $id): ?array
    {
        return Db::one(
            'SELECT d.*, r.label, r.origin, r.destination
             FROM deals d JOIN routes r ON r.id = d.route_id
             WHERE d.id = ?',
            [$id],
        );
    }

    public function expireStaleDeals(): int
    {
        return Db::execute(
            "UPDATE deals SET status = 'expired' WHERE status = 'active' AND expires_at <= ?",
            [self::now()],
        );
    }

    public function originAirports(): array
    {
        return array_column(
            Db::query('SELECT DISTINCT origin FROM routes WHERE active = 1 ORDER BY origin'),
            'origin',
        );
    }

    // --- scan bookkeeping ---------------------------------------------------

    public function startScanRun(string $provider): int
    {
        return (int) Db::insert(
            'INSERT INTO scan_runs (provider, started_at) VALUES (?, ?)',
            [$provider, self::now()],
        );
    }

    public function finishScanRun(int $runId, array $stats, array $errors): void
    {
        Db::execute(
            'UPDATE scan_runs
             SET finished_at = ?, routes_scanned = ?, offers_seen = ?, offers_kept = ?,
                 deals_found = ?, errors = ?
             WHERE id = ?',
            [
                self::now(),
                $stats['routes'] ?? 0,
                // Two-stage counts candidates where single-stage counts offers.
                $stats['offers'] ?? $stats['candidates'] ?? 0,
                $stats['kept'] ?? $stats['screened'] ?? 0,
                $stats['deals'] ?? 0,
                $errors === [] ? null : implode("\n", array_slice($errors, 0, 20)),
                $runId,
            ],
        );
    }
}
