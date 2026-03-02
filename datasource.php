<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function ds_cfg(array $config): array {
    $ds = $config['data_sources'] ?? [];
    if (!is_array($ds)) $ds = [];
    $ds += [
        'cache_dir' => __DIR__ . '/cache',
        'default_ttl_s' => 86400,
        'airports' => ['source' => 'local', 'custom_url' => '', 'ttl_s' => 86400],
    ];
    if (!isset($ds['airports']) || !is_array($ds['airports'])) $ds['airports'] = [];
    $ds['airports'] += ['source' => 'local', 'custom_url' => '', 'ttl_s' => (int)$ds['default_ttl_s']];
    $ds['airports']['source'] = strtolower((string)$ds['airports']['source']);
    $ds['airports']['ttl_s'] = (int)($ds['airports']['ttl_s'] ?? $ds['default_ttl_s']);
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

function parse_airports_any(string $source, string $raw): array {
    $list = [];

    if ($source === 'openflights' || $source === 'local' || $source === 'custom') {
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($row = fgetcsv($fh)) !== false) {
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
        $hdr = fgetcsv($fh);
        if (!is_array($hdr)) { fclose($fh); return []; }
        $idx = array_flip($hdr);
        $need = ['ident','iata_code','name','municipality','iso_country','latitude_deg','longitude_deg'];
        foreach ($need as $k) if (!isset($idx[$k])) { fclose($fh); return []; }
        while (($row = fgetcsv($fh)) !== false) {
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
        $hdr = fgetcsv($fh);
        if (!is_array($hdr)) { fclose($fh); return []; }
        $idx = array_flip($hdr);
        $need = ['name','municipality','iso_country','gps_code','iata_code','coordinates'];
        foreach ($need as $k) if (!isset($idx[$k])) { fclose($fh); return []; }
        while (($row = fgetcsv($fh)) !== false) {
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

function load_airports_list(array $config): array {
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
    return is_string($raw) ? parse_airports_any($source, $raw) : [];
}

echo json_encode(['ok' => true, 'airports' => load_airports_list($config)], JSON_UNESCAPED_SLASHES);
