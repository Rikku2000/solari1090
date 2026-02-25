<?php
$config = [
    'dump1090_base' => 'http://localhost/skyaware',			/* Dump1090 base URL */
    'airport' => [
        'name' => 'Frankfurt Main Airport | EDDF',			/* Airport name */
        'lat' => 50.0333,									/* Airport latitude */
        'lon' => 8.5706,									/* Airport longitude */
    ],
    'radius_km' => 30.0,									/* Radius (km) */
    'alt_ceiling_ft' => 12000,								/* Altitude ceiling (ft) */
    'min_alt_ft' => 0,										/* Minimum altitude (ft) */
    'min_seen_s' => 8,										/* Min seen (seconds) */
    'trend_window_s' => 90,									/* Trend window (seconds) */
    'arrival_trend_fpm' => -300,							/* Arrival trend (fpm) */
    'depart_trend_fpm' =>  300,								/* Depart trend (fpm) */
    'max_rows' => 8,										/* Max rows */
    'state_cache_file' => __DIR__ . '/state_cache.json',	/* State cache file */
    'state_ttl_s' => 600,									/* State TTL (seconds) */
    'admin_password' => 'changeme',							/* Admin password */
	'status' => [
		'enable' => true,
		'influence_lists' => true,							/* If true, LANDING/LANDED are forced into arrivals, TAKE OFF into departures */
		'low_alt_ft' => 2500,								/* Below this altitude we show LANDING / TAKE OFF */
		'landed_alt_ft' => 1000,							/* Below this altitude we show LANDED */
		'up_fpm' => 150,									/* Minimum climb rate to call TAKE OFF */
		'down_fpm' => 100,									/* Minimum descent rate to call LANDING */
	],
];
?>
