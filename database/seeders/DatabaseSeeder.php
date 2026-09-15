<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The application has no sign-up flow, so this seeder is the only way to obtain
 * an account. Values come from the environment so that a password from the
 * repository never ends up on a server.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = (string) env('SEED_USER_EMAIL', 'demo@example.com');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('SEED_USER_NAME', 'Демо-пользователь'),
                // Hashing is handled by the model's 'password' => 'hashed' cast
                'password' => (string) env('SEED_USER_PASSWORD', 'password'),
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("User {$email} is ready to sign in.");
    }
}
