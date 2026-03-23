<?php
/**
 * LiteSpeed Cache for Prestashop.
 *
 * @author   LiteSpeed Technologies
 * @copyright  Copyright (c) 2017-2024 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace LiteSpeed\Cache\Form;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class ImportSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('import_file', FileType::class, [
                'label' => 'Configuration file',
                'label_attr' => ['class' => 'd-block text-left mb-2'],
                'attr' => [
                    'accept' => '.json',
                    'placeholder' => 'Choose file',
                ],
                'required' => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Import',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }
}
