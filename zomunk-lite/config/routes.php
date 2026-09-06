<?php
/**
 * The watchlist. Zomunk watches a fixed set of Indian departure airports and
 * only surfaces a fare once it is far below what that route normally costs, so
 * every route needs a seed "typical fare" to compare against on day one.
 *
 * typical_fare_inr is a *seed* only: as soon as the route has enough
 * observations in fare_history (rules.min_history_points), the engine switches
 * to the observed median and the seed is ignored. Round-trip, one adult,
 * economy, all-in price in INR.
 *
 * scan.trip_nights  - trip lengths to price (a deal is a specific itinerary)
 * scan.months_ahead - how far out to look
 * scan.dates_per_month - departure dates sampled per month (keeps API calls bounded)
 */

return [
    'defaults' => [
        'cabin'             => 'ECONOMY',
        'currency'          => 'INR',
        'adults'            => 1,
        'max_duration_hours'=> 26,
        'max_stops'         => null,   // null = use ZOMUNK_MAX_STOPS
        'scan'              => [
            'trip_nights'     => [7, 14],
            'months_ahead'    => 6,
            'dates_per_month' => 2,
        ],
    ],

    'routes' => [
        // --- Long haul: Europe -------------------------------------------
        ['origin' => 'DEL', 'destination' => 'LHR', 'typical_fare_inr' => 65000, 'label' => 'Delhi -> London'],
        ['origin' => 'BOM', 'destination' => 'LHR', 'typical_fare_inr' => 68000, 'label' => 'Mumbai -> London'],
        ['origin' => 'DEL', 'destination' => 'CDG', 'typical_fare_inr' => 62000, 'label' => 'Delhi -> Paris'],
        ['origin' => 'BOM', 'destination' => 'FRA', 'typical_fare_inr' => 60000, 'label' => 'Mumbai -> Frankfurt'],
        ['origin' => 'BLR', 'destination' => 'AMS', 'typical_fare_inr' => 63000, 'label' => 'Bengaluru -> Amsterdam'],

        // --- Long haul: North America -------------------------------------
        ['origin' => 'DEL', 'destination' => 'JFK', 'typical_fare_inr' => 95000, 'label' => 'Delhi -> New York',
         'max_duration_hours' => 30],
        ['origin' => 'BOM', 'destination' => 'EWR', 'typical_fare_inr' => 98000, 'label' => 'Mumbai -> Newark',
         'max_duration_hours' => 30],
        ['origin' => 'BLR', 'destination' => 'SFO', 'typical_fare_inr' => 105000, 'label' => 'Bengaluru -> San Francisco',
         'max_duration_hours' => 32],
        ['origin' => 'DEL', 'destination' => 'YYZ', 'typical_fare_inr' => 92000, 'label' => 'Delhi -> Toronto',
         'max_duration_hours' => 30],

        // --- Long haul: APAC / Oceania -------------------------------------
        ['origin' => 'DEL', 'destination' => 'NRT', 'typical_fare_inr' => 70000, 'label' => 'Delhi -> Tokyo'],
        ['origin' => 'BOM', 'destination' => 'SYD', 'typical_fare_inr' => 85000, 'label' => 'Mumbai -> Sydney',
         'max_duration_hours' => 30],

        // --- Short haul: Gulf + South East Asia ----------------------------
        ['origin' => 'BOM', 'destination' => 'DXB', 'typical_fare_inr' => 22000, 'label' => 'Mumbai -> Dubai',
         'max_duration_hours' => 12],
        ['origin' => 'HYD', 'destination' => 'DOH', 'typical_fare_inr' => 25000, 'label' => 'Hyderabad -> Doha',
         'max_duration_hours' => 14],
        ['origin' => 'DEL', 'destination' => 'BKK', 'typical_fare_inr' => 26000, 'label' => 'Delhi -> Bangkok',
         'max_duration_hours' => 14],
        ['origin' => 'BLR', 'destination' => 'SIN', 'typical_fare_inr' => 30000, 'label' => 'Bengaluru -> Singapore',
         'max_duration_hours' => 14],
        ['origin' => 'MAA', 'destination' => 'KUL', 'typical_fare_inr' => 28000, 'label' => 'Chennai -> Kuala Lumpur',
         'max_duration_hours' => 14],
        ['origin' => 'CCU', 'destination' => 'BKK', 'typical_fare_inr' => 20000, 'label' => 'Kolkata -> Bangkok',
         'max_duration_hours' => 12],

        // --- Tier-2 origin: Raipur ------------------------------------------
        // RPR has no meaningful non-stop international service, so every one of
        // these needs a stop to reach a gateway (DEL/BOM/HYD) plus, often, an
        // international connection. max_stops is raised to 2 for that reason;
        // the trade-off is longer, less comfortable itineraries, which is why
        // the duration caps are wider than the metro routes above.
        ['origin' => 'RPR', 'destination' => 'DXB', 'typical_fare_inr' => 32000, 'label' => 'Raipur -> Dubai',
         'max_duration_hours' => 18, 'max_stops' => 2],
        ['origin' => 'RPR', 'destination' => 'BKK', 'typical_fare_inr' => 33000, 'label' => 'Raipur -> Bangkok',
         'max_duration_hours' => 20, 'max_stops' => 2],
        ['origin' => 'RPR', 'destination' => 'SIN', 'typical_fare_inr' => 38000, 'label' => 'Raipur -> Singapore',
         'max_duration_hours' => 20, 'max_stops' => 2],
        ['origin' => 'RPR', 'destination' => 'KUL', 'typical_fare_inr' => 35000, 'label' => 'Raipur -> Kuala Lumpur',
         'max_duration_hours' => 20, 'max_stops' => 2],
        ['origin' => 'RPR', 'destination' => 'HKT', 'typical_fare_inr' => 40000, 'label' => 'Raipur -> Phuket',
         'max_duration_hours' => 22, 'max_stops' => 2],
    ],
];
