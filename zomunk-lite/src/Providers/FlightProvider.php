<?php

namespace Zomunk\Providers;

use Zomunk\Offer;

interface FlightProvider
{
    /**
     * Cheapest offers for one route and one set of dates.
     *
     * @param array{origin:string, destination:string, cabin:string, currency:string, adults:int} $route
     * @return Offer[]
     */
    public function search(array $route, string $departDate, ?string $returnDate, int $limit = 10): array;

    public function name(): string;
}
