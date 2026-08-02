# Major System Update & Optimization Plan: Co-Curricular Student Management System (SMS)

This plan outlines the major update, backend database consolidation, bug fixes, performance cleanup, and feature completions for the BCP Co-Curricular Student Management System. The goal is to ensure the entire application is 100% functional, bug-free, securely integrated, and operational at all times.

---

## User Review Required

> [!IMPORTANT]
> **Database & Architecture Alignment**
> - The application operates with dual-capability support for **MySQL (`sms_db`)** via native PHP PDO/mysqli (XAMPP environment) and **Laravel Eloquent API endpoints**.
> - The leftover `laravel-temp/` directory will be permanently removed to prevent directory clutter and redundant vendor packages.
> - Registration will be re-enabled and restored in `auth_actions.php` and `app/auth/register.php` with password hashing (`password_hash` / Argon2id).

---

## Proposed Changes

### 1. Database Schema & Backend Setup (`database/`, `app/shared/`)

#### [MODIFY] [setup.php](file:///c:/xamppp/htdocs/sms/app/shared/setup.php)
- Add `students` table creation and seeding into `setup.php` alongside the 9 existing system tables (`users`, `clubs`, `club_memberships`, `events`, `budget_requests`, `attendance_logs`, `achievements`, `notifications`, `audit_logs`).
- Ensure all 6 roles (`student`, `club_officer`, `club_adviser`, `osa_director`, `finance_officer`, `admin`) are fully seeded with working test credentials.
- Ensure proper foreign key constraints, UTF8mb4 encoding, and automatic directory creation for file uploads (`uploads/achievements/`).

#### [MODIFY] [.env](file:///c:/xamppp/htdocs/sms/.env)
- Generate app key (`APP_KEY`) and set valid default environment settings for database connections.

---

### 2. Backend Logic & Action Handlers Bug Fixes (`app/shared/`, `app/Http/Controllers/`)

#### [MODIFY] [budget_actions.php](file:///c:/xamppp/htdocs/sms/app/shared/budget_actions.php)
- Fix duplicate `$stmt->bind_param` call error on lines 83 & 85 (`'issdii'` vs `'issdi'`).
- Validate input amounts and role permissions for Adviser, OSA, and Finance approval stages.

#### [MODIFY] [auth_actions.php](file:///c:/xamppp/htdocs/sms/app/shared/auth_actions.php)
- Re-enable the `register` action case with full input validation, unique username/email checks, password hashing, and auto-created student account profile.

#### [MODIFY] [register.php](file:///c:/xamppp/htdocs/sms/app/auth/register.php)
- Replace static redirect with interactive student registration form aligned with the modern system UI design system.

#### [MODIFY] [register2.php](file:///c:/xamppp/htdocs/sms/app/auth/register2.php)
- Remove unused leftover registration step or unify into the streamlined registration workflow.

#### [MODIFY] [achievement_actions.php](file:///c:/xamppp/htdocs/sms/app/shared/achievement_actions.php)
- Fix upload handling validation and OSA verification status update notifications.

#### [MODIFY] [event_actions.php](file:///c:/xamppp/htdocs/sms/app/shared/event_actions.php)
- Ensure proper role-based filtering for event proposals and approval statuses.

#### [MODIFY] [attendance_actions.php](file:///c:/xamppp/htdocs/sms/app/shared/attendance_actions.php)
- Verify QR parsing (`BCP-STUDENT-{id}`) and duplicate check prevention.

---

### 3. Directory Cleanup & System Optimization

#### [DELETE] `c:/xamppp/htdocs/sms/laravel-temp`
- Remove redundant temporary directory `laravel-temp` to optimize disk space and eliminate leftover temporary build assets.

---

## Verification Plan

### Automated Verification
- Run PHP syntax check across all modified PHP files:
  ```bash
  php -l app/shared/db.php
  php -l app/shared/auth_actions.php
  php -l app/shared/budget_actions.php
  php -l app/shared/event_actions.php
  php -l app/shared/roster_actions.php
  php -l app/shared/achievement_actions.php
  php -l app/shared/attendance_actions.php
  php -l app/shared/admin_actions.php
  ```
- Run setup script execution via CLI:
  ```bash
  php app/shared/setup.php
  ```

### Manual Verification
- Test registration workflow from `http://localhost/sms/app/auth/register.php`.
- Test sign-in with all 6 system roles (`student`, `officer`, `adviser`, `osa`, `finance`, `admin`).
- Verify role-based dashboard rendering and quick role switcher.
- Test budget submission and approval workflow (Officer -> Adviser -> OSA -> Finance).
- Test event submission and OSA approval.
- Test achievement upload & verification.
- Test QR attendance scanning logic.
