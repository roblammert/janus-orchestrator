<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO(
            Config::dbDsn(),
            Config::dbUser(),
            Config::dbPassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
