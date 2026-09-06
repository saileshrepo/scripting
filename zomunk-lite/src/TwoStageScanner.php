<?php

namespace Zomunk;

use Throwable;
use Zomunk\Providers\CandidateProvider;
use Zomunk\Providers\FlightProvider;

/**
 * Two-stage scan, which is what makes continuous monitoring affordable.
 *
 *   Stage 1  a calendar sweep prices a whole month of departure dates in one
 *            call. Cheap enough to run often, so it is also where the price
 *            history that defines a route's "typical" fare comes from. But it
 *            carries no itinerary detail, so it can only screen on price and
 *            stop count.
 *
 *   Stage 2  only the candidates that already look like deals get a metered
 *            shopping call, which returns full itineraries. The layover,
 *            duration, transit-visa and checked-bag rules run here, on real
 *            segments, and only survivors become deals.
 *
 * The point is that the expensive call is spent on the few fares that might
 * matter, not on the hundreds that never will.
 */
final class TwoStageScanner
{
    private array $errors = [];
    private array $stats = [
        'routes' => 0, 'sweeps' => 0, 'candidates' => 0, 'screened' => 0,
        'verifications' => 0, 'offers' => 0, 'deals' => 0,
    ];

    public function __construct(
        private CandidateProvider $candidates,
        private ?FlightProvider $verifier,
        private DealEngine $engine,
        private DealRepository $repository,
        private $logger = null,
    ) {
        $this->logger ??= static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    /**
     * @param array $options {months?:int, verify_budget?:int, dry_run?:bool,
     *                        sleep_ms?:int, trust_unverified?:bool}
     */
    public function run(array $routes, array $options = []): array
    {
        $months = $options['months'] ?? 6;
        $verifyBudget = $options['verify_budget'] ?? 40;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sleepMs = (int) ($options['sleep_ms'] ?? 200);
        // Without a verifier the itinerary rules cannot run, so candidates are
        // reported but never saved as deals unless this is explicitly set.
        $trustUnverified = (bool) ($options['trust_unverified'] ?? false);

        $screened = [];

        // --- stage 1: sweep -------------------------------------------------
        foreach ($routes as $route) {
            $this->stats['routes']++;
            $this->log(sprintf('-- %s', $route['label']));

            foreach ($this->monthsAhead($months) as $month) {
                foreach ($route['scan']['trip_nights'] as $nights) {
                    try {
                        $this->stats['sweeps']++;
                        $found = $this->candidates->scanMonth($route, $month, $nights);
                    } catch (Throwable $e) {
                        $this->errors[] = sprintf('%s %s: %s', $route['label'], $month, $e->getMessage());
                        $this->log('   ! ' . $e->getMessage());
                        continue;
                    }
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                    if ($found === []) {
                        continue;
                    }
                    $this->stats['candidates'] += count($found);

                    // Cheapest sighting of the month is the price-history point.
                    usort($found, static fn(FareCandidate $a, FareCandidate $b) => $a->price <=> $b->price);
                    if (!$dryRun) {
                        $this->repository->recordCandidateObservation((int) $route['id'], $found[0]);
                    }

                    [$typical, $source] = $this->repository->typicalFareFor(
                        (int) $route['id'], $month, (float) $route['typical_fare_inr']
                    );
                    if ($typical === null || $typical <= 0) {
                        continue;
                    }

                    $maxStops = (int) ($route['max_stops'] ?? $this->engine->rules()['max_stops']);
                    foreach ($found as $candidate) {
                        if ($candidate->stops > $maxStops) {
                            continue;
                        }
                        if ($this->engine->discount($candidate->price, $typical) < $this->engine->rules()['min_discount']) {
                            continue;
                        }
                        $key = $route['id'] . '|' . $month;
                        // One candidate per route-month: the cheapest wins, and
                        // verification is the expensive part.
                        if (!isset($screened[$key]) || $candidate->price < $screened[$key]['candidate']->price) {
                            $screened[$key] = ['candidate' => $candidate, 'route' => $route,
                                               'typical' => $typical, 'source' => $source];
                        }
                    }
                }
            }
        }

        $this->stats['screened'] = count($screened);
        $this->log(sprintf(
            "\nStage 1: %d candidate(s) from %d sweep(s); %d look like deals",
            $this->stats['candidates'], $this->stats['sweeps'], $this->stats['screened'],
        ));

        // Best discounts first, so a tight verification budget is spent well.
        uasort($screened, fn(array $a, array $b) =>
            $this->engine->discount($b['candidate']->price, $b['typical'])
            <=> $this->engine->discount($a['candidate']->price, $a['typical']));

        // --- stage 2: verify ------------------------------------------------
        foreach ($screened as $entry) {
            if ($this->stats['verifications'] >= $verifyBudget) {
                $this->log('   verification budget reached; remaining candidates carry over to the next run');
                break;
            }

            $candidate = $entry['candidate'];
            $route = $entry['route'];

            if ($this->verifier === null) {
                $this->log('   ? ' . $candidate->describe() . ' — unverified (no shopping provider configured)');
                if (!$trustUnverified) {
                    continue;
                }
            }

            $offers = [];
            if ($this->verifier !== null) {
                try {
                    $this->stats['verifications']++;
                    $offers = $this->verifier->search(
                        $route, $candidate->departDate, $candidate->returnDate
                    );
                    $this->stats['offers'] += count($offers);
                } catch (Throwable $e) {
                    $this->errors[] = sprintf('verify %s: %s', $candidate->describe(), $e->getMessage());
                    $this->log('   ! verify failed: ' . $e->getMessage());
                    continue;
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            if ($offers === []) {
                $this->log('   x ' . $candidate->describe() . ' — gone by the time we looked');
                continue;
            }

            $best = null;
            foreach ($offers as $offer) {
                [$verified] = $this->engine->evaluate($offer, $route, $entry['typical'], $entry['source']);
                if ($verified !== null && ($best === null || $verified->offer->price < $best->offer->price)) {
                    $best = $verified;
                }
            }

            if ($best === null) {
                $this->log('   x ' . $candidate->describe() . ' — real itinerary failed the quality rules');
                continue;
            }

            if (!$this->engine->shouldAlert($best, $this->repository->lastAlertedDeal($best->dedupeKey()))) {
                $this->log('   = ' . $best->dedupeKey() . ' already alerted at a similar price');
                continue;
            }

            $this->stats['deals']++;
            $this->log(sprintf('   * DEAL %s %s (%d%% off typical %s)',
                $best->offer->origin . '-' . $best->offer->destination,
                Money::format($best->offer->price, $best->offer->currency),
                $best->discountPercent(),
                Money::format($best->typicalFare, $best->offer->currency)));

            if (!$dryRun) {
                $this->repository->saveDeal($best, (int) $route['id']);
            }
        }

        return ['stats' => $this->stats, 'errors' => $this->errors];
    }

    /** @return string[] YYYY-MM for the next N months */
    private function monthsAhead(int $months): array
    {
        $out = [];
        $cursor = new \DateTimeImmutable('first day of this month');
        for ($i = 1; $i <= $months; $i++) {
            $out[] = $cursor->modify("+$i month")->format('Y-m');
        }
        return $out;
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }
}
