<?php

namespace Zomunk;

use RuntimeException;
use Zomunk\Providers\AmadeusProvider;
use Zomunk\Providers\CandidateProvider;
use Zomunk\Providers\FlightProvider;
use Zomunk\Providers\SampleCandidateProvider;
use Zomunk\Providers\SampleProvider;
use Zomunk\Providers\TravelpayoutsProvider;

final class ProviderFactory
{
    public static function make(?string $name = null): FlightProvider
    {
        $config = zomunk_config();
        $name ??= $config['provider'];

        return match ($name) {
            'amadeus' => new AmadeusProvider(
                $config['amadeus']['client_id'],
                $config['amadeus']['client_secret'],
                $config['amadeus']['base'],
                new HttpClient(),
                $config['root'] . '/var/amadeus-token.json',
            ),
            'sample'  => new SampleProvider(),
            'none'    => throw new RuntimeException("No shopping provider configured."),
            default   => throw new RuntimeException("Unknown provider '$name'. Use 'amadeus' or 'sample'."),
        };
    }

    /** The wide-sweep provider used for stage 1, or null when disabled. */
    public static function makeCandidateProvider(?string $name = null): ?CandidateProvider
    {
        $config = zomunk_config();
        $name ??= $config['candidate_provider'];

        return match ($name) {
            'travelpayouts' => new TravelpayoutsProvider($config['travelpayouts']['token'], new HttpClient()),
            'sample'        => new SampleCandidateProvider(),
            'none', null    => null,
            default         => throw new RuntimeException(
                "Unknown candidate provider '$name'. Use 'travelpayouts', 'sample' or 'none'."
            ),
        };
    }

    /** The shopping provider used to verify candidates, or null when disabled. */
    public static function makeVerifier(): ?FlightProvider
    {
        $name = zomunk_config()['provider'];
        return $name === 'none' ? null : self::make($name);
    }
}
