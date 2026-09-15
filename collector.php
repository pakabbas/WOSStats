<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Fetch alliance data for a Whiteout Survival state.
 *
 * Data source priority:
 * 1. sample_mode in config (development only — clearly marked sample data)
 * 2. api_url in config (your own HTTP bridge that returns alliance JSON)
 *
 * There is no official public HTTP API for Whiteout Survival alliance rankings.
 * See README.md for the expected response format if you configure api_url.
 */
function fetchAlliances(string $stateId): array
{
    $config = loadConfig();

    if (!empty($config['sample_mode'])) {
        return fetchSampleAlliances($stateId);
    }

    $apiUrl = trim((string) ($config['api_url'] ?? ''));
    if ($apiUrl === '') {
        throw new RuntimeException(
            'No data source configured. Whiteout Survival does not provide a public HTTP alliance API. '
            . 'Set api_url in config.json to your own data bridge, or enable sample_mode for development.'
        );
    }

    return fetchFromHttpApi($apiUrl, $stateId, $config);
}

function fetchSampleAlliances(string $stateId): array
{
    $sampleFile = DATA_DIR . '/sample-alliances.json';
    if (!file_exists($sampleFile)) {
        throw new RuntimeException('Sample data file not found: data/sample-alliances.json');
    }

    $data = loadJson($sampleFile, []);
    if (!is_array($data)) {
        throw new RuntimeException('Sample data file contains invalid JSON.');
    }

    $alliances = [];
    $rank = 1;

    foreach ($data as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }

        $normalized = normalizeAlliance($alliance, $rank);
        $normalized['updated_at'] = date('Y-m-d H:i:s');
        $alliances[] = $normalized;
        $rank++;
    }

    usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

    return $alliances;
}

function fetchFromHttpApi(string $apiUrl, string $stateId, array $config): array
{
    $url = buildApiUrl($apiUrl, $stateId);
    $apiKey = trim((string) ($config['api_key'] ?? ''));

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize HTTP client.');
    }

    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'WhiteoutStateTracker/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('API request failed: ' . ($curlError ?: 'unknown error'));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('API returned HTTP ' . $httpCode);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('API returned invalid JSON.');
    }

    $rawAlliances = extractAlliancesFromPayload($payload);
    $alliances = [];
    $rank = 1;

    foreach ($rawAlliances as $alliance) {
        if (!is_array($alliance)) {
            continue;
        }

        $normalized = normalizeAlliance($alliance, $rank);
        $normalized['updated_at'] = date('Y-m-d H:i:s');
        $alliances[] = $normalized;
        $rank++;
    }

    usort($alliances, static fn(array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

    return $alliances;
}

function buildApiUrl(string $apiUrl, string $stateId): string
{
    if (str_contains($apiUrl, '{state_id}')) {
        return str_replace('{state_id}', rawurlencode($stateId), $apiUrl);
    }

    $separator = str_contains($apiUrl, '?') ? '&' : '?';
    return $apiUrl . $separator . 'state_id=' . rawurlencode($stateId);
}

function extractAlliancesFromPayload(array $payload): array
{
    if (isset($payload['alliances']) && is_array($payload['alliances'])) {
        return $payload['alliances'];
    }

    if (array_is_list($payload)) {
        return $payload;
    }

    throw new RuntimeException('API response missing "alliances" array.');
}
