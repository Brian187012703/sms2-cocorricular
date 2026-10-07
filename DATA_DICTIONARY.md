# DATA DICTIONARY — BCP CO-CURRICULAR MANAGEMENT SYSTEM
**Institution**: Bestlink College of the Philippines (BCP)  
**System**: Co-Curricular & Student Affairs Management System  
**Schema Model**: 3rd Normal Form (3NF) Relational Architecture  
**Database Engine**: MySQL / InnoDB (utf8mb4_unicode_ci)  
**Evaluation Standard**: Section 6 Database Architecture  

---

## 1. Core Authentication & Identity Entities

### 1.1 `users`
Central user registry supporting authentication, RBAC, session tracking, and account security.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Unique primary key for system user |
| `username` | VARCHAR(50) | NO | Unique login identifier (e.g. scc.admin, ssc.officer) |
| `email` | VARCHAR(100) | NO | Unique verified institutional email address |
| `first_name` | VARCHAR(100) | NO | User given first name |
| `last_name` | VARCHAR(100) | NO | User family surname |
| `password_hash` | VARCHAR(255) | NO | Argon2ID or Bcrypt salted cryptographic hash |
| `role` | ENUM('student','club_adviser','ssc','admin') | NO | Canonical system role enforcing strict RBAC |
| `status` | ENUM('Active','Inactive','Suspended') | NO | Operational account state |
| `profile_pic` | VARCHAR(255) | YES | Storage path to avatar thumbnail |
| `failed_login_attempts` | INT | NO | Consecutive failed login counter (lockout trigger) |
| `locked_until` | DATETIME | YES | Lockout expiration timestamp |
| `last_login` | DATETIME | YES | Timestamp of most recent successful authentication |
| `last_mfa_verified_at`| DATETIME | YES | Timestamp of last 2FA OTP code validation |
| `created_at` | DATETIME | YES | Account creation timestamp |

### 1.2 `students`
Student academic profiles linked 1:1 with user accounts.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Student record identifier |
| `user_id` | INT | NO (FK) | Reference to `users.id` |
| `student_number` | VARCHAR(50) | NO | Unique campus matriculation ID (e.g. 2024-10001) |
| `first_name` | VARCHAR(100) | NO | Student first name |
| `last_name` | VARCHAR(100) | NO | Student surname |
| `course` | VARCHAR(100) | NO | Degree program (e.g. BSIT, BSCpE, BSHM, BSBA) |
| `year_level` | VARCHAR(50) | NO | Academic progression standing (e.g. 1st Year) |
| `section` | VARCHAR(50) | YES | Assigned academic cohort section |
| `status` | ENUM('Active','Inactive','Graduated') | NO | Academic enrollment status |

---

## 2. Organization & Governance Entities

### 2.1 `clubs`
Accredited student organizations, departmental councils, and interest guilds.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Organization identifier |
| `code` | VARCHAR(50) | NO | Unique organization code acronym (e.g. CSSEC, JPCS) |
| `name` | VARCHAR(255) | NO | Official organization name |
| `category` | VARCHAR(100) | NO | Departmental, Academic, Special Interest, Cultural |
| `description` | TEXT | YES | Charter summary and mission statement |
| `adviser_user_id` | INT | YES (FK) | Assigned faculty adviser reference (`users.id`) |
| `adviser_name` | VARCHAR(150) | YES | Display name of faculty adviser |
| `status` | ENUM('Active','Pending','Probation','Inactive') | NO | Accreditation standing |
| `deleted_at` | DATETIME | YES | Soft-deletion timestamp |

### 2.2 `club_memberships`
Junction table tracking student club affiliations and official roles.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Membership identifier |
| `club_id` | INT | NO (FK) | Reference to `clubs.id` |
| `user_id` | INT | NO (FK) | Reference to `users.id` |
| `role` | VARCHAR(100) | NO | Member, President, Vice President, Secretary, Adviser |
| `status` | ENUM('Pending','Active','Rejected','Inactive') | NO | Multi-tier membership approval state |
| `adviser_review` | VARCHAR(50) | YES | Stage 1 review outcome |
| `ssc_review` | VARCHAR(50) | YES | Stage 2 review outcome |
| `joined_at` | DATETIME | YES | Date membership was officially activated |

---

## 3. Events, Attendance & Telemetry Entities

### 3.1 `events`
Campus activities, seminars, assemblies, and multi-tier club events.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Event identifier |
| `club_id` | INT | YES (FK) | Hosting organization (`clubs.id`) |
| `title` | VARCHAR(255) | NO | Official event title |
| `description` | TEXT | YES | Event scope, agenda, and outcomes |
| `event_date` | DATETIME | NO | Scheduled event start date and time |
| `venue` | VARCHAR(150) | NO | Campus venue or facility allocation |
| `expected_attendees` | INT | NO | Capacity planning metric |
| `status` | ENUM('Pending SSC','Pending Admin','Approved','Rejected','Completed') | NO | Multi-tier approval workflow state |

### 3.2 `attendance_logs`
Verified check-in timestamps for participants.
| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | INT AUTO_INCREMENT | NO (PK) | Attendance log entry |
| `event_id` | INT | NO (FK) | Reference to `events.id` |
| `user_id` | INT | NO (FK) | Reference to `users.id` |
| `check_in` | DATETIME | NO | Check-in timestamp |
| `method` | ENUM('Self_QR','Scanner_Terminal','IoT_Hardware','Offline_Sync','Manual') | NO | Channel through which attendance was verified |
| `logged_by` | INT | YES (FK) | Verifier / Marshal user ID |
| `status` | ENUM('Present','Late','Excused','Absent') | NO | Verified attendance grading |
| `override_reason` | TEXT | YES | Justification note if manual adjustment made |

### 3.3 `iot_devices` & `iot_device_logs`
Telemetry and transaction store for physical scanners, RFID readers, and turnstiles.
| Table | Key Columns | Purpose |
|---|---|---|
| `iot_devices` | `device_uid`, `type`, `api_token`, `status`, `last_ping_at` | Connected device authorization directory |
| `iot_device_logs` | `device_id`, `event_type`, `payload`, `response_ms`, `created_at` | Real-time hardware telemetry and event logs |

---

## 4. Security, Audit & Data Privacy Entities

### 4.1 `audit_logs`
Tamper-resistant security and operational audit trail.
| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT | Primary key |
| `user_id` | INT (FK) | User who performed or triggered the action |
| `action` | VARCHAR(100) | Action identifier (e.g. AUTH_LOGIN, BUDGET_APPROVE) |
| `module` | VARCHAR(100) | Target module (e.g. users, events, budget) |
| `record_id` | INT | ID of modified entity record |
| `description` | TEXT | Human-readable contextual action details |
| `severity` | ENUM('info','warning','critical') | Incident classification |
| `ip_address` | VARCHAR(45) | Client network origin address |
| `created_at` | DATETIME | Timestamp of event occurrence |

### 4.2 `workflow_history`
Chronological state transition ledger for multi-tier approvals.
| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT | Primary key |
| `module` | VARCHAR(100) | Workflow module (`membership`, `event`, `budget`) |
| `record_id` | INT | Target entity identifier |
| `from_status` | VARCHAR(50) | State prior to transition |
| `to_status` | VARCHAR(50) | Resulting state after transition |
| `action` | VARCHAR(100) | Transition trigger (e.g. ADVISER_ENDORSE, SSC_APPROVE) |
| `performed_by` | INT (FK) | Reviewing officer user ID |
| `remarks` | TEXT | Evaluator rationale or justification |
| `created_at` | DATETIME | Execution timestamp |

### 4.3 `user_consents` & `data_deletion_requests`
Philippine Data Privacy Act (RA 10173) compliance stores.
| Table | Key Columns | Purpose |
|---|---|---|
| `user_consents` | `user_id`, `consent_type`, `version`, `agreed_at` | Records informed consent under RA 10173 |
| `data_deletion_requests`| `user_id`, `reason`, `status`, `processed_at` | User Right to Erasure / Personal Data Deletion requests |

### 4.4 `scheduler_logs` & `backup_logs`
Operational background task telemetry and recovery stores.
| Table | Key Columns | Purpose |
|---|---|---|
| `scheduler_logs` | `job_name`, `status`, `duration_ms`, `output`, `executed_at` | Automated background task execution logs |
| `backup_logs` | `filename`, `filesize_bytes`, `type`, `status`, `created_at` | Database SQL archive logs and recovery tracking |

---

## 5. Referential Integrity & Normalization Compliance
- **1st Normal Form (1NF)**: All attributes contain atomic values; no repeating groups.
- **2nd Normal Form (2NF)**: All non-key attributes are fully functionally dependent on primary keys.
- **3rd Normal Form (3NF)**: Transitive dependencies removed via junction and foreign key relationships (`club_memberships`, `event_registrations`, `election_votes`).
- **Referential Integrity**: Cascading constraints and prepared queries prevent orphaned records.
