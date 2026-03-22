<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DatabaseController extends FrameworkBundleAdminController
{
    use NavPillsTrait;

    public function indexAction(Request $request): Response
    {
        $db = \Db::getInstance();
        $prefix = _DB_PREFIX_;
        $d = 'Modules.Litespeedcache.Admin';

        // Handle POST cleanup actions
        if ($request->isMethod('POST') && $request->request->has('cleanup_action')) {
            $action = $request->request->get('cleanup_action');
            $result = $this->executeCleanup($action, $db, $prefix);
            $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);
            \PrestaShopLogger::addLog('DB cleanup: ' . $action . ' — ' . $result['message'], 1, null, 'LiteSpeedCache', 0, true);

            return $this->redirectToRoute('admin_litespeedcache_database');
        }

        // Handle table engine conversion
        if ($request->isMethod('POST') && $request->request->has('convert_table')) {
            $table = $request->request->get('convert_table');
            // Validate table name (only allow alphanumeric and underscores)
            if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                try {
                    $db->execute('ALTER TABLE `' . pSQL($table) . '` ENGINE = InnoDB');
                    $this->addFlash('success', $this->trans('Table %s converted to InnoDB.', $d, [$table]));
                    \PrestaShopLogger::addLog('Table converted to InnoDB: ' . $table, 1, null, 'LiteSpeedCache', 0, true);
                } catch (\Throwable $e) {
                    $this->addFlash('error', $this->trans('Failed to convert table %s: %s', $d, [$table, $e->getMessage()]));
                }
            }

            return $this->redirectToRoute('admin_litespeedcache_database');
        }

        // Handle optimize tables
        if ($request->isMethod('POST') && $request->request->has('optimize_tables')) {
            $this->optimizeAllTables($db);
            $this->addFlash('success', $this->trans('All tables optimized.', $d));
            \PrestaShopLogger::addLog('All database tables optimized', 1, null, 'LiteSpeedCache', 0, true);

            return $this->redirectToRoute('admin_litespeedcache_database');
        }

        // Cleanup items with counts
        $cleanupItems = $this->getCleanupItems($db, $prefix);

        // Table engine info
        $tables = $this->getTableEngines($db);
        $nonInnodbCount = count(array_filter($tables, fn ($t) => strtolower($t['engine']) !== 'innodb'));

        // Database summary
        $summary = $this->getDatabaseSummary($db);

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/database.html.twig', [
            'cleanupItems' => $cleanupItems,
            'tables' => $tables,
            'nonInnodbCount' => $nonInnodbCount,
            'summary' => $summary,
        ], $request);
    }

    private function getCleanupItems(\Db $db, string $prefix): array
    {
        $items = [];

        // Abandoned carts (no order, older than 48h)
        $items[] = [
            'key' => 'abandoned_carts',
            'title' => 'Abandoned Carts',
            'desc' => 'Delete carts with no associated order, older than 48 hours.',
            'icon' => 'remove_shopping_cart',
            'count' => (int) $db->getValue(
                "SELECT COUNT(*) FROM `{$prefix}cart` c
                 LEFT JOIN `{$prefix}orders` o ON o.id_cart = c.id_cart
                 WHERE o.id_cart IS NULL AND c.date_add < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
            ),
        ];

        // Old logs (older than 30 days)
        $items[] = [
            'key' => 'old_logs',
            'title' => 'Old Logs',
            'desc' => 'Delete PrestaShop log entries older than 30 days.',
            'icon' => 'delete_sweep',
            'count' => (int) $db->getValue(
                "SELECT COUNT(*) FROM `{$prefix}log`
                 WHERE date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
        ];

        // Expired connections
        $items[] = [
            'key' => 'expired_connections',
            'title' => 'Expired Connections',
            'desc' => 'Delete expired guest connection records.',
            'icon' => 'link_off',
            'count' => (int) $db->getValue(
                "SELECT COUNT(*) FROM `{$prefix}connections`
                 WHERE date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
        ];

        // Search statistics
        $items[] = [
            'key' => 'search_stats',
            'title' => 'Search Statistics',
            'desc' => 'Clear all internal search statistics.',
            'icon' => 'search_off',
            'count' => (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}statssearch`"),
        ];

        // Guest entries without customer
        $items[] = [
            'key' => 'old_guests',
            'title' => 'Old Guest Records',
            'desc' => 'Delete guest records older than 30 days.',
            'icon' => 'person_off',
            'count' => (int) $db->getValue(
                "SELECT COUNT(*) FROM `{$prefix}guest`
                 WHERE id_customer = 0 AND id_guest NOT IN (
                     SELECT id_guest FROM `{$prefix}connections`
                     WHERE date_add > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 )"
            ),
        ];

        // Mail logs
        $items[] = [
            'key' => 'mail_logs',
            'title' => 'Email Logs',
            'desc' => 'Clear all sent email log records.',
            'icon' => 'mark_email_read',
            'count' => (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}mail`"),
        ];

        // Page not found (404) logs
        $items[] = [
            'key' => 'page_not_found',
            'title' => '404 Page Logs',
            'desc' => 'Delete all 404 error page log records.',
            'icon' => 'error_outline',
            'count' => (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}pagenotfound`"),
        ];

        // Expired specific prices
        $items[] = [
            'key' => 'expired_specific_prices',
            'title' => 'Expired Specific Prices',
            'desc' => 'Delete specific price rules that have already expired.',
            'icon' => 'sell',
            'count' => (int) $db->getValue(
                "SELECT COUNT(*) FROM `{$prefix}specific_price`
                 WHERE `to` != '0000-00-00 00:00:00' AND `to` < NOW()"
            ),
        ];

        // Total cleanup count
        $totalCount = array_sum(array_column($items, 'count'));
        array_unshift($items, [
            'key' => 'clean_all',
            'title' => 'Clean All',
            'desc' => 'Run all cleanup tasks at once.',
            'icon' => 'cleaning_services',
            'count' => $totalCount,
        ]);

        return $items;
    }

    private function executeCleanup(string $action, \Db $db, string $prefix): array
    {
        $affected = 0;

        $actions = ($action === 'clean_all')
            ? ['abandoned_carts', 'old_logs', 'expired_connections', 'search_stats', 'old_guests', 'mail_logs', 'page_not_found', 'expired_specific_prices']
            : [$action];

        foreach ($actions as $a) {
            switch ($a) {
                case 'abandoned_carts':
                    $affected += (int) $db->execute(
                        "DELETE c FROM `{$prefix}cart` c
                         LEFT JOIN `{$prefix}orders` o ON o.id_cart = c.id_cart
                         WHERE o.id_cart IS NULL AND c.date_add < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
                    );
                    break;
                case 'old_logs':
                    $affected += (int) $db->execute(
                        "DELETE FROM `{$prefix}log` WHERE date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );
                    break;
                case 'expired_connections':
                    $affected += (int) $db->execute(
                        "DELETE FROM `{$prefix}connections` WHERE date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );
                    // Also clean connections_page for deleted connections
                    $db->execute(
                        "DELETE cp FROM `{$prefix}connections_page` cp
                         LEFT JOIN `{$prefix}connections` c ON c.id_connections = cp.id_connections
                         WHERE c.id_connections IS NULL"
                    );
                    break;
                case 'search_stats':
                    $affected += (int) $db->execute("TRUNCATE TABLE `{$prefix}statssearch`");
                    break;
                case 'old_guests':
                    $affected += (int) $db->execute(
                        "DELETE FROM `{$prefix}guest`
                         WHERE id_customer = 0 AND id_guest NOT IN (
                             SELECT id_guest FROM `{$prefix}connections`
                             WHERE date_add > DATE_SUB(NOW(), INTERVAL 30 DAY)
                         )"
                    );
                    break;
                case 'mail_logs':
                    $affected += (int) $db->execute("TRUNCATE TABLE `{$prefix}mail`");
                    break;
                case 'page_not_found':
                    $affected += (int) $db->execute("TRUNCATE TABLE `{$prefix}pagenotfound`");
                    break;
                case 'expired_specific_prices':
                    $affected += (int) $db->execute(
                        "DELETE FROM `{$prefix}specific_price`
                         WHERE `to` != '0000-00-00 00:00:00' AND `to` < NOW()"
                    );
                    break;
            }
        }

        $label = $action === 'clean_all' ? 'All cleanup tasks' : $action;

        return ['success' => true, 'message' => $label . ' completed.'];
    }

    private function getTableEngines(\Db $db): array
    {
        $dbName = \Db::getInstance()->getValue('SELECT DATABASE()');
        $rows = $db->executeS(
            "SELECT TABLE_NAME AS table_name, ENGINE AS engine,
                    ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mb,
                    ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mb,
                    TABLE_ROWS AS row_count
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . pSQL($dbName) . "'
             ORDER BY ENGINE, TABLE_NAME"
        );

        return $rows ?: [];
    }

    private function getDatabaseSummary(\Db $db): array
    {
        $dbName = $db->getValue('SELECT DATABASE()');
        $safeDb = pSQL($dbName);

        $stats = $db->getRow(
            "SELECT COUNT(*) AS total_tables,
                    ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_size_mb,
                    ROUND(SUM(DATA_FREE) / 1024 / 1024, 2) AS free_space_mb,
                    ROUND(SUM(DATA_LENGTH) / 1024 / 1024, 2) AS data_size_mb,
                    ROUND(SUM(INDEX_LENGTH) / 1024 / 1024, 2) AS index_size_mb,
                    SUM(TABLE_ROWS) AS total_rows
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '{$safeDb}'"
        );

        $mysqlVersion = $db->getValue('SELECT VERSION()') ?: '-';
        $defaultEngine = $db->getValue('SELECT @@default_storage_engine') ?: '-';
        $charset = $db->getValue("SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$safeDb}'") ?: '-';
        $collation = $db->getValue("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$safeDb}'") ?: '-';

        $topTables = $db->executeS(
            "SELECT TABLE_NAME AS table_name,
                    ENGINE AS engine,
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
                    TABLE_ROWS AS row_count
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '{$safeDb}'
             ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
             LIMIT 15"
        ) ?: [];

        return [
            'db_name' => $dbName,
            'mysql_version' => $mysqlVersion,
            'default_engine' => $defaultEngine,
            'charset' => $charset,
            'collation' => $collation,
            'total_tables' => (int) ($stats['total_tables'] ?? 0),
            'total_rows' => (int) ($stats['total_rows'] ?? 0),
            'total_size' => (float) ($stats['total_size_mb'] ?? 0),
            'data_size' => (float) ($stats['data_size_mb'] ?? 0),
            'index_size' => (float) ($stats['index_size_mb'] ?? 0),
            'free_space' => (float) ($stats['free_space_mb'] ?? 0),
            'top_tables' => $topTables,
        ];
    }

    private function optimizeAllTables(\Db $db): void
    {
        $dbName = $db->getValue('SELECT DATABASE()');
        $tables = $db->executeS(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . pSQL($dbName) . "'"
        );
        foreach ($tables ?: [] as $t) {
            $db->execute('OPTIMIZE TABLE `' . pSQL($t['TABLE_NAME']) . '`');
        }
    }
}
