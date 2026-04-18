<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manually create a root user.
 *   php artisan root:create {email} {password} --name="Platform Ops"
 */
class CreateRootUser extends Command
{
    protected $signature = 'root:create
                            {email : Root account email}
                            {password : Initial password}
                            {--name=Platform Root : Display name}';

    protected $description = 'Create a platform ROOT user (no organisation, full infrastructure access)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $name = $this->option('name');

        if (User::where('email', $email)->exists()) {
            $this->error("User with email [{$email}] already exists.");
            return self::FAILURE;
        }

        User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'root',
            'org_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("✅ Root user [{$email}] created. Tell them to change the password on first login.");
        return self::SUCCESS;
    }
}
