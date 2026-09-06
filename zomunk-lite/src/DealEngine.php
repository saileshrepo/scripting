<?php

namespace Zomunk;

/**
 * The "best deals" rules, in one place and free of I/O so they can be tested.
 *
 * A fare becomes a deal only when all three hold:
 *   1. it is at least rules.min_discount below what the route normally costs;
 *   2. the itinerary is one a person would actually want to fly (stop count,
 *      layover length, total duration, checked bag);
 *   3. it does not need a transit visa for the configured passport.
 *
 * Deals at or above rules.premium_discount, and anything outside economy, are
 * premium-only. Everything else reaches free members after rules.free_delay_hours.
 */
final class DealEngine
{
    public function __construct(
        private array $rules,
        private array $transitVisa = ['airports' => [], 'requires' => []],
    ) {
    }

    public static function fromConfig(): self
    {
        $config = zomunk_config();
        return new self($config['rules'], require $config['root'] . '/config/transit_visa.php');
    }

    /**
     * @return array{0: DealCandidate|null, 1: string[]} candidate (or null) and rejection reasons
     */
    public function evaluate(Offer $offer, array $route, ?float $typicalFare, string $baselineSource): array
    {
        [$rejections, $flags] = $this->inspectItinerary($offer, $route);

        if ($typicalFare === null || $typicalFare <= 0) {
            $rejections[] = 'no_baseline';
        }
        if ($offer->price <= 0) {
            $rejections[] = 'invalid_price';
        }

        $discount = ($typicalFare !== null && $typicalFare > 0)
            ? $this->discount($offer->price, $typicalFare)
            : 0.0;

        if ($discount < $this->rules['min_discount']) {
            $rejections[] = sprintf(
                'discount_below_threshold(%d%% < %d%%)',
                (int) round($discount * 100),
                (int) round($this->rules['min_discount'] * 100),
            );
        }

        if ($rejections !== []) {
            return [null, $rejections];
        }

        $isMistake = $discount >= $this->rules['premium_discount'];
        $tier = ($isMistake || strtoupper($offer->cabin) !== 'ECONOMY') ? 'premium' : 'free';

        return [
            new DealCandidate($offer, $route, $typicalFare, $baselineSource, $discount, $tier, $isMistake, $flags),
            [],
        ];
    }

    public function discount(float $price, float $typicalFare): float
    {
        if ($typicalFare <= 0) {
            return 0.0;
        }
        return round(max(0.0, 1 - ($price / $typicalFare)), 4);
    }

    /**
     * Itinerary quality gates.
     *
     * @return array{0: string[], 1: string[]} [rejections, flags]
     */
    public function inspectItinerary(Offer $offer, array $route): array
    {
        $rejections = [];
        $flags = [];

        if ($offer->maxStops() > $this->rules['max_stops']) {
            $rejections[] = sprintf('too_many_stops(%d)', $offer->maxStops());
        }

        foreach ($offer->layovers() as $layover) {
            if ($layover['minutes'] < $this->rules['min_layover']) {
                // Too tight to be a protected connection in practice.
                $rejections[] = sprintf('layover_too_short(%s %dm)', $layover['airport'], $layover['minutes']);
            } elseif ($layover['minutes'] > $this->rules['max_layover']) {
                $rejections[] = sprintf('layover_too_long(%s %dm)', $layover['airport'], $layover['minutes']);
            }
        }

        $maxDuration = (int) ($route['max_duration_hours'] ?? 26) * 60;
        if ($offer->longestDirectionMinutes() > $maxDuration) {
            $rejections[] = sprintf('too_long(%dm > %dm)', $offer->longestDirectionMinutes(), $maxDuration);
        }

        if ($this->rules['require_bag'] && !$offer->bagIncluded) {
            $rejections[] = 'no_checked_bag';
        }

        foreach ($this->transitVisaProblems($offer) as $problem) {
            if ($problem['known']) {
                $rejections[] = 'transit_visa_required(' . $problem['airport'] . ')';
            } else {
                $flags[] = 'transit_unknown(' . $problem['airport'] . ')';
            }
        }

        return [$rejections, $flags];
    }

    /**
     * @return array<int, array{airport:string, known:bool}>
     * A connection through an airport we have no country for is flagged, not
     * rejected — an incomplete lookup table should never eat a real deal.
     */
    public function transitVisaProblems(Offer $offer): array
    {
        $passport = strtoupper($this->rules['passport'] ?? 'IN');
        $blocked = $this->transitVisa['requires'][$passport] ?? [];
        if ($blocked === []) {
            return [];
        }

        $problems = [];
        foreach ($offer->connectionAirports() as $airport) {
            $country = $this->transitVisa['airports'][$airport] ?? null;
            if ($country === null) {
                $problems[] = ['airport' => $airport, 'known' => false];
            } elseif (in_array($country, $blocked, true)) {
                $problems[] = ['airport' => $airport, 'known' => true];
            }
        }
        return $problems;
    }

    /**
     * The "typical" fare for a route: the median of what we have actually seen.
     * Median rather than mean because one mistake fare in the history should
     * not drag the baseline down and hide the next one.
     *
     * @param float[] $samples
     */
    public function typicalFare(array $samples, float $seed): array
    {
        $samples = array_values(array_filter($samples, static fn($price) => $price > 0));
        if (count($samples) < $this->rules['min_history_points']) {
            return [$seed > 0 ? $seed : null, 'seed'];
        }
        return [self::median($samples), 'observed'];
    }

    /** @param float[] $values */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1
            ? (float) $values[$middle]
            : (float) (($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Should this candidate be alerted, given the last deal we alerted on the
     * same route and month? Re-alert only on a materially better price, or once
     * the previous alert has gone stale.
     *
     * @param array{price:float, found_at:string}|null $lastAlerted
     */
    public function shouldAlert(DealCandidate $candidate, ?array $lastAlerted, ?int $now = null): bool
    {
        if ($lastAlerted === null) {
            return true;
        }

        $now ??= time();
        $ageDays = ($now - strtotime($lastAlerted['found_at'])) / 86400;
        if ($ageDays >= $this->rules['realert_days']) {
            return true;
        }

        $previous = (float) $lastAlerted['price'];
        if ($previous <= 0) {
            return true;
        }
        return (1 - ($candidate->offer->price / $previous)) >= $this->rules['realert_drop'];
    }

    public function rules(): array
    {
        return $this->rules;
    }
}
