<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Zangra\SyliusBarcodePrintPlugin\Label\PrintableItemVariablesProviderInterface;
use Zangra\SyliusBarcodePrintPlugin\Label\PrintableOrderItems;

final class PrintableOrderItemsTest extends TestCase
{
    public function testItemsWithAnEanAreReturnedWithCodeEanAndQuantity(): void
    {
        $order = $this->order([
            $this->item('abc.001', '5415249002848', 4),
            $this->item('abc.002', '4006381333931', 0),
        ]);

        self::assertSame([
            ['code' => 'abc.001', 'ean' => '5415249002848', 'quantity' => 4, 'variables' => []],
            ['code' => 'abc.002', 'ean' => '4006381333931', 'quantity' => 1, 'variables' => []],
        ], $this->service()->forOrder($order));
    }

    public function testItemsWithoutPrintableDataAreSkipped(): void
    {
        $withoutVariant = $this->createMock(OrderItemInterface::class);
        $withoutVariant->method('getVariant')->willReturn(null);

        $order = $this->order([
            $withoutVariant,
            $this->item('no.ean', null, 1),
            $this->item('empty.ean', '  ', 1),
            $this->item('', '5415249002848', 1),
            $this->item('ok', ' 5415249002848 ', 2),
        ]);

        self::assertSame([['code' => 'ok', 'ean' => '5415249002848', 'quantity' => 2, 'variables' => []]], $this->service()->forOrder($order));
    }

    public function testAnUnreadablePropertyPathSkipsEveryItem(): void
    {
        $order = $this->order([$this->item('abc.001', '5415249002848', 1)]);

        self::assertSame([], $this->service('doesNotExist')->forOrder($order));
    }

    public function testProviderVariablesAreMergedAndFiltered(): void
    {
        $high = $this->provider(['madeIn' => 'Belgium', 'code' => 'hacked', 'ean13' => '0', 'footer' => 'high', 'invalid' => 12]);
        $low = $this->provider(['footer' => 'low', 'icons' => ['ce', 'weee'], 'mixed' => ['ce', 3]]);
        $empty = $this->provider([]);

        $rows = $this->service('ean', [$high, $empty, $low])->forOrder($this->order([$this->item('abc.001', '5415249002848', 1)]));

        self::assertSame(['madeIn' => 'Belgium', 'footer' => 'high', 'icons' => ['ce', 'weee']], $rows[0]['variables']);
    }

    /** @param iterable<PrintableItemVariablesProviderInterface> $providers */
    private function service(string $path = 'ean', iterable $providers = []): PrintableOrderItems
    {
        return new PrintableOrderItems(PropertyAccess::createPropertyAccessor(), $path, $providers);
    }

    /** @param list<OrderItemInterface> $items */
    private function order(array $items): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn(new ArrayCollection($items));

        return $order;
    }

    private function item(string $code, ?string $ean, int $quantity): OrderItemInterface
    {
        $variant = new class() extends ProductVariant {
            public ?string $ean = null;
        };
        $variant->setCode($code);
        $variant->ean = $ean;

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getVariant')->willReturn($variant);
        $item->method('getQuantity')->willReturn($quantity);

        return $item;
    }

    /** @param array<string, mixed> $variables */
    private function provider(array $variables): PrintableItemVariablesProviderInterface
    {
        return new class($variables) implements PrintableItemVariablesProviderInterface {
            /** @param array<string, mixed> $variables */
            public function __construct(private readonly array $variables)
            {
            }

            public function provide(OrderItemInterface $item): array
            {
                return $this->variables; // @phpstan-ignore-line
            }
        };
    }
}
