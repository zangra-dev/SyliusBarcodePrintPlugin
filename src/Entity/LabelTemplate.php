<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelTemplateInterface;
use Zangra\SyliusBarcodePrintPlugin\Validator\AllTokensDefined;
use Zangra\SyliusBarcodePrintPlugin\Validator\TemplateFileExists;

#[ORM\Entity]
#[ORM\Table(name: 'zangra_barcode_label_template')]
#[ORM\UniqueConstraint(name: 'uniq_zangra_barcode_label_template_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'zangra_barcode_print.template.code.unique')]
#[AllTokensDefined]
class LabelTemplate implements ResourceInterface, LabelTemplateInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.template.code.not_blank')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]*$/i', message: 'zangra_barcode_print.template.code.invalid')]
    protected ?string $code = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.template.name.not_blank')]
    #[Assert\Length(max: 255)]
    protected ?string $name = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'zangra_barcode_print.template.path.not_blank')]
    #[Assert\Length(max: 255)]
    #[TemplateFileExists]
    protected ?string $path = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /** @var Collection<int, LabelTemplateVariable> */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: LabelTemplateVariable::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    protected Collection $variables;

    public function __construct()
    {
        $this->variables = new ArrayCollection();
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

    public function getPath(): string
    {
        return (string) $this->path;
    }

    public function setPath(?string $path): void
    {
        $this->path = null === $path ? null : trim($path);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** @return Collection<int, LabelTemplateVariable> */
    public function getVariables(): Collection
    {
        return $this->variables;
    }

    public function addVariable(LabelTemplateVariable $variable): void
    {
        if (!$this->variables->contains($variable)) {
            $variable->setTemplate($this);
            $variable->setPosition($this->variables->count());
            $this->variables->add($variable);
        }
    }

    public function removeVariable(LabelTemplateVariable $variable): void
    {
        $this->variables->removeElement($variable);
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
