<?php

namespace Zomunk\Providers;

use DateTimeImmutable;
use Zomunk\Offer;
use Zomunk\Segment;

/**
 * Offline provider for development and tests: no API keys, no network, and
 * deterministic output for a given (route, dates) pair so a scan can be re-run
 * and reasoned about. It deliberately emits itineraries that the quality
 * filters should reject — three-stop routings, missing checked bags, US/UK
 * transit connections — as well as the occasional genuine price drop.
 */
final class SampleProvider implements FlightProvider
{
    /** Hubs used to synthesise connections, some of them transit-visa traps. */
    private const HUBS = ['DXB', 'DOH', 'IST', 'AUH', 'FRA', 'LHR', 'ORD', 'SIN', 'HKG', 'ADD'];

    public function __construct(private array $typicalFares = [])
    {
    }

    public function name(): string
    {
        return 'sample';
    }

    public function search(array $route, string $departDate, ?string $returnDate, int $limit = 10): array
    {
        $key = $route['origin'] . $route['destination'] . $departDate . ($returnDate ?? '');
        $seed = crc32($key);
        $typical = (float) ($this->typicalFares[$route['origin'] . '-' . $route['destination']]
            ?? $route['typical_fare_inr']
            ?? 50000);

        $offers = [];
        $count = 3 + ($seed % 3);
        for ($i = 0; $i < min($count, $limit); $i++) {
            $rand = $this->pseudoRandom($seed + $i * 7919);

            // ~1 offer in 12 is a real drop; the rest hover around typical.
            $isDrop = ($seed + $i) % 12 === 0;
            $factor = $isDrop
                ? 0.30 + $rand * 0.28          // 30%-58% of typical => 42%-70% off
                : 0.82 + $rand * 0.45;         // ordinary pricing, sometimes above
            $price = round($typical * $factor, 2);

            $stops = match (true) {
                $rand < 0.35 => 0,
                $rand < 0.80 => 1,
                default      => 2,
            };
            $hub = self::HUBS[($seed + $i) % count(self::HUBS)];
            $bagIncluded = $rand > 0.2;

            $offers[] = new Offer(
                $route['origin'],
                $route['destination'],
                $price,
                $route['currency'] ?? 'INR',
                $route['cabin'] ?? 'ECONOMY',
                $this->buildItineraries($route, $departDate, $returnDate, $stops, $hub, $rand),
                $bagIncluded,
                ['AI', 'EK', 'QR', 'LH', 'SQ'][($seed + $i) % 5],
                null,
                ['synthetic' => true],
            );
        }

        return $offers;
    }

    private function buildItineraries(
        array $route,
        string $departDate,
        ?string $returnDate,
        int $stops,
        string $hub,
        float $rand,
    ): array {
        $itineraries = [$this->buildLeg($route['origin'], $route['destination'], $departDate, $stops, $hub, $rand)];
        if ($returnDate !== null) {
            $itineraries[] = $this->buildLeg($route['destination'], $route['origin'], $returnDate, $stops, $hub, $rand);
        }
        return $itineraries;
    }

    /** @return Segment[] */
    private function buildLeg(string $from, string $to, string $date, int $stops, string $hub, float $rand): array
    {
        $depart = new DateTimeImmutable($date . ' 02:40');
        if ($stops === 0) {
            return [new Segment($from, $to, $depart, $depart->modify('+9 hours'), 'AI', '101')];
        }

        // Layover length swings from a tight 35 minutes to an ugly 7 hours so
        // the min/max layover gates get exercised.
        $layoverMinutes = (int) round(35 + $rand * 385);
        $segments = [];
        $legFrom = $from;
        $cursor = $depart;
        $viaHubs = $stops === 1 ? [$hub] : [$hub, 'CMB'];

        foreach ($viaHubs as $index => $via) {
            $arrive = $cursor->modify('+4 hours');
            $segments[] = new Segment($legFrom, $via, $cursor, $arrive, 'AI', (string) (200 + $index));
            $cursor = $arrive->modify("+{$layoverMinutes} minutes");
            $legFrom = $via;
        }
        $segments[] = new Segment($legFrom, $to, $cursor, $cursor->modify('+5 hours'), 'AI', '299');

        return $segments;
    }

    /** Deterministic 0..1 from an integer seed. */
    private function pseudoRandom(int $seed): float
    {
        return (abs(crc32((string) $seed)) % 10000) / 10000;
    }
}
