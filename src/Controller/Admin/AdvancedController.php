<?php

namespace LiteSpeed\Cache\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvancedController extends AbstractController
{
    use NavPillsTrait;

    private const CFG_KEY = 'LITESPEED_CACHE_ADVANCED';

    public function indexAction(Request $request): Response
    {
        $config = $this->getAdvancedConfig();

        if ($request->isMethod('POST') && $request->request->has('submitAdvanced')) {
            $this->handleSave($request, $config);

            return $this->redirectToRoute('admin_litespeedcache_advanced');
        }

        return $this->renderWithNavPills('@Modules/litespeedcache/views/templates/admin/advanced.html.twig', [
            'config' => $config,
        ], $request);
    }

    private function getAdvancedConfig(): array
    {
        $stored = json_decode(\Configuration::getGlobalValue(self::CFG_KEY) ?: '{}', true);

        return array_merge([
            'login_cookie' => '_lscache_vary',
            'vary_cookies' => '',
            'instant_click' => 0,
        ], $stored ?: []);
    }

    private function handleSave(Request $request, array $currentConfig): void
    {
        $d = 'Modules.Litespeedcache.Admin';
        $errors = [];

        $loginCookie = trim($request->request->get('login_cookie', '_lscache_vary'));
        if ($loginCookie !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $loginCookie)) {
            $errors[] = 'Login Cookie: only alphanumeric characters and underscores allowed.';
        }

        $varyCookies = trim($request->request->get('vary_cookies', ''));
        if ($varyCookies !== '') {
            $lines = preg_split("/\r?\n/", $varyCookies, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $line)) {
                    $errors[] = 'Vary Cookies: "' . htmlspecialchars($line) . '" contains invalid characters. Only alphanumeric and underscores allowed.';
                }
            }
        }

        $instantClick = (int) $request->request->get('instant_click', 0);

        if ($errors) {
            foreach ($errors as $e) {
                $this->addFlash('error', $e);
            }

            return;
        }

        $newConfig = [
            'login_cookie' => $loginCookie ?: '_lscache_vary',
            'vary_cookies' => $varyCookies,
            'instant_click' => $instantClick,
        ];

        $oldConfig = $currentConfig;
        \Configuration::updateGlobalValue(self::CFG_KEY, json_encode($newConfig));

        // Update .htaccess if login cookie or vary cookies changed
        if ($oldConfig['login_cookie'] !== $newConfig['login_cookie'] || $oldConfig['vary_cookies'] !== $newConfig['vary_cookies']) {
            $this->updateHtaccess($newConfig);
        }

        $this->addFlash('success', $this->trans('Advanced settings saved.', $d));
        \PrestaShopLogger::addLog('Advanced cache settings updated', 1, null, 'LiteSpeedCache', 0, true);
    }

    private function updateHtaccess(array $config): void
    {
        $htaccessPath = _PS_ROOT_DIR_ . '/.htaccess';
        if (!is_file($htaccessPath) || !is_writable($htaccessPath)) {
            $this->addFlash('warning', $this->trans('Could not update .htaccess — file not writable.', 'Modules.Litespeedcache.Admin'));

            return;
        }

        $content = file_get_contents($htaccessPath);

        // Remove existing LSCache vary cookie block
        $content = preg_replace(
            '/\n?# BEGIN LSCache Vary Cookie.*?# END LSCache Vary Cookie\n?/s',
            '',
            $content
        );

        // Build new block
        $block = "\n# BEGIN LSCache Vary Cookie\n";
        $block .= '<IfModule LiteSpeed>' . "\n";
        $block .= 'CacheLookup on' . "\n";

        if ($config['login_cookie'] !== '_lscache_vary') {
            $block .= 'RewriteRule .* - [E="Cache-Vary:' . $config['login_cookie'] . '"]' . "\n";
        }

        if (!empty($config['vary_cookies'])) {
            $cookies = preg_split("/\r?\n/", $config['vary_cookies'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($cookies as $cookie) {
                $cookie = trim($cookie);
                if ($cookie !== '') {
                    $block .= 'RewriteRule .* - [E="Cache-Vary:' . $cookie . '"]' . "\n";
                }
            }
        }

        $block .= '</IfModule>' . "\n";
        $block .= "# END LSCache Vary Cookie\n";

        // Insert before first RewriteRule or at end
        if (preg_match('/(<IfModule mod_rewrite\.c>)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $content = substr($content, 0, $m[0][1]) . $block . substr($content, $m[0][1]);
        } else {
            $content .= $block;
        }

        file_put_contents($htaccessPath, $content);
        $this->addFlash('info', $this->trans('.htaccess updated with vary cookie settings.', 'Modules.Litespeedcache.Admin'));
    }
}
