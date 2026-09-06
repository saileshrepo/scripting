<?php

namespace Zomunk;

use DateTimeImmutable;

/** One flown leg of an itinerary, provider-independent. */
final class Segment
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly DateTimeImmutable $departAt,
        public readonly DateTimeImmutable $arriveAt,
        public readonly string $carrierCode,
        public readonly string $flightNumber = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['from'],
            $data['to'],
            new DateTimeImmutable($data['depart_at']),
            new DateTimeImmutable($data['arrive_at']),
            $data['carrier'] ?? '',
            (string) ($data['number'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'from'      => $this->from,
            'to'        => $this->to,
            'depart_at' => $this->departAt->format(DATE_ATOM),
            'arrive_at' => $this->arriveAt->format(DATE_ATOM),
            'carrier'   => $this->carrierCode,
            'number'    => $this->flightNumber,
        ];
    }
}
