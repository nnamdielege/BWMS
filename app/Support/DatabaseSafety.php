<?php

namespace App\Support;

use RuntimeException;

/**
 * Destructive database operations (migrate:fresh/refresh/reset/rollback,
 * db:wipe, RefreshDatabase, DatabaseTruncation) only run against a database
 * whose name ends in "_test". Everything else - dev, staging, production - is
 * protected; restore from a backup instead of rolling back.
 */
final class DatabaseSafety
{
    public const SUFFIX = '_test';

    public static function databaseName(?string $connection = null): string
    {
        $connection ??= config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    public static function destructiveAllowed(?string $connection = null): bool
    {
        return str_ends_with(self::databaseName($connection), self::SUFFIX);
    }

    public static function assertDestructiveAllowed(string $what, ?string $connection = null): void
    {
        if (! self::destructiveAllowed($connection)) {
            throw new RuntimeException(sprintf(
                'Refusing %s: database "%s" does not end in "%s". Tests must use the dedicated test database (.env.testing / phpunit.xml).',
                $what, self::databaseName($connection), self::SUFFIX,
            ));
        }
    }
}
