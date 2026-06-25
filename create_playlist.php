<?php

require_once __DIR__ . '/spotify.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respondWithError(400, 'Invalid JSON body.');
}

$name     = trim($input['name'] ?? '');
$trackIds = $input['trackIds'] ?? [];

if ($name === '') {
    respondWithError(400, 'Playlist name is required.');
}
if (!is_array($trackIds) || count($trackIds) === 0) {
    respondWithError(400, 'At least one track ID is required.');
}

// Validate that all IDs look like Spotify track IDs to prevent junk data.
foreach ($trackIds as $id) {
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9]{22}$/', $id)) {
        respondWithError(400, 'Invalid track ID: ' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8'));
    }
}

// Fetch the current user's ID — required to create a playlist.
[$meStatus, , $meBody] = spotifyApiRequest('GET', '/me');
if ($meStatus < 200 || $meStatus >= 300) {
    respondWithError($meStatus, 'Failed to fetch user profile: ' . ($meBody['error']['message'] ?? 'Unknown error'));
}

$userId = $meBody['id'] ?? null;
if (!$userId) {
    respondWithError(500, 'Could not determine Spotify user ID.');
}

// Create the playlist.
[$createStatus, , $createBody] = spotifyApiRequest('POST', '/me/playlists', [], [
    'name'        => $name,
    'public'      => false,
    'description' => 'Created with Spotify to SQL',
]);
if ($createStatus < 200 || $createStatus >= 300) {
    $message       = $createBody['error']['message'] ?? 'Unknown';
    $reason        = $createBody['error']['reason']  ?? null;
    $grantedScopes = $_SESSION['spotify_granted_scopes'] ?? 'not stored';
    $detail        = "HTTP $createStatus: $message" . ($reason ? " ($reason)" : '');
    $detail       .= " | user_id: $userId | scopes: $grantedScopes";
    respondWithError($createStatus, "Failed to create playlist — $detail");
}

$playlistId  = $createBody['id'] ?? null;
$playlistUrl = $createBody['external_urls']['spotify'] ?? null;

if (!$playlistId) {
    respondWithError(500, 'Spotify did not return a playlist ID.');
}

// Add tracks in batches of 100 (Spotify's maximum per request).
$uris    = array_map(fn($id) => 'spotify:track:' . $id, $trackIds);
$batches = array_chunk($uris, 100);

foreach ($batches as $batch) {
    [$addStatus, , $addBody] = spotifyApiRequest('POST', '/playlists/' . rawurlencode($playlistId) . '/items', [], [
        'uris' => $batch,
    ]);
    if ($addStatus < 200 || $addStatus >= 300) {
        respondWithError($addStatus, 'Failed to add tracks: ' . ($addBody['error']['message'] ?? 'Unknown error'));
    }
}

http_response_code(200);
echo json_encode([
    'playlistId'  => $playlistId,
    'playlistUrl' => $playlistUrl,
    'trackCount'  => count($trackIds),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
