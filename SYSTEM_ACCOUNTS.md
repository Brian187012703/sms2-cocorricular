# System Deployment Accounts & Verification Credentials

The following accounts are pre-seeded in `database/hostforge_production_sms_db.sql` and `app/shared/setup.php` for direct post-deployment login and verification across all 4 system roles:

| Role | Username | Password | Email | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **Admin** | `scc.admin` | `Bcp@Admin2026!` | `Briantanael0127@gmail.com` | Full Administrative Clearance, System Health, Backups, User Management |
| **SSC Officer** | `ssc.officer` | `Bcp@SSC2026!` | `Briantanael187@gmail.com` | Stage 2 Event / Budget Reviews, Org Master, Inter-Club Chat Collaboration |
| **Club Adviser** | `cssec.adviser` | `Bcp@Adviser2026!` | `Briantanael0127+cssec_adviser@gmail.com` | Stage 1 Requisitions, Roster Endorsements, Attendance QR Management |
| **Student** | `bsit.student` | `Bcp@Student2026!` | `Briantanael0127+bsit_student@gmail.com` | Membership Applications, Event Participation, Secret Ballots, Self-Scanner |

> [!NOTE]
> All passwords strictly comply with security policy (minimum 8 characters, mixed uppercase, lowercase, numbers, and symbols). Accounts are marked `Active` in the canonical database.

