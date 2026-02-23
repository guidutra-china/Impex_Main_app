<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@tradingapp.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('password'),
                'type' => 'internal',
                'is_admin' => true,
                'status' => 'active',
                'locale' => 'en',
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }
}
