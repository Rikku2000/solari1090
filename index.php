<?php declare (strict_types = 1);
require __DIR__ . '/config.php';
$cols = (isset($config['columns']) && is_array($config['columns'])) ? $config['columns'] : [];
?>
<!doctype html>
	<html lang="en">
		<head>
			<title>Solari1090</title>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<link rel="stylesheet" href="assets/style1.css">
		</head>
		<body>
			<div id="preloader" class="preloader" role="status" aria-label="Loading">
				<div class="preloader-inner">
					<div class="preloader-plane" aria-hidden="true"></div>
					<div class="preloader-text">
						<div class="preloader-title">Solari1090</div>
						<div class="preloader-sub">Loading board…</div>
					</div>
					<div class="preloader-dots" aria-hidden="true"><span></span><span></span><span></span></div>
				</div>
			</div>
			<div class="topbar">
				<div class="titleLeft">
					<span class="plane" aria-hidden="true"></span>
					<span id="modeTitle">Departures</span>
				</div>
				<div class="airportName" id="airportName">Airport</div>
			</div>
			<div class="subbar">
				<div class="tabs">
					<button class="tab active" id="btnDep" onclick="setMode('departures')">DEPARTURES</button>
					<button class="tab" id="btnArr" onclick="setMode('arrivals')">ARRIVALS</button>
				</div>
				<div class="metaWrap">
					<div class="meta" id="meta">Loading…</div>
					<div class="clock" id="clock">--:--:--</div>
				</div>
			</div>
			<div class="board fids">
			<table>
				<thead>
					<tr>
						<th>Time</th>
						<th>Airline</th>
						<th>Flight</th>
						<?php if (!empty($cols['from'])): ?><th>From</th><?php endif; ?>
						<?php if (!empty($cols['to'])): ?><th>To</th><?php endif; ?>
						<?php if (!empty($cols['alt_ft'])): ?><th class="col-right">Height (FT)</th><?php endif; ?>
						<?php if (!empty($cols['dist_km'])): ?><th class="col-right">Distance (KM)</th><?php endif; ?>
						<?php if (!empty($cols['gs_kt'])): ?><th class="col-right">Speed (KT)</th><?php endif; ?>
						<th class="col-right">Status</th>
					</tr>
				</thead>
				<tbody id="rows"></tbody>
			</table>
			<div class="err" id="err"></div>
			</div>
			<div class="footer">
				<div id="footerLeft">Updated —</div>
				<div id="footerRight">Solari1090</div>
			</div>
			<script>
				window.SOLARI_COLUMNS = <?php echo json_encode([
					'from' => !empty($cols['from']),
					'to' => !empty($cols['to']),
					'alt_ft' => !empty($cols['alt_ft']),
					'dist_km' => !empty($cols['dist_km']),
					'gs_kt' => !empty($cols['gs_kt']),
				], JSON_UNESCAPED_SLASHES); ?>;
			</script>
			<script src="assets/script.js" defer></script>
			<script>
				(function(){
					function hide(){
						var el=document.getElementById('preloader');
						if(!el) return;
						el.classList.add('hide');
						setTimeout(function(){ try{ el.remove(); }catch(e){} }, 1250);
					}
					window.hidePreloader = hide;
					window.addEventListener('load', hide, { once:true }); 
				})();
			</script>
		</body>
	</html>
