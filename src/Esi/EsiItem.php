<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Esi;

use LiteSpeed\Cache\Config\CacheConfig;
use LiteSpeed\Cache\Helper\CacheHelper as LSHelper;

/**
 * EsiItem — represents a single ESI block (type, params, content, inline/include tags).
 */
class EsiItem
{
    const ESI_RENDERWIDGET = 'rw';
    const ESI_CALLHOOK     = 'ch';
    const ESI_JSDEF        = 'js';
    const ESI_SMARTYFIELD  = 'mf';
    const ESI_TOKEN        = 'tk';
    const ESI_ENV          = 'env';
    const RES_FAILED       = '__LSC_RES_FAILED__';

    private $sdata;
    private $cdata;
    private $conf;
    private $esiInline = false;
    private $content;
    private $err;

    public function __construct(array $param, EsiModuleConfig $conf)
    {
        $this->conf = $conf;
        if (!$this->conf->isPrivate($param)) {
            $param['pub'] = 1;
        }
        $this->sdata = [
            'id'       => json_encode($param),
            'param'    => $param,
            'url'      => '',
            'inc'      => false,
            'inlStart' => false,
            'shared'   => null,
            'tag'      => implode(',', $conf->getTags()),
        ];
        $this->cdata = [
            'inlStart' => false,
        ];
    }

    public function getInfoLog(bool $simple = false): string
    {
        $params = implode('|', $this->sdata['param']);
        if ($simple) {
            return $params;
        }
        return $this->sdata['id'] . ': ' . $params . ' ' . $this->err;
    }

    public static function newFromSavedData(array $sdata): ?self
    {
        $conf = CacheConfig::getInstance()->getEsiModuleConf($sdata['param']['m']);
        if ($conf === null) {
            return null;
        }
        $item           = new self($sdata['param'], $conf);
        $item->sdata    = $sdata;
        $item->cdata['inlStart'] = $sdata['inlStart'];

        return $item;
    }

    public function getSavedData(): array
    {
        return $this->sdata;
    }

    public function getId(): string
    {
        return $this->sdata['id'];
    }

    public function getInclude()
    {
        return $this->sdata['inc'];
    }

    public function getInline()
    {
        return $this->esiInline;
    }

    public function setIncludeInlineTag($esiInclude, $inlineStart, string $url, $ttl): void
    {
        $this->cdata['inlStart'] = $inlineStart;
        if ($ttl > 0) {
            $this->sdata['inlStart'] = $inlineStart;
        }
        $this->sdata['inc'] = $esiInclude;
        $this->sdata['url'] = $url;
    }

    public function getConf(): EsiModuleConfig
    {
        return $this->conf;
    }

    public function getTagString(string $prefix): string
    {
        $tags = $this->getTags();
        $s = '';
        foreach ($tags as $tag) {
            if (strpos($tag, 'public:') !== false) {
                $s .= 'public:' . $prefix . substr($tag, 7) . ',';
            } else {
                $s .= $prefix . $tag . ',';
            }
        }
        return rtrim($s, ',');
    }

    public function getTags(): array
    {
        return $this->conf->getTags($this->sdata['param']);
    }

    public function getTTL()
    {
        return $this->conf->getTTL($this->sdata['param']);
    }

    public function isPrivate(): bool
    {
        return $this->conf->isPrivate($this->sdata['param']);
    }

    public function onlyCacheEmpty(): bool
    {
        return $this->conf->onlyCacheEmpty($this->sdata['param']);
    }

    public function ignoreEmptyContent(): bool
    {
        return $this->conf->ignoreEmptyContent($this->sdata['param']);
    }

    public function asVar(): bool
    {
        return $this->conf->asVar($this->sdata['param']);
    }

    public function noItemCache(): bool
    {
        return $this->conf->getNoItemCache($this->sdata['param']);
    }

    public function getParam(string $key = '')
    {
        if ($key === '') {
            return $this->sdata['param'];
        }
        return isset($this->sdata['param'][$key]) ? $this->sdata['param'][$key] : null;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = trim($content);

        if ($this->sdata['inlStart'] == false || $this->onlyCacheEmpty()) {
            if ($this->content === '' && $this->ignoreEmptyContent()) {
                $this->sdata['inc'] = '';
                return;
            }
            LSHelper::genEsiElements($this);
        }
        $this->esiInline = $this->cdata['inlStart'] . $this->content . '</esi:inline>';
    }

    public function setFailed(string $err = ''): void
    {
        $this->content = self::RES_FAILED;
        $this->err     = $err;
    }

    public function preRenderWidget(): void
    {
        $p = $this->sdata['param'];
        unset($p['pt'], $p['m'], $p['h']);
        foreach ($p as $k => $v) {
            $_GET[$k] = $v;
        }
    }

    /**
     * @return self|string  EsiItem on success, error string on failure
     */
    public static function decodeEsiUrl()
    {
        $err = $pd = $param = $conf = null;

        if (($pd = \Tools::getValue('pd')) == null) {
            $err = 'missing pd';
        } elseif (($param = json_decode($pd, true)) == null) {
            $err = 'invalid format in pd';
        } elseif (!isset($param['pt'])) {
            $err = 'missing pt';
        } elseif (!isset($param['m'])) {
            $err = 'missing m';
        } elseif (($conf = CacheConfig::getInstance()->getEsiModuleConf($param['m'])) == null) {
            $err = $param['m'] . ' is not found';
        } else {
            switch ($param['pt']) {
                case self::ESI_TOKEN:
                case self::ESI_ENV:
                    break;
                case self::ESI_JSDEF:
                    if (!isset($param['jsk'])) {
                        $err = 'missing jsk';
                    }
                    break;
                case self::ESI_RENDERWIDGET:
                    if (!isset($param['h'])) {
                        $err = 'missing h';
                    }
                    break;
                case self::ESI_CALLHOOK:
                    if (!isset($param['mt'])) {
                        $err = 'missing mt';
                    }
                    break;
                case self::ESI_SMARTYFIELD:
                    if (!isset($param['f']) || $param['f'] == '') {
                        $err = 'missing f';
                    } elseif ($param['f'] == 'widget_block' && !isset($param['t'])) {
                        $err = 'missing t';
                    }
                    break;
                default:
                    $err = 'pt invalid ' . $param['pt'];
            }
        }

        if ($err) {
            return $err . ' : ' . $pd;
        }

        return new self($param, $conf);
    }
}
