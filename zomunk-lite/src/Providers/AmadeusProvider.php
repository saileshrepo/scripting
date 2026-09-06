<?php

namespace Zomunk\Providers;

use DateTimeImmutable;
use RuntimeException;
use Zomunk\HttpClient;
use Zomunk\Offer;
use Zomunk\Segment;

/**
 * Real fares from the Amadeus Self-Service API (Flight Offers Search v2).
 *
 *   token  POST {base}/v1/security/oauth2/token   (client_credentials)
 *   search GET  {base}/v2/shopping/flight-offers
 *
 * The free "test" host serves cached fares, which is enough to exercise the
 * whole pipeline; point AMADEUS_ENV at production for live pricing.
 */
final class AmadeusProvider implements FlightProvider
{
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $base = 'https://test.api.amadeus.com',
        private ?HttpClient $http = null,
        private ?string $tokenCacheFile = null,
    ) {
        $this->http ??= new HttpClient();
    }

    public function name(): string
    {
        return 'amadeus';
    }

    public function search(array $route, string $departDate, ?string $returnDate, int $limit = 10): array
    {
        $query = [
            'originLocationCode'      => $route['origin'],
            'destinationLocationCode' => $route['destination'],
            'departureDate'           => $departDate,
            'adults'                  => $route['adults'] ?? 1,
            'currencyCode'            => $route['currency'] ?? 'INR',
            'travelClass'             => $route['cabin'] ?? 'ECONOMY',
            'max'                     => $limit,
        ];
        if ($returnDate !== null) {
            $query['returnDate'] = $returnDate;
        }

        $response = $this->http->getJson(
            $this->base . '/v2/shopping/flight-offers',
            $query,
            ['Authorization' => 'Bearer ' . $this->accessToken()],
        );

        if ($response['status'] === 400) {
            // Amadeus answers "no fares for this date pair" with a 400; that is
            // an empty result for us, not a failure of the scan.
            return [];
        }
        if ($response['status'] >= 400) {
            throw new RuntimeException(
                "Amadeus search failed ({$response['status']}): " . substr($response['body'], 0, 300)
            );
        }

        $payload = $response['json'] ?? [];
        $carriers = $payload['dictionaries']['carriers'] ?? [];

        $offers = [];
        foreach ($payload['data'] ?? [] as $raw) {
            $offer = $this->mapOffer($raw, $route, $carriers);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }
        return $offers;
    }

    private function mapOffer(array $raw, array $route, array $carriers): ?Offer
    {
        if (empty($raw['itineraries'])) {
            return null;
        }

        $itineraries = [];
        foreach ($raw['itineraries'] as $itinerary) {
            $segments = [];
            foreach ($itinerary['segments'] ?? [] as $segment) {
                $segments[] = new Segment(
                    $segment['departure']['iataCode'],
                    $segment['arrival']['iataCode'],
                    new DateTimeImmutable($segment['departure']['at']),
                    new DateTimeImmutable($segment['arrival']['at']),
                    $segment['carrierCode'] ?? '',
                    (string) ($segment['number'] ?? ''),
                );
            }
            if ($segments !== []) {
                $itineraries[] = $segments;
            }
        }
        if ($itineraries === []) {
            return null;
        }

        $carrierCode = $raw['validatingAirlineCodes'][0]
            ?? $itineraries[0][0]->carrierCode;

        return new Offer(
            $route['origin'],
            $route['destination'],
            (float) ($raw['price']['grandTotal'] ?? $raw['price']['total'] ?? 0),
            $raw['price']['currency'] ?? ($route['currency'] ?? 'INR'),
            $this->cabinOf($raw) ?? ($route['cabin'] ?? 'ECONOMY'),
            $itineraries,
            $this->hasCheckedBag($raw),
            $carrierCode,
            $carriers[$carrierCode] ?? null,
            $raw,
        );
    }

    private function cabinOf(array $raw): ?string
    {
        return $raw['travelerPricings'][0]['fareDetailsBySegment'][0]['cabin'] ?? null;
    }

    /** True only if every flown segment carries at least one checked bag. */
    private function hasCheckedBag(array $raw): bool
    {
        $fareDetails = $raw['travelerPricings'][0]['fareDetailsBySegment'] ?? [];
        if ($fareDetails === []) {
            return false;
        }
        foreach ($fareDetails as $detail) {
            $bags = $detail['includedCheckedBags'] ?? null;
            if ($bags === null) {
                return false;
            }
            $quantity = (int) ($bags['quantity'] ?? 0);
            $weight = (int) ($bags['weight'] ?? 0);
            if ($quantity < 1 && $weight < 1) {
                return false;
            }
        }
        return true;
    }

    private function accessToken(): string
    {
        if ($this->token !== null && $this->tokenExpiresAt > time() + 30) {
            return $this->token;
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException(
                'AMADEUS_CLIENT_ID / AMADEUS_CLIENT_SECRET are not set. '
                . 'Register at developers.amadeus.com, or run with ZOMUNK_PROVIDER=sample.'
            );
        }

        if ($this->loadCachedToken()) {
            return $this->token;
        }

        $response = $this->http->postForm($this->base . '/v1/security/oauth2/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response['status'] >= 400 || empty($response['json']['access_token'])) {
            throw new RuntimeException(
                "Amadeus auth failed ({$response['status']}): " . substr($response['body'], 0, 300)
            );
        }

        $this->token = $response['json']['access_token'];
        $this->tokenExpiresAt = time() + (int) ($response['json']['expires_in'] ?? 1799);
        $this->storeCachedToken();

        return $this->token;
    }

    private function loadCachedToken(): bool
    {
        if ($this->tokenCacheFile === null || !is_readable($this->tokenCacheFile)) {
            return false;
        }
        $cached = json_decode((string) file_get_contents($this->tokenCacheFile), true);
        if (!is_array($cached) || ($cached['expires_at'] ?? 0) <= time() + 30) {
            return false;
        }
        $this->token = $cached['token'];
        $this->tokenExpiresAt = (int) $cached['expires_at'];
        return true;
    }

    private function storeCachedToken(): void
    {
        if ($this->tokenCacheFile === null) {
            return;
        }
        @file_put_contents(
            $this->tokenCacheFile,
            json_encode(['token' => $this->token, 'expires_at' => $this->tokenExpiresAt]),
            LOCK_EX,
        );
        @chmod($this->tokenCacheFile, 0600);
    }
}
