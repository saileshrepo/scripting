<?php

namespace Zomunk;

use DateTimeImmutable;
use Throwable;
use Zomunk\Providers\FlightProvider;

/**
 * One scan pass: price every route on the watchlist over a spread of future
 * dates, remember the lowest bookable fare seen (that is what "typical" is
 * built from), and record anything far enough below typical as a deal.
 */
final class Scanner
{
    private array $errors = [];
    private array $stats = ['routes' => 0, 'offers' => 0, 'kept' => 0, 'deals' => 0, 'requests' => 0];

    public function __construct(
        private FlightProvider $provider,
        private DealEngine $engine,
        private DealRepository $repository,
        private $logger = null,
    ) {
        $this->logger ??= static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    /**
     * @param array $routes routes as returned by DealRepository::syncRoutes()
     * @param array{max_requests?:int, months?:int, dry_run?:bool, sleep_ms?:int} $options
     */
    public function run(array $routes, array $options = []): array
    {
        $maxRequests = $options['max_requests'] ?? PHP_INT_MAX;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sleepMs = (int) ($options['sleep_ms'] ?? 250);

        // Best candidate per route+month+cabin for this pass, so one scan cannot
        // write five near-identical deals for the same trip.
        $bestByKey = [];

        foreach ($routes as $route) {
            $this->stats['routes']++;
            $this->log(sprintf('-- %s (%s-%s)', $route['label'], $route['origin'], $route['destination']));

            foreach ($this->datePairs($route, $options['months'] ?? null) as [$departDate, $returnDate]) {
                if ($this->stats['requests'] >= $maxRequests) {
                    $this->log('   request budget reached, stopping this pass');
                    break 2;
                }

                try {
                    $this->stats['requests']++;
                    $offers = $this->provider->search($route, $departDate, $returnDate);
                } catch (Throwable $e) {
                    $this->errors[] = sprintf('%s %s: %s', $route['label'], $departDate, $e->getMessage());
                    $this->log('   ! ' . $e->getMessage());
                    continue;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
                if ($offers === []) {
                    continue;
                }
                $this->stats['offers'] += count($offers);

                $bookable = $this->bookableOffers($offers, $route);
                if ($bookable === []) {
                    continue;
                }
                $this->stats['kept'] += count($bookable);

                // The cheapest itinerary a member would actually accept is the
                // observation that defines this route's normal price.
                usort($bookable, static fn(Offer $a, Offer $b) => $a->price <=> $b->price);
                $cheapest = $bookable[0];
                if (!$dryRun) {
                    $this->repository->recordObservation((int) $route['id'], $cheapest);
                }

                [$typicalFare, $baselineSource] = $this->repository->typicalFareFor(
                    (int) $route['id'],
                    $cheapest->departMonth(),
                    (float) $route['typical_fare_inr'],
                );

                foreach ($bookable as $offer) {
                    [$candidate] = $this->engine->evaluate($offer, $route, $typicalFare, $baselineSource);
                    if ($candidate === null) {
                        continue;
                    }
                    $key = $candidate->dedupeKey();
                    if (!isset($bestByKey[$key]) || $candidate->offer->price < $bestByKey[$key][0]->offer->price) {
                        $bestByKey[$key] = [$candidate, $route];
                    }
                }
            }
        }

        foreach ($bestByKey as $key => [$candidate, $route]) {
            $lastAlerted = $this->repository->lastAlertedDeal($key);
            if (!$this->engine->shouldAlert($candidate, $lastAlerted)) {
                $this->log(sprintf('   = %s already alerted at a similar price, skipping', $key));
                continue;
            }

            $this->stats['deals']++;
            $this->log(sprintf(
                '   * DEAL %s %s %s %s (%d%% off typical %s) %s',
                $candidate->offer->origin,
                $candidate->offer->destination,
                $candidate->offer->departDate(),
                Money::format($candidate->offer->price, $candidate->offer->currency),
                $candidate->discountPercent(),
                Money::format($candidate->typicalFare, $candidate->offer->currency),
                $candidate->baselineSource === 'seed' ? '[seed baseline]' : '',
            ));

            if (!$dryRun) {
                $this->repository->saveDeal($candidate, (int) $route['id']);
            }
        }

        return ['stats' => $this->stats, 'errors' => $this->errors];
    }

    /** @return Offer[] */
    private function bookableOffers(array $offers, array $route): array
    {
        $kept = [];
        foreach ($offers as $offer) {
            [$rejections] = $this->engine->inspectItinerary($offer, $route);
            if ($rejections === []) {
                $kept[] = $offer;
            }
        }
        return $kept;
    }

    /**
     * Departure/return pairs to price for a route. Spread across months rather
     * than consecutive days: a deal is usually a whole booking window, and a
     * spread costs far fewer API calls than a full calendar sweep.
     *
     * @return array<int, array{0:string, 1:?string}>
     */
    public function datePairs(array $route, ?int $monthsOverride = null): array
    {
        $scan = $route['scan'];
        $months = $monthsOverride ?? $scan['months_ahead'];
        $datesPerMonth = max(1, (int) $scan['dates_per_month']);
        $today = new DateTimeImmutable('today');

        $pairs = [];
        for ($month = 1; $month <= $months; $month++) {
            $base = $today->modify("+$month month")->modify('first day of this month');
            for ($slot = 0; $slot < $datesPerMonth; $slot++) {
                // Spread the samples across the month (8th, 20th, ... for 2/mo).
                $day = (int) round(28 / ($datesPerMonth + 1) * ($slot + 1)) + 5;
                $depart = $base->modify('+' . ($day - 1) . ' days');
                if ($depart <= $today) {
                    continue;
                }
                foreach ($scan['trip_nights'] as $nights) {
                    $pairs[] = [
                        $depart->format('Y-m-d'),
                        $depart->modify("+$nights days")->format('Y-m-d'),
                    ];
                }
            }
        }
        return $pairs;
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }
}
