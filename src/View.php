<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Plain-PHP templates in templates/. Variables are extracted into scope; escape with e(). */
final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $vars);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $vars + ['content' => $content]);
    }

    private static function capture(string $template, array $vars): string
    {
        $file = BMON_ROOT . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $template");
        }
        $vars += ['title' => '', 'nav' => '', 'errors' => [], 'user' => Auth::user()];
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
