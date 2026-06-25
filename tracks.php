<?php

require_once __DIR__ . '/spotify.php';

header('Content-Type: application/json; charset=utf-8');

$playlistFields = 'href,limit,offset,next,previous,total,items(added_at,item(type,duration_ms,id,name,album(album_type,id,name,release_date,release_date_precision,artists(id,name)),artists(id,name),external_urls(spotify)))';
$savedTracksFields = 'href,limit,offset,next,previous,total,items(added_at,track(type,duration_ms,id,name,album(album_type,id,name,release_date,release_date_precision,artists(id,name)),artists(id,name),external_urls(spotify)))';

function normalizeReleaseDate(?string $releaseDate, ?string $releaseDatePrecision): ?string {
    if (!$releaseDate || !$releaseDatePrecision) {
        return null;
    }
    return match ($releaseDatePrecision) {
        'day'   => $releaseDate,
        'month' => "{$releaseDate}-01",
        'year'  => "{$releaseDate}-01-01",
        default => null,
    };
}

function trimToMaxTracks(array $items, int $currentTotalFetched): array
{
    $newTotal = $currentTotalFetched + count($items);

    if ($newTotal <= MAX_TRACKS) {
        return [$items, $newTotal, false];
    }

    // Trim to ensure MAX_TRACKS is not exceeded
    $keep = MAX_TRACKS - $currentTotalFetched;
    $trimmed = array_slice($items, 0, max(0, $keep));

    return [$trimmed, MAX_TRACKS, true];
}

function normalizeTrackItems(array $items, string $playlistId, int $offset): array {
    $normalized = [];
    $position = $offset;

    foreach ($items as $row) {
        $track = $row['track'] ?? $row['item'] ?? null;
        if ($track === null) {
            continue;
        }

        // Skip non-track items (e.g. podcast episodes).
        if (($track['type'] ?? null) !== 'track') {
            $position++;
            continue;
        }

        $album = $track['album'] ?? [];
        $artists = [];

        foreach ($track['artists'] ?? [] as $artist) {
            $artists[] = [
                'artistId'   => $artist['id'] ?? null,
                'artistName' => $artist['name'] ?? null,
            ];
        }

        $albumArtists = [];
        foreach ($album['artists'] ?? [] as $artist) {
            $albumArtists[] = [
                'artistId'   => $artist['id'] ?? null,
                'artistName' => $artist['name'] ?? null,
            ];
        }

        $normalized[] = [
            'trackId'          => $track['id'] ?? null,
            'trackName'        => $track['name'] ?? null,
            'trackAddedAt'     => $row['added_at'] ?? null,
            'trackDurationMs'  => $track['duration_ms'] ?? null,
            'trackType'        => $track['type'] ?? null,
            'trackUrl'         => $track['external_urls']['spotify'] ?? null,

            'albumId'          => $album['id'] ?? null,
            'albumName'        => $album['name'] ?? null,
            'albumReleaseDate' => normalizeReleaseDate(
                $album['release_date'] ?? null,
                $album['release_date_precision'] ?? null
            ),
            'albumType'        => $album['album_type'] ?? null,

            'artists'          => $artists,
            'albumArtists'     => $albumArtists,
            'playlistId'       => $playlistId,
            'position'         => $position,
        ];

        $position++;
    }
    return $normalized;
}

function respondWithTracks(
    string $kind,
    string $id,
    array $body,
    array $items,
    int $limit,
    int $offset,
    int $totalFetched,
    bool $limitReached
): void {
    echo json_encode([
        'kind'         => $kind,
        'id'           => $id,
        'limit'        => $body['limit'] ?? $limit,
        'offset'       => $body['offset'] ?? $offset,
        'total'        => $body['total'] ?? null,
        'next'         => $body['next'] ?? null,
        'previous'     => $body['previous'] ?? null,
        'items'        => $items,
        'totalFetched' => $totalFetched,
        'limitReached' => $limitReached,
        'maxTracks'    => MAX_TRACKS,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Main logic ─────────────────────────────────────────────────────

$limit  = min(max((int)($_GET['limit'] ?? 50), 1), 50);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$kind   = $_GET['kind'] ?? '';
$id     = $_GET['id']   ?? '';
$isDemo = isset($_GET['demo']) && $_GET['demo'] === '1';

if ($kind === '' && $id === 'SAVEDTRACKS') {
    $kind = 'saved_tracks';
}

if ($kind !== 'playlist' && $kind !== 'saved_tracks') {
    respondWithError(400, "Missing or invalid 'kind'. Use 'playlist' or 'saved_tracks'.");
}

// Accept the global fetched count from the frontend to enforce the cross-playlist limit.
$totalFetchedSoFar = max((int)($_GET['totalFetched'] ?? 0), 0);

// ── Determine endpoint and fields ──────────────────────────────────
if ($kind === 'playlist') {
    if (empty($id) || $id === 'SAVEDTRACKS') {
        respondWithError(400, "Missing or invalid 'id' for kind=playlist.");
    }
    $endpoint = '/playlists/' . rawurlencode($id) . '/items';
    $fields   = $playlistFields;
} else {
    $endpoint = '/me/tracks';
    $fields   = $savedTracksFields;
}

// ── Fetch data ─────────────────────────────────────────────────────
if ($isDemo) {
    if (!preg_match('/^[A-Za-z0-9]+$/', $id)) {
        respondWithError(400, "Invalid demo id.");
    }
    $demoFile = __DIR__ . '/../demo/' . $id . '.json';
    if (!is_file($demoFile)) {
        respondWithError(404, "Demo data not found for id: {$id}");
    }
    $body = json_decode(file_get_contents($demoFile), true);
    if (!is_array($body)) {
        respondWithError(500, "Invalid demo JSON for id: {$id}");
    }
    http_response_code(200);
} else {
    [$status, , $body] = spotifyApiRequest('GET', $endpoint, [
        'limit'  => $limit,
        'offset' => $offset,
        'fields' => $fields,
    ]);

    http_response_code($status);
    if ($status < 200 || $status >= 300) {
        $message = spotifyErrorMessage($body);
        respondWithError($status, "Spotify API error (HTTP {$status}): {$message}", [
            'kind'   => $kind,
            'id'     => $id,
            'status' => $status,
            'body'   => $body,
        ]);
    }
}

// ── Apply global track limit ───────────────────────────────────────
$items = $body['items'] ?? [];

// trimToMaxTracks trims the page if the limit is reached or exceeded
[$trimmedItems, $newTotal, $limitReached] = trimToMaxTracks($items, $totalFetchedSoFar);

$normalized = normalizeTrackItems($trimmedItems, $id, $offset);

respondWithTracks($kind, $id, $body, $normalized, $limit, $offset, $newTotal, $limitReached);