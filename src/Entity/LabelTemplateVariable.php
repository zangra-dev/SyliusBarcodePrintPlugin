<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelTemplateVariableInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\Ean13;

#[ORM\Entity]
#[ORM\Table(name: 'zangra_barcode_label_template_variable')]
class LabelTemplateVariable implements ResourceInterface, LabelTemplateVariableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LabelTemplate::class, inversedBy: 'variables')]
    #[ORM\JoinColumn(name: 'template_id', nullable: false, onDelete: 'CASCADE')]
    protected ?LabelTemplate $template = null;

    #[ORM\Column(name: 'variable_key', type: 'string', length: 64)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.variable.key.not_blank')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^[A-Za-z_][A-Za-z0-9_]*$/', message: 'zangra_barcode_print.variable.key.invalid')]
    #[Assert\NotEqualTo(value: 'quantity', message: 'zangra_barcode_print.variable.key.reserved')]
    protected ?string $key = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.variable.label.not_blank')]
    #[Assert\Length(max: 255)]
    protected ?string $label = null;

    #[ORM\Column(type: 'string', length: 16, enumType: VariableType::class)]
    protected VariableType $type = VariableType::Text;

    #[ORM\Column(name: 'default_value', type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $defaultValue = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $required = false;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?LabelTemplate
    {
        return $this->template;
    }

    public function setTemplate(?LabelTemplate $template): void
    {
        $this->template = $template;
    }

    public function getKey(): string
    {
        return (string) $this->key;
    }

    public function setKey(?string $key): void
    {
        $this->key = null === $key ? null : trim($key);
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getType(): VariableType
    {
        return $this->type;
    }

    public function setType(VariableType $type): void
    {
        $this->type = $type;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): void
    {
        $this->defaultValue = '' === $defaultValue ? null : $defaultValue;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }

    #[Assert\Callback]
    public function validateDefaultValue(ExecutionContextInterface $context): void
    {
        if (VariableType::Ean13 === $this->type && null !== $this->defaultValue && !Ean13::isValid($this->defaultValue)) {
            $context->buildViolation('zangra_barcode_print.variable.default_value.invalid_ean13')
                ->atPath('defaultValue')
                ->addViolation()
            ;
        }
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
