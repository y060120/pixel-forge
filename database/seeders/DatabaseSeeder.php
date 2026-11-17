<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Demo User',
                'password' => bcrypt('password'),
                'balance' => '50.000000000',
                'is_admin' => true,
            ]
        );

        if (bccomp((string) $user->balance, '0', 9) === 0) {
            $user->update([
                'balance' => '50.000000000',
                'is_admin' => true,
            ]);
        }
    }
}
