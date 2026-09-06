<?php

namespace Zomunk\Providers;

use Zomunk\FareCandidate;

/**
 * A cheap, wide sweep over a route: one call covers a whole month of departure
 * dates. Used to find price candidates before spending a metered shopping call
 * on any of them.
 */
interface CandidateProvider
{
    /**
     * @param array{origin:string, destination:string, currency:string} $route
     * @param string $month YYYY-MM
     * @return FareCandidate[]
     */
    public function scanMonth(array $route, string $month, ?int $tripNights = null): array;

    public function name(): string;
}
