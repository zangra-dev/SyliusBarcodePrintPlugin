<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\InvalidLabelValuesException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\LabelException;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TemplateNotFoundException;
use Zangra\SyliusBarcodePrintPlugin\Label\IconRegistry;
use Zangra\SyliusBarcodePrintPlugin\Label\LabelRenderer;
use Zangra\SyliusBarcodePrintPlugin\Label\TemplateFileLocator;
use Zangra\SyliusBarcodePrintPlugin\Label\VariableType;

final class LabelRendererTest extends TestCase
{
    private const SAMPLE = [
        'code' => 'textilecable.031.001',
        'ean13' => '5415249002848',
        'techInfo' => '2 core wires (0.75mm2) IP20',
        'madeIn' => 'Made in Italy',
        'icons' => ['ce', 'weee'],
        'footer' => 'www.zangra.com Violetstraat 29, 2060 Antwerp, Belgium',
    ];

    private LabelRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new LabelRenderer(new TemplateFileLocator(Fixtures::labelsDirectory()), new IconRegistry());
    }

    public function testItMatchesTheVerifiedTestLabelExceptForQuantityAndIconOffsets(): void
    {
        $zpl = $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, 1);

        $golden = (string) file_get_contents(__DIR__ . '/../fixtures/test-label.zpl');

        self::assertSame(self::absolutePositions($golden), self::absolutePositions($zpl));
    }

    public function testItIsExactlyTheTestLabelWhenIconsAreMadeAbsolute(): void
    {
        $zpl = $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, 1);

        // Only the ^LH wrapping of the icons differs from the verified label.
        self::assertStringContainsString("^LH275,102\n^FO0,0\n^A0N,18,18\n^FDCE^FS\n^LH0,0\n^LH308,102\n^FO0,1\n", $zpl);
        self::assertStringContainsString("\n^PQ1,0,1,N\n^XZ\n", $zpl);
    }

    public function testItAppliesQuantity(): void
    {
        $zpl = $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, 25);

        self::assertStringContainsString("^PQ25,0,1,N\n", $zpl);
    }

    public function testItFallsBackToDefaultsForMissingValues(): void
    {
        $zpl = $this->renderer->render(Fixtures::cableTemplate(), ['code' => 'abc', 'ean13' => '5415249002848'], 1);

        self::assertStringContainsString('^FD2 core wires (0.75mm2) IP20^FS', $zpl);
        self::assertStringContainsString('^FDMade in Italy^FS', $zpl);
        self::assertStringContainsString('^FDCE^FS', $zpl);
        self::assertStringContainsString('^FDwww.zangra.com Violetstraat 29, 2060 Antwerp, Belgium^FS', $zpl);
    }

    public function testItRendersOnlyTheSelectedIcons(): void
    {
        $onlyWeee = $this->renderer->render(Fixtures::cableTemplate(), [...self::SAMPLE, 'icons' => ['weee']], 1);
        self::assertStringNotContainsString('^FDCE^FS', $onlyWeee);
        self::assertStringContainsString("^LH275,102\n^FO0,1\n", $onlyWeee);

        $none = $this->renderer->render(Fixtures::cableTemplate(), [...self::SAMPLE, 'icons' => []], 1);
        self::assertStringNotContainsString('^LH275', $none);
    }

    public function testEmptyIconSelectionWithoutDefaultRendersNoIcons(): void
    {
        $template = Fixtures::template('cable-label.zpl', [
            Fixtures::variable('code', VariableType::Text),
            Fixtures::variable('ean13', VariableType::Text),
            Fixtures::variable('techInfo', VariableType::Text),
            Fixtures::variable('madeIn', VariableType::Text),
            Fixtures::variable('icons', VariableType::Icons),
            Fixtures::variable('footer', VariableType::Text),
        ]);

        $zpl = $this->renderer->render($template, ['icons' => ''], 1);

        self::assertStringNotContainsString('^LH275', $zpl);
    }

    public function testItAcceptsIconsAsCommaSeparatedString(): void
    {
        $zpl = $this->renderer->render(Fixtures::cableTemplate(), [...self::SAMPLE, 'icons' => 'CE, weee'], 1);

        self::assertStringContainsString('^FDCE^FS', $zpl);
        self::assertStringContainsString('^LH308,102', $zpl);
    }

    public function testItStripsZplControlCharactersFromTextValues(): void
    {
        $zpl = $this->renderer->render(
            Fixtures::cableTemplate(),
            [...self::SAMPLE, 'code' => "ab^FS^XZ~DY\nc\r\td\x00e", 'madeIn' => '{{footer}}'],
            1,
        );

        self::assertStringContainsString('^FDabFSXZDYcde^FS', $zpl);
        // values are never re-expanded
        self::assertStringContainsString('^FD{{footer}}^FS', $zpl);
        self::assertSame(1, substr_count($zpl, '^XZ'));
        self::assertStringNotContainsString('~', $zpl);
    }

    public function testItRejectsAnInvalidEan13(): void
    {
        $this->expectValidationErrors(['ean13'], fn () => $this->renderer->render(Fixtures::cableTemplate(), [...self::SAMPLE, 'ean13' => '5415249002849'], 1));
    }

    public function testItRequiresRequiredValues(): void
    {
        $this->expectValidationErrors(['code', 'ean13'], fn () => $this->renderer->render(Fixtures::cableTemplate(), [], 1));
    }

    public function testItRejectsUnknownIcons(): void
    {
        $this->expectValidationErrors(['icons'], fn () => $this->renderer->render(Fixtures::cableTemplate(), [...self::SAMPLE, 'icons' => ['ce', 'nope']], 1));
    }

    #[DataProvider('quantities')]
    public function testItRejectsAnOutOfRangeQuantity(int $quantity): void
    {
        $this->expectValidationErrors(['quantity'], fn () => $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, $quantity));
    }

    /** @return iterable<string, array{int}> */
    public static function quantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too many' => [1000];
    }

    public function testItAcceptsQuantityBounds(): void
    {
        self::assertStringContainsString('^PQ1,', $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, 1));
        self::assertStringContainsString('^PQ999,', $this->renderer->render(Fixtures::cableTemplate(), self::SAMPLE, 999));
    }

    public function testItFailsOnTokensWithoutVariable(): void
    {
        $template = Fixtures::template('cable-label.zpl', [Fixtures::variable('code', VariableType::Text)]);

        try {
            $this->renderer->render($template, ['code' => 'x'], 1);
            self::fail('Expected an exception.');
        } catch (LabelException $exception) {
            self::assertStringContainsString('ean13', $exception->getMessage());
            self::assertNotInstanceOf(InvalidLabelValuesException::class, $exception);
        }
    }

    public function testItRejectsTemplatePathsOutsideTheTemplatesDirectory(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        $this->renderer->render(Fixtures::cableTemplate('../composer.json'), self::SAMPLE, 1);
    }

    public function testItExtractsTokens(): void
    {
        self::assertSame(['a', 'b'], $this->renderer->extractTokens('x {{a}} {{ b }} {{a}} {c} {{1x}}'));
    }

    /** @param list<string> $fields */
    private function expectValidationErrors(array $fields, callable $call): void
    {
        try {
            $call();
            self::fail('Expected an InvalidLabelValuesException.');
        } catch (InvalidLabelValuesException $exception) {
            self::assertSame($fields, array_keys($exception->getErrors()));
        }
    }

    /**
     * Resolves ^LH offsets into absolute ^FO positions and ignores the ^PQ line,
     * so the output can be compared with the verified test label.
     *
     * @return list<string>
     */
    private static function absolutePositions(string $zpl): array
    {
        $offsetX = $offsetY = 0;
        $lines = [];
        foreach (explode("\n", trim($zpl)) as $line) {
            if (1 === preg_match('/^\^LH(\d+),(\d+)$/', $line, $match)) {
                [$offsetX, $offsetY] = [(int) $match[1], (int) $match[2]];

                continue;
            }
            if (1 === preg_match('/^\^FO(\d+),(\d+)$/', $line, $match)) {
                $line = sprintf('^FO%d,%d', (int) $match[1] + $offsetX, (int) $match[2] + $offsetY);
            }
            if (str_starts_with($line, '^PQ')) {
                $line = '^PQ';
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
