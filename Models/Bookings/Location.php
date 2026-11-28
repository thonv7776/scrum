<?php
declare(strict_types = 1);

/** Nine supported locations and approximate/optional road mileage (US07). */
function landmarks(): array {
    return ['heathrow' => ['name' => 'Heathrow Airport, Terminal 5',
        'x' => 95,
        'y' => 168],
        'paddington' => ['name' => 'Paddington Station',
        'x' => 280,
        'y' => 125],
        'baker' => ['name' => '221B Baker Street, Marylebone',
        'x' => 332,
        'y' => 108],
        'westminster' => ['name' => 'Westminster Abbey, Central London',
        'x' => 365,
        'y' => 168],
        'savoy' => ['name' => 'The Savoy Hotel, Strand',
        'x' => 405,
        'y' => 142],
        'kingscross' => ['name' => "King's Cross St. Pancras",
        'x' => 398,
        'y' => 86],
        'canary' => ['name' => 'Canary Wharf Financial District',
        'x' => 518,
        'y' => 156],
        'cityairport' => ['name' => 'London City Airport (LCY)',
        'x' => 605,
        'y' => 152],
        'gatwick' => ['name' => 'Gatwick Airport, South Terminal',
        'x' => 395,
        'y' => 278],
    ];
}

function route_distance(string $a, string $b): float {
    $m = landmarks();
    if (!isset ($m[$a], $m[$b])) throw new DomainException('Please choose a supported pickup and destination from the location lists.');
    if ($a === $b) throw new DomainException('Pickup and destination must be different.');
    // Optional configured road-route service for the nine validated London locations.
    // When no provider is configured, retain the original prototype estimate.
    $road = phc_road_distance($a, $b);
    if ($road !== null) return $road;
    if (($a === 'heathrow' && $b === 'savoy') || ($b === 'heathrow' && $a === 'savoy')) return 18.50;
    return money(max(1.8, hypot($m[$a]['x'] - $m[$b]['x'], $m[$a]['y'] - $m[$b]['y']) * 0.0595));
}
// Prototype approximate mileage based on the supplied interactive map. NOT a routing-provider result.
function phc_road_distance(string $pickup, string $destination): ? float {
    $template = trim((string)getenv('PHC_ROUTING_URL'));
    if ($template === '') return null;
    $coords = ['heathrow' => [ - 0.4543,
        51.4700],
        'paddington' => [ - 0.1755,
        51.5154],
        'baker' => [ - 0.1585,
        51.5238],
        'westminster' => [ - 0.1273,
        51.4994],
        'savoy' => [ - 0.1204,
        51.5101],
        'kingscross' => [ - 0.1238,
        51.5308],
        'canary' => [ - 0.0235,
        51.5054],
        'cityairport' => [0.0495,
        51.5048],
        'gatwick' => [ - 0.1821,
        51.1537]];
    if (!isset ($coords[$pickup], $coords[$destination])) return null;
    if (!str_starts_with($template, 'https://') || !extension_loaded('curl')) return null;
    $point = static fn(array $v) => sprintf('%.6f,%.6f', $v[0], $v[1]);
    $url = str_replace(['{from}',
        '{to}'], [$point($coords[$pickup]),
        $point($coords[$destination])], $template);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $json = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // PHP 8+: cURL handles close automatically
    if (!is_string($json) || $code !== 200) return null;
    $answer = json_decode($json, true);
    $metres = $answer['routes'][0]['distance'] ?? null;
    if (!is_numeric($metres) || $metres <= 0 || $metres > 1000000) return null;
    return money((float)$metres / 1609.344);
}
