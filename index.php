<?php

session_start();
require_once __DIR__ . '/config.php';

$allowedReturnTo = ['import.php', 'query.php'];
$requestedReturn = $_GET['returnTo'] ?? null;
unset($_SESSION['spotify_return_to']);
if ($requestedReturn !== null && in_array($requestedReturn, $allowedReturnTo, true)) {
    $_SESSION['spotify_return_to'] = $requestedReturn;
}

// Store state in the session so the callback can verify the OAuth request.
$state = bin2hex(random_bytes(16));
$_SESSION['spotify_oauth_state'] = $state;

// Store the PKCE verifier in the session so the callback can use it when
// exchanging the authorization code for tokens.
$codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
$_SESSION['spotify_pkce_verifier'] = $codeVerifier;

$challengeBytes = hash('sha256', $codeVerifier, true);
$codeChallenge = rtrim(strtr(base64_encode($challengeBytes), '+/', '-_'), '=');

$authorizeParams = http_build_query([
    'response_type' => 'code',
    'client_id' => SPOTIFY_CLIENT_ID,
    'scope' => SPOTIFY_SCOPE,
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
    'state' => $state,
    'code_challenge_method' => 'S256',
    'code_challenge' => $codeChallenge,
]);

$authUrl = "https://accounts.spotify.com/authorize?$authorizeParams";

if (isset($_SESSION['spotify_return_to'])) {
    header("Location: {$authUrl}");
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Spotify to SQL</title>
    <meta name="description" content="Export your Spotify library to a local, read-only SQL.js database. Slice, filter, and explore your playlists with plain SQL.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=1.1">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
</head>

<body>
<main class="hero">
    <div class="hero-intro">
        <h1>Spotify to SQL</h1>
        <p class="hero-description">Export your Spotify library to a local, read-only SQL.js database. Slice, filter, and explore your playlists with plain SQL.</p>
    </div>

    <div class="hero-actions">
        <div class="action">
            <a class="btn-primary" href="import.php?demo=1">Try with sample data</a>
            <p class="action-note">No Spotify account needed — loads mock data that mirrors the real API response format.</p>
        </div>
        <div class="action">
            <a class="btn-secondary" href="<?= htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8') ?>">Try with your own library</a>
            <p class="action-note">Requires allowlist access to my Spotify API app. Not on it yet? Email me at <a href="mailto:hugo.guson@gmail.com">hugo.guson@gmail.com</a> and I'll add you.</p>
        </div>
    </div>

    <nav class="hero-links">
        <a href="images/spotify-to-sql-schema.png">View database schema</a>
        <a href="https://github.com/HugoGuson/spotify-to-sql-showcase">View source code on GitHub</a>
        <a href="https://hugosprojects.com">My portfolio</a>
    </nav>
</main>
</body>
</html>