<?php

namespace Zomunk\Providers;

use RuntimeException;
use Zomunk\FareCandidate;
use Zomunk\HttpClient;

/**
 * Wide sweep via the Travelpayouts (Aviasales) Data API.
 *
 *   GET https://api.travelpayouts.com/v1/prices/calendar
 *       origin, destination, depart_date (YYYY-MM), calendar_type=departure_date,
 *       currency, and optionally return_date / length
 *   Auth: x-access-token header (a token query parameter also works)
 *   Envelope: {success: bool, data: ..., error: string|null}
 *
 * One call covers every departure date in a month, which is what makes
 * continuous scanning affordable. The trade-off is that this data is cached
 * from Aviasales searches (up to about a week old) and carries no itinerary
 * detail, so anything it flags is a *candidate* and must be verified against a
 * live shopping API before it is alerted on.
 *
 * Endpoint and parameter names are constants below; `php bin/doctor.php`
 * exercises them against your token so a mismatch surfaces immediately.
 */
final class TravelpayoutsProvider implements CandidateProvider
{
    private const BASE = 'https://api.travelpayouts.com';
    private const PATH_CALENDAR = '/v1/prices/calendar';

    public function __construct(
        private string $token,
        private ?HttpClient $http = null,
    ) {
        $this->http ??= new HttpClient();
    }

    public function name(): string
    {
        return 'travelpayouts';
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    public function scanMonth(array $route, string $month, ?int $tripNights = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'TRAVELPAYOUTS_TOKEN is not set. Register free at travelpayouts.com, '
                . 'or run with ZOMUNK_CANDIDATE_PROVIDER=sample.'
            );
        }

        $query = [
            'origin'        => $route['origin'],
            'destination'   => $route['destination'],
            'depart_date'   => $month,
            'calendar_type' => 'departure_date',
            'currency'      => strtolower($route['currency'] ?? 'inr'),
        ];
        if ($tripNights !== null) {
            $query['length'] = $tripNights;
        }

        $response = $this->http->getJson(
            self::BASE . self::PATH_CALENDAR,
            $query,
            ['x-access-token' => $this->token],
        );

        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new RuntimeException("Travelpayouts rejected the token ({$response['status']}).");
        }
        if ($response['status'] >= 400) {
            throw new RuntimeException(
                sprintf('Travelpayouts calendar failed (%d): %s',
                    $response['status'], substr($response['body'], 0, 300))
            );
        }

        $payload = $response['json'] ?? [];
        if (($payload['success'] ?? false) !== true) {
            throw new RuntimeException(
                'Travelpayouts returned an error: ' . ($payload['error'] ?? 'unknown')
            );
        }

        return $this->mapCandidates($payload['data'] ?? [], $route);
    }

    /**
     * The calendar returns a map of date => entry, but a date-less list turns up
     * in some responses, so both shapes are handled.
     *
     * @return FareCandidate[]
     */
    private function mapCandidates($data, array $route): array
    {
        if (!is_array($data)) {
            return [];
        }

        $candidates = [];
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $departDate = $entry['depart_date'] ?? (is_string($key) ? $key : null);
            if ($departDate === null) {
                continue;
            }
            $departDate = substr((string) $departDate, 0, 10);

            $price = (float) ($entry['price'] ?? $entry['value'] ?? 0);
            if ($price <= 0) {
                continue;
            }

            $returnDate = isset($entry['return_date']) && $entry['return_date'] !== ''
                ? substr((string) $entry['return_date'], 0, 10)
                : null;

            $candidates[] = new FareCandidate(
                $entry['origin'] ?? $route['origin'],
                $entry['destination'] ?? $route['destination'],
                $departDate,
                $returnDate,
                $price,
                strtoupper($route['currency'] ?? 'INR'),
                (int) ($entry['number_of_changes'] ?? $entry['transfers'] ?? 0),
                $entry['airline'] ?? null,
                $this->name(),
            );
        }

        return $candidates;
    }
}
