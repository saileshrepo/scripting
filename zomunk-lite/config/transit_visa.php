<?php
/**
 * Zomunk filters out itineraries that need a transit visa. Doing that needs
 * two things: which country a connecting airport sits in, and which of those
 * countries require a transit visa for the traveller's passport.
 *
 * airports  - IATA code => ISO country code. Connections through an airport
 *             that is not listed are allowed but flagged 'transit_unknown',
 *             so a missing entry never silently drops a real deal.
 * requires  - passport ISO code => countries that require a transit visa even
 *             when the traveller stays airside.
 */

return [
    'airports' => [
        // Gulf / Middle East
        'DXB' => 'AE', 'AUH' => 'AE', 'SHJ' => 'AE', 'DOH' => 'QA', 'KWI' => 'KW',
        'BAH' => 'BH', 'MCT' => 'OM', 'RUH' => 'SA', 'JED' => 'SA', 'IST' => 'TR',
        'SAW' => 'TR', 'TLV' => 'IL', 'AMM' => 'JO', 'CAI' => 'EG', 'BEY' => 'LB',
        // Europe
        'LHR' => 'GB', 'LGW' => 'GB', 'MAN' => 'GB', 'CDG' => 'FR', 'ORY' => 'FR',
        'FRA' => 'DE', 'MUC' => 'DE', 'AMS' => 'NL', 'ZRH' => 'CH', 'GVA' => 'CH',
        'VIE' => 'AT', 'BRU' => 'BE', 'CPH' => 'DK', 'ARN' => 'SE', 'OSL' => 'NO',
        'HEL' => 'FI', 'MAD' => 'ES', 'BCN' => 'ES', 'FCO' => 'IT', 'MXP' => 'IT',
        'LIS' => 'PT', 'WAW' => 'PL', 'PRG' => 'CZ', 'BUD' => 'HU', 'ATH' => 'GR',
        'DUB' => 'IE', 'SVO' => 'RU', 'DME' => 'RU', 'BAK' => 'AZ', 'TAS' => 'UZ',
        'ALA' => 'KZ', 'TBS' => 'GE', 'EVN' => 'AM', 'BEG' => 'RS',
        // North America
        'JFK' => 'US', 'EWR' => 'US', 'ORD' => 'US', 'SFO' => 'US', 'LAX' => 'US',
        'IAD' => 'US', 'BOS' => 'US', 'SEA' => 'US', 'ATL' => 'US', 'DFW' => 'US',
        'YYZ' => 'CA', 'YVR' => 'CA', 'YUL' => 'CA', 'MEX' => 'MX',
        // Asia Pacific
        'SIN' => 'SG', 'BKK' => 'TH', 'DMK' => 'TH', 'KUL' => 'MY', 'HKG' => 'HK',
        'NRT' => 'JP', 'HND' => 'JP', 'KIX' => 'JP', 'ICN' => 'KR', 'TPE' => 'TW',
        'PVG' => 'CN', 'PEK' => 'CN', 'CAN' => 'CN', 'CTU' => 'CN', 'HKT' => 'TH',
        'CGK' => 'ID', 'DPS' => 'ID', 'MNL' => 'PH', 'SGN' => 'VN', 'HAN' => 'VN',
        'CMB' => 'LK', 'KTM' => 'NP', 'DAC' => 'BD', 'MLE' => 'MV',
        'SYD' => 'AU', 'MEL' => 'AU', 'BNE' => 'AU', 'AKL' => 'NZ',
        // India (origins, never a "foreign" transit)
        'DEL' => 'IN', 'BOM' => 'IN', 'BLR' => 'IN', 'MAA' => 'IN', 'HYD' => 'IN',
        'CCU' => 'IN', 'COK' => 'IN', 'AMD' => 'IN', 'GOI' => 'IN', 'PNQ' => 'IN',
        // Africa
        'ADD' => 'ET', 'NBO' => 'KE', 'JNB' => 'ZA', 'CMN' => 'MA',
    ],

    'requires' => [
        // Airside transit for an Indian passport holder without a US/UK/Schengen
        // visa. Conservative on purpose: a wrongly-kept deal wastes a reader's
        // time at the airport, a wrongly-dropped one only costs us one deal.
        'IN' => ['US', 'CA', 'GB', 'CN', 'RU', 'ZA', 'PH', 'NZ', 'AU'],
    ],
];
