<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateEscriptoriumUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'escriptorium:create-user
                            {username : The username for the new user}
                            {email : The email address for the new user}
                            {password : The password for the new user}
                            {--first-name= : The first name of the user}
                            {--last-name= : The last name of the user}
                            {--no-superuser : Do not make the user a superuser}
                            {--no-staff : Do not make the user staff}
                            {--inactive : Make the user inactive}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user in the eScriptorium PostgreSQL database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = $this->argument('username');
        $email = $this->argument('email');
        $password = $this->argument('password');
        $firstName = $this->option('first-name') ?? '';
        $lastName = $this->option('last-name') ?? '';
        $isSuperuser = ! $this->option('no-superuser');
        $isStaff = ! $this->option('no-staff');
        $isActive = ! $this->option('inactive');

        // Check if user already exists
        $existingUser = DB::connection('escriptorium')
            ->table('users_user')
            ->where('username', $username)
            ->orWhere('email', $email)
            ->first();

        if ($existingUser) {
            $this->error("A user with username '{$username}' or email '{$email}' already exists.");

            return self::FAILURE;
        }

        // Hash password using Django's PBKDF2 format
        $hashedPassword = $this->hashPasswordDjango($password);

        try {
            DB::connection('escriptorium')
                ->table('users_user')
                ->insert([
                    'username' => $username,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'is_superuser' => $isSuperuser,
                    'is_staff' => $isStaff,
                    'is_active' => $isActive,
                    'legacy_mode' => false,
                    'date_joined' => now(),
                    'last_login' => null,
                ]);

            $this->info("User '{$username}' created successfully!");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Username', $username],
                    ['Email', $email],
                    ['First Name', $firstName ?: '(empty)'],
                    ['Last Name', $lastName ?: '(empty)'],
                    ['Superuser', $isSuperuser ? 'Yes' : 'No'],
                    ['Staff', $isStaff ? 'Yes' : 'No'],
                    ['Active', $isActive ? 'Yes' : 'No'],
                    ['Legacy Mode', 'No'],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create user: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Hash password using Django's PBKDF2 format.
     * Format: pbkdf2_sha256$iterations$salt$hash
     */
    private function hashPasswordDjango(string $password): string
    {
        $algorithm = 'pbkdf2_sha256';
        $iterations = 600000;
        $salt = base64_encode(random_bytes(16));
        // Remove any padding characters and special chars that might cause issues
        $salt = rtrim(strtr($salt, '+/', 'Jq'), '=');

        $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $hashBase64 = base64_encode($hash);

        return "{$algorithm}\${$iterations}\${$salt}\${$hashBase64}";
    }
}
