<?php
session_start();
require_once __DIR__ . '/config.php';

function respondWithError(string $message): void
{
    http_response_code(400);
    echo '<pre>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

if (isset($_GET['error'])) {
    respondWithError('Spotify returned an error: ' . $_GET['error']);
}

if (!isset($_GET['state'], $_GET['code'])) {
    respondWithError('Missing required callback parameters: state and/or code.');
}

// Validate the OAuth state to protect against CSRF attacks.
$expectedState = $_SESSION['spotify_oauth_state'] ?? null;
$receivedState = $_GET['state'];

if (!$expectedState || !hash_equals($expectedState, $receivedState)) {
    respondWithError('OAuth state validation failed.');
}

// The OAuth state is single-use and should be removed after validation.
unset($_SESSION['spotify_oauth_state']);

// Retrieve the PKCE code verifier that was stored before the authorization redirect.
$codeVerifier = $_SESSION['spotify_pkce_verifier'] ?? null;

if (!$codeVerifier) {
    respondWithError('Missing PKCE code verifier in session.');
}

// Exchange the authorization code for access and refresh tokens.
$code = $_GET['code'];

$postFields = http_build_query([
    'client_id' => SPOTIFY_CLIENT_ID,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
    'code_verifier' => $codeVerifier,
]);

$curlHandle = curl_init(SPOTIFY_TOKEN_ENDPOINT);

curl_setopt_array($curlHandle, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($curlHandle);

if ($response === false) {
    respondWithError('Token request failed: ' . curl_error($curlHandle));
}

$httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
curl_close($curlHandle);

$data = json_decode($response, true);

if (!is_array($data)) {
    respondWithError('Could not interpret the JSON response from Spotify.');
}

if ($httpCode < 200 || $httpCode >= 300) {
    $errorMessage = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
    respondWithError("Token exchange failed (HTTP {$httpCode}): {$errorMessage}");
}

// Store the access token and its adjusted expiry time in the session.
$_SESSION['spotify_access_token'] = $data['access_token'];
$_SESSION['spotify_access_token_expires_at'] = time() + (int) $data['expires_in'] - 30;
$_SESSION['spotify_granted_scopes'] = $data['scope'] ?? null;

// Store the refresh token if Spotify returned one.
if (isset($data['refresh_token'])) {
    $_SESSION['spotify_refresh_token'] = $data['refresh_token'];
}

// The PKCE code verifier is no longer needed after a successful token exchange.
unset($_SESSION['spotify_pkce_verifier']);

$allowedReturnTo = ['import.php', 'query.php'];
$returnTo = $_SESSION['spotify_return_to'] ?? 'import.php';
unset($_SESSION['spotify_return_to']);
if (!in_array($returnTo, $allowedReturnTo, true)) {
    $returnTo = 'import.php';
}

header('Location: ' . APP_BASE_URL . $returnTo);
exit;