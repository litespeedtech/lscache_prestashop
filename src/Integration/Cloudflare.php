<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Integration;

/**
 * Cloudflare — lightweight wrapper around the Cloudflare API v4.
 *
 * Supports both legacy (Email + Global API Key) and token (Bearer) auth.
 * Email is optional; when omitted, Bearer token auth is used.
 */
class Cloudflare
{
    const API_BASE = 'https://api.cloudflare.com/client/v4/';

    /** @var string */
    private $key;

    /** @var string */
    private $email;

    public function __construct(string $key, string $email = '')
    {
        $this->key   = $key;
        $this->email = $email;
    }

    // -------------------------------------------------------------------------
    // Public API methods
    // -------------------------------------------------------------------------

    /**
     * Finds the Cloudflare zone ID for a given domain (partial match supported).
     * Extracts the registrable domain (last two labels) before querying.
     */
    public function findZone(string $domain): ?string
    {
        $domain = trim($domain, '/. ');
        $parts  = explode('.', $domain);
        $zone   = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $domain;

        $result = $this->request('GET', 'zones?name=' . urlencode($zone) . '&per_page=1&status=active');

        if (!empty($result['success']) && !empty($result['result'])) {
            return $result['result'][0]['id'];
        }

        return null;
    }

    /**
     * Returns the zone name (hostname) for a given zone ID.
     */
    public function getZoneName(string $zoneId): string
    {
        $result = $this->request('GET', 'zones/' . $zoneId);

        return $result['result']['name'] ?? '-';
    }

    /**
     * Returns the current development mode value: 'on', 'off', or null on error.
     */
    public function getDevModeStatus(string $zoneId): ?string
    {
        $result = $this->request('GET', 'zones/' . $zoneId . '/settings/development_mode');

        if (!empty($result['success'])) {
            return $result['result']['value'] ?? null;
        }

        return null;
    }

    /**
     * Enables or disables development mode for a zone.
     */
    public function setDevMode(string $zoneId, bool $on): bool
    {
        $result = $this->request(
            'PATCH',
            'zones/' . $zoneId . '/settings/development_mode',
            ['value' => $on ? 'on' : 'off']
        );

        if (!empty($result['success'])) {
            return true;
        }

        $this->lastError = self::getErrors($result);
        return false;
    }

    /**
     * Purges all cached content for a zone.
     */
    public function purgeAll(string $zoneId): bool
    {
        $result = $this->request(
            'POST',
            'zones/' . $zoneId . '/purge_cache',
            ['purge_everything' => true]
        );

        if (!empty($result['success'])) {
            return true;
        }

        $this->lastError = self::getErrors($result);
        return false;
    }

    /**
     * Returns analytics totals for a zone over the last $hoursBack hours.
     * Uses the Cloudflare GraphQL Analytics API (the v4 REST dashboard
     * endpoint was deprecated and removed for most plan types).
     */
    public function getAnalytics(string $zoneId, int $hoursBack = 24): ?array
    {
        $since = date('Y-m-d\TH:i:s\Z', time() - $hoursBack * 3600);
        $until = date('Y-m-d\TH:i:s\Z');

        $query = <<<'GQL'
        {
          viewer {
            zones(filter: {zoneTag: "%s"}) {
              httpRequests1hGroups(
                filter: {datetime_geq: "%s", datetime_leq: "%s"}
                limit: 1000
                orderBy: [datetime_ASC]
              ) {
                dimensions { datetime }
                sum {
                  requests
                  cachedRequests
                  bytes
                  cachedBytes
                }
              }
            }
          }
        }
        GQL;

        $result = $this->graphql(sprintf($query, $zoneId, $since, $until));

        // Surface GraphQL errors for debugging
        if (!empty($result['errors'])) {
            $this->lastError = implode(' | ', array_column($result['errors'], 'message'));
            return null;
        }

        if (empty($result['data']['viewer']['zones'][0]['httpRequests1hGroups'])) {
            $this->lastError = 'Empty response — no hourly groups returned. Raw: ' . json_encode($result['data'] ?? $result);
            return null;
        }

        $reqTotal  = 0;
        $reqCached = 0;
        $bwTotal   = 0;
        $bwCached  = 0;

        foreach ($result['data']['viewer']['zones'][0]['httpRequests1hGroups'] as $group) {
            $s          = $group['sum'];
            $reqTotal  += (int) ($s['requests']       ?? 0);
            $reqCached += (int) ($s['cachedRequests'] ?? 0);
            $bwTotal   += (int) ($s['bytes']          ?? 0);
            $bwCached  += (int) ($s['cachedBytes']    ?? 0);
        }

        return [
            'period_hours' => $hoursBack,
            'req_total'    => $reqTotal,
            'req_cached'   => $reqCached,
            'req_ratio'    => $reqTotal > 0 ? round($reqCached / $reqTotal * 100, 1) : 0.0,
            'bw_total_mb'  => round($bwTotal  / 1048576, 2),
            'bw_cached_mb' => round($bwCached / 1048576, 2),
        ];
    }

    /** @var string */
    public string $lastError = '';

    /**
     * Returns a human-readable error string from an API response.
     */
    public static function getErrors(array $apiResult): string
    {
        if (empty($apiResult['errors'])) {
            return 'Unknown Cloudflare API error.';
        }

        return implode(' ', array_map(
            static function (array $e) { return $e['message'] ?? ''; },
            $apiResult['errors']
        ));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Executes a GraphQL query against the Cloudflare Analytics API.
     */
    private function graphql(string $query): array
    {
        $url     = 'https://api.cloudflare.com/client/v4/graphql';
        $headers = ['Content-Type: application/json'];

        if ($this->email !== '') {
            $headers[] = 'X-Auth-Email: ' . $this->email;
            $headers[] = 'X-Auth-Key: ' . $this->key;
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Executes a cURL request against the Cloudflare API.
     *
     * @param string $method  HTTP verb: GET, POST, PATCH, DELETE
     * @param string $endpoint  Path relative to API_BASE
     * @param array  $body  JSON body for write requests
     *
     * @return array Decoded JSON response
     */
    private function request(string $method, string $endpoint, array $body = []): array
    {
        $url     = self::API_BASE . $endpoint;
        $headers = ['Content-Type: application/json'];

        if ($this->email !== '') {
            $headers[] = 'X-Auth-Email: ' . $this->email;
            $headers[] = 'X-Auth-Key: ' . $this->key;
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'errors' => [['message' => $error]]];
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : ['success' => false, 'errors' => [['message' => 'Invalid API response']]];
    }
}
