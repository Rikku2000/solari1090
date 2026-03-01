<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
session_start();

function cfg_get_password_hash(array $config): ?string {
    if (isset($config['admin_password_hash']) && is_string($config['admin_password_hash']) && $config['admin_password_hash'] !== '') {
        return $config['admin_password_hash'];
    }
    return null;
}

function cfg_get_password_plain(array $config): ?string {
    if (isset($config['admin_password']) && is_string($config['admin_password']) && $config['admin_password'] !== '') {
        return $config['admin_password'];
    }
    return null;
}

function is_authed(): bool {
    return !empty($_SESSION['solari_admin_authed']);
}

function require_authed(): void {
    if (!is_authed()) {
        header('Location: admin.php');
        exit;
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bool_from_post(string $key, bool $default): bool {
    return isset($_POST[$key]) ? true : $default;
}

function num_from_post(string $key, float $default): float {
    if (!isset($_POST[$key])) return $default;
    $raw = trim((string)$_POST[$key]);
    if ($raw === '') return $default;
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) ? (float)$raw : $default;
}

function int_from_post(string $key, int $default): int {
    if (!isset($_POST[$key])) return $default;
    $raw = trim((string)$_POST[$key]);
    if ($raw === '') return $default;
    return preg_match('/^-?\d+$/', $raw) ? (int)$raw : $default;
}

function str_from_post(string $key, string $default): string {
    if (!isset($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function write_config_file(array $cfg, string $path): bool {
    $airport = $cfg['airport'] ?? ['name'=>'','lat'=>0,'lon'=>0];
    $status  = $cfg['status']  ?? [];

    $php = "<?php ";
    $php .= "\$config = [";
    $php .= "    'dump1090_base' => " . var_export((string)($cfg['dump1090_base'] ?? ''), true) . ",			/* Dump1090 base URL */";
    $php .= "    'airport' => [";
    $php .= "        'name' => " . var_export((string)($airport['name'] ?? ''), true) . ",			/* Airport name */";
    $php .= "        'lat' => " . (float)($airport['lat'] ?? 0.0) . ",								/* Airport latitude */";
    $php .= "        'lon' => " . (float)($airport['lon'] ?? 0.0) . ",								/* Airport longitude */";
    $php .= "    ],";
    $php .= "    'radius_km' => " . (float)($cfg['radius_km'] ?? 0.0) . ",								/* Radius (km) */";
    $php .= "    'alt_ceiling_ft' => " . (int)($cfg['alt_ceiling_ft'] ?? 0) . ",							/* Altitude ceiling (ft) */";
    $php .= "    'min_alt_ft' => " . (int)($cfg['min_alt_ft'] ?? 0) . ",								/* Minimum altitude (ft) */";
    $php .= "    'min_seen_s' => " . (int)($cfg['min_seen_s'] ?? 0) . ",								/* Min seen (seconds) */";
    $php .= "    'trend_window_s' => " . (int)($cfg['trend_window_s'] ?? 0) . ",							/* Trend window (seconds) */";
    $php .= "    'arrival_trend_fpm' => " . (int)($cfg['arrival_trend_fpm'] ?? 0) . ",						/* Arrival trend (fpm) */";
    $php .= "    'depart_trend_fpm' => " . (int)($cfg['depart_trend_fpm'] ?? 0) . ",						/* Depart trend (fpm) */";
    $php .= "    'max_rows' => " . (int)($cfg['max_rows'] ?? 0) . ",								/* Max rows */";

    $cols = $cfg['columns'] ?? [];
    $php .= "    'columns' => [\t\t\t\t\t\t\t\t\t/* Toggle optional columns */";
    $php .= "        'from' => " . (!empty($cols['from']) ? 'true' : 'false') . ",";
    $php .= "        'to' => " . (!empty($cols['to']) ? 'true' : 'false') . ",";
    $php .= "        'alt_ft' => " . (!empty($cols['alt_ft']) ? 'true' : 'false') . ",";
    $php .= "        'dist_km' => " . (!empty($cols['dist_km']) ? 'true' : 'false') . ",";
    $php .= "        'gs_kt' => " . (!empty($cols['gs_kt']) ? 'true' : 'false') . ",";
    $php .= "    ],";

    $php .= "    'state_cache_file' => __DIR__ . '/state_cache.json',	/* State cache file */";
    $php .= "    'state_ttl_s' => " . (int)($cfg['state_ttl_s'] ?? 600) . ",							/* State TTL (seconds) */";

    if (isset($cfg['admin_password_hash']) && is_string($cfg['admin_password_hash']) && $cfg['admin_password_hash'] !== '') {
        $php .= "    'admin_password_hash' => " . var_export($cfg['admin_password_hash'], true) . ",	/* Admin password hash (recommended) */";
    } else {
        $php .= "    'admin_password' => " . var_export((string)($cfg['admin_password'] ?? 'changeme'), true) . ",						/* Admin password for admin.php */";
    }

    $php .= "'status' => [";
    $php .= "		'enable' => " . (!empty($status['enable']) ? 'true' : 'false') . ",";
    $php .= "		'influence_lists' => " . (!empty($status['influence_lists']) ? 'true' : 'false') . ",						/* If true, LANDING/LANDED are forced into arrivals, TAKE OFF into departures */";
    $php .= "		'low_alt_ft' => " . (int)($status['low_alt_ft'] ?? 2500) . ",								/* Below this altitude we show LANDING / TAKE OFF */";
    $php .= "		'landed_alt_ft' => " . (int)($status['landed_alt_ft'] ?? 1000) . ",							/* Below this altitude we show LANDED */";
    $php .= "		'up_fpm' => " . (int)($status['up_fpm'] ?? 150) . ",								/* Minimum climb rate to call TAKE OFF */";
    $php .= "		'down_fpm' => " . (int)($status['down_fpm'] ?? 100) . ",								/* Minimum descent rate to call LANDING */";
    $php .= "	],";
    $php .= "];";
    $php .= " ?>";

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $php) === false) return false;
    return rename($tmp, $path);
}

$errors = [];
$notice = null;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $pw = (string)($_POST['password'] ?? '');
    $hash = cfg_get_password_hash($config);
    $plain = cfg_get_password_plain($config);

    $ok = false;
    if ($hash) {
        $ok = password_verify($pw, $hash);
    } elseif ($plain !== null) {
        $ok = hash_equals($plain, $pw);
    }

    if ($ok) {
        $_SESSION['solari_admin_authed'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $errors[] = 'Wrong password.';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'save') {
    require_authed();

    $new = $config;

    $new['dump1090_base'] = str_from_post('dump1090_base', (string)$config['dump1090_base']);

    if (!isset($new['airport']) || !is_array($new['airport'])) $new['airport'] = [];
    $new['airport']['name'] = str_from_post('airport_name', (string)($config['airport']['name'] ?? ''));
    $new['airport']['lat']  = num_from_post('airport_lat', (float)($config['airport']['lat'] ?? 0.0));
    $new['airport']['lon']  = num_from_post('airport_lon', (float)($config['airport']['lon'] ?? 0.0));

    $new['radius_km']       = num_from_post('radius_km', (float)$config['radius_km']);
    $new['alt_ceiling_ft']  = int_from_post('alt_ceiling_ft', (int)$config['alt_ceiling_ft']);
    $new['min_alt_ft']      = int_from_post('min_alt_ft', (int)$config['min_alt_ft']);
    $new['min_seen_s']      = int_from_post('min_seen_s', (int)$config['min_seen_s']);
    $new['trend_window_s']  = int_from_post('trend_window_s', (int)$config['trend_window_s']);
    $new['arrival_trend_fpm'] = int_from_post('arrival_trend_fpm', (int)$config['arrival_trend_fpm']);
    $new['depart_trend_fpm']  = int_from_post('depart_trend_fpm', (int)$config['depart_trend_fpm']);
    $new['max_rows']        = int_from_post('max_rows', (int)$config['max_rows']);
    $new['state_ttl_s']     = int_from_post('state_ttl_s', (int)$config['state_ttl_s']);

    if (!isset($new['columns']) || !is_array($new['columns'])) $new['columns'] = [];
    $new['columns']['from'] = isset($_POST['col_from']);
    $new['columns']['to'] = isset($_POST['col_to']);
    $new['columns']['alt_ft'] = isset($_POST['col_alt_ft']);
    $new['columns']['dist_km'] = isset($_POST['col_dist_km']);
    $new['columns']['gs_kt'] = isset($_POST['col_gs_kt']);

    $pw_new = (string)($_POST['admin_password_new'] ?? '');
    if ($pw_new !== '') {
        $new['admin_password'] = $pw_new;
    }

    if (!isset($new['status']) || !is_array($new['status'])) $new['status'] = [];
    $new['status']['enable'] = isset($_POST['status_enable']);
    $new['status']['influence_lists'] = isset($_POST['status_influence_lists']);
    $new['status']['low_alt_ft'] = int_from_post('status_low_alt_ft', (int)($config['status']['low_alt_ft'] ?? 2500));
    $new['status']['landed_alt_ft'] = int_from_post('status_landed_alt_ft', (int)($config['status']['landed_alt_ft'] ?? 1000));
    $new['status']['up_fpm'] = int_from_post('status_up_fpm', (int)($config['status']['up_fpm'] ?? 150));
    $new['status']['down_fpm'] = int_from_post('status_down_fpm', (int)($config['status']['down_fpm'] ?? 100));

    $ok = write_config_file($new, __DIR__ . '/config.php');
    if ($ok) {
        $notice = 'Saved. config.php updated.';
        $config = $new;
    } else {
        $errors[] = 'Could not write config.php. Check file permissions.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Solari1090 Admin</title>
  <link rel="stylesheet" href="assets/style1.css">
</head>
<body>
  <div class="topbar">
    <div class="titleLeft">
      <span class="plane" aria-hidden="true"></span>
      <span>Solari1090</span>
    </div>
    <div class="adminTopRight">
      <?php if (is_authed()): ?>
        <a class="adminLink" href="admin.php?logout=1">Logout</a>
      <?php else: ?>
        <a class="adminLink" href="index.php">Dashboard</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="adminWrap">
    <div class="adminCard">
      <div class="adminTitle"><?php echo is_authed() ? 'Admin Settings' : 'Admin Login'; ?></div>
      <?php if (!empty($errors)): ?>
        <div class="adminAlert adminAlertErr">
          <?php foreach ($errors as $e): ?>
            <div><?php echo h($e); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($notice): ?>
        <div class="adminAlert adminAlertOk"><?php echo h($notice); ?></div>
      <?php endif; ?>
      <?php if (!is_authed()): ?>
        <form method="post" class="adminForm">
          <input type="hidden" name="action" value="login">
          <label class="adminLabel" for="password">Password</label>
          <input class="adminInput" id="password" name="password" type="password" autocomplete="current-password" required>
		  <p>
            <button class="adminBtn" type="submit">Login</button>
		  </p>
        </form>
      <?php else: ?>
        <form method="post" class="adminForm">
          <input type="hidden" name="action" value="save">
          <div class="adminGrid">
            <div class="adminSection">
              <div class="adminSectionTitle">Dump1090</div>
              <label class="adminLabel" for="dump1090_base">Base URL</label>
              <input class="adminInput" id="dump1090_base" name="dump1090_base" value="<?php echo h((string)$config['dump1090_base']); ?>">
            </div>
            <div class="adminSection">
              <div class="adminSectionTitle">Airport</div>
              <label class="adminLabel" for="airport_name">Name</label>
              <input class="adminInput" id="airport_name" name="airport_name" value="<?php echo h((string)($config['airport']['name'] ?? '')); ?>">
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="airport_lat">Lat</label>
                  <input class="adminInput" id="airport_lat" name="airport_lat" value="<?php echo h((string)($config['airport']['lat'] ?? '')); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="airport_lon">Lon</label>
                  <input class="adminInput" id="airport_lon" name="airport_lon" value="<?php echo h((string)($config['airport']['lon'] ?? '')); ?>">
                </div>
              </div>
              <label class="adminLabel" for="airport_search">Search</label>
              <input class="adminInput" id="airport_search" placeholder="Type name / city / IATA / ICAO (e.g. Berlin, TXL, EDDB)">
              <select class="adminInput" id="airport_select" size="6" style="height:auto;"></select>
            </div>
            <div class="adminSection">
              <div class="adminSectionTitle">Board</div>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="radius_km">Radius (km)</label>
                  <input class="adminInput" id="radius_km" name="radius_km" value="<?php echo h((string)$config['radius_km']); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="max_rows">Max rows</label>
                  <input class="adminInput" id="max_rows" name="max_rows" value="<?php echo h((string)$config['max_rows']); ?>">
                </div>
              </div>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="alt_ceiling_ft">Altitude ceiling (ft)</label>
                  <input class="adminInput" id="alt_ceiling_ft" name="alt_ceiling_ft" value="<?php echo h((string)$config['alt_ceiling_ft']); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="min_alt_ft">Minimum altitude (ft)</label>
                  <input class="adminInput" id="min_alt_ft" name="min_alt_ft" value="<?php echo h((string)$config['min_alt_ft']); ?>">
                </div>
              </div>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="min_seen_s">Min seen (s)</label>
                  <input class="adminInput" id="min_seen_s" name="min_seen_s" value="<?php echo h((string)$config['min_seen_s']); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="trend_window_s">Trend window (s)</label>
                  <input class="adminInput" id="trend_window_s" name="trend_window_s" value="<?php echo h((string)$config['trend_window_s']); ?>">
                </div>
              </div>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="arrival_trend_fpm">Arrival trend (fpm)</label>
                  <input class="adminInput" id="arrival_trend_fpm" name="arrival_trend_fpm" value="<?php echo h((string)$config['arrival_trend_fpm']); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="depart_trend_fpm">Depart trend (fpm)</label>
                  <input class="adminInput" id="depart_trend_fpm" name="depart_trend_fpm" value="<?php echo h((string)$config['depart_trend_fpm']); ?>">
                </div>
              </div>
              <label class="adminLabel" for="state_ttl_s">State TTL (s)</label>
              <input class="adminInput" id="state_ttl_s" name="state_ttl_s" value="<?php echo h((string)$config['state_ttl_s']); ?>">
            </div>
            <div class="adminSection">
              <div class="adminSectionTitle">Display</div>
              <?php $cols = (isset($config['columns']) && is_array($config['columns'])) ? $config['columns'] : []; ?>
              <label class="adminCheck">
                <input type="checkbox" name="col_from" <?php echo !empty($cols['from']) ? 'checked' : ''; ?>>
                <span>Show “From”</span>
              </label>
              <label class="adminCheck">
                <input type="checkbox" name="col_to" <?php echo !empty($cols['to']) ? 'checked' : ''; ?>>
                <span>Show “To”</span>
              </label>
              <label class="adminCheck">
                <input type="checkbox" name="col_alt_ft" <?php echo !empty($cols['alt_ft']) ? 'checked' : ''; ?>>
                <span>Show “Height (FT)”</span>
              </label>
              <label class="adminCheck">
                <input type="checkbox" name="col_dist_km" <?php echo !empty($cols['dist_km']) ? 'checked' : ''; ?>>
                <span>Show “Distance (KM)”</span>
              </label>
              <label class="adminCheck">
                <input type="checkbox" name="col_gs_kt" <?php echo !empty($cols['gs_kt']) ? 'checked' : ''; ?>>
                <span>Show “Speed (KT)”</span>
              </label>
            </div>
            <div class="adminSection">
              <div class="adminSectionTitle">Status Labels</div>
              <label class="adminCheck">
                <input type="checkbox" name="status_enable" <?php echo !empty($config['status']['enable']) ? 'checked' : ''; ?>>
                <span>Enable status labels</span>
              </label>
              <label class="adminCheck">
                <input type="checkbox" name="status_influence_lists" <?php echo !empty($config['status']['influence_lists']) ? 'checked' : ''; ?>>
                <span>Influence arrival/depart lists</span>
              </label>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="status_low_alt_ft">Low altitude (ft)</label>
                  <input class="adminInput" id="status_low_alt_ft" name="status_low_alt_ft" value="<?php echo h((string)($config['status']['low_alt_ft'] ?? 2500)); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="status_landed_alt_ft">Landed altitude (ft)</label>
                  <input class="adminInput" id="status_landed_alt_ft" name="status_landed_alt_ft" value="<?php echo h((string)($config['status']['landed_alt_ft'] ?? 1000)); ?>">
                </div>
              </div>
              <div class="adminRow2">
                <div>
                  <label class="adminLabel" for="status_up_fpm">Up rate (fpm)</label>
                  <input class="adminInput" id="status_up_fpm" name="status_up_fpm" value="<?php echo h((string)($config['status']['up_fpm'] ?? 150)); ?>">
                </div>
                <div>
                  <label class="adminLabel" for="status_down_fpm">Down rate (fpm)</label>
                  <input class="adminInput" id="status_down_fpm" name="status_down_fpm" value="<?php echo h((string)($config['status']['down_fpm'] ?? 100)); ?>">
                </div>
              </div>
            </div>
            <div class="adminSection">
              <div class="adminSectionTitle">Admin</div>
              <label class="adminLabel" for="admin_password_new">Change password (optional)</label>
              <input class="adminInput" id="admin_password_new" name="admin_password_new" type="password" autocomplete="new-password" placeholder="leave empty to keep">
            </div>
          </div>
          <button class="adminBtn" type="submit">Save</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<script>
(function(){
  const searchEl = document.getElementById('airport_search');
  const selectEl = document.getElementById('airport_select');
  const nameEl = document.getElementById('airport_name');
  const latEl  = document.getElementById('airport_lat');
  const lonEl  = document.getElementById('airport_lon');
  if(!searchEl || !selectEl || !nameEl || !latEl || !lonEl) return;

  let airports = null;
  let loading = false;

  function parseCsvLine(line){
    const out = [];
    let cur = '';
    let inQ = false;
    for(let i=0;i<line.length;i++){
      const ch = line[i];
      if(inQ){
        if(ch === '"'){
          if(line[i+1] === '"'){ cur += '"'; i++; }
          else { inQ = false; }
        } else {
          cur += ch;
        }
      } else {
        if(ch === '"'){ inQ = true; }
        else if(ch === ','){ out.push(cur); cur=''; }
        else { cur += ch; }
      }
    }
    out.push(cur);
    return out;
  }

  function normalize(s){ return (s || '').toString().toLowerCase(); }

  function parseAirports(text){
    const t = (text || '').trim();
    if(!t) return [];
    if(t[0] === '['){
      try {
        const arr = JSON.parse(t);
        return (arr || []).map(a => ({
          name: a.name || '',
          city: a.city || '',
          country: a.country || '',
          iata: a.code || '',
          icao: a.icao || '',
          lat: a.lat || '',
          lon: a.lon || ''
        })).filter(a => a.name && a.lat && a.lon);
      } catch(e){
        return [];
      }
    }
    const arr = [];
    for(const line0 of t.split(/\r?\n/)){
      const line = line0.trim();
      if(!line) continue;
      const cols = parseCsvLine(line);
      if(cols.length < 9) continue;
      const name = cols[1] || '';
      const city = cols[2] || '';
      const country = cols[3] || '';
      const iata = cols[4] === '\\N' ? '' : cols[4];
      const icao = cols[5] === '\\N' ? '' : cols[5];
      const lat = cols[6];
      const lon = cols[7];
      if(!name || !lat || !lon) continue;
      arr.push({name, city, country, iata, icao, lat, lon});
    }
    return arr;
  }

  async function loadAirports(){
    if(airports || loading) return airports;
    loading = true;
    selectEl.innerHTML = '<option>Loading airports…</option>';
    try{
      const resp = await fetch('airports.dat', {cache:'no-cache'});
      const text = await resp.text();
      airports = parseAirports(text);
    }catch(e){
      airports = [];
    }finally{
      loading = false;
    }
    return airports;
  }

  function renderResults(list){
    selectEl.innerHTML = '';
    if(!list.length){
      const opt = document.createElement('option');
      opt.textContent = 'No matches';
      opt.disabled = true;
      selectEl.appendChild(opt);
      return;
    }
    for(const a of list){
      const opt = document.createElement('option');
      const codes = [a.iata, a.icao].filter(Boolean).join(' / ');
      const where = [a.city, a.country].filter(Boolean).join(', ');
      opt.textContent = `${a.name}${codes ? ' ('+codes+')' : ''}${where ? ' — '+where : ''}`;
      opt.value = JSON.stringify(a);
      selectEl.appendChild(opt);
    }
  }

  function doSearch(){
    const q = normalize(searchEl.value).trim();
    if(!airports){
      renderResults([]);
      return;
    }
    if(q.length < 2){
      renderResults([]);
      return;
    }
    const res = [];
    for(const a of airports){
      const hay = normalize([a.name,a.city,a.country,a.iata,a.icao].join(' '));
      if(hay.includes(q)) res.push(a);
      if(res.length >= 30) break;
    }
    renderResults(res);
  }

  selectEl.addEventListener('change', () => {
    const opt = selectEl.options[selectEl.selectedIndex];
    if(!opt || !opt.value) return;
    try{
      const a = JSON.parse(opt.value);
      nameEl.value = a.name +' ('+ a.icao +')' || '';
      latEl.value = a.lat || '';
      lonEl.value = a.lon || '';
    }catch(e){}
  });

  searchEl.addEventListener('input', () => {
    if(!airports){
      loadAirports().then(() => doSearch());
    } else {
      doSearch();
    }
  });

})();
</script>

</body>
</html>