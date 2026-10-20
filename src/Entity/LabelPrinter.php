<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\Transport\PrinterTransport;

#[ORM\Entity]
#[ORM\Table(name: 'zangra_barcode_label_printer')]
#[ORM\UniqueConstraint(name: 'uniq_zangra_barcode_label_printer_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'zangra_barcode_print.printer.code.unique')]
class LabelPrinter implements ResourceInterface
{
    public const DEFAULT_AGENT_URL = 'http://127.0.0.1:9101';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.printer.code.not_blank')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/i', message: 'zangra_barcode_print.printer.code.invalid')]
    protected ?string $code = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.printer.name.not_blank')]
    #[Assert\Length(max: 255)]
    protected ?string $name = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.printer.host.not_blank')]
    #[Assert\Length(max: 255)]
    protected ?string $host = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 1, max: 65535)]
    protected int $port = 9100;

    #[ORM\Column(type: 'string', length: 32, enumType: PrinterTransport::class)]
    protected PrinterTransport $transport = PrinterTransport::LocalAgent;

    #[ORM\Column(name: 'agent_url', type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.printer.agent_url.not_blank')]
    protected string $agentUrl = self::DEFAULT_AGENT_URL;

    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /**
     * Equivalent of Assert\Url(requireTld: false, protocols: ['http', 'https']) without the `requireTld`
     * option, which only exists since Symfony 7.1 (keeps compatibility with Symfony 6.4).
     */
    #[Assert\Callback]
    public function validateAgentUrl(ExecutionContextInterface $context): void
    {
        $url = $this->agentUrl;
        if ('' === $url) {
            return;
        }

        $parts = parse_url($url);
        $valid = false !== filter_var($url, \FILTER_VALIDATE_URL)
            && \is_array($parts)
            && \in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && '' !== (string) ($parts['host'] ?? '');

        if (!$valid) {
            $context->buildViolation('This value is not a valid URL.')
                ->setTranslationDomain('validators')
                ->atPath('agentUrl')
                ->setCode(Url::INVALID_URL_ERROR)
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): void
    {
        $this->host = null === $host ? null : trim($host);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getTransport(): PrinterTransport
    {
        return $this->transport;
    }

    public function setTransport(PrinterTransport $transport): void
    {
        $this->transport = $transport;
    }

    public function getAgentUrl(): string
    {
        return $this->agentUrl;
    }

    public function setAgentUrl(?string $agentUrl): void
    {
        $this->agentUrl = rtrim((string) $agentUrl, '/');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
