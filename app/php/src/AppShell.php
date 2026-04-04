<?php

declare(strict_types=1);

namespace Janus;

final class AppShell
{
    public static function navItems(?array $user = null): array
    {
        $items = [
            ['label' => 'Workflows', 'href' => '/'],
            ['label' => 'Executions', 'href' => '/executions'],
            ['label' => 'Dead Letters', 'href' => '/dead-letters'],
            ['label' => 'Observability', 'href' => '/observability'],
            ['label' => 'Settings', 'href' => '/settings'],
        ];

        $role = strtoupper((string)($user['role'] ?? ''));
        if ($role === 'ADMIN') {
            $items[] = ['label' => 'Audit', 'href' => '/audit'];
        }

        return $items;
    }

    public static function meta(string $title, ?array $user, array $extra = []): array
    {
        return array_merge([
            'title' => $title,
            'environment' => Config::appEnvironment(),
            'version' => Config::appVersion(),
            'user' => $user,
            'navItems' => self::navItems($user),
        ], $extra);
    }
}
