<?php
/**
 * LiteSpeed Cache — Adds Redis option to the Performance > Caching form.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 *
 * Standard Symfony Form Extension: extends the core CachingType without
 * modifying core files. Registered via services.yml tag.
 */

namespace LiteSpeed\Cache\Form\Extension;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Form\Admin\AdvancedParameters\Performance\CachingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

class CachingTypeExtension extends AbstractTypeExtension
{
    private static $extensionsList = [
        'CacheMemcache' => ['memcache'],
        'CacheMemcached' => ['memcached'],
        'CacheApc' => ['apc', 'apcu'],
        'CacheXcache' => ['xcache'],
        'CacheRedis' => ['redis'],
    ];

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$builder->has('caching_system')) {
            return;
        }

        $field = $builder->get('caching_system');
        $fOpts = $field->getOptions();

        // Add Redis to choices
        $fOpts['choices']['Redis'] = 'CacheRedis';

        // Replace closures that reference the original extensionsList
        $extList = self::$extensionsList;

        $fOpts['choice_label'] = static function ($value, $key, $index) use ($extList) {
            if (!isset($extList[$index])) {
                return $value;
            }
            $disabled = false;
            foreach ($extList[$index] as $ext) {
                if (extension_loaded($ext)) {
                    $disabled = false;
                    break;
                }
                $disabled = true;
            }
            if ($disabled) {
                return $value . ' (extension not available)';
            }

            return $value;
        };

        $fOpts['choice_attr'] = static function ($value, $key, $index) use ($extList) {
            if (!isset($extList[$index])) {
                return [];
            }
            $disabled = false;
            foreach ($extList[$index] as $ext) {
                if (extension_loaded($ext)) {
                    $disabled = false;
                    break;
                }
                $disabled = true;
            }

            return $disabled ? ['disabled' => true] : [];
        };

        $builder->add('caching_system', ChoiceType::class, $fOpts);
    }

    /**
     * {@inheritdoc}
     */
    public static function getExtendedTypes(): iterable
    {
        return [CachingType::class];
    }
}
