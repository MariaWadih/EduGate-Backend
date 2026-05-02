<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{


public function run()
{
    User::create([
        'name' => 'Admin',
        'email' => 'admin@edugate.com',
        'password' => Hash::make('password'),
        'role' => 'admin', 
        'email_verified_at' => now(), 
    ]);
}
}
