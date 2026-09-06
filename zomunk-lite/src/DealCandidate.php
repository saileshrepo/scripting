<?php

namespace Zomunk;

/** An offer that cleared every gate, with the numbers that got it there. */
final class DealCandidate
{
    public function __construct(
        public readonly Offer $offer,
        public readonly array $route,
        public readonly float $typicalFare,
        public readonly string $baselineSource,   // 'observed' | 'seed'
        public readonly float $discount,          // 0.42 == 42% below typical
        public readonly string $tier,             // 'free' (everyone) | 'premium'
        public readonly bool $isMistake,
        public readonly array $flags = [],        // e.g. ['transit_unknown']
    ) {
    }

    public function dedupeKey(): string
    {
        return sprintf(
            '%s-%s|%s|%s',
            $this->offer->origin,
            $this->offer->destination,
            $this->offer->departMonth(),
            $this->offer->cabin,
        );
    }

    public function savings(): float
    {
        return round($this->typicalFare - $this->offer->price, 2);
    }

    public function discountPercent(): int
    {
        return (int) round($this->discount * 100);
    }
}
