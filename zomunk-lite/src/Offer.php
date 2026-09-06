<?php

namespace Zomunk;

use DateTimeImmutable;

/**
 * A priced round trip, normalised away from whichever provider produced it.
 * $itineraries[0] is the outbound, $itineraries[1] (if present) the return;
 * each is an ordered list of Segment.
 */
final class Offer
{
    /** @param Segment[][] $itineraries */
    public function __construct(
        public readonly string $origin,
        public readonly string $destination,
        public readonly float $price,
        public readonly string $currency,
        public readonly string $cabin,
        public readonly array $itineraries,
        public readonly bool $bagIncluded,
        public readonly ?string $carrierCode = null,
        public readonly ?string $carrierName = null,
        public readonly array $raw = [],
    ) {
    }

    public function departDate(): string
    {
        return $this->itineraries[0][0]->departAt->format('Y-m-d');
    }

    public function departMonth(): string
    {
        return substr($this->departDate(), 0, 7);
    }

    public function returnDate(): ?string
    {
        if (!isset($this->itineraries[1][0])) {
            return null;
        }
        return $this->itineraries[1][0]->departAt->format('Y-m-d');
    }

    /** Stops on the busiest direction — what "non-stop or one stop" is judged on. */
    public function maxStops(): int
    {
        $stops = 0;
        foreach ($this->itineraries as $segments) {
            $stops = max($stops, count($segments) - 1);
        }
        return $stops;
    }

    /**
     * @return array<int, array{airport:string, minutes:int}>
     */
    public function layovers(): array
    {
        $layovers = [];
        foreach ($this->itineraries as $segments) {
            for ($i = 1, $n = count($segments); $i < $n; $i++) {
                $minutes = (int) round(
                    ($segments[$i]->departAt->getTimestamp() - $segments[$i - 1]->arriveAt->getTimestamp()) / 60
                );
                $layovers[] = ['airport' => $segments[$i - 1]->to, 'minutes' => $minutes];
            }
        }
        return $layovers;
    }

    /** Longest single direction, gate to gate. Long-haul returns are asymmetric. */
    public function longestDirectionMinutes(): int
    {
        $longest = 0;
        foreach ($this->itineraries as $segments) {
            $first = $segments[0];
            $last = $segments[count($segments) - 1];
            $minutes = (int) round(($last->arriveAt->getTimestamp() - $first->departAt->getTimestamp()) / 60);
            $longest = max($longest, $minutes);
        }
        return $longest;
    }

    /** Airports the traveller connects through — the transit-visa filter input. */
    public function connectionAirports(): array
    {
        return array_values(array_unique(array_column($this->layovers(), 'airport')));
    }

    public function layoverSummary(): string
    {
        $parts = [];
        foreach ($this->layovers() as $layover) {
            $parts[] = sprintf('%s %s', $layover['airport'], self::formatMinutes($layover['minutes']));
        }
        return implode(', ', $parts);
    }

    public static function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours > 0 ? sprintf('%dh %02dm', $hours, $rest) : sprintf('%dm', $rest);
    }

    /**
     * Zomunk is not a booking platform — it points members at Google Flights,
     * the airline or an OTA. This builds the Google Flights search for the
     * exact itinerary so the member lands on the same fare.
     */
    public function bookingUrl(): string
    {
        $query = sprintf(
            'Flights from %s to %s on %s',
            $this->origin,
            $this->destination,
            $this->departDate(),
        );
        if ($this->returnDate() !== null) {
            $query .= ' through ' . $this->returnDate();
        }
        return 'https://www.google.com/travel/flights?q=' . rawurlencode($query);
    }

    public function toArray(): array
    {
        return [
            'origin'       => $this->origin,
            'destination'  => $this->destination,
            'price'        => $this->price,
            'currency'     => $this->currency,
            'cabin'        => $this->cabin,
            'bag_included' => $this->bagIncluded,
            'carrier_code' => $this->carrierCode,
            'carrier_name' => $this->carrierName,
            'itineraries'  => array_map(
                static fn(array $segments) => array_map(static fn(Segment $s) => $s->toArray(), $segments),
                $this->itineraries,
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['origin'],
            $data['destination'],
            (float) $data['price'],
            $data['currency'],
            $data['cabin'] ?? 'ECONOMY',
            array_map(
                static fn(array $segments) => array_map(
                    static fn(array $segment) => Segment::fromArray($segment),
                    $segments,
                ),
                $data['itineraries'],
            ),
            (bool) ($data['bag_included'] ?? false),
            $data['carrier_code'] ?? null,
            $data['carrier_name'] ?? null,
            $data,
        );
    }
}
