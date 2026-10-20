<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplateVariable;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\PrinterTransport;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

/** Seeds the example "cable" template (cable-label.zpl) and one printer. Idempotent. */
#[AsCommand(name: 'zangra:barcode-print:install-example', description: 'Create the example cable label template and a default printer if they do not exist.')]
final class InstallExampleCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('printer-host', null, InputOption::VALUE_REQUIRED, 'Printer host or IP', '172.16.0.22')
            ->addOption('printer-port', null, InputOption::VALUE_REQUIRED, 'Printer TCP port', '9100')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Printer transport (local_agent or server_tcp)', PrinterTransport::LocalAgent->value)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $transport = PrinterTransport::tryFrom((string) $input->getOption('transport'));
        if (null === $transport) {
            $io->error('Unknown transport.');

            return Command::INVALID;
        }

        if (null === $this->entityManager->getRepository(LabelTemplate::class)->findOneBy(['code' => 'cable'])) {
            $template = new LabelTemplate();
            $template->setCode('cable');
            $template->setName('Cable label (50.8 x 25.4 mm)');
            $template->setPath('cable-label.zpl');

            $position = 0;
            foreach ([
                ['code', 'Code', VariableType::Text, null, true],
                ['ean13', 'EAN13', VariableType::Ean13, null, true],
                ['techInfo', 'Technical info', VariableType::Text, '', false],
                ['madeIn', 'Made in', VariableType::Text, 'Made in Italy', false],
                ['icons', 'Icons', VariableType::Icons, 'ce,weee', false],
                ['footer', 'Footer', VariableType::Text, 'www.zangra.com Violetstraat 29, 2060 Antwerp, Belgium', false],
            ] as [$key, $label, $type, $default, $required]) {
                $variable = new LabelTemplateVariable();
                $variable->setKey($key);
                $variable->setLabel($label);
                $variable->setType($type);
                $variable->setDefaultValue($default);
                $variable->setRequired($required);
                $variable->setPosition($position++);
                $template->addVariable($variable);
            }
            $this->entityManager->persist($template);
            $io->success('Template "cable" created.');
        } else {
            $io->note('Template "cable" already exists.');
        }

        if (null === $this->entityManager->getRepository(LabelPrinter::class)->findOneBy(['code' => 'tsc-atelier'])) {
            $printer = new LabelPrinter();
            $printer->setCode('tsc-atelier');
            $printer->setName('TSC TTP-2410MU (atelier)');
            $printer->setHost((string) $input->getOption('printer-host'));
            $printer->setPort((int) $input->getOption('printer-port'));
            $printer->setTransport($transport);
            $this->entityManager->persist($printer);
            $io->success('Printer "tsc-atelier" created.');
        } else {
            $io->note('Printer "tsc-atelier" already exists.');
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
