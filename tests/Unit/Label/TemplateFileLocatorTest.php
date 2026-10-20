<?php

declare(strict_types=1);

namespace Tests\Zangra\SyliusBarcodePrintPlugin\Unit\Label;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TemplateNotFoundException;
use Zangra\SyliusBarcodePrintPlugin\Label\TemplateFileLocator;

final class TemplateFileLocatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/zangra-locator-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/templates/sub', 0777, true);
        file_put_contents($this->directory . '/templates/a.zpl', "^XA\r\n^XZ\r\n");
        file_put_contents($this->directory . '/templates/sub/b.zpl', '^XA^XZ');
        file_put_contents($this->directory . '/secret.zpl', 'secret');
        mkdir($this->directory . '/templates-evil');
        file_put_contents($this->directory . '/templates-evil/c.zpl', 'evil');
        symlink($this->directory . '/secret.zpl', $this->directory . '/templates/link.zpl');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testItReadsFilesAndNormalizesLineEndings(): void
    {
        $locator = new TemplateFileLocator($this->directory . '/templates');

        self::assertSame("^XA\n^XZ\n", $locator->read('a.zpl'));
        self::assertSame('^XA^XZ', $locator->read('sub/b.zpl'));
        self::assertTrue($locator->exists('a.zpl'));
    }

    #[DataProvider('provideEscapingPaths')]
    public function testItRejectsPathsOutsideTheTemplatesDirectory(string $path): void
    {
        $locator = new TemplateFileLocator($this->directory . '/templates');

        self::assertFalse($locator->exists($path));
        $this->expectException(TemplateNotFoundException::class);
        $locator->locate($path);
    }

    /** @return iterable<string, array{string}> */
    public static function provideEscapingPaths(): iterable
    {
        yield 'parent traversal' => ['../secret.zpl'];
        yield 'nested traversal' => ['sub/../../secret.zpl'];
        yield 'sibling with the same prefix' => ['../templates-evil/c.zpl'];
        yield 'symlink pointing outside' => ['link.zpl'];
        yield 'missing file' => ['missing.zpl'];
        yield 'directory' => ['sub'];
        yield 'empty' => [''];
        yield 'null byte' => ["a.zpl\0.txt"];
    }

    public function testItFailsWhenTheTemplatesDirectoryIsMissing(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        (new TemplateFileLocator($this->directory . '/nope'))->locate('a.zpl');
    }
}
