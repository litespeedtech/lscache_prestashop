<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author     LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

declare(strict_types=1);

namespace LiteSpeed\Cache\Service\Esi;

if (!defined('_PS_VERSION_')) {
    exit;
}

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Core\CacheState;
use LiteSpeed\Cache\Esi\EsiItem;
use LiteSpeed\Cache\Helper\CacheHelper;
use LiteSpeed\Cache\Logger\CacheLogger as LSLog;

/**
 * EsiMarkerManager -- manages ESI injection state (tracker + marker arrays).
 *
 * Extracted from LiteSpeedCache main module class to isolate ESI marker
 * registration, replacement, and tracker bookkeeping.
 */
class EsiMarkerManager
{
    private CacheConfig $config;

    /** @var array{tracker: int[], marker: array<string, EsiItem>} */
    private array $esiInjection = ['tracker' => [], 'marker' => []];

    public function __construct(CacheConfig $config)
    {
        $this->config = $config;
    }

    // ---- Marker registration ----------------------------------------------------

    public function registerMarker(array $params, $conf): string
    {
        $item = new EsiItem($params, $conf);
        $id = $item->getId();
        if (!isset($this->esiInjection['marker'][$id])) {
            $this->esiInjection['marker'][$id] = $item;
        }

        return '_LSCESI-' . $id . '-START_';
    }

    // ---- Marker replacement -----------------------------------------------------

    /**
     * Replace all ESI markers in the output buffer, inject token/env blocks,
     * and sync the item cache for private ESI items.
     */
    public function replaceMarkers(string $buf): string
    {
        if (!empty($this->esiInjection['marker'])) {
            $buf = preg_replace_callback(
                [
                    '/_LSC(ESI)-(.+)-START_(.*)_LSCESIEND_/Usm',
                    '/(\'|\")_LSCESIJS-(.+)-START__LSCESIEND_(\'|\")/Usm',
                ],
                function (array $m): string {
                    $id = $m[2];
                    if (!isset($this->esiInjection['marker'][$id])) {
                        $id = stripslashes($id);
                    }
                    if (!isset($this->esiInjection['marker'][$id])) {
                        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_UNEXPECTED) {
                            LSLog::log('Lost Injection ' . $id, LSLog::LEVEL_UNEXPECTED);
                        }

                        return '';
                    }
                    $item = $this->esiInjection['marker'][$id];
                    $esiInclude = $item->getInclude();
                    if ($esiInclude === false) {
                        if ($item->getParam('pt') === EsiItem::ESI_JSDEF) {
                            \LscIntegration::processJsDef($item);
                        } else {
                            $item->setContent($m[3]);
                        }
                        $esiInclude = $item->getInclude();
                    }

                    return $esiInclude;
                },
                $buf
            );
        }

        // Always inject the static token and env ESI blocks.
        $staticToken = \Tools::getToken(false);
        $tkItem = new EsiItem(
            ['pt' => EsiItem::ESI_TOKEN, 'm' => \LscToken::NAME, 'd' => 'static'],
            $this->config->getEsiModuleConf(\LscToken::NAME)
        );
        $tkItem->setContent($staticToken);
        $this->esiInjection['marker'][$tkItem->getId()] = $tkItem;

        $envItem = new EsiItem(
            ['pt' => EsiItem::ESI_ENV, 'm' => \LscEnv::NAME],
            $this->config->getEsiModuleConf(\LscEnv::NAME)
        );
        $envItem->setContent('');
        $this->esiInjection['marker'][$envItem->getId()] = $envItem;

        if (CacheState::isCacheable()) {
            if (strpos($buf, $staticToken) !== false) {
                $buf = str_replace($staticToken, $tkItem->getInclude(), $buf);
            }
            $buf = $envItem->getInclude() . $buf;
        }

        $bufInline = '';
        $allPrivateItems = [];
        foreach ($this->esiInjection['marker'] as $item) {
            $inline = $item->getInline();
            if ($inline !== false) {
                $bufInline .= $inline;
                if ($item->isPrivate()) {
                    $allPrivateItems[] = $item;
                }
            }
        }

        if ($bufInline) {
            if (!empty($allPrivateItems)) {
                CacheHelper::syncItemCache($allPrivateItems);
            }
            CacheState::set(CacheState::ESI_ON);
        }

        if ($bufInline && _LITESPEED_DEBUG_ >= LSLog::LEVEL_ESI_OUTPUT) {
            LSLog::log('ESI inline output ' . $bufInline, LSLog::LEVEL_ESI_OUTPUT);
        }

        return $bufInline . $buf;
    }

    // ---- Accessor methods -------------------------------------------------------

    /**
     * @return array<string, EsiItem>
     */
    public function getMarkers(): array
    {
        return $this->esiInjection['marker'];
    }

    public function hasMarkers(): bool
    {
        return !empty($this->esiInjection['marker']);
    }

    public function setMarker(string $id, EsiItem $item): void
    {
        $this->esiInjection['marker'][$id] = $item;
    }

    // ---- Tracker methods --------------------------------------------------------

    public function pushTracker(int $err): void
    {
        array_push($this->esiInjection['tracker'], $err);
    }

    public function popTracker(): ?int
    {
        return array_pop($this->esiInjection['tracker']);
    }

    public function hasActiveTracker(): bool
    {
        return !empty($this->esiInjection['tracker']);
    }
}
