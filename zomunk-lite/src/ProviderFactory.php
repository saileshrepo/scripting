<?php

namespace Zomunk;

use RuntimeException;
use Zomunk\Providers\AmadeusProvider;
use Zomunk\Providers\FlightProvider;
use Zomunk\Providers\SampleProvider;

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
            default   => throw new RuntimeException("Unknown provider '$name'. Use 'amadeus' or 'sample'."),
        };
    }
}
