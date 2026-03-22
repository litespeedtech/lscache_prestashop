<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace LiteSpeed\Cache\Integration;

if (!defined('_PS_VERSION_')) {
    exit;
}

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
     * Gets the proxy status of the main A/AAAA record for a domain.
     * Returns ['proxied' => bool, 'ip' => string, 'type' => string] or null on error.
     */
    public function getProxyStatus(string $zoneId, string $domain): ?array
    {
        $domain = trim($domain, '/. ');
        $result = $this->request('GET', 'zones/' . $zoneId . '/dns_records?type=A&name=' . urlencode($domain));

        if (!empty($result['success']) && !empty($result['result'])) {
            $record = $result['result'][0];
            return [
                'proxied' => (bool) ($record['proxied'] ?? false),
                'ip'      => $record['content'] ?? '-',
                'type'    => $record['type'] ?? 'A',
            ];
        }

        // Try AAAA if no A record
        $result = $this->request('GET', 'zones/' . $zoneId . '/dns_records?type=AAAA&name=' . urlencode($domain));
        if (!empty($result['success']) && !empty($result['result'])) {
            $record = $result['result'][0];
            return [
                'proxied' => (bool) ($record['proxied'] ?? false),
                'ip'      => $record['content'] ?? '-',
                'type'    => $record['type'] ?? 'AAAA',
            ];
        }

        return null;
    }

    /**
     * Toggles the proxy status of the main A/AAAA record for a domain.
     */
    public function setProxyStatus(string $zoneId, string $domain, bool $proxied): bool
    {
        $domain = trim($domain, '/. ');

        foreach (['A', 'AAAA'] as $type) {
            $result = $this->request('GET', 'zones/' . $zoneId . '/dns_records?type=' . $type . '&name=' . urlencode($domain));
            if (!empty($result['success']) && !empty($result['result'])) {
                $record = $result['result'][0];
                $update = $this->request('PATCH', 'zones/' . $zoneId . '/dns_records/' . $record['id'], [
                    'proxied' => $proxied,
                ]);
                if (!empty($update['success'])) {
                    return true;
                }
                $this->lastError = self::getErrors($update);
                return false;
            }
        }

        $this->lastError = 'No A/AAAA record found for ' . $domain;
        return false;
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

    /**
     * Gets the current value of a zone setting.
     */
    public function getSetting(string $zoneId, string $setting): ?string
    {
        $result = $this->request('GET', 'zones/' . $zoneId . '/settings/' . $setting);
        if (!empty($result['success'])) {
            return $result['result']['value'] ?? null;
        }
        return null;
    }

    /**
     * Updates a single zone setting.
     */
    public function setSetting(string $zoneId, string $setting, $value): bool
    {
        $result = $this->request('PATCH', 'zones/' . $zoneId . '/settings/' . $setting, ['value' => $value]);
        if (!empty($result['success'])) {
            return true;
        }
        $this->lastError = self::getErrors($result);
        return false;
    }

    /**
     * Applies optimal Cloudflare settings for a PrestaShop site.
     * Returns an array of results: ['setting' => ['success' => bool, 'value' => mixed, 'error' => string]]
     */
    public function optimizeForPrestaShop(string $zoneId): array
    {
        $settings = [
            // Performance
            'ssl'                  => 'full',
            'tls_1_3'             => 'on',
            'http3'               => 'on',
            'brotli'              => 'on',
            'early_hints'         => 'on',
            'always_use_https'    => 'on',
            '0rtt'                => 'on',

            // Cache — only static files, never PHP/HTML
            'browser_cache_ttl'   => 0,        // Respect origin headers
            'cache_level'         => 'basic',   // Only cache files with extensions (not query strings)

            // Disable features that break PrestaShop
            'rocket_loader'       => 'off',    // Breaks PS JS (cart, checkout, modules)
            'email_obfuscation'   => 'off',    // Interferes with contact forms
            'minify'              => ['css' => 'off', 'html' => 'off', 'js' => 'off'], // PS has its own minification

            // Security — balanced
            'security_level'      => 'medium',
            'challenge_ttl'       => 3600,
            'browser_check'       => 'on',
        ];

        // Save current settings before applying (for revert)
        $currentSettings = $this->getAllSettings($zoneId);
        $backup = [];
        foreach ($settings as $key => $value) {
            if (isset($currentSettings[$key])) {
                $backup[$key] = $currentSettings[$key];
            }
        }
        \Configuration::updateGlobalValue('LITESPEED_CF_OPTIMIZE_BACKUP', json_encode($backup));

        $results = [];
        foreach ($settings as $key => $value) {
            $ok = $this->setSetting($zoneId, $key, $value);
            $results[$key] = [
                'success' => $ok,
                'value'   => $value,
                'error'   => $ok ? '' : $this->lastError,
            ];
            $this->lastError = '';
        }

        // Create cache rules: bypass PHP, cache static assets
        $cacheRuleResult = $this->createPrestaShopCacheRules($zoneId);
        $results['cache_rules'] = $cacheRuleResult;

        // Create WAF/firewall rules for payment gateways (Redsys)
        $ruleResult = $this->createRedsysFirewallRule($zoneId);
        $results['redsys_waf_rule'] = $ruleResult;

        // Purge cache after optimization
        $this->purgeAll($zoneId);

        return $results;
    }

    /**
     * Reverts the optimize by restoring backed-up settings and deleting created rules.
     */
    public function revertOptimization(string $zoneId): array
    {
        $results = [];

        // Restore backed-up settings, or apply Cloudflare defaults
        $backup = json_decode(\Configuration::getGlobalValue('LITESPEED_CF_OPTIMIZE_BACKUP') ?: '{}', true);
        if (empty($backup)) {
            // Cloudflare free plan defaults
            $backup = [
                'ssl'                => 'flexible',
                'tls_1_3'            => 'on',
                'http3'              => 'on',
                'brotli'             => 'on',
                'early_hints'        => 'off',
                'always_use_https'   => 'off',
                '0rtt'               => 'on',
                'browser_cache_ttl'  => 14400,
                'cache_level'        => 'aggressive',
                'rocket_loader'      => 'off',
                'email_obfuscation'  => 'on',
                'minify'             => ['css' => 'off', 'html' => 'off', 'js' => 'off'],
                'security_level'     => 'medium',
                'challenge_ttl'      => 1800,
                'browser_check'      => 'on',
            ];
        }

        foreach ($backup as $key => $value) {
            $ok = $this->setSetting($zoneId, $key, $value);
            $results[$key] = ['success' => $ok, 'error' => $ok ? '' : $this->lastError];
            $this->lastError = '';
        }
        \Configuration::deleteByName('LITESPEED_CF_OPTIMIZE_BACKUP');

        // Delete created rules
        $this->deleteFirewallRule($zoneId, 'Redsys Payment Gateway');

        // Delete cache rules
        $rulesets = $this->request('GET', 'zones/' . $zoneId . '/rulesets?phase=http_request_cache_settings');
        if (!empty($rulesets['result'])) {
            foreach ($rulesets['result'] as $rs) {
                if (!empty($rs['rules'])) {
                    foreach ($rs['rules'] as $rule) {
                        if (str_contains($rule['description'] ?? '', 'PrestaShop')) {
                            $this->request('DELETE', 'zones/' . $zoneId . '/rulesets/' . $rs['id'] . '/rules/' . $rule['id']);
                        }
                    }
                }
            }
        }

        $this->purgeAll($zoneId);
        return $results;
    }

    /**
     * Creates Cloudflare cache rules optimized for PrestaShop:
     * 1. Bypass cache for all PHP/dynamic content
     * 2. Cache static assets (images, video, fonts, CSS, JS) with long TTL
     */
    public function createPrestaShopCacheRules(string $zoneId): array
    {
        // Check if rules already exist
        $rulesets = $this->request('GET', 'zones/' . $zoneId . '/rulesets?phase=http_request_cache_settings');
        $rulesetId = null;
        $hasRules = false;

        if (!empty($rulesets['result'])) {
            foreach ($rulesets['result'] as $rs) {
                if (($rs['phase'] ?? '') === 'http_request_cache_settings') {
                    $rulesetId = $rs['id'];
                    // Check if our rules already exist
                    if (!empty($rs['rules'])) {
                        foreach ($rs['rules'] as $rule) {
                            if (str_contains($rule['description'] ?? '', 'PrestaShop')) {
                                $hasRules = true;
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        }

        if ($hasRules) {
            return ['success' => true, 'value' => 'already_exists', 'error' => ''];
        }

        $rules = [
            // Rule 1: Bypass cache for PHP/dynamic content
            [
                'expression'  => '(not http.request.uri.path.extension in {"jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "ico" "bmp" "mp4" "webm" "ogg" "mp3" "wav" "woff" "woff2" "ttf" "eot" "otf" "css" "js" "pdf" "zip"})',
                'description' => 'PrestaShop — Bypass cache for dynamic content',
                'action'      => 'set_cache_settings',
                'action_parameters' => [
                    'cache' => false,
                ],
            ],
            // Rule 2: Cache static assets with long edge TTL
            [
                'expression'  => '(http.request.uri.path.extension in {"jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "ico" "bmp" "mp4" "webm" "ogg" "mp3" "wav" "woff" "woff2" "ttf" "eot" "otf" "pdf" "zip"})',
                'description' => 'PrestaShop — Cache static assets (images, video, fonts)',
                'action'      => 'set_cache_settings',
                'action_parameters' => [
                    'cache'    => true,
                    'edge_ttl' => ['mode' => 'override_origin', 'default' => 2592000], // 30 days
                    'browser_ttl' => ['mode' => 'override_origin', 'default' => 604800], // 7 days
                ],
            ],
        ];

        if ($rulesetId) {
            // Add rules to existing ruleset
            $success = true;
            foreach ($rules as $rule) {
                $result = $this->request('POST', 'zones/' . $zoneId . '/rulesets/' . $rulesetId . '/rules', $rule);
                if (empty($result['success'])) {
                    $this->lastError = self::getErrors($result);
                    $success = false;
                }
            }
            return ['success' => $success, 'value' => $success ? 'added' : 'partial', 'error' => $this->lastError];
        }

        // Create new ruleset with rules
        $createResult = $this->request('POST', 'zones/' . $zoneId . '/rulesets', [
            'name'  => 'PrestaShop Cache Rules',
            'kind'  => 'zone',
            'phase' => 'http_request_cache_settings',
            'rules' => $rules,
        ]);

        if (!empty($createResult['success'])) {
            return ['success' => true, 'value' => 'created', 'error' => ''];
        }

        $this->lastError = self::getErrors($createResult);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    /**
     * Creates a Cloudflare WAF custom rule to allow Redsys payment gateway
     * using ASN 31627 (Redsys Servicios de Procesamiento S.L.).
     * This covers all Redsys IPs (production + test) automatically.
     */
    public function createRedsysFirewallRule(string $zoneId): array
    {
        // ASN 31627 = Redsys Servicios de Procesamiento S.L.
        $expression = '(ip.geoip.asnum eq 31627)';

        // Check if rule already exists
        $existing = $this->request('GET', 'zones/' . $zoneId . '/firewall/rules?description=Redsys+Payment+Gateway');
        if (!empty($existing['result'])) {
            return ['success' => true, 'value' => 'already_exists', 'error' => ''];
        }

        // First create the filter
        $filterResult = $this->request('POST', 'zones/' . $zoneId . '/filters', [
            [
                'expression'  => $expression,
                'description' => 'Redsys Payment Gateway',
            ]
        ]);

        if (empty($filterResult['success']) || empty($filterResult['result'][0]['id'])) {
            // Try with WAF custom rules (Rulesets API) as fallback
            return $this->createRedsysWafRule($zoneId, $expression);
        }

        $filterId = $filterResult['result'][0]['id'];

        // Create the firewall rule with action "allow"
        $ruleResult = $this->request('POST', 'zones/' . $zoneId . '/firewall/rules', [
            [
                'filter'      => ['id' => $filterId],
                'action'      => 'allow',
                'description' => 'Redsys Payment Gateway',
                'priority'    => 1,
            ]
        ]);

        if (!empty($ruleResult['success'])) {
            return ['success' => true, 'value' => 'created', 'error' => ''];
        }

        $this->lastError = self::getErrors($ruleResult);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    /**
     * Fallback: create Redsys allow rule via the Rulesets API (WAF Custom Rules).
     */
    private function createRedsysWafRule(string $zoneId, string $expression): array
    {
        // Get the zone's custom WAF ruleset
        $rulesets = $this->request('GET', 'zones/' . $zoneId . '/rulesets?phase=http_request_firewall_custom');

        $rulesetId = null;
        if (!empty($rulesets['result'])) {
            foreach ($rulesets['result'] as $rs) {
                if (($rs['phase'] ?? '') === 'http_request_firewall_custom') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }

        if (!$rulesetId) {
            // Create a new ruleset
            $createResult = $this->request('POST', 'zones/' . $zoneId . '/rulesets', [
                'name'        => 'LiteSpeed Cache Custom Rules',
                'kind'        => 'zone',
                'phase'       => 'http_request_firewall_custom',
                'rules'       => [
                    [
                        'expression'  => $expression,
                        'action'      => 'skip',
                        'action_parameters' => ['ruleset' => 'current'],
                        'description' => 'Redsys Payment Gateway — Allow',
                    ]
                ],
            ]);

            if (!empty($createResult['success'])) {
                return ['success' => true, 'value' => 'created_ruleset', 'error' => ''];
            }

            $this->lastError = self::getErrors($createResult);
            return ['success' => false, 'value' => null, 'error' => $this->lastError];
        }

        // Add rule to existing ruleset
        $addResult = $this->request('POST', 'zones/' . $zoneId . '/rulesets/' . $rulesetId . '/rules', [
            'expression'  => $expression,
            'action'      => 'skip',
            'action_parameters' => ['ruleset' => 'current'],
            'description' => 'Redsys Payment Gateway — Allow',
        ]);

        if (!empty($addResult['success'])) {
            return ['success' => true, 'value' => 'added_to_ruleset', 'error' => ''];
        }

        $this->lastError = self::getErrors($addResult);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    /**
     * Creates a WAF rule to block problematic bots by ASN.
     *
     * ASN 32934 = Facebook/Meta — aggressive crawlers that consume excessive server resources.
     */
    public function createBlockProblematicBots(string $zoneId): array
    {
        $description = 'Block Problematic Bots';

        // Check if rule already exists
        $existing = $this->request('GET', 'zones/' . $zoneId . '/firewall/rules?description=' . urlencode($description));
        if (!empty($existing['result'])) {
            return ['success' => true, 'value' => 'already_exists', 'error' => ''];
        }

        // Block by ASN: 32934 (Facebook/Meta)
        $expression = '(ip.geoip.asnum eq 32934)';

        // Create filter
        $filterResult = $this->request('POST', 'zones/' . $zoneId . '/filters', [
            [
                'expression'  => $expression,
                'description' => $description,
            ]
        ]);

        if (empty($filterResult['success']) || empty($filterResult['result'][0]['id'])) {
            // Fallback to Rulesets API
            return $this->createBlockBotsViaRuleset($zoneId, $expression, $description);
        }

        $filterId = $filterResult['result'][0]['id'];

        $ruleResult = $this->request('POST', 'zones/' . $zoneId . '/firewall/rules', [
            [
                'filter'      => ['id' => $filterId],
                'action'      => 'block',
                'description' => $description,
                'priority'    => 10,
            ]
        ]);

        if (!empty($ruleResult['success'])) {
            return ['success' => true, 'value' => 'created', 'error' => ''];
        }

        $this->lastError = self::getErrors($ruleResult);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    private function createBlockBotsViaRuleset(string $zoneId, string $expression, string $description): array
    {
        $rulesets = $this->request('GET', 'zones/' . $zoneId . '/rulesets?phase=http_request_firewall_custom');
        $rulesetId = null;

        if (!empty($rulesets['result'])) {
            foreach ($rulesets['result'] as $rs) {
                if (($rs['phase'] ?? '') === 'http_request_firewall_custom') {
                    $rulesetId = $rs['id'];
                    break;
                }
            }
        }

        $rule = [
            'expression'  => $expression,
            'action'      => 'block',
            'description' => $description,
        ];

        if ($rulesetId) {
            $result = $this->request('POST', 'zones/' . $zoneId . '/rulesets/' . $rulesetId . '/rules', $rule);
        } else {
            $result = $this->request('POST', 'zones/' . $zoneId . '/rulesets', [
                'name'  => 'LiteSpeed Cache Security Rules',
                'kind'  => 'zone',
                'phase' => 'http_request_firewall_custom',
                'rules' => [$rule],
            ]);
        }

        if (!empty($result['success'])) {
            return ['success' => true, 'value' => 'created', 'error' => ''];
        }

        $this->lastError = self::getErrors($result);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    /**
     * Creates a WAF rule to block traffic from problematic countries.
     * BR (Brazil), CN (China), RU (Russia), IR (Iran), KP (North Korea).
     */
    public function createBlockProblematicCountries(string $zoneId): array
    {
        $description = 'Block Problematic Countries';

        $existing = $this->request('GET', 'zones/' . $zoneId . '/firewall/rules?description=' . urlencode($description));
        if (!empty($existing['result'])) {
            return ['success' => true, 'value' => 'already_exists', 'error' => ''];
        }

        $expression = '(ip.geoip.country eq "BR" or ip.geoip.country eq "CN" or ip.geoip.country eq "RU" or ip.geoip.country eq "IR" or ip.geoip.country eq "KP")';

        $filterResult = $this->request('POST', 'zones/' . $zoneId . '/filters', [
            [
                'expression'  => $expression,
                'description' => $description,
            ]
        ]);

        if (empty($filterResult['success']) || empty($filterResult['result'][0]['id'])) {
            // Fallback to Rulesets API
            $rulesets = $this->request('GET', 'zones/' . $zoneId . '/rulesets?phase=http_request_firewall_custom');
            $rulesetId = null;
            if (!empty($rulesets['result'])) {
                foreach ($rulesets['result'] as $rs) {
                    if (($rs['phase'] ?? '') === 'http_request_firewall_custom') {
                        $rulesetId = $rs['id'];
                        break;
                    }
                }
            }

            $rule = ['expression' => $expression, 'action' => 'block', 'description' => $description];

            if ($rulesetId) {
                $result = $this->request('POST', 'zones/' . $zoneId . '/rulesets/' . $rulesetId . '/rules', $rule);
            } else {
                $result = $this->request('POST', 'zones/' . $zoneId . '/rulesets', [
                    'name' => 'LiteSpeed Cache Country Rules', 'kind' => 'zone',
                    'phase' => 'http_request_firewall_custom', 'rules' => [$rule],
                ]);
            }

            return !empty($result['success'])
                ? ['success' => true, 'value' => 'created', 'error' => '']
                : ['success' => false, 'value' => null, 'error' => self::getErrors($result)];
        }

        $filterId = $filterResult['result'][0]['id'];
        $ruleResult = $this->request('POST', 'zones/' . $zoneId . '/firewall/rules', [
            [
                'filter'      => ['id' => $filterId],
                'action'      => 'block',
                'description' => $description,
                'priority'    => 10,
            ]
        ]);

        if (!empty($ruleResult['success'])) {
            return ['success' => true, 'value' => 'created', 'error' => ''];
        }

        $this->lastError = self::getErrors($ruleResult);
        return ['success' => false, 'value' => null, 'error' => $this->lastError];
    }

    /**
     * Checks if a firewall rule with a given description exists.
     */
    public function hasFirewallRule(string $zoneId, string $description): bool
    {
        $result = $this->request('GET', 'zones/' . $zoneId . '/firewall/rules?description=' . urlencode($description));
        return !empty($result['result']);
    }

    /**
     * Deletes a firewall rule (and its filter) by description.
     */
    public function deleteFirewallRule(string $zoneId, string $description): bool
    {
        $result = $this->request('GET', 'zones/' . $zoneId . '/firewall/rules?description=' . urlencode($description));
        if (empty($result['result'])) {
            return true; // Already gone
        }

        foreach ($result['result'] as $rule) {
            $ruleId = $rule['id'];
            $filterId = $rule['filter']['id'] ?? null;

            $this->request('DELETE', 'zones/' . $zoneId . '/firewall/rules/' . $ruleId);
            if ($filterId) {
                $this->request('DELETE', 'zones/' . $zoneId . '/filters/' . $filterId);
            }
        }

        return true;
    }

    /**
     * Gets all current zone settings for audit/display.
     */
    public function getAllSettings(string $zoneId): array
    {
        $result = $this->request('GET', 'zones/' . $zoneId . '/settings');
        if (!empty($result['success']) && !empty($result['result'])) {
            $settings = [];
            foreach ($result['result'] as $s) {
                $settings[$s['id']] = $s['value'];
            }
            return $settings;
        }
        $this->lastError = self::getErrors($result);
        return [];
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
