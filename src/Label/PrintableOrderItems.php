<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Turns an order into the printable rows of the batch print dialog: the order items whose variant has an EAN.
 */
final class PrintableOrderItems
{
    /** Template variable keys that are always taken from the order item itself. */
    private const RESERVED_KEYS = ['code', 'ean13'];

    /** @param iterable<PrintableItemVariablesProviderInterface> $providers highest priority first */
    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly string $eanPropertyPath,
        private readonly iterable $providers = [],
    ) {
    }

    /** @return list<array{code: string, ean: string, quantity: int, variables: array<string, string|list<string>>}> */
    public function forOrder(OrderInterface $order): array
    {
        $rows = [];
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();
            if (null === $variant) {
                continue;
            }

            $code = trim((string) $variant->getCode());
            $ean = $this->ean($variant);
            if ('' === $code || '' === $ean) {
                continue;
            }

            $variables = [];
            foreach ($this->providers as $provider) {
                // The first provider (highest priority) wins when several supply the same key.
                $variables += self::sanitizeVariables($provider->provide($item));
            }

            $rows[] = [
                'code' => $code,
                'ean' => $ean,
                'quantity' => max(1, $item->getQuantity()),
                'variables' => $variables,
            ];
        }

        return $rows;
    }

    /**
     * Keeps the string / list of strings values and drops the reserved keys.
     *
     * @param array<array-key, mixed> $variables
     *
     * @return array<string, string|list<string>>
     */
    public static function sanitizeVariables(array $variables): array
    {
        $sanitized = [];
        foreach ($variables as $key => $value) {
            $key = (string) $key;
            if (\in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }

            if (\is_string($value)) {
                $sanitized[$key] = $value;
            } elseif (\is_array($value) && array_is_list($value)) {
                $strings = array_values(array_filter($value, \is_string(...)));
                if (\count($strings) === \count($value)) {
                    $sanitized[$key] = $strings;
                }
            }
        }

        return $sanitized;
    }

    private function ean(object $variant): string
    {
        try {
            $ean = $this->propertyAccessor->getValue($variant, $this->eanPropertyPath);
        } catch (PropertyAccessException) {
            return '';
        }

        return \is_string($ean) || \is_int($ean) || $ean instanceof \Stringable ? trim((string) $ean) : '';
    }
}
