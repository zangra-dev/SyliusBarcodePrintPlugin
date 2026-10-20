<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\PrinterTransport;

final class LabelPrinterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'zangra_barcode_print.form.printer.code',
                'disabled' => $options['data'] instanceof LabelPrinter && null !== $options['data']->getId(),
            ])
            ->add('name', TextType::class, [
                'label' => 'zangra_barcode_print.form.printer.name',
            ])
            ->add('host', TextType::class, [
                'label' => 'zangra_barcode_print.form.printer.host',
                'help' => 'zangra_barcode_print.form.printer.host_help',
            ])
            ->add('port', IntegerType::class, [
                'label' => 'zangra_barcode_print.form.printer.port',
                'empty_data' => '9100',
            ])
            ->add('transport', EnumType::class, [
                'class' => PrinterTransport::class,
                'label' => 'zangra_barcode_print.form.printer.transport',
                'choice_label' => static fn (PrinterTransport $transport): string => 'zangra_barcode_print.transport.' . $transport->value,
                'help' => 'zangra_barcode_print.form.printer.transport_help',
            ])
            ->add('agentUrl', UrlType::class, [
                'label' => 'zangra_barcode_print.form.printer.agent_url',
                'default_protocol' => null,
                'empty_data' => LabelPrinter::DEFAULT_AGENT_URL,
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'zangra_barcode_print.form.enabled',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelPrinter::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'zangra_barcode_print_label_printer';
    }
}
