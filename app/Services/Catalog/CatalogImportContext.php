<?php

namespace App\Services\Catalog;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class CatalogImportContext
{
    private static ?int $owner = null;

    private static int $archiveOwner = 0;

    private static string $archiveNonce = '';

    public static function owner(): int
    {
        return self::$owner ?? 0;
    }

    public static function register(Connection $connection): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $connection->getPdo()->sqliteCreateFunction('catalog_safe_partial_owner', static fn () => self::owner(), 0);
            $connection->getPdo()->sqliteCreateFunction('catalog_full_archive_owner', static fn () => self::$archiveOwner, 0);
            $connection->getPdo()->sqliteCreateFunction('catalog_full_archive_nonce', static fn () => self::$archiveNonce, 0);
        }
    }

    public static function run(int $runId, callable $callback): mixed
    {
        $previous = self::$owner;
        self::$owner = $runId;
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET @catalog_safe_partial_run_id = ?', [$runId]);
        }
        try {
            return $callback();
        } finally {
            self::$owner = $previous;
            if ($mysql) {
                DB::statement('SET @catalog_safe_partial_run_id = ?', [$previous]);
            }
        }
    }

    /** A transaction-local capability for archive fields of an exact proved ID list. */
    public static function archive(int $runId, string $nonce, callable $callback): mixed
    {
        $previous = [self::$archiveOwner, self::$archiveNonce];
        [self::$archiveOwner, self::$archiveNonce] = [$runId, $nonce];
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET @catalog_full_archive_run_id = ?, @catalog_full_archive_nonce = ?', [$runId, $nonce]);
        }
        try {
            return $callback();
        } finally {
            [self::$archiveOwner, self::$archiveNonce] = $previous;
            if ($mysql) {
                DB::statement('SET @catalog_full_archive_run_id = ?, @catalog_full_archive_nonce = ?', $previous);
            }
        }
    }
}
