<?php

declare(strict_types=1);

namespace Janus;

final class AppShell
{
    public static function navItems(): array
    {
        return [
            ['label' => 'Workflows', 'href' => '/'],
            ['label' => 'Executions', 'href' => '/executions'],
            ['label' => 'Settings', 'href' => '/settings'],
        ];
    }

    public static function meta(string $title, ?array $user): array
    {
        return [
            'title' => $title,
            'environment' => Config::appEnvironment(),
            'version' => Config::appVersion(),
            'user' => $user,
            'navItems' => self::navItems(),
        ];
    }
}
