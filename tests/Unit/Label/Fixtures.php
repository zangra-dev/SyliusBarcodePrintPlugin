<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label;

use Zangra\SyliusBarcodePrintPlugin\Label\LabelTemplateInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelTemplateVariableInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

final class Fixtures
{
    public static function labelsDirectory(): string
    {
        return \dirname(__DIR__, 3) . '/resources/labels';
    }

    public static function cableTemplate(string $path = 'cable-label.zpl'): LabelTemplateInterface
    {
        return self::template($path, [
            self::variable('code', VariableType::Text, null, true),
            self::variable('ean13', VariableType::Ean13, null, true),
            self::variable('techInfo', VariableType::Text, '2 core wires (0.75mm2) IP20'),
            self::variable('madeIn', VariableType::Text, 'Made in Italy'),
            self::variable('icons', VariableType::Icons, 'ce,weee'),
            self::variable('footer', VariableType::Text, 'www.zangra.com Violetstraat 29, 2060 Antwerp, Belgium'),
        ]);
    }

    /** @param list<LabelTemplateVariableInterface> $variables */
    public static function template(string $path, array $variables): LabelTemplateInterface
    {
        return new class($path, $variables) implements LabelTemplateInterface {
            /** @param list<LabelTemplateVariableInterface> $variables */
            public function __construct(private readonly string $path, private readonly array $variables)
            {
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getVariables(): iterable
            {
                return $this->variables;
            }
        };
    }

    public static function variable(string $key, VariableType $type, ?string $default = null, bool $required = false): LabelTemplateVariableInterface
    {
        return new class($key, $type, $default, $required) implements LabelTemplateVariableInterface {
            public function __construct(
                private readonly string $key,
                private readonly VariableType $type,
                private readonly ?string $default,
                private readonly bool $required,
            ) {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getType(): VariableType
            {
                return $this->type;
            }

            public function getDefaultValue(): ?string
            {
                return $this->default;
            }

            public function isRequired(): bool
            {
                return $this->required;
            }
        };
    }
}
