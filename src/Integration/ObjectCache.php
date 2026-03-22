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

use LiteSpeed\Cache\Config\ObjConfig;

/**
 * ObjectCache — helper for checking extension availability and testing connections.
 */
class ObjectCache
{
    /**
     * Returns the availability status of Memcached and Redis PHP extensions.
     *
     * @return array{memcached: bool, redis: bool}
     */
    public static function extensionStatus(): array
    {
        return [
            'memcached' => extension_loaded('memcached'),
            'redis' => extension_loaded('redis'),
        ];
    }

    /**
     * Returns hit/miss/memory statistics from the configured backend.
     * Returns null if the backend is unreachable or the extension is missing.
     */
    public static function getStats(array $cfg): ?array
    {
        $method = $cfg[ObjConfig::OBJ_METHOD] ?? 'redis';
        $host = $cfg[ObjConfig::OBJ_HOST] ?? 'localhost';
        $port = (int) ($cfg[ObjConfig::OBJ_PORT] ?? 6379);

        try {
            if ($method === 'redis') {
                if (!extension_loaded('redis')) {
                    return null;
                }
                $redis = new \Redis();
                if (!$redis->connect($host, $port, 2.0)) {
                    return null;
                }
                if (!empty($cfg[ObjConfig::OBJ_PASSWORD])) {
                    $redis->auth($cfg[ObjConfig::OBJ_PASSWORD]);
                }
                $db = (int) ($cfg[ObjConfig::OBJ_REDIS_DB] ?? 0);
                if ($db > 0) {
                    $redis->select($db);
                }
                $info = $redis->info();
                $ksInfo = $redis->info('keyspace');
                $redis->close();

                $keys = 0;
                foreach ($ksInfo as $k => $v) {
                    if (str_starts_with((string) $k, 'db') && is_string($v) && preg_match('/keys=(\d+)/', $v, $m)) {
                        $keys += (int) $m[1];
                    }
                }

                $hits = (int) ($info['keyspace_hits'] ?? 0);
                $misses = (int) ($info['keyspace_misses'] ?? 0);
                $total = $hits + $misses;
                $clients = (int) ($info['connected_clients'] ?? 0);
                $ops = (int) ($info['instantaneous_ops_per_sec'] ?? 0);

                $uptimeSeconds = (int) ($info['uptime_in_seconds'] ?? 0);
                $startedAt = date('Y-m-d H:i', time() - $uptimeSeconds);
                $days = (int) floor($uptimeSeconds / 86400);
                $hours = (int) floor(($uptimeSeconds % 86400) / 3600);
                $minutes = (int) floor(($uptimeSeconds % 3600) / 60);
                if ($days > 0) {
                    $uptimeHuman = $days . 'd ' . $hours . 'h';
                } else {
                    $uptimeHuman = $hours . 'h ' . $minutes . 'm';
                }

                return [
                    'method' => 'Redis',
                    'version' => $info['redis_version'] ?? '-',
                    'hits' => $hits,
                    'misses' => $misses,
                    'ratio' => $total > 0 ? round($hits / $total * 100, 2) : 0.0,
                    'memory' => $info['used_memory_human'] ?? '-',
                    'memory_peak' => $info['used_memory_peak_human'] ?? '-',
                    'keys' => $keys,
                    'ops_per_sec' => $ops,
                    'clients' => $clients,
                    'uptime' => $startedAt,
                    'uptime_human' => $uptimeHuman,
                    'fragmentation' => $info['mem_fragmentation_ratio'] ?? '-',
                ];
            }

            if ($method === 'memcached') {
                if (!extension_loaded('memcached')) {
                    return null;
                }
                $mc = new \Memcached();
                $mc->addServer($host, $port);
                $stats = $mc->getStats();
                $s = $stats[$host . ':' . $port] ?? null;
                if (!$s) {
                    return null;
                }

                $hits = (int) ($s['get_hits'] ?? 0);
                $misses = (int) ($s['get_misses'] ?? 0);
                $total = $hits + $misses;

                return [
                    'method' => 'Memcached',
                    'hits' => $hits,
                    'misses' => $misses,
                    'ratio' => $total > 0 ? round($hits / $total * 100, 1) : 0.0,
                    'memory' => round(($s['bytes'] ?? 0) / 1048576, 2) . ' MB',
                    'uptime' => (int) ($s['uptime'] ?? 0),
                    'keys' => (int) ($s['curr_items'] ?? 0),
                    'version' => $s['version'] ?? '-',
                ];
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Takes an instantaneous snapshot of Redis metrics for time-series charting.
     * Measures PING latency, reads instantaneous bandwidth and ops counters.
     * Stores the snapshot in PS Configuration (rolling window of 60 points).
     * Returns the full history array (newest last).
     */
    public static function recordSnapshot(array $cfg): ?array
    {
        if (($cfg[ObjConfig::OBJ_METHOD] ?? 'redis') !== 'redis' || !extension_loaded('redis')) {
            return null;
        }

        $host = $cfg[ObjConfig::OBJ_HOST] ?? 'localhost';
        $port = (int) ($cfg[ObjConfig::OBJ_PORT] ?? 6379);

        try {
            $redis = new \Redis();
            if (!$redis->connect($host, $port, 2.0)) {
                return null;
            }
            if (!empty($cfg[ObjConfig::OBJ_PASSWORD])) {
                $redis->auth($cfg[ObjConfig::OBJ_PASSWORD]);
            }

            // Measure PING latency
            $t0 = microtime(true);
            $redis->ping();
            $latency = round((microtime(true) - $t0) * 1000, 3);

            $info = $redis->info();
            $redis->close();
        } catch (\Exception $e) {
            return null;
        }

        $point = [
            'ts' => time(),
            'label' => date('H:i'),
            'lat' => $latency,
            'ops' => (int) ($info['instantaneous_ops_per_sec'] ?? 0),
            'in' => round((float) ($info['instantaneous_input_kbps'] ?? 0) * 1024, 0),
            'out' => round((float) ($info['instantaneous_output_kbps'] ?? 0) * 1024, 0),
            'mem' => (int) ($info['used_memory'] ?? 0),
            'hits' => (int) ($info['keyspace_hits'] ?? 0),
            'misses' => (int) ($info['keyspace_misses'] ?? 0),
        ];

        $raw = \Configuration::getGlobalValue('LITESPEED_STAT_REDIS_HISTORY');
        $history = $raw ? (json_decode($raw, true) ?? []) : [];
        $history[] = $point;

        // Keep last 60 points
        if (count($history) > 60) {
            $history = array_values(array_slice($history, -60));
        }

        \Configuration::updateGlobalValue('LITESPEED_STAT_REDIS_HISTORY', json_encode($history));

        return $history;
    }

    /**
     * Tests the connection to the configured backend.
     * Returns true on success, false on failure.
     */
    public static function testConnection(array $cfg): bool
    {
        $method = $cfg[ObjConfig::OBJ_METHOD] ?? 'redis';
        $host = $cfg[ObjConfig::OBJ_HOST] ?? 'localhost';
        $port = (int) ($cfg[ObjConfig::OBJ_PORT] ?? 6379);

        try {
            if ($method === 'redis') {
                if (!extension_loaded('redis')) {
                    return false;
                }
                $redis = new \Redis();
                if (!$redis->connect($host, $port, 2.0)) {
                    return false;
                }
                if (!empty($cfg[ObjConfig::OBJ_PASSWORD])) {
                    $redis->auth($cfg[ObjConfig::OBJ_PASSWORD]);
                }
                if ((int) ($cfg[ObjConfig::OBJ_REDIS_DB] ?? 0) > 0) {
                    $redis->select((int) $cfg[ObjConfig::OBJ_REDIS_DB]);
                }
                $redis->ping();
                $redis->close();

                return true;
            }

            if ($method === 'memcached') {
                if (!extension_loaded('memcached')) {
                    return false;
                }
                $m = new \Memcached();
                $m->addServer($host, $port);
                $stats = $m->getStats();

                return !empty($stats[$host . ':' . $port]);
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }
}
