<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain-PHP template engine with layout inheritance and section stacking.
 * Every echo goes through e() so XSS protection is the default, not opt-in.
 */
final class View
{
    private static string $path = '';
    private static array $shared = [];
    private static array $sections = [];
    private static array $stack = [];
    private static ?string $layout = null;

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/\\');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(): array
    {
        return self::$shared;
    }

    public static function render(string $template, array $data = []): string
    {
        $file = self::resolve($template);

        $previousLayout = self::$layout;
        self::$layout = null;

        $content = self::evaluate($file, array_merge(self::$shared, $data));

        $layout = self::$layout;
        self::$layout = $previousLayout;

        if ($layout !== null) {
            // A template may define its own `content` section; otherwise its
            // whole output becomes the content.
            if (!isset(self::$sections['content'])) {
                self::$sections['content'] = $content;
            }

            $content = self::evaluate(self::resolve($layout), array_merge(self::$shared, $data));
        }

        return $content;
    }

    public static function exists(string $template): bool
    {
        return is_file(self::$path . '/' . str_replace('.', '/', $template) . '.php');
    }

    private static function resolve(string $template): string
    {
        $file = self::$path . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }

        return $file;
    }

    /**
     * The local variables here are deliberately obscure: extract() uses
     * EXTR_SKIP, so a template variable named `$file` or `$data` would
     * otherwise be silently shadowed by this method's own parameters.
     */
    private static function evaluate(string $__templateFile, array $__templateData): string
    {
        extract($__templateData, EXTR_SKIP);

        ob_start();

        try {
            include $__templateFile;
        } catch (\Throwable $__templateError) {
            ob_end_clean();
            throw $__templateError;
        }

        return (string) ob_get_clean();
    }

    public static function extend(string $layout): void
    {
        self::$layout = $layout;
    }

    public static function startSection(string $name): void
    {
        self::$stack[] = $name;
        ob_start();
    }

    public static function endSection(): void
    {
        $name = array_pop(self::$stack);
        if ($name === null) {
            ob_end_clean();

            return;
        }

        self::$sections[$name] = (string) ob_get_clean();
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    public static function hasSection(string $name): bool
    {
        return isset(self::$sections[$name]) && trim(self::$sections[$name]) !== '';
    }

    public static function include(string $template, array $data = []): string
    {
        return self::evaluate(self::resolve($template), array_merge(self::$shared, $data));
    }

    public static function reset(): void
    {
        self::$sections = [];
        self::$stack = [];
        self::$layout = null;
    }
}
