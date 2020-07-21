<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2020 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

use LiteSpeedCacheLog as LSLog;
use LiteSpeedCacheConfig as Conf;

class LscPkAmp extends LscIntegration
{
    const NAME = 'pk_amp';

    protected function init()
    {
        $this->addPreDispatchAction($this);
        $this->addCacheableControllers($this->getPubControllers());
        $this->addInitCacheTagAction($this);
        return true;
    }
    
    protected function getPubControllers()
    {
        return [
            'pk_amphomeModuleFrontController' => LiteSpeedCacheConfig::TAG_HOME, // controller name - tag linked to it
            'pk_ampproductController' => '',
            'pk_ampproductModuleFrontController' => '',
            'pk_ampcategoryModuleFrontController' => '',
            'pk_ampcmsModuleFrontController' => '',
            'pk_ampmanufacturerModuleFrontController' => '',
            'pk_ampsupplierModuleFrontController' => '',
            'pk_ampsearchModuleFrontController' => LiteSpeedCacheConfig::TAG_SEARCH,
            'pk_ampbestsalesModuleFrontController' => LiteSpeedCacheConfig::TAG_SEARCH,
            'pk_ampnewProductsModuleFrontController' => LiteSpeedCacheConfig::TAG_SEARCH,
            'pk_amppricesdropModuleFrontController' => LiteSpeedCacheConfig::TAG_SEARCH,
            'pk_ampsitemapModuleFrontController' => LiteSpeedCacheConfig::TAG_SITEMAP,
            'pk_ampstoresModuleFrontController' => LiteSpeedCacheConfig::TAG_STORES,
            'pk_amp404ModuleFrontController' => LiteSpeedCacheConfig::TAG_404,
        ];
    }
    
    protected function actionPreDispatch()
    {
        $mtb = '';
        $context = Context::getContext();
        $mtb .= $context->isMobile() ? '1' : '0';
        $mtb .= $context->isTablet() ? '1' : '0';
        //$isbot = Module::getInstanceByName(self::NAME)->is_bot();
        $isbot = $this->is_bot();
        $mtb .= $isbot ? '1' : '0';

        if (_LITESPEED_DEBUG_ >= LSLog::LEVEL_ENVCOOKIE_DETAIL) {
            LSLog::log(__FUNCTION__ . 'LscPkAmp setAmpVary=' . $mtb, LSLog::LEVEL_ENVCOOKIE_DETAIL);
        }
        LiteSpeedCacheVaryCookie::setAmpVary($mtb);
    }
    
    /* Please make sure this function is exactly same as Pk_Amp->is_bot(). 
     * If mismatch, it will cache wrong copy */
    public function is_bot()
    {
        $bots = array(
            'Googlebot', 
            'Baiduspider', 
            'ia_archiver',
            'R6_FeedFetcher', 
            'NetcraftSurveyAgent', 
            'Sogou web spider',
            'bingbot', 
            'Yahoo! Slurp', 
            'facebookexternalhit', 
            'PrintfulBot',
            'msnbot', 
            'Twitterbot', 
            'UnwindFetchor',
            'urlresolver',
            'Butterfly', 
            'TweetmemeBot');
     
       foreach($bots as $b){
          if( stripos( $_SERVER['HTTP_USER_AGENT'], $b ) !== false ) return true;
       }
       return false;
    }    
    
    protected function initCacheTagsByController($params)
    {
        $controller = $params['controller'];
        $pagename = $controller->getPageName();
        if (strpos($pagename, 'module-pk_amp-') === false) {
            return null;
        }
        $pagename = substr($pagename, 14);
        switch ($pagename) {
            case 'category':
                $id = Tools::getValue('id_category');
                if ($id) {
                    return Conf::TAG_PREFIX_CATEGORY . $id;
                }
                break;
            case 'manufacturer':
                $id = Tools::getValue('id_manufacturer');
                if ($id) {
                    return Conf::TAG_PREFIX_MANUFACTURER . $id;
                }
                break;
            case 'supplier':
                $id = Tools::getValue('id_supplier');
                if ($id) {
                    $tag = Conf::TAG_PREFIX_SUPPLIER . $id;
                }
                break;
            case 'cms':
                $id = Tools::getValue('id_cms');
                if ($id) {
                    return Conf::TAG_PREFIX_CMS . $id;
                }
                break;
        }

        return null;
    }
    
    
}

LscPkAmp::register();
