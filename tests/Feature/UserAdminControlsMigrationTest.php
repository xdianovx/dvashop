<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('admin controls migration preserves existing users and is safely reversible', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'user_admin_controls_upgrade';

    config([
        "database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);
    Schema::clearResolvedInstance('db.schema');

    try {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('customer')->index();
            $table->timestamps();
        });

        $userId = DB::table('users')->insertGetId([
            'name' => 'Существующий пользователь',
            'email' => 'existing@example.com',
            'password' => 'existing-hash',
            'role' => 'manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_03_000100_add_admin_controls_to_users_table.php');
        $migration->up();

        $user = DB::table('users')->where('id', $userId)->first();

        expect(Schema::hasColumns('users', ['is_active', 'blocked_at']))->toBeTrue()
            ->and((bool) $user->is_active)->toBeTrue()
            ->and($user->blocked_at)->toBeNull()
            ->and($user->name)->toBe('Существующий пользователь')
            ->and($user->email)->toBe('existing@example.com')
            ->and($user->password)->toBe('existing-hash')
            ->and($user->role)->toBe('manager');

        $migration->down();

        expect(Schema::hasColumn('users', 'is_active'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'blocked_at'))->toBeFalse()
            ->and(DB::table('users')->where('id', $userId)->value('password'))->toBe('existing-hash');

        $migration->up();
        $user = DB::table('users')->where('id', $userId)->first();

        expect((bool) $user->is_active)->toBeTrue()
            ->and($user->blocked_at)->toBeNull()
            ->and($user->name)->toBe('Существующий пользователь')
            ->and($user->role)->toBe('manager');
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        config(["database.connections.{$connection}" => null]);
    }
});
