<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

use Zangra\SyliusBarcodePrintPlugin\Label\Exception\InvalidLabelValuesException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\LabelException;

final class LabelRenderer
{
    public const TOKEN_QUANTITY = 'quantity';

    public const MIN_QUANTITY = 1;

    public const MAX_QUANTITY = 999;

    private const TOKEN_PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    public function __construct(
        private readonly TemplateFileLocator $locator,
        private readonly IconRegistry $icons,
        private readonly int $iconStartX = 275,
        private readonly int $iconY = 102,
    ) {
    }

    /**
     * @param array<string, string|int|list<string>|null> $values
     *
     * @throws InvalidLabelValuesException when the quantity or a value is invalid
     * @throws LabelException              when the template cannot be loaded or uses an undefined token
     */
    public function render(LabelTemplateInterface $template, array $values, int $quantity): string
    {
        $zpl = $this->locator->read($template->getPath());

        $errors = [];
        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            $errors[self::TOKEN_QUANTITY] = sprintf('Quantity must be between %d and %d.', self::MIN_QUANTITY, self::MAX_QUANTITY);
        }

        $replacements = [self::TOKEN_QUANTITY => (string) $quantity];
        $defined = [self::TOKEN_QUANTITY];
        foreach ($template->getVariables() as $variable) {
            $key = $variable->getKey();
            $defined[] = $key;

            try {
                $replacements[$key] = $this->resolve($variable, $values[$key] ?? null);
            } catch (\InvalidArgumentException $exception) {
                $errors[$key] = $exception->getMessage();
            }
        }

        $undefined = array_diff($this->extractTokens($zpl), $defined);
        if ([] !== $undefined) {
            throw new LabelException(sprintf('Template "%s" uses undefined variable(s): %s.', $template->getPath(), implode(', ', $undefined)));
        }

        if ([] !== $errors) {
            throw new InvalidLabelValuesException($errors);
        }

        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static fn (array $match): string => $replacements[$match[1]],
            $zpl,
        );
    }

    /** @return list<string> unique token names used by the given ZPL */
    public function extractTokens(string $zpl): array
    {
        preg_match_all(self::TOKEN_PATTERN, $zpl, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param string|int|list<string>|null $value */
    private function resolve(LabelTemplateVariableInterface $variable, string|int|array|null $value): string
    {
        // An explicit empty icon selection means "no icons"; for any other value, empty means "use the default".
        $useDefault = null === $value || (VariableType::Icons !== $variable->getType() && ('' === $value || [] === $value));
        if ($useDefault) {
            $value = $variable->getDefaultValue() ?? '';
        }

        if ($variable->getType() === VariableType::Icons) {
            $resolved = $this->renderIcons($this->toIconKeys($value));
        } else {
            if (\is_array($value)) {
                throw new \InvalidArgumentException('A single value is expected.');
            }

            $resolved = self::sanitize((string) $value);
            if ($variable->getType() === VariableType::Ean13 && '' !== $resolved && !Ean13::isValid($resolved)) {
                throw new \InvalidArgumentException('Invalid EAN13: it must be 13 digits with a valid check digit.');
            }
        }

        if ('' === $resolved && $variable->isRequired()) {
            throw new \InvalidArgumentException('This value is required.');
        }

        return $resolved;
    }

    /**
     * @param string|int|list<string> $value
     *
     * @return list<string>
     */
    private function toIconKeys(string|int|array $value): array
    {
        $keys = \is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(static fn (mixed $key): string => strtolower(trim((string) $key)), $keys), static fn (string $key): bool => '' !== $key));
    }

    /** @param list<string> $keys */
    private function renderIcons(array $keys): string
    {
        $x = $this->iconStartX;
        $blocks = [];
        foreach ($keys as $key) {
            if (!$this->icons->has($key)) {
                throw new \InvalidArgumentException(sprintf('Unknown icon "%s".', $key));
            }

            $blocks[] = $this->icons->snippet($key, $x, $this->iconY);
            $x += $this->icons->width($key);
        }

        return implode("\n", $blocks);
    }

    /** Removes the ZPL command characters (^ and ~) and all control characters. */
    private static function sanitize(string $value): string
    {
        return trim((string) preg_replace('/[\^~\x00-\x1F\x7F]+/', '', $value));
    }
}
