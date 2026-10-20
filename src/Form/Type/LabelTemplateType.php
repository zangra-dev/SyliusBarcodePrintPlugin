<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;

final class LabelTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'zangra_barcode_print.form.template.code',
                'disabled' => $options['data'] instanceof LabelTemplate && null !== $options['data']->getId(),
            ])
            ->add('name', TextType::class, [
                'label' => 'zangra_barcode_print.form.template.name',
            ])
            ->add('path', TextType::class, [
                'label' => 'zangra_barcode_print.form.template.path',
                'help' => 'zangra_barcode_print.form.template.path_help',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'zangra_barcode_print.form.enabled',
                'required' => false,
            ])
            ->add('variables', LiveCollectionType::class, [
                'entry_type' => LabelTemplateVariableType::class,
                'label' => false,
                'button_add_options' => [
                    'label' => 'zangra_barcode_print.form.template.add_variable',
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'delete_empty' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelTemplate::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'zangra_barcode_print_label_template';
    }
}
