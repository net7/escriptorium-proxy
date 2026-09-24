<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.connections.escriptorium' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);
    DB::purge('escriptorium');
});

afterEach(function () {
    DB::purge('escriptorium');
});

test('creates an eScriptorium user across the database upgrade', function (bool $hasDownloadRetention) {
    Schema::connection('escriptorium')->create('users_user', function (Blueprint $table) use ($hasDownloadRetention) {
        $table->id();
        $table->string('username')->unique();
        $table->string('email');
        $table->string('password');
        $table->string('first_name');
        $table->string('last_name');
        $table->boolean('is_superuser');
        $table->boolean('is_staff');
        $table->boolean('is_active');
        $table->boolean('legacy_mode');
        $table->dateTime('date_joined');
        $table->dateTime('last_login')->nullable();

        if ($hasDownloadRetention) {
            $table->unsignedInteger('download_retention_days');
        }
    });

    $this->artisan('escriptorium:create-user', [
        'username' => 'compatibility-user',
        'email' => 'compatibility@example.test',
        'password' => 'test-password',
        '--no-superuser' => true,
        '--no-staff' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $this->assertDatabaseHas('users_user', [
        'username' => 'compatibility-user',
        'email' => 'compatibility@example.test',
        'is_superuser' => false,
        'is_staff' => false,
        'is_active' => true,
    ], 'escriptorium');

    if ($hasDownloadRetention) {
        $this->assertDatabaseHas('users_user', [
            'username' => 'compatibility-user',
            'download_retention_days' => 30,
        ], 'escriptorium');
    }
})->with([
    'before v26.07' => false,
    'v26.07 required retention without database default' => true,
]);
