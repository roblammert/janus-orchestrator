<?php

declare(strict_types=1);

namespace Janus;

final class View
{
    public static function render(string $template, array $vars = []): void
    {
        $templatePath = __DIR__ . '/../views/' . $template . '.php';
        if (!is_file($templatePath)) {
            http_response_code(500);
            echo 'Template not found';
            return;
        }

        extract($vars, EXTR_SKIP);
        include __DIR__ . '/../views/layout/header.php';
        include $templatePath;
        include __DIR__ . '/../views/layout/footer.php';
    }
}
