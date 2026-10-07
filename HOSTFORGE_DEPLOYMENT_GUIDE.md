# Hostforge & Docker Deployment Guide

## Overview
This system is production-ready for deployment to **Hostforge** (cPanel / Managed PHP) or any containerized environment using **Docker**.

---

## Deployment Option A: Docker Deployment (Fastest)

1. **Verify Environment Configuration**:
   Ensure `.env` or `docker-compose.yml` contains your production credentials:
   ```yaml
   DB_HOST: db
   DB_PORT: 3306
   DB_DATABASE: sms_db
   DB_USERNAME: sms_user
   DB_PASSWORD: sms_secure_password
   APP_ENV: production
   APP_DEBUG: "false"
   APP_TIMEZONE: "Asia/Manila"
   ```

2. **Launch Application & Database**:
   ```bash
   docker compose up -d --build
   ```
   - Automatically builds with PHP 8.2 Apache, `mysqli`, `pdo_mysql`, `gd`, `zip`, `bcmath`, `intl`, and `opcache`.
   - Initializes MySQL 8.0 with [hostforge_production_sms_db.sql](file:///c:/xamppp/htdocs/sms2-cocorricular-3/database/hostforge_production_sms_db.sql) (authentic seed data, multi-tier workflows, active user credentials).
   - Configures upload & storage permissions (`storage/`, `bootstrap/cache/`, `app/uploads/`).

3. **Access Application**:
   Open browser at `http://localhost:8080`.

---

## Deployment Option B: Hostforge cPanel / Shared Hosting

1. **Database Import**:
   - In Hostforge cPanel, open **phpMyAdmin** or **MySQL Database Wizard**.
   - Create a database (e.g. `sms_db`) and user with full privileges.
   - Import [database/hostforge_production_sms_db.sql](file:///c:/xamppp/htdocs/sms2-cocorricular-3/database/hostforge_production_sms_db.sql).
   - Alternatively, on an empty database run:
     ```bash
     php artisan migrate --force
     ```

2. **Configure Production `.env`**:
   Set database and environment settings:
   ```ini
   APP_NAME="BCP Co-Curricular System"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.com
   APP_TIMEZONE=Asia/Manila

   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=your_hostforge_db
   DB_USERNAME=your_hostforge_user
   DB_PASSWORD=your_hostforge_password
   ```

3. **File Uploads & Permissions**:
   Ensure write permissions (`chmod -R 775` or `755`) on:
   - `storage/`
   - `bootstrap/cache/`
   - `app/uploads/` (achievements, applications, avatars, signatures, stamps)

4. **Verify System Access**:
   All 4 system roles are pre-seeded in the database:
   - **Admin**: `scc.admin` / `Bcp@Admin2026!`
   - **Club Adviser**: `cssec.adviser` / `Bcp@Adviser2026!`
   - **SSC Officer**: `ssc.officer` / `Bcp@Ssc2026!`
   - **Student**: `bsit.student` / `Bcp@Test2026!`

---

## Acceptance Verification Commands
```bash
# Run acceptance test suite
php tests/run_all_acceptance_tests.php

# Run technical software evaluation
php tests/run_technical_software_evaluation.php

# Run PHPUnit test suite
php vendor/phpunit/phpunit/phpunit
```
