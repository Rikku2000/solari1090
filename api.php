<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

function http_get_json(string $url, int $timeout_s = 2): ?array {
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout_s, 'header' => "User-Agent: airport-board\r\n"],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    return 2 * $R * asin(min(1.0, sqrt($a)));
}

function read_state(string $file): array {
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_state(string $file, array $state): void {
    @file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

function now(): int { return time(); }
function safe_str(?string $s): string { return trim($s ?? ''); }

function airline_codes_from_flight(string $flight): array {
    $f = strtoupper(trim($flight));
    if ($f === '') return ['iata' => '', 'icao' => ''];
    if (!preg_match('/^([A-Z]{2,3})/', $f, $m)) return ['iata' => '', 'icao' => ''];
    $p = $m[1];
    return [
        'iata' => (strlen($p) >= 2) ? substr($p, 0, 2) : '',
        'icao' => (strlen($p) >= 3) ? substr($p, 0, 3) : '',
    ];
}

header('Content-Type: application/json; charset=utf-8');

$mode = strtolower((string)($_GET['mode'] ?? 'departures'));
if (!in_array($mode, ['arrivals', 'departures'], true)) $mode = 'departures';

$aircraftUrl = rtrim($config['dump1090_base'], '/') . '/data/aircraft.json';
$payload = http_get_json($aircraftUrl, 2);

if (!$payload || !isset($payload['aircraft']) || !is_array($payload['aircraft'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => "Could not read $aircraftUrl"]);
    exit;
}

$state = read_state($config['state_cache_file']);
$t = now();

foreach ($state as $icao => $rec) {
    if (!isset($rec['last_seen']) || ($t - (int)$rec['last_seen']) > $config['state_ttl_s']) {
        unset($state[$icao]);
    }
}

$airportLat = (float)$config['airport']['lat'];
$airportLon = (float)$config['airport']['lon'];

$arrivals = [];
$departures = [];

foreach ($payload['aircraft'] as $a) {
    $icao = strtolower((string)($a['hex'] ?? ''));
    if ($icao === '') continue;

    $seen = $a['seen'] ?? null;
    if ($seen === null || !is_numeric($seen) || (float)$seen > $config['min_seen_s']) continue;

    $lat = $a['lat'] ?? null;
    $lon = $a['lon'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon)) continue;

    $alt = $a['alt_baro'] ?? ($a['altitude'] ?? null);
    if (!is_numeric($alt)) continue;
    $alt = (int)$alt;

    if ($alt < $config['min_alt_ft'] || $alt > $config['alt_ceiling_ft']) continue;

    $dist = haversine_km($airportLat, $airportLon, (float)$lat, (float)$lon);
    if ($dist > $config['radius_km']) continue;

    $flight = safe_str($a['flight'] ?? '');
    $codes = airline_codes_from_flight($flight);
    $gs = $a['gs'] ?? null;

    if (!isset($state[$icao])) {
        $state[$icao] = ['history' => [], 'last_seen' => $t];
    }
    $state[$icao]['last_seen'] = $t;
    $state[$icao]['history'][] = [$t, $alt];

    $prevDistKm = $state[$icao]['last_dist_km'] ?? null;
    if ($lat !== null && $lon !== null) {
        $curDistKm = haversine_km((float)$lat, (float)$lon, $airportLat, $airportLon);
        $state[$icao]['last_dist_km'] = $curDistKm;
    } else {
        $curDistKm = null;
    }


    $trimBefore = $t - max(10, $config['trend_window_s'] + 10);
    $hist2 = [];
    foreach ($state[$icao]['history'] as $pt) {
        if (is_array($pt) && count($pt) === 2 && (int)$pt[0] >= $trimBefore) {
            $hist2[] = [(int)$pt[0], (int)$pt[1]];
        }
    }
    $state[$icao]['history'] = $hist2;

    $targetTime = $t - (int)$config['trend_window_s'];
    $oldAlt = null; $oldTime = null;
    foreach ($hist2 as $pt) {
        if ($pt[0] <= $targetTime) { $oldTime = $pt[0]; $oldAlt = $pt[1]; }
    }
    if ($oldAlt === null && count($hist2) > 0) { $oldTime = $hist2[0][0]; $oldAlt = $hist2[0][1]; }

    $trendFpm = null;
    if ($oldAlt !== null && $oldTime !== null && $t > $oldTime) {
        $dtMin = ($t - $oldTime) / 60.0;
        if ($dtMin > 0) $trendFpm = ($alt - $oldAlt) / $dtMin;
    }

    $statusTxt = null;
    $statusCls = null;
    $st = is_array($config['status'] ?? null) ? $config['status'] : [];
    $stEnable = (bool)($st['enable'] ?? false);
    $stInfluenceLists = (bool)($st['influence_lists'] ?? true);
    if ($stEnable) {
        $lowAltFt   = (int)($st['low_alt_ft'] ?? 2000);
        $landedFt   = (int)($st['landed_alt_ft'] ?? 1000);
        $upFpm      = (int)($st['up_fpm'] ?? 150);
        $downFpm    = (int)($st['down_fpm'] ?? 150);

		if ($alt <= $landedFt) {
			$statusTxt = 'LANDED';
			$statusCls = 'good';
		} elseif ($alt <= $lowAltFt) {
			if ($trendFpm !== null && $trendFpm >= $upFpm) {
				$statusTxt = 'TAKE OFF';
				$statusCls = 'good';
			} elseif ($trendFpm !== null && $trendFpm <= -$downFpm) {
				$statusTxt = 'LANDING';
				$statusCls = 'warn';
			} else {
				$distDelta = null;
				if (isset($curDistKm) && $curDistKm !== null && isset($prevDistKm) && $prevDistKm !== null) {
					$distDelta = $curDistKm - $prevDistKm;
				}
				if ($distDelta !== null && $distDelta <= -0.20) {
					$statusTxt = 'LANDING';
					$statusCls = 'warn';
				} elseif ($distDelta !== null && $distDelta >= 0.20) {
					$statusTxt = 'TAKE OFF';
					$statusCls = 'good';
				} else {
					$statusTxt = 'LOW ALT';
					$statusCls = 'warn';
				}
			}
		}
    }

    $row = [
        'flight'    => $flight,
        'icao'      => strtoupper($icao),
        'alt_ft'    => $alt,
        'dist_km'   => round($dist, 1),
        'gs_kt'     => is_numeric($gs) ? (int)round((float)$gs) : null,
        'trend_fpm' => $trendFpm !== null ? (int)round($trendFpm) : null,
        'status'    => $statusTxt,
        'status_cls'=> $statusCls,
        'seen_s'    => (int)round((float)$seen),
        'last_seen_epoch' => $t - (int)round((float)$seen),
        'airline_iata_guess' => $codes['iata'],
        'airline_icao_guess' => $codes['icao'],
    ];

    $bucket = null;
    if ($stEnable && $stInfluenceLists && $statusTxt !== null) {
        if ($statusTxt === 'TAKE OFF') {
            $bucket = 'departures';
        } elseif ($statusTxt === 'LANDING' || $statusTxt === 'LANDED') {
            $bucket = 'arrivals';
        }
    }

    if ($bucket === 'arrivals') {
        $arrivals[] = $row;
    } elseif ($bucket === 'departures') {
        $departures[] = $row;
    } else {
        if ($trendFpm !== null && $trendFpm <= $config['arrival_trend_fpm']) {
            $arrivals[] = $row;
        } elseif ($trendFpm !== null && $trendFpm >= $config['depart_trend_fpm']) {
            $departures[] = $row;
        }
    }
}

usort($arrivals, fn($a,$b) => ($a['dist_km'] <=> $b['dist_km']) ?: ($a['alt_ft'] <=> $b['alt_ft']));
usort($departures, fn($a,$b) => ($a['dist_km'] <=> $b['dist_km']) ?: ($b['alt_ft'] <=> $a['alt_ft']));

write_state($config['state_cache_file'], $state);

$list = ($mode === 'arrivals') ? $arrivals : $departures;
$list = array_slice($list, 0, $config['max_rows']);

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'airport' => $config['airport']['name'],
    'updated_epoch' => $t,
    'rows' => $list,
    'counts' => [
        'arrivals_in_radius' => count($arrivals),
        'departures_in_radius' => count($departures),
        'shown' => count($list),
    ],
], JSON_UNESCAPED_SLASHES);
?>