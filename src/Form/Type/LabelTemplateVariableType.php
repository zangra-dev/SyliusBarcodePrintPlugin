<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplateVariable;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

final class LabelTemplateVariableType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('key', TextType::class, [
                'label' => 'zangra_barcode_print.form.variable.key',
            ])
            ->add('label', TextType::class, [
                'label' => 'zangra_barcode_print.form.variable.label',
            ])
            ->add('type', EnumType::class, [
                'class' => VariableType::class,
                'label' => 'zangra_barcode_print.form.variable.type',
                'empty_data' => VariableType::Text->value,
                'choice_label' => static fn (VariableType $type): string => 'zangra_barcode_print.variable_type.' . $type->value,
            ])
            ->add('defaultValue', TextType::class, [
                'label' => 'zangra_barcode_print.form.variable.default_value',
                'required' => false,
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'zangra_barcode_print.form.variable.required',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelTemplateVariable::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'zangra_barcode_print_label_template_variable';
    }
}
