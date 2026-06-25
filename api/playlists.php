<?php

require_once __DIR__ . '/spotify.php';

header('Content-Type: application/json; charset=utf-8');

const MAX_PLAYLISTS = 250;
const PAGE_LIMIT = 50;

$playlistFields = 'items(collaborative,id,name,public,items(total)),total,next';
$savedTracksFields = 'total';

$isDemo = isset($_GET['demo']) && $_GET['demo'] === '1';

// ── Main logic ─────────────────────────────────────────────────────

$savedTracksItems = [];
$playlists = [];
$offset = 0;

if ($isDemo) {
    $demoDir = __DIR__ . '/../demo';

    $savedTracksBody = json_decode(file_get_contents($demoDir . '/SAVEDTRACKS.json'), true);
    $savedTotal = (int) ($savedTracksBody['total'] ?? 0);

    $savedTracksItems[] = [
        'id' => 'SAVEDTRACKS',
        'name' => 'Liked Songs',
        'total' => $savedTotal,
        'public' => null,
        'collaborative' => null,
        'kind' => 'saved_tracks',
        'tracks_endpoint' => 'tracks.php?demo=1&kind=saved_tracks&id=SAVEDTRACKS',
    ];

    $playlistsData = json_decode(file_get_contents($demoDir . '/playlists.json'), true);
    foreach ($playlistsData['items'] ?? [] as $playlistItem) {
        $playlists[] = [
            'id' => $playlistItem['id'] ?? null,
            'name' => $playlistItem['name'] ?? null,
            'total' => $playlistItem['items']['total'] ?? 0,
            'public' => $playlistItem['public'] ?? null,
            'collaborative' => $playlistItem['collaborative'] ?? null,
            'kind' => 'playlist',
            'tracks_endpoint' => 'tracks.php?demo=1&kind=playlist&id=' . rawurlencode((string) ($playlistItem['id'] ?? '')),
        ];
    }
} else {
    // Expose saved tracks as a synthetic playlist-like item so the client can render
    // it together with regular playlists.
    [$savedTracksStatus, , $savedTracksBody] = spotifyApiRequest('GET', '/me/tracks', [
        'limit' => 1,
        'offset' => 0,
        'fields' => $savedTracksFields,
    ]);

    if ($savedTracksStatus < 200 || $savedTracksStatus >= 300) {
        respondWithError($savedTracksStatus, 'Spotify API error', ['status' => $savedTracksStatus, 'body' => $savedTracksBody]);
    }

    $savedTotal = (int) $savedTracksBody['total'];

    $savedTracksItems[] = [
        'id' => 'SAVEDTRACKS',
        'name' => 'Liked Songs',
        'total' => $savedTotal,
        'public' => null,
        'collaborative' => null,
        'kind' => 'saved_tracks',
        'tracks_endpoint' => 'tracks.php?kind=saved_tracks&id=SAVEDTRACKS',
    ];

    while (count($playlists) < MAX_PLAYLISTS) {
        $remaining = MAX_PLAYLISTS - count($playlists);
        $limit = min(PAGE_LIMIT, $remaining);

        [$status, , $body] = spotifyApiRequest('GET', '/me/playlists', [
            'limit' => $limit,
            'offset' => $offset,
            'fields' => $playlistFields,
        ]);

        if ($status < 200 || $status >= 300) {
            respondWithError($status, 'Spotify API error', ['status' => $status, 'body' => $body]);
        }

        $items = $body['items'] ?? [];

        foreach ($items as $playlistItem) {
            $normalizedPlaylist = [
                'id' => $playlistItem['id'] ?? null,
                'name' => $playlistItem['name'] ?? null,
                'total' => $playlistItem['items']['total'] ?? 0,
                'public' => $playlistItem['public'] ?? null,
                'collaborative' => $playlistItem['collaborative'] ?? null,
                'kind' => 'playlist',
                'tracks_endpoint' => 'tracks.php?kind=playlist&id=' . rawurlencode((string) ($playlistItem['id'] ?? '')),
            ];

            $playlists[] = $normalizedPlaylist;

            if (count($playlists) >= MAX_PLAYLISTS) {
                break;
            }
        }

        if (count($items) === 0) {
            break;
        }

        $offset += count($items);

        // Stop paginating when Spotify indicates there is no next page, or when the
        // current offset has reached the reported total.
        $total = isset($body['total']) ? (int) $body['total'] : null;
        $next = $body['next'] ?? null;

        if ($next === null) {
            break;
        }

        if ($total !== null && $offset >= $total) {
            break;
        }
    }
}

$allItems = [...$savedTracksItems, ...$playlists];

http_response_code(200);
echo json_encode([
    'count' => count($allItems),
    'items' => $allItems,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);