<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\BudgetRequest;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed System Users (Argon2id Hashed Passwords)
        $pwdAdmin = Hash::make('Admin@1234');
        $pwdDefault = Hash::make('Password123!');

        $users = [
            ['username' => 'admin',   'email' => 'admin@bcp.edu.ph',   'first_name' => 'System',     'last_name' => 'Administrator', 'role' => 'admin',         'password_hash' => $pwdAdmin],
            ['username' => 'student', 'email' => 'student@bcp.edu.ph', 'first_name' => 'Juan',       'last_name' => 'Dela Cruz',     'role' => 'student',       'password_hash' => $pwdDefault],
            ['username' => 'officer', 'email' => 'officer@bcp.edu.ph', 'first_name' => 'Maria',      'last_name' => 'Santos',        'role' => 'club_officer',  'password_hash' => $pwdDefault],
            ['username' => 'adviser', 'email' => 'adviser@bcp.edu.ph', 'first_name' => 'Prof. Alex', 'last_name' => 'Reyes',         'role' => 'club_adviser',  'password_hash' => $pwdDefault],
            ['username' => 'osa',     'email' => 'osa@bcp.edu.ph',     'first_name' => 'Dr. Elena',  'last_name' => 'Cruz',          'role' => 'osa_director',  'password_hash' => $pwdDefault],
            ['username' => 'finance', 'email' => 'finance@bcp.edu.ph', 'first_name' => 'Roberto',    'last_name' => 'Garcia',        'role' => 'finance_officer','password_hash' => $pwdDefault],
        ];

        foreach ($users as $uData) {
            User::updateOrCreate(['username' => $uData['username']], $uData);
        }

        // 2. Seed Clubs
        $clubs = [
            ['id' => 1, 'code' => 'ITS',    'name' => 'Information Technology Society',             'category' => 'Academic', 'description' => 'Official organization for IT students.', 'adviser_name' => 'Prof. Alex Reyes', 'status' => 'Active'],
            ['id' => 2, 'code' => 'CSSEC',  'name' => 'Computer Science Student Executive Council', 'category' => 'Academic', 'description' => 'Empowering CS students through leadership.', 'adviser_name' => 'Prof. Alex Reyes', 'status' => 'Active'],
            ['id' => 3, 'code' => 'BCPVOL', 'name' => 'BCP Campus Volunteers & Extension',          'category' => 'Advocacy', 'description' => 'Community outreach and volunteer projects.', 'adviser_name' => 'Dr. Elena Cruz', 'status' => 'Active'],
            ['id' => 4, 'code' => 'BCPARTS','name' => 'BCP Cultural Arts & Performing Troupe',      'category' => 'Cultural', 'description' => 'Dance, music, theater across campus.', 'adviser_name' => 'Prof. Sarah Mercado', 'status' => 'Active'],
        ];

        foreach ($clubs as $cData) {
            Club::updateOrCreate(['id' => $cData['id']], $cData);
        }

        // 3. Seed Students
        $students = [
            ['first_name' => 'Juswa',    'last_name' => 'Pudaders',   'birthday' => '2004-06-20', 'course' => 'Bachelor of Science in Information Technology', 'year_level' => '4th Year', 'section' => '41018', 'phone' => '09999999999', 'status' => 'Active'],
            ['first_name' => 'Maria',    'last_name' => 'Santos',     'birthday' => '2003-03-15', 'course' => 'Bachelor of Science in Computer Science',       'year_level' => '3rd Year', 'section' => '31011', 'phone' => '09111111111', 'status' => 'Inactive'],
            ['first_name' => 'Jose',     'last_name' => 'Reyes',      'birthday' => '2002-09-10', 'course' => 'Bachelor of Science in Information Technology', 'year_level' => '4th Year', 'section' => '41019', 'phone' => '09222222222', 'status' => 'Active'],
            ['first_name' => 'Ana',      'last_name' => 'Cruz',       'birthday' => '2005-01-25', 'course' => 'Bachelor of Science in Information Systems',    'year_level' => '2nd Year', 'section' => '21005', 'phone' => '09333333333', 'status' => 'Active'],
        ];

        foreach ($students as $sData) {
            Student::create($sData);
        }

        // 4. Seed Club Memberships
        $officer = User::where('username', 'officer')->first();
        $studentUser = User::where('username', 'student')->first();

        if ($officer) {
            ClubMembership::updateOrCreate(
                ['club_id' => 1, 'user_id' => $officer->id],
                ['role' => 'President', 'status' => 'Active']
            );
        }

        if ($studentUser) {
            ClubMembership::updateOrCreate(
                ['club_id' => 1, 'user_id' => $studentUser->id],
                ['role' => 'Member', 'status' => 'Active']
            );
        }
    }
}
