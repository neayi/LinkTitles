<?php
/**
 * Script de benchmark pour LinkTitles API.
 *
 * Ce script :
 * 1. Restaure une page à une version spécifique (oldid)
 * 2. Mesure le temps d'exécution de l'API LinkTitles
 * 3. Affiche les statistiques de performance
 *
 * Usage :
 *   php benchmark-linktitles.php --url <wiki_url> --page <page_name> --user <username> --pass <password> --oldid <revision_id> [--runs <number>]
 *
 * Exemple :
 *   php benchmark-linktitles.php --url https://wiki.dev.tripleperformance.fr/wiki --page "Sandbox" --user "Bertrand Gorge@Triple_Performance_Robot" --pass oggbeitecs3dgqtep18cbm3o5qhpakf2 --oldid 178081 --runs 3
 */

// ---------------------------------------------------------------------------
// Lecture des paramètres
// ---------------------------------------------------------------------------

$options = getopt( '', [ 'url:', 'page:', 'user:', 'pass:', 'oldid:', 'runs:' ] );

$missing = [];
foreach ( [ 'url', 'page', 'user', 'pass', 'oldid' ] as $param ) {
	if ( empty( $options[$param] ) ) {
		$missing[] = "--$param";
	}
}

if ( !empty( $missing ) ) {
	fwrite( STDERR, "Paramètre(s) manquant(s) : " . implode( ', ', $missing ) . "\n" );
	fwrite( STDERR, "Usage : php " . basename( __FILE__ ) . " --url <wiki_url> --page <page_name> --user <username> --pass <password> --oldid <revision_id> [--runs <number>]\n" );
	exit( 1 );
}

$apiUrl  = preg_replace( '#/(wiki|w)(/.*)?$#', '', rtrim( $options['url'], '/' ) ) . '/api.php';
$page    = $options['page'];
$user    = $options['user'];
$pass    = $options['pass'];
$oldid   = $options['oldid'];
$runs    = !empty( $options['runs'] ) ? (int)$options['runs'] : 1;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function apiRequest( $url, array $params, $post, $ch ) {
	$params['format'] = 'json';

	if ( $post ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $params ) );
	} else {
		curl_setopt( $ch, CURLOPT_POST, false );
		$url .= '?' . http_build_query( $params );
	}

	curl_setopt( $ch, CURLOPT_URL, $url );
	$response = curl_exec( $ch );

	if ( $response === false ) {
		fwrite( STDERR, "Erreur cURL : " . curl_error( $ch ) . "\n" );
		exit( 1 );
	}

	$data = json_decode( $response, true );
	if ( $data === null ) {
		fwrite( STDERR, "Réponse JSON invalide : $response\n" );
		exit( 1 );
	}

	return $data;
}

function formatTime( $seconds ) {
	if ( $seconds < 0.001 ) {
		return sprintf( "%.3f µs", $seconds * 1000000 );
	} elseif ( $seconds < 1 ) {
		return sprintf( "%.2f ms", $seconds * 1000 );
	} else {
		return sprintf( "%.2f s", $seconds );
	}
}

// ---------------------------------------------------------------------------
// Initialisation cURL
// ---------------------------------------------------------------------------

$cookieFile = tempnam( sys_get_temp_dir(), 'mw_cookie_' );

$ch = curl_init();
curl_setopt_array( $ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_COOKIEJAR      => $cookieFile,
	CURLOPT_COOKIEFILE     => $cookieFile,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_SSL_VERIFYPEER => false,
	CURLOPT_SSL_VERIFYHOST => false,
] );

// ---------------------------------------------------------------------------
// Étape 1 : Authentification
// ---------------------------------------------------------------------------

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          BENCHMARK LINKTITLES API                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Configuration:\n";
echo "  • Page: $page\n";
echo "  • Oldid: $oldid\n";
echo "  • Runs: $runs\n\n";

echo "[1/5] Authentification...\n";

// Obtenir le token de connexion
$data = apiRequest( $apiUrl, [
	'action' => 'query',
	'meta'   => 'tokens',
	'type'   => 'login',
], false, $ch );

if ( !isset( $data['query']['tokens']['logintoken'] ) ) {
	fwrite( STDERR, "Impossible d'obtenir le token de connexion.\n" );
	exit( 1 );
}

$loginToken = $data['query']['tokens']['logintoken'];

// S'authentifier
$data = apiRequest( $apiUrl, [
	'action'     => 'login',
	'lgname'     => $user,
	'lgpassword' => $pass,
	'lgtoken'    => $loginToken,
], true, $ch );

if ( !isset( $data['login']['result'] ) || $data['login']['result'] !== 'Success' ) {
	fwrite( STDERR, "Échec de l'authentification.\n" );
	exit( 1 );
}

echo "      ✓ Authentifié en tant que '$user'\n\n";

// Obtenir le token CSRF
$data = apiRequest( $apiUrl, [
	'action' => 'query',
	'meta'   => 'tokens',
], false, $ch );

if ( !isset( $data['query']['tokens']['csrftoken'] ) ) {
	fwrite( STDERR, "Impossible d'obtenir le token CSRF.\n" );
	exit( 1 );
}

$csrfToken = $data['query']['tokens']['csrftoken'];

// ---------------------------------------------------------------------------
// Étape 2 : Récupérer le contenu de l'oldid
// ---------------------------------------------------------------------------

echo "[2/5] Récupération du contenu de oldid=$oldid...\n";

$data = apiRequest( $apiUrl, [
	'action' => 'query',
	'prop'   => 'revisions',
	'titles' => $page,
	'rvprop' => 'content',
	'rvlimit' => 1,
	'rvstartid' => $oldid,
	'rvslots' => 'main',
], false, $ch );

if ( !isset( $data['query']['pages'] ) ) {
	fwrite( STDERR, "Impossible de récupérer le contenu de la page.\n" );
	exit( 1 );
}

$pages = $data['query']['pages'];
$pageInfo = reset( $pages );

if ( !isset( $pageInfo['revisions'][0]['slots']['main']['*'] ) ) {
	fwrite( STDERR, "Contenu de révision non trouvé.\n" );
	exit( 1 );
}

$oldContent = $pageInfo['revisions'][0]['slots']['main']['*'];
$contentLength = strlen( $oldContent );

echo "      ✓ Contenu récupéré (" . number_format( $contentLength ) . " octets)\n\n";

// ---------------------------------------------------------------------------
// Fonction de restauration de la page
// ---------------------------------------------------------------------------

function restorePage( $apiUrl, $page, $content, $csrfToken, $ch ) {
	echo "[3/5] Restauration de la page à l'état initial...\n";
	
	$data = apiRequest( $apiUrl, [
		'action'  => 'edit',
		'title'   => $page,
		'text'    => $content,
		'summary' => 'Restauration pour benchmark LinkTitles (oldid)',
		'token'   => $csrfToken,
		'bot'     => true,
	], true, $ch );

	if ( !isset( $data['edit']['result'] ) || $data['edit']['result'] !== 'Success' ) {
		fwrite( STDERR, "Échec de la restauration de la page.\n" );
		fwrite( STDERR, print_r( $data, true ) );
		exit( 1 );
	}

	echo "      ✓ Page restaurée\n\n";
}

// ---------------------------------------------------------------------------
// Fonction de benchmark LinkTitles
// ---------------------------------------------------------------------------

function benchmarkLinkTitles( $apiUrl, $page, $csrfToken, $ch ) {
	$startTime = microtime( true );
	$startMemory = memory_get_usage();
	
	$data = apiRequest( $apiUrl, [
		'action' => 'linktitles',
		'page'   => $page,
		'skiptemplatesexcept' => 'Résumé long',
		'token'  => $csrfToken,
	], true, $ch );

	$endTime = microtime( true );
	$endMemory = memory_get_usage();
	
	$elapsed = $endTime - $startTime;
	$memoryUsed = $endMemory - $startMemory;

	if ( isset( $data['error'] ) ) {
		fwrite( STDERR, "Erreur API : " . $data['error']['info'] . "\n" );
		exit( 1 );
	}

	if ( !isset( $data['linktitles']['result'] ) || $data['linktitles']['result'] !== 'success' ) {
		fwrite( STDERR, "Échec du traitement LinkTitles.\n" );
		exit( 1 );
	}

	return [
		'time' => $elapsed,
		'memory' => $memoryUsed,
	];
}

// ---------------------------------------------------------------------------
// Fonction pour récupérer le nombre de liens ajoutés
// ---------------------------------------------------------------------------

function getPageStats( $apiUrl, $page, $ch ) {
	$data = apiRequest( $apiUrl, [
		'action' => 'query',
		'prop'   => 'revisions|links',
		'titles' => $page,
		'rvprop' => 'content|size',
		'rvlimit' => 1,
		'rvslots' => 'main',
		'pllimit' => 500,
	], false, $ch );

	$pages = $data['query']['pages'];
	$pageInfo = reset( $pages );

	$content = $pageInfo['revisions'][0]['slots']['main']['*'] ?? '';
	$size = $pageInfo['revisions'][0]['size'] ?? 0;
	$linkCount = isset( $pageInfo['links'] ) ? count( $pageInfo['links'] ) : 0;
	
	// Compter les liens wiki dans le contenu
	preg_match_all( '/\[\[([^\]]+)\]\]/', $content, $matches );
	$wikiLinkCount = count( $matches[0] );

	return [
		'size' => $size,
		'links' => $linkCount,
		'wikilinks' => $wikiLinkCount,
	];
}

// ---------------------------------------------------------------------------
// Exécution des benchmarks
// ---------------------------------------------------------------------------

$times = [];
$memories = [];

for ( $i = 1; $i <= $runs; $i++ ) {
	echo "═══════════════════════════════════════════════════════════════\n";
	echo " RUN #$i / $runs\n";
	echo "═══════════════════════════════════════════════════════════════\n\n";

	// Restaurer la page
	restorePage( $apiUrl, $page, $oldContent, $csrfToken, $ch );

	// Statistiques avant
	echo "[4/5] Statistiques AVANT LinkTitles...\n";
	$statsBefore = getPageStats( $apiUrl, $page, $ch );
	echo "      • Taille: " . number_format( $statsBefore['size'] ) . " octets\n";
	echo "      • Liens wiki: " . $statsBefore['wikilinks'] . "\n\n";

	// Exécuter LinkTitles
	echo "[5/5] Exécution de LinkTitles API...\n";
	$result = benchmarkLinkTitles( $apiUrl, $page, $csrfToken, $ch );
	
	$times[] = $result['time'];
	$memories[] = $result['memory'];

	echo "      ✓ Terminé en " . formatTime( $result['time'] ) . "\n";
	echo "      • Mémoire: " . number_format( $result['memory'] / 1024, 2 ) . " KB\n\n";

	// Statistiques après
	sleep( 1 ); // Laisser le temps à MW de traiter
	$statsAfter = getPageStats( $apiUrl, $page, $ch );
	echo "      Statistiques APRÈS LinkTitles:\n";
	echo "      • Taille: " . number_format( $statsAfter['size'] ) . " octets (";
	$diff = $statsAfter['size'] - $statsBefore['size'];
	echo ( $diff > 0 ? '+' : '' ) . number_format( $diff ) . ")\n";
	echo "      • Liens wiki: " . $statsAfter['wikilinks'] . " (";
	$linkDiff = $statsAfter['wikilinks'] - $statsBefore['wikilinks'];
	echo ( $linkDiff > 0 ? '+' : '' ) . $linkDiff . ")\n\n";

	if ( $i < $runs ) {
		echo "Attente avant le prochain run...\n\n";
		sleep( 2 );
	}
}

// ---------------------------------------------------------------------------
// Affichage des statistiques finales
// ---------------------------------------------------------------------------

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                   RÉSULTATS FINAUX                             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

if ( count( $times ) > 1 ) {
	$avgTime = array_sum( $times ) / count( $times );
	$minTime = min( $times );
	$maxTime = max( $times );
	$avgMemory = array_sum( $memories ) / count( $memories );

	echo "Temps d'exécution:\n";
	echo "  • Minimum:  " . formatTime( $minTime ) . "\n";
	echo "  • Maximum:  " . formatTime( $maxTime ) . "\n";
	echo "  • Moyenne:  " . formatTime( $avgTime ) . "\n";
	echo "  • Écart:    " . formatTime( $maxTime - $minTime ) . "\n\n";

	echo "Mémoire (moyenne): " . number_format( $avgMemory / 1024, 2 ) . " KB\n\n";

	echo "Détails par run:\n";
	foreach ( $times as $idx => $time ) {
		$runNum = $idx + 1;
		echo "  Run #$runNum: " . formatTime( $time ) . " (" . number_format( $memories[$idx] / 1024, 2 ) . " KB)\n";
	}
} else {
	echo "Temps d'exécution: " . formatTime( $times[0] ) . "\n";
	echo "Mémoire: " . number_format( $memories[0] / 1024, 2 ) . " KB\n";
}

echo "\n";

// ---------------------------------------------------------------------------
// Nettoyage
// ---------------------------------------------------------------------------

curl_close( $ch );
unlink( $cookieFile );

echo "═══════════════════════════════════════════════════════════════\n";
echo "Benchmark terminé!\n";
exit( 0 );
