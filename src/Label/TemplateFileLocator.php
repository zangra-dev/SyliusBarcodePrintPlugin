<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\Label;

use Zangra\SyliusBarcodePrintPlugin\Label\Exception\TemplateNotFoundException;

final class TemplateFileLocator
{
    public function __construct(private readonly string $templatesPath)
    {
    }

    public function getTemplatesPath(): string
    {
        return $this->templatesPath;
    }

    /** Returns the real path of a template file, guaranteed to live inside the templates directory. */
    public function locate(string $relativePath): string
    {
        $base = realpath($this->templatesPath);
        if (false === $base || !is_dir($base)) {
            throw new TemplateNotFoundException(sprintf('Templates directory "%s" does not exist.', $this->templatesPath));
        }

        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            throw new TemplateNotFoundException('Invalid template path.');
        }

        $real = realpath($base . \DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));
        if (false === $real || !is_file($real)) {
            throw new TemplateNotFoundException(sprintf('Template file "%s" does not exist.', $relativePath));
        }

        if (!str_starts_with($real, $base . \DIRECTORY_SEPARATOR)) {
            throw new TemplateNotFoundException(sprintf('Template file "%s" is outside the templates directory.', $relativePath));
        }

        return $real;
    }

    public function exists(string $relativePath): bool
    {
        try {
            $this->locate($relativePath);

            return true;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    public function read(string $relativePath): string
    {
        $content = file_get_contents($this->locate($relativePath));
        if (false === $content) {
            throw new TemplateNotFoundException(sprintf('Template file "%s" is not readable.', $relativePath));
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}
