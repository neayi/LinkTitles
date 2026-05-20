<?php
/**
 * Script de test pour le module API LinkTitles.
 *
 * Traite une seule page via l'API MediaWiki en s'authentifiant avec
 * un nom d'utilisateur et un mot de passe.
 *
 * Usage :
 *   php test-api-linktitles.php --url <wiki_url> --page <page_name> --user <username> --pass <password>
 *
 * Exemple :
 *   php test-api-linktitles.php --url http://localhost/wiki --page "Accueil" --user Admin --pass secret
 */

// ---------------------------------------------------------------------------
// Lecture des paramètres de la ligne de commande
// ---------------------------------------------------------------------------

$options = getopt( '', [ 'url:', 'page:', 'user:', 'pass:' ] );

$missing = [];
foreach ( [ 'url', 'page', 'user', 'pass' ] as $param ) {
	if ( empty( $options[$param] ) ) {
		$missing[] = "--$param";
	}
}

if ( !empty( $missing ) ) {
	fwrite( STDERR, "Paramètre(s) manquant(s) : " . implode( ', ', $missing ) . "\n" );
	fwrite( STDERR, "Usage : php " . basename( __FILE__ ) . " --url <wiki_url> --page <page_name> --user <username> --pass <password>\n" );
	exit( 1 );
}

// L'API MediaWiki est à la racine du domaine (/api.php), pas sous le chemin
// des articles (/wiki/). On supprime les suffixes /wiki et /w éventuels.
$apiUrl  = preg_replace( '#/(wiki|w)(/.*)?$#', '', rtrim( $options['url'], '/' ) ) . '/api.php';
$page    = $options['page'];
$user    = $options['user'];
$pass    = $options['pass'];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Envoie une requête HTTP à l'API MediaWiki.
 *
 * @param string   $url     URL de l'API.
 * @param array    $params  Paramètres POST ou GET.
 * @param bool     $post    Vrai pour POST, faux pour GET.
 * @param resource $ch      Handle cURL réutilisable (avec cookies).
 * @return array            Réponse décodée.
 */
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

// ---------------------------------------------------------------------------
// Initialisation cURL avec gestion des cookies (session persistante)
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
// Étape 1 : Obtenir le token de connexion
// ---------------------------------------------------------------------------

echo "==> Récupération du token de connexion...\n";

$data = apiRequest( $apiUrl, [
	'action' => 'query',
	'meta'   => 'tokens',
	'type'   => 'login',
], false, $ch );

if ( !isset( $data['query']['tokens']['logintoken'] ) ) {
	fwrite( STDERR, "Impossible d'obtenir le token de connexion.\n" );
	fwrite( STDERR, print_r( $data, true ) );
	exit( 1 );
}

$loginToken = $data['query']['tokens']['logintoken'];
echo "    Token de connexion : $loginToken\n";

// ---------------------------------------------------------------------------
// Étape 2 : S'authentifier
// ---------------------------------------------------------------------------

echo "==> Authentification en tant que '$user'...\n";

$data = apiRequest( $apiUrl, [
	'action'     => 'login',
	'lgname'     => $user,
	'lgpassword' => $pass,
	'lgtoken'    => $loginToken,
], true, $ch );

if ( !isset( $data['login']['result'] ) || $data['login']['result'] !== 'Success' ) {
	fwrite( STDERR, "Échec de l'authentification.\n" );
	fwrite( STDERR, print_r( $data, true ) );
	exit( 1 );
}

echo "    Authentification réussie.\n";

// ---------------------------------------------------------------------------
// Étape 3 : Obtenir le token CSRF
// ---------------------------------------------------------------------------

echo "==> Récupération du token CSRF...\n";

$data = apiRequest( $apiUrl, [
	'action' => 'query',
	'meta'   => 'tokens',
], false, $ch );

if ( !isset( $data['query']['tokens']['csrftoken'] ) ) {
	fwrite( STDERR, "Impossible d'obtenir le token CSRF.\n" );
	fwrite( STDERR, print_r( $data, true ) );
	exit( 1 );
}

$csrfToken = $data['query']['tokens']['csrftoken'];
echo "    Token CSRF : $csrfToken\n";

// ---------------------------------------------------------------------------
// Étape 4 : Appeler le module API linktitles
// ---------------------------------------------------------------------------

echo "==> Traitement de la page '$page' via l'API linktitles...\n";

$data = apiRequest( $apiUrl, [
	'action' => 'linktitles',
	'page'   => $page,
	'skiptemplatesexcept' => 'Résumé long',
	'token'  => $csrfToken,
], true, $ch );

if ( isset( $data['error'] ) ) {
	fwrite( STDERR, "Erreur API : " . $data['error']['info'] . "\n" );
	fwrite( STDERR, print_r( $data, true ) );
	exit( 1 );
}

if ( isset( $data['linktitles']['result'] ) ) {
	$result   = $data['linktitles']['result'];
	$pageName = $data['linktitles']['page'] ?? $page;
	if ( $result === 'success' ) {
		echo "    Succès : la page '$pageName' a été traitée.\n";
	} else {
		echo "    Échec : impossible de traiter la page '$pageName'.\n";
		exit( 1 );
	}
} else {
	fwrite( STDERR, "Réponse inattendue :\n" );
	fwrite( STDERR, print_r( $data, true ) );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Nettoyage
// ---------------------------------------------------------------------------

curl_close( $ch );
unlink( $cookieFile );

echo "==> Terminé.\n";
exit( 0 );
