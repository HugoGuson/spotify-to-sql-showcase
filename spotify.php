<?php

session_start();
require_once __DIR__ . '/../config.php';

function respondWithError(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function spotifyErrorMessage(array $body): string
{
    return $body['error']['message']
        ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
        ?? $body['error_description']
        ?? 'Unknown error';
}

/**
 * Returns a valid Spotify access token from the current session.
 * Reuses the existing token when it is still valid, otherwise refreshes it.
 */
function spotifyGetAccessToken(): string
{
    $accessToken = $_SESSION['spotify_access_token'] ?? null;
    $expiresAt = $_SESSION['spotify_access_token_expires_at'] ?? 0;

    if ($accessToken && time() < (int) $expiresAt) {
        return $accessToken;
    }

    $refreshToken = $_SESSION['spotify_refresh_token'] ?? null;
    if (!$refreshToken) {
        respondWithError(401, 'Not logged in (refresh token is missing).');
    }

    $postFields = http_build_query([
        'client_id' => SPOTIFY_CLIENT_ID,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
    ]);

    $curlHandle = curl_init(SPOTIFY_TOKEN_ENDPOINT);
    curl_setopt_array($curlHandle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($curlHandle);
    if ($response === false) {
        respondWithError(500, 'cURL error during token refresh: ' . curl_error($curlHandle));
    }

    $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    curl_close($curlHandle);

    $data = json_decode($response, true);
    if (!is_array($data)) {
        respondWithError(500, 'Could not interpret JSON from Spotify token refresh response: ' . $response);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $error = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
        respondWithError(401, 'Refresh failed (HTTP ' . $httpCode . '): ' . $error);
    }

    if (!isset($data['access_token'], $data['expires_in'])) {
        respondWithError(500, 'Refresh response is missing access_token/expires_in: ' . $response);
    }

    $_SESSION['spotify_access_token'] = $data['access_token'];

    // Subtract a small margin so the token is refreshed slightly before expiry.
    $_SESSION['spotify_access_token_expires_at'] = time() + (int) $data['expires_in'] - 30;

    // Spotify may return a new refresh token during refresh; persist it when present.
    if (isset($data['refresh_token'])) {
        $_SESSION['spotify_refresh_token'] = $data['refresh_token'];
    }

    return $_SESSION['spotify_access_token'];
}

// Calls the Spotify Web API and returns [status, rawHeaders, body].
function spotifyApiRequest(string $method, string $path, array $query = [], mixed $jsonBody = null): array
{
    $maxRetries = 5;
    $retry = 0;

    while ($retry <= $maxRetries) {
        $accessToken = spotifyGetAccessToken();

        $url = SPOTIFY_API_BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $curlHandle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
        ];

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $payload;
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($curlHandle, $options);
        $rawResponse = curl_exec($curlHandle);

        if ($rawResponse === false) {
            respondWithError(500, 'cURL error against Spotify API: ' . curl_error($curlHandle));
        }

        $status     = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($curlHandle, CURLINFO_HEADER_SIZE);
        curl_close($curlHandle);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $rawBody    = substr($rawResponse, $headerSize);
        $body       = json_decode($rawBody, true);

        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            $body = ['raw' => $rawBody];
        }

        // 1. Rate limited — wait and retry
        if ($status === 429) {
            $retryAfter = 5; // fallback if the header is missing

            // Extract Retry-After from headers as recommended by Spotify.
            if (preg_match('/Retry-After:\s*(\d+)/i', $rawHeaders, $matches)) {
                $retryAfter = (int)$matches[1];
            }

            // Wait with a small buffer and exponential backoff.
            $waitSeconds = $retryAfter + 1 + ($retry * 2);
            sleep($waitSeconds);

            $retry++;
            continue;
        }

        // 2. Success
        if ($status >= 200 && $status < 300) {
            usleep(250000); // 250 ms courtesy delay
            return [$status, $rawHeaders, $body];
        }

        // 3. Other error — return immediately
        return [$status, $rawHeaders, $body];
    }

    // Max retries reached — return the last response (likely a 429).
    return [$status ?? 429, $rawHeaders ?? '', $body ?? ['error' => 'Max retries reached']];
}
