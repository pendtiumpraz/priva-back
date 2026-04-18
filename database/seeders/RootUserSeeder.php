<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the initial root user from env vars ROOT_EMAIL + ROOT_PASSWORD.
 * Idempotent: does nothing if the email already exists.
 *
 * Root user has no organisation (org_id = NULL) and is the only role able to
 * manage platform-infrastructure features (Web Terminal, OTA Update, System
 * Logs, API Hub, Menu Control Matrix).
 */
class RootUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ROOT_EMAIL');
        $password = env('ROOT_PASSWORD');

        if (!$email || !$password) {
            $this->command?->warn('ROOT_EMAIL / ROOT_PASSWORD not set — skipping root user seed.');
            return;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            $this->command?->info("Root user [{$email}] already exists. Skipping.");
            return;
        }

        // Enforce single-root — do not create a second root even via seeder.
        $existingRoot = User::where('role', 'root')->first();
        if ($existingRoot) {
            $this->command?->warn("A root user already exists: [{$existingRoot->email}]. Skipping creation of {$email}.");
            return;
        }

        User::create([
            'id' => (string) Str::uuid(),
            'name' => env('ROOT_NAME', 'Platform Root'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'root',
            'org_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Root user [{$email}] created.");
    }
}
