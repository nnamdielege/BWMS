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

    /**
     * The one exception to the _test rule: bwms:dev-reset may rebuild the local
     * development database. Returns why it may not, or null when it may: APP_ENV
     * must be local, the database must be listed in BWMS_DEV_RESET_DATABASES
     * (default bwms_next), and the host must be this machine.
     */
    public static function devResetRefusal(?string $connection = null): ?string
    {
        $connection ??= config('database.default');
        $name = self::databaseName($connection);
        $host = (string) config("database.connections.{$connection}.host");

        return match (true) {
            ! app()->environment('local') => sprintf('APP_ENV is "%s"; dev-reset only runs with APP_ENV=local.', app()->environment()),
            ! in_array($name, config('bwms.dev_reset_databases'), true) => sprintf('Database "%s" is not in BWMS_DEV_RESET_DATABASES (%s).', $name, implode(', ', config('bwms.dev_reset_databases'))),
            ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) => sprintf('Database host "%s" is not this machine.', $host),
            default => null,
        };
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
