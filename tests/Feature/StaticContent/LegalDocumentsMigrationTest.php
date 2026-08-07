<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('legal documents migration upgrades rolls back and preserves unrelated data', function (): void {
    $original = DB::getDefaultConnection();
    $connection = 'legal_documents_upgrade';
    config(["database.connections.{$connection}" => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('unrelated_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('unrelated_records')->insert(['id' => 41, 'value' => 'keep']);

        $migration = require database_path('migrations/2026_08_07_000200_create_legal_documents_table.php');
        $migration->up();

        expect(Schema::hasColumns('legal_documents', [
            'id', 'code', 'title', 'body', 'is_active', 'created_at', 'updated_at',
        ]))->toBeTrue();

        DB::table('legal_documents')->insert([
            'code' => 'privacy_policy',
            'title' => 'Политика конфиденциальности',
            'body' => null,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('legal_documents')->insert([
            'code' => 'privacy_policy',
            'title' => 'Дубликат',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasTable('legal_documents'))->toBeFalse()
            ->and(DB::table('unrelated_records')->where('id', 41)->value('value'))->toBe('keep');

        $migration->up();

        expect(Schema::hasTable('legal_documents'))->toBeTrue()
            ->and(DB::table('legal_documents')->count())->toBe(0)
            ->and(DB::table('unrelated_records')->where('id', 41)->value('value'))->toBe('keep');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
