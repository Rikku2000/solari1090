<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

function ds_cfg(array $config): array {
    $ds = $config['data_sources'] ?? [];
    if (!is_array($ds)) $ds = [];
    $ds += [
        'cache_dir' => __DIR__ . '/cache',
        'default_ttl_s' => 86400,
        'airports' => ['source' => 'local', 'custom_url' => '', 'ttl_s' => 86400],
        'airlines' => ['source' => 'local', 'custom_url' => '', 'ttl_s' => 86400],
        'routes' => ['source' => 'local', 'custom_url' => '', 'ttl_s' => 86400],
    ];
    foreach (['airports','airlines','routes'] as $k) {
        if (!isset($ds[$k]) || !is_array($ds[$k])) $ds[$k] = [];
        $ds[$k] += ['source' => 'local', 'custom_url' => '', 'ttl_s' => (int)$ds['default_ttl_s']];
        $ds[$k]['source'] = strtolower((string)$ds[$k]['source']);
        $ds[$k]['ttl_s'] = (int)($ds[$k]['ttl_s'] ?? $ds['default_ttl_s']);
    }
    return $ds;
}

function ds_ensure_cache_dir(string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function ds_cache_path(string $cacheDir, string $name): string {
    return rtrim($cacheDir, '/\\') . '/' . $name;
}

function ds_download_if_needed(string $url, string $cacheFile, int $ttl_s): ?string {
    if ($url === '') return null;

    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl_s) {
        return $cacheFile;
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 5, 'header' => "User-Agent: airport-board\r\n"],
        'https' => ['timeout' => 5, 'header' => "User-Agent: airport-board\r\n"],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return is_file($cacheFile) ? $cacheFile : null;

    $tmp = $cacheFile . '.tmp';
    if (@file_put_contents($tmp, $raw) === false) return is_file($cacheFile) ? $cacheFile : null;
    @rename($tmp, $cacheFile);

    return $cacheFile;
}

function ds_airports_source_url(string $source, string $customUrl): string {
    if ($source === 'openflights') {
        return 'https://raw.githubusercontent.com/jpatokal/openflights/master/data/airports.dat';
    }
    if ($source === 'ourairports') {
        return 'https://ourairports.com/data/airports.csv';
    }
    if ($source === 'airportcodes') {
        return 'https://raw.githubusercontent.com/datasets/airport-codes/master/data/airport-codes.csv';
    }
    if ($source === 'custom') {
        return $customUrl;
    }
    return '';
}

function ds_airlines_source_url(string $source, string $customUrl): string {
    if ($source === 'openflights') {
        return 'https://raw.githubusercontent.com/jpatokal/openflights/master/data/airlines.dat';
    }
    if ($source === 'custom') {
        return $customUrl;
    }
    return '';
}

function ds_routes_source_url(string $source, string $customUrl): string {
    if ($source === 'custom') return $customUrl;
    return '';
}

function parse_airports_any(string $source, string $raw): array {
    $list = [];

    if ($source === 'openflights' || $source === 'local' || $source === 'custom') {
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

		while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $iata = strtoupper(trim($row[4] ?? ''));
            $icao = strtoupper(trim($row[5] ?? ''));
            $lat  = $row[6] ?? null;
            $lon  = $row[7] ?? null;
            if ($iata === '' || $iata === '\\N' || $icao === '' || $icao === '\\N') continue;
            if (!is_numeric($lat) || !is_numeric($lon)) continue;
            $list[] = [
                'name' => (string)($row[1] ?? ''),
                'city' => (string)($row[2] ?? ''),
                'country' => (string)($row[3] ?? ''),
                'iata' => $iata,
                'icao' => $icao,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
            ];
        }
        fclose($fh);
        return $list;
    }

    if ($source === 'ourairports') {
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $hdr = fgetcsv($fh, 0, ',', '"', '\\');
        if (!is_array($hdr)) { fclose($fh); return []; }
        $idx = array_flip($hdr);
        $need = ['ident','iata_code','name','municipality','iso_country','latitude_deg','longitude_deg'];
        foreach ($need as $k) if (!isset($idx[$k])) { fclose($fh); return []; }
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $icao = strtoupper(trim($row[$idx['ident']] ?? ''));
            $iata = strtoupper(trim($row[$idx['iata_code']] ?? ''));
            $lat  = $row[$idx['latitude_deg']] ?? null;
            $lon  = $row[$idx['longitude_deg']] ?? null;
            if ($iata === '' || $icao === '') continue;
            if (!is_numeric($lat) || !is_numeric($lon)) continue;
            $list[] = [
                'name' => (string)($row[$idx['name']] ?? ''),
                'city' => (string)($row[$idx['municipality']] ?? ''),
                'country' => (string)($row[$idx['iso_country']] ?? ''),
                'iata' => $iata,
                'icao' => $icao,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
            ];
        }
        fclose($fh);
        return $list;
    }

    if ($source === 'airportcodes') {
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $hdr = fgetcsv($fh, 0, ',', '"', '\\');
        if (!is_array($hdr)) { fclose($fh); return []; }
        $idx = array_flip($hdr);
        $need = ['name','municipality','iso_country','gps_code','iata_code','coordinates'];
        foreach ($need as $k) if (!isset($idx[$k])) { fclose($fh); return []; }
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $icao = strtoupper(trim($row[$idx['gps_code']] ?? ''));
            $iata = strtoupper(trim($row[$idx['iata_code']] ?? ''));
            $coords = (string)($row[$idx['coordinates']] ?? '');
            $lat = null; $lon = null;
            if (preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $coords, $m)) {
                $lon = (float)$m[1];
                $lat = (float)$m[2];
            }
            if ($iata === '' || $icao === '') continue;
            if ($lat === null || $lon === null) continue;
            $list[] = [
                'name' => (string)($row[$idx['name']] ?? ''),
                'city' => (string)($row[$idx['municipality']] ?? ''),
                'country' => (string)($row[$idx['iso_country']] ?? ''),
                'iata' => $iata,
                'icao' => $icao,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
            ];
        }
        fclose($fh);
        return $list;
    }

    return [];
}

function ds_load_airports_list(array $config): array {
    $ds = ds_cfg($config);
    $cfg = $ds['airports'];
    $source = $cfg['source'];

    if ($source === 'local') {
        $raw = @file_get_contents(__DIR__ . '/airports.dat');
        return is_string($raw) ? parse_airports_any('local', $raw) : [];
    }

    ds_ensure_cache_dir($ds['cache_dir']);
    $url = ds_airports_source_url($source, (string)$cfg['custom_url']);
    $cache = ds_cache_path($ds['cache_dir'], "airports_{$source}.dat");
    $path = ds_download_if_needed($url, $cache, (int)$cfg['ttl_s']);
    if (!$path) return [];

    $raw = @file_get_contents($path);
    if (!is_string($raw)) return [];
    return parse_airports_any($source, $raw);
}

function ds_load_airports_maps(array $config): array {
    static $maps = null;
    if ($maps !== null) return $maps;

    $list = ds_load_airports_list($config);
    $icaoToIata = [];
    $iataToIata = [];
    foreach ($list as $a) {
        $icao = strtoupper(trim((string)($a['icao'] ?? '')));
        $iata = strtoupper(trim((string)($a['iata'] ?? '')));
        if ($icao !== '' && $iata !== '') {
            $icaoToIata[$icao] = $iata;
            $iataToIata[$iata] = $iata;
        }
    }
    $maps = ['icao_to_iata' => $icaoToIata, 'iata_to_iata' => $iataToIata];
    return $maps;
}

function ds_load_airlines_map(array $config): array {
    static $map = null;
    if ($map !== null) return $map;

    $ds = ds_cfg($config);
    $cfg = $ds['airlines'];
    $source = $cfg['source'];

    $raw = null;

    if ($source === 'local') {
        $raw = @file_get_contents(__DIR__ . '/airlines.dat');
    } else {
        ds_ensure_cache_dir($ds['cache_dir']);
        $url = ds_airlines_source_url($source, (string)$cfg['custom_url']);
        $cache = ds_cache_path($ds['cache_dir'], "airlines_{$source}.dat");
        $path = ds_download_if_needed($url, $cache, (int)$cfg['ttl_s']);
        if ($path) $raw = @file_get_contents($path);
    }

    $map = [];
    if (!is_string($raw) || $raw === '') return $map;

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $iata = strtoupper(trim($row[3] ?? ''));
        $icao = strtoupper(trim($row[4] ?? ''));
        if ($icao !== '' && $icao !== '\\N' && $iata !== '' && $iata !== '\\N') {
            $map[$icao] = $iata;
        }
    }
    fclose($fh);
    return $map;
}

function ds_load_flight_routes(array $config): array {
    static $map = null;
    if ($map !== null) return $map;

    $local = load_flight_routes(__DIR__ . '/flights.dat');

    $ds = ds_cfg($config);
    $cfg = $ds['routes'];
    $source = $cfg['source'];

    $remote = [];
    if ($source === 'custom') {
        ds_ensure_cache_dir($ds['cache_dir']);
        $url = ds_routes_source_url($source, (string)$cfg['custom_url']);
        $cache = ds_cache_path($ds['cache_dir'], "routes_custom.json");
        $path = ds_download_if_needed($url, $cache, (int)$cfg['ttl_s']);
        if ($path) $remote = load_flight_routes($path);
    }

    $map = $remote + $local;
    return $map;
}

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

function load_icao_to_iata_map(string $datFile): array {
    static $map = null;
    if ($map !== null) return $map;

    if (!is_readable($datFile)) {
        throw new RuntimeException("airlines.dat not readable: {$datFile}");
    }

    $map = [];

    $fh = fopen($datFile, 'r');
    if (!$fh) {
        throw new RuntimeException("Failed to open airlines.dat");
    }

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $iata = strtoupper(trim($row[3] ?? ''));
        $icao = strtoupper(trim($row[4] ?? ''));

        if ($icao !== '' && $icao !== '\\N' &&
            $iata !== '' && $iata !== '\\N') {
            $map[$icao] = $iata;
        }
    }

    fclose($fh);

    return $map;
}

function load_airports_maps(string $datFile): array {
    static $maps = null;
    if ($maps !== null) return $maps;

    if (!is_readable($datFile)) {
        throw new RuntimeException("airports.dat not readable: {$datFile}");
    }

    $icaoToIata = [];
    $iataToIata = [];
    $fh = fopen($datFile, 'r');
    if (!$fh) throw new RuntimeException("Failed to open airports.dat");

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $iata = strtoupper(trim($row[4] ?? ''));
        $icao = strtoupper(trim($row[5] ?? ''));

        if ($icao !== '' && $icao !== '\\N' && $iata !== '' && $iata !== '\\N') {
            $icaoToIata[$icao] = $iata;
            $iataToIata[$iata] = $iata;
        }
    }
    fclose($fh);

    $maps = ['icao_to_iata' => $icaoToIata, 'iata_to_iata' => $iataToIata];
    return $maps;
}

function load_flight_routes(string $jsonFile): array {
    static $map = null;
    if ($map !== null) return $map;

    if (!is_readable($jsonFile)) {
        $map = [];
        return $map;
    }

    $raw = @file_get_contents($jsonFile);
    $data = json_decode($raw ?: '[]', true);
    $map = [];

    if (is_array($data)) {
        foreach ($data as $row) {
            if (!is_array($row) || count($row) < 3) continue;
            $flight = strtoupper(trim((string)$row[0]));
            $from   = strtoupper(trim((string)$row[1]));
            $to     = strtoupper(trim((string)$row[2]));
            if ($flight !== '' && $from !== '' && $to !== '') {
                $map[$flight] = [$from, $to];
            }
        }
    }

    return $map;
}

function guess_airport_icao_from_name(string $name): string {
    if (preg_match('/\b([A-Z0-9]{4})\b/', strtoupper($name), $m)) return $m[1];
    return '';
}

function guess_airport_iata_from_name(string $name): string {
    if (preg_match('/\b([A-Z]{3})\b/', strtoupper($name), $m)) return $m[1];
    return '';
}

function best_code(string $code, array $icaoToIata): string {
    $c = strtoupper(trim($code));
    if ($c === '' || $c === '\\N') return '';
    return $icaoToIata[$c] ?? $c;
}

function airline_codes_from_flight(string $flight): array {
    global $config;

    $f = strtoupper(trim($flight));
    if ($f === '') return ['iata' => '', 'icao' => ''];

    if (!preg_match('/^([A-Z]{2,3})/', $f, $m))
        return ['iata' => '', 'icao' => ''];

    $p = $m[1];

    $icao_to_iata = ds_load_airlines_map($config);

    if (strlen($p) === 3) {
        return [
            'iata' => $icao_to_iata[$p] ?? '',
            'icao' => $p
        ];
    }

    return [
        'iata' => $p,
        'icao' => ''
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

$airportsMaps = ds_load_airports_maps($config);
$icaoToIata = $airportsMaps['icao_to_iata'];

$airportIcao = strtoupper((string)($config['airport']['icao'] ?? ''));
if ($airportIcao === '') $airportIcao = guess_airport_icao_from_name((string)($config['airport']['name'] ?? ''));

$airportIata = strtoupper((string)($config['airport']['iata'] ?? ''));
if ($airportIata === '') $airportIata = guess_airport_iata_from_name((string)($config['airport']['name'] ?? ''));
if ($airportIata === '' && $airportIcao !== '') {
    $airportIata = $icaoToIata[$airportIcao] ?? '';
}

if ($airportIata === '') {
    if (strlen($airportIcao) === 3 || strlen($airportIcao) === 4) $airportIata = $airportIcao;
}

$flightRoutes = ds_load_flight_routes($config);

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

    $fkey = strtoupper(trim($flight));
    $route = $flightRoutes[$fkey] ?? null;

    $origCode = '';
    $destCode = '';
    if (is_array($route) && count($route) >= 2) {
        $origCode = best_code((string)$route[0], $icaoToIata);
        $destCode = best_code((string)$route[1], $icaoToIata);
    }

    $row = [
        'flight'    => $flight,
        'from'      => '',
        'to'        => '',
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

    $dirEff = $bucket;
    if ($dirEff === null) {
        if ($trendFpm !== null && $trendFpm <= $config['arrival_trend_fpm']) $dirEff = 'arrivals';
        elseif ($trendFpm !== null && $trendFpm >= $config['depart_trend_fpm']) $dirEff = 'departures';
    }

    if ($dirEff === 'arrivals') {
        $row['from'] = $origCode !== '' ? $origCode : '';
        $row['to']   = $airportIata;
    } elseif ($dirEff === 'departures') {
        $row['from'] = $airportIata;
        $row['to']   = $destCode !== '' ? $destCode : '';
    } else {
        $row['from'] = $origCode;
        $row['to']   = $destCode;
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
