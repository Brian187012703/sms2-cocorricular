<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('app:create-admin', function () {
    $data = [
        'username' => $this->ask('Username'),
        'email' => $this->ask('Email'),
        'first_name' => $this->ask('First name'),
        'last_name' => $this->ask('Last name'),
        'password' => $this->secret('Password (at least 12 characters)'),
        'password_confirmation' => $this->secret('Confirm password'),
    ];
    $validator = Validator::make($data, [
        'username' => 'required|string|max:50|unique:users,username',
        'email' => 'required|email|max:100|unique:users,email',
        'first_name' => 'required|string|max:50',
        'last_name' => 'required|string|max:50',
        'password' => 'required|string|min:12|confirmed',
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    User::create([
        'username' => $data['username'], 'email' => $data['email'],
        'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
        'password_hash' => Hash::make($data['password']), 'role' => 'admin', 'status' => 'Active',
    ]);
    $this->info('Administrator created.');

    return 0;
})->purpose('Create an administrator with credentials supplied interactively');
