<?php
/**
 * NUR FÜR DIAGNOSE: Verbindungstest dieses Servers zu DAS ZENTRUM (docs/04-quellen.md Abschnitt 5 "Erreichbarkeit").
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert. Nur lesende Abrufe, keine
 * Datenbankänderung. Vergleicht DAS ZENTRUM mit einem Ziel, das auf demselben Weg erreichbar sein muss
 * (api.region-bayreuth.de, Quelle Bayreuth Tourismus).
 *
 * Aufruf per Kommandozeile:
 *   php tools/diagnose-connection.php <pfad/zu/wp-load.php>
 * Aufruf ohne Shell: Datei vorübergehend in das WordPress-Hauptverzeichnis (neben wp-load.php) laden, als
 * Administrator angemeldet https://<website>/diagnose-connection.php öffnen, Ausgabe kopieren, Datei wieder löschen.
 *
 * @package Eventcrawler
 */

if ( 'cli' === PHP_SAPI ) {
	if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
		fwrite( STDERR, "Aufruf: php diagnose-connection.php <pfad/zu/wp-load.php>\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']   = 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	require $argv[1];
} else {
	// Browser: nur neben wp-load.php und nur für angemeldete Administratoren (manage_options), sonst HTTP 403.
	// Vor dem Laden von WordPress nichts preisgeben (kein Fatal Error mit Pfaden, wenn wp-load.php fehlt).
	if ( ! is_file( __DIR__ . '/wp-load.php' ) ) {
		http_response_code( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( 'Zugriff verweigert.' );
	}
	require __DIR__ . '/wp-load.php';
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( 'Zugriff verweigert.' );
	}
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
}
// Ausgegeben werden nur Messwerte zu den Zielhosts und die öffentliche ausgehende IP: keine Request-Header, Cookies,
// Pfade, Zugangsdaten, internen Adressen oder Konfigurationswerte.
set_time_limit( 180 );

$host     = 'www.das-zentrum.de';
$control  = 'api.region-bayreuth.de';
$urls     = array( 'https://www.das-zentrum.de/', 'https://www.das-zentrum.de/wp-json/tribe/events/v1/events?per_page=50&page=1' );
$ua       = 'Eventcrawler/' . ( defined( 'EVENTCRAWLER_VERSION' ) ? EVENTCRAWLER_VERSION : '?' ) . ' (WordPress; ' . home_url() . ')';
$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
$can_exec = function_exists( 'exec' ) && ! in_array( 'exec', $disabled, true );

$line = function ( $label, $value ) {
	echo str_pad( $label, 44 ), ' ', $value, "\n";
};

echo "== Umgebung\n";
$line( 'Zeit', gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
$line( 'WordPress / PHP', get_bloginfo( 'version' ) . ' / ' . PHP_VERSION );
$cv = function_exists( 'curl_version' ) ? curl_version() : null;
$line( 'PHP cURL', $cv ? $cv['version'] . ' / ' . $cv['ssl_version'] . ( ( $cv['features'] & CURL_VERSION_IPV6 ) ? ' / IPv6' : ' / ohne IPv6' ) : 'nicht verfügbar' );
$wp_http = new WP_Http();
$line( 'WordPress blockiert ' . $host, $wp_http->block_request( $urls[0] ) ? 'JA (WP_HTTP_BLOCK_EXTERNAL)' : 'nein' );
$line( 'WordPress blockiert ' . $control, $wp_http->block_request( 'https://' . $control . '/' ) ? 'JA (WP_HTTP_BLOCK_EXTERNAL)' : 'nein' );
$line( 'Proxy in WordPress konfiguriert', defined( 'WP_PROXY_HOST' ) ? 'ja' : 'nein' );
$out = wp_remote_get( 'https://api.ipify.org/', array( 'timeout' => 10 ) );
$ip  = is_wp_error( $out ) ? '' : trim( wp_remote_retrieve_body( $out ) );
if ( '' === $ip && $cv ) { // z. B. WP_HTTP_BLOCK_EXTERNAL: direkt über PHP cURL.
	$ch = curl_init( 'https://api.ipify.org/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ip = trim( (string) curl_exec( $ch ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
}
$line( 'ausgehende IP (api.ipify.org)', filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'nicht ermittelbar' );
$line( 'exec() für curl-Kommandozeile', $can_exec ? 'verfügbar' : 'nicht verfügbar' );

echo "\n== DNS\n";
foreach ( array( $host, 'das-zentrum.de', $control ) as $h ) {
	$a    = @dns_get_record( $h, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$aaaa = @dns_get_record( $h, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$line( $h, 'gethostbyname ' . gethostbyname( $h ) . ' | A ' . ( $a ? implode( ', ', wp_list_pluck( $a, 'ip' ) ) : '-' ) . ' | AAAA ' . ( $aaaa ? implode( ', ', wp_list_pluck( $aaaa, 'ipv6' ) ) : '-' ) );
}
$line( 'Erwartung (2026-10-29)', $host . ' A 91.233.84.81, kein AAAA' );

echo "\n== TCP (fsockopen, 5 s)\n";
foreach ( array( array( gethostbyname( $host ), 443 ), array( gethostbyname( $host ), 80 ), array( gethostbyname( $control ), 443 ) ) as $t ) {
	$start = microtime( true );
	$sock  = @fsockopen( $t[0], $t[1], $errno, $errstr, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$line( $t[0] . ':' . $t[1], sprintf( '%s (%.2f s)', $sock ? 'offen' : "FEHLER {$errno} {$errstr}", microtime( true ) - $start ) );
	if ( $sock ) {
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}

echo "\n== PHP cURL direkt (IPv4, Connect-Timeout 10 s)\n";
if ( $cv ) {
	foreach ( array_merge( $urls, array( 'https://' . $control . '/' ) ) as $u ) {
		$ch = curl_init( $u ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_USERAGENT => $ua, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$body = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$i    = curl_getinfo( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$err  = curl_errno( $ch ) ? 'cURL ' . curl_errno( $ch ) . ': ' . curl_error( $ch ) : 'HTTP ' . $i['http_code'] . ', ' . strlen( (string) $body ) . ' B'; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- ab PHP 8 ohne Wirkung.
		}
		$line( $u, sprintf( '%s | ip %s dns %.3f connect %.3f gesamt %.3f', $err, $i['primary_ip'], $i['namelookup_time'], $i['connect_time'], $i['total_time'] ) );
	}
}

echo "\n== WordPress wp_remote_get (wie der Adapter)\n";
foreach ( $urls as $u ) {
	$start = microtime( true );
	$r     = wp_remote_get( $u, array( 'timeout' => 20, 'redirection' => 3, 'user-agent' => $ua, 'limit_response_size' => 5 * MB_IN_BYTES ) );
	$line( $u, sprintf( '%s (%.2f s)', is_wp_error( $r ) ? 'Fehler: ' . $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ', ' . strlen( wp_remote_retrieve_body( $r ) ) . ' B', microtime( true ) - $start ) );
}

echo "\n== curl-Kommandozeile\n";
if ( $can_exec && '/' === DIRECTORY_SEPARATOR ) {
	foreach ( array( '-4', '-6' ) as $flag ) {
		$cmd    = 'curl ' . $flag . ' -s -o /dev/null --connect-timeout 10 -m 20 -w ' . escapeshellarg( 'code=%{http_code} ip=%{remote_ip} connect=%{time_connect} gesamt=%{time_total}' ) . ' ' . escapeshellarg( $urls[1] ) . ' 2>&1; echo " exit=$?"';
		$output = array();
		exec( $cmd, $output ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		$line( 'curl ' . $flag, implode( ' ', $output ) );
	}
} else {
	echo "übersprungen (exec nicht verfügbar oder kein Linux-Server)\n";
}

echo "\nAuswertung: Ist {$control} erreichbar, {$host} aber nicht (TCP 'Connection timed out'), wird die Verbindung beim\n";
echo "Ziel oder unterwegs verworfen, nicht vom Plugin. Ausgehende IP oben an den Betreiber von {$host} geben.\n";
