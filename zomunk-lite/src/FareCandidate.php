<?php

namespace Zomunk;

/**
 * A price sighting from a wide-sweep provider: enough to say "this looks cheap
 * for this route", not enough to say "this is a trip worth taking".
 *
 * Calendar-style endpoints return a price, a stop count and an airline for a
 * date — no segment times, no connecting airports, no baggage. So a candidate
 * can only be screened on price and stop count; the layover, duration, transit
 * visa and checked-bag rules need a full itinerary, which is what the
 * verification stage fetches.
 */
final class FareCandidate
{
    public function __construct(
        public readonly string $origin,
        public readonly string $destination,
        public readonly string $departDate,
        public readonly ?string $returnDate,
        public readonly float $price,
        public readonly string $currency,
        public readonly int $stops,
        public readonly ?string $carrierCode = null,
        public readonly ?string $source = null,
    ) {
    }

    public function departMonth(): string
    {
        return substr($this->departDate, 0, 7);
    }

    public function routeKey(): string
    {
        return $this->origin . '-' . $this->destination;
    }

    public function describe(): string
    {
        return sprintf(
            '%s %s%s %s %s (%d stop%s)',
            $this->routeKey(),
            $this->departDate,
            $this->returnDate !== null ? '/' . $this->returnDate : '',
            Money::format($this->price, $this->currency),
            $this->carrierCode ?? '',
            $this->stops,
            $this->stops === 1 ? '' : 's',
        );
    }
}
