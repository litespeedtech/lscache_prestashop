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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
namespace LiteSpeed\Cache;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Warmup extends Command
{
    protected $output;

    protected function configure()
    {
        // The name of the command (the part after "bin/console")
        $this->setName('litespeedcache:warmup')
             ->setDescription('warm up litespeed cache for a given sitemap xml')
             ->addArgument('sitemap', null, "Sitemap xml url");
    }

    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $sitemap = $input->getArgument("sitemap");
        if($sitemap==null){
            $output->writeln('<error>Invalid or Missing Sitemap xml url!<error>');
            return;
        }

        $xml = simplexml_load_file($sitemap);
        $urls = [];

        foreach ($xml->url as $url_item) {
            $urls[] = (string)$url_item->loc;  
        }

        if(empty($urls)){
            $output->writeln('<error>No urls found!<error>');
            return;
        }

        $this->crawlUrls($urls);
    }


    private function crawlUrls($urls) {
        set_time_limit(0);
        $acceptCode = array(200, 201);
        $total = count($urls);
        $current = 0;

        foreach ($urls as $url) {
            $current++;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'lscache_runner');
            curl_setopt($ch, CURLOPT_ENCODING, "gzip");
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            
            $buffer = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (in_array($httpcode, $acceptCode)) {
                $this->output->writeln($current . '/'. $total . ' Warm up:    ' . $url . "    success! " . $httpcode);
            } else if($httpcode==428){
                $this->output->writeln("Web Server crawler feature not enabled, please check https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:enabling_the_crawler");
                break;
            } else {
                $this->output->writeln($current . '/'. $total . ' Warm up:    ' . $url . "    failed! " . $httpcode);
            }
        }
    }    
}