<?php

namespace Zomunk\Providers;

use DateTimeImmutable;
use Zomunk\FareCandidate;

/**
 * Offline stand-in for a calendar sweep: one deterministic price per departure
 * date in the month, mostly ordinary with the occasional drop.
 */
final class SampleCandidateProvider implements CandidateProvider
{
    public function name(): string
    {
        return 'sample';
    }

    public function scanMonth(array $route, string $month, ?int $tripNights = null): array
    {
        $typical = (float) ($route['typical_fare_inr'] ?? 50000);
        $nights = $tripNights ?? 7;
        $first = new DateTimeImmutable($month . '-01');
        $today = new DateTimeImmutable('today');
        $seed = crc32($route['origin'] . $route['destination'] . $month);

        $candidates = [];
        for ($day = 0; $day < (int) $first->format('t'); $day++) {
            $depart = $first->modify("+$day days");
            if ($depart <= $today) {
                continue;
            }

            $rand = (abs(crc32((string) ($seed + $day * 7919))) % 10000) / 10000;
            $isDrop = ($seed + $day) % 14 === 0;
            $price = round($typical * ($isDrop ? 0.30 + $rand * 0.28 : 0.82 + $rand * 0.45), 2);

            $candidates[] = new FareCandidate(
                $route['origin'],
                $route['destination'],
                $depart->format('Y-m-d'),
                $depart->modify("+$nights days")->format('Y-m-d'),
                $price,
                strtoupper($route['currency'] ?? 'INR'),
                $rand < 0.35 ? 0 : ($rand < 0.85 ? 1 : 2),
                ['AI', 'EK', 'QR', 'LH', 'SQ'][($seed + $day) % 5],
                $this->name(),
            );
        }

        return $candidates;
    }
}
