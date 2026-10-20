<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

/**
 * ZPL snippets of the label icons. Snippets are authored relative to (0,0) and
 * are positioned by the renderer through ^LH.
 */
final class IconRegistry
{
    /** @var array<string, array{width: int, zpl: list<string>}> */
    private const ICONS = [
        'ce' => [
            'width' => 33,
            'zpl' => [
                '^FO0,0',
                '^A0N,18,18',
                '^FDCE^FS',
            ],
        ],
        'weee' => [
            'width' => 40,
            'zpl' => [
                '^FO0,1',
                '^GB15,15,2^FS',
                '^FO4,5',
                '^GB7,7,2^FS',
                '^FO27,0',
                '^GB13,2,1^FS',
                '^FO30,2',
                '^GB7,13,1^FS',
                '^FO28,16',
                '^GB11,2,1^FS',
                '^FO27,4',
                '^GD13,11,1,R,B^FS',
                '^FO27,4',
                '^GD13,11,1,L,B^FS',
            ],
        ],
    ];

    public function has(string $key): bool
    {
        return isset(self::ICONS[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::ICONS);
    }

    public function width(string $key): int
    {
        return $this->get($key)['width'];
    }

    /** ZPL placing the icon with its origin at (x, y). */
    public function snippet(string $key, int $x, int $y): string
    {
        return implode("\n", ['^LH' . $x . ',' . $y, ...$this->get($key)['zpl'], '^LH0,0']);
    }

    /** @return array{width: int, zpl: list<string>} */
    private function get(string $key): array
    {
        return self::ICONS[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown icon "%s".', $key));
    }
}
