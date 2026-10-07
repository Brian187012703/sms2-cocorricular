# Role modules and workflow status

The active web application uses Laravel 12 routes, Eloquent database queries, sessions, validation and Inertia/Vue pages. Vue is the presentation layer; the PHP backend is Laravel. Legacy scripts under app/auth, app/dashboard and app/shared remain in the checkout for migration reference and are not public application endpoints. Serve the public directory.

## Role responsibilities

| Module | Student | Club adviser | SSC | Administrator |
| --- | --- | --- | --- | --- |
| Dashboard | Own student metrics and permitted published records | Assigned club records and published campus records | Institutional overview | Institutional overview |
| Students | View own record; API updates own phone | Read students in assigned clubs | Read/create/update through API | Read/create/update/delete through API |
| Clubs | Browse active membership options and directory | Browse directory, operate assigned clubs | Browse directory | Browse directory |
| Memberships | Apply; track own applications | Endorse/reject assigned pending applications | Review endorsed applications | Final clearance and activation |
| Events | View approved/upcoming/completed activities | Submit for assigned clubs; endorse proposals | Review and forward to admin | Final approval/rejection |
| Budgets | No access | Submit and endorse assigned club requests | Recommend amount; forward for clearance | Approve final amount; record one disbursement |
| Attendance | Own attendance history | Record approved events for assigned clubs | Record approved events | Record approved events |
| Elections | Vote once when eligible and within opening dates | Register candidates for assigned clubs before ballots exist | Create elections/register candidates | Create elections/register candidates |
| Achievements | View verified records and own submissions; submit via API for memberships | View permitted records; submit via API for assigned clubs | View/submit via API | View/submit via API |
| Announcements | Published, unexpired notices for audience and membership | Read permitted notices; publish for assigned clubs | Publish notices | Publish notices |
| Accounts and audit | No access | No access | No access | Provision accounts, change status, read audit logs |

## Approval rules

Events and budgets created by an assigned adviser enter Pending SSC; the adviser's submission constitutes stage one endorsement. Submissions created by SSC or admin enter Pending Adviser, then require an assigned adviser. SSC forwards Pending SSC to Pending Admin. Only admin grants final clearance. Only an approved, unpaid budget may be disbursed.

Memberships remain Pending through adviser endorsement and SSC approval. Admin clearance sets Active, records approved_by, and marks admin_review Approved. Existing active memberships retain their status; migration does not invent a historical admin approval.

Workflow writes and their history run in one transaction. Bound records are locked during mutations. Attendance and membership creation lock the relevant parent record before duplicate checks. Voting locks the election, validates candidate membership in that election, enforces one choice per position, and records participation separately from ballot choices.

## Data and test rules

Business records and statistics are database-backed. Empty results stay empty. Fixed login password bypasses, invented birthdates, fake adviser names, decorative QR posters, and arbitrary eligible voter totals have been removed from active Laravel paths. UI labels, role identifiers, workflow states and pagination limits are application rules, not simulated records.

DatabaseSeeder does not insert sample records. TestingSeeder refuses to run outside the testing environment. PHPUnit uses an isolated in-memory database; it does not target sms_db. MySQL deployment and concurrency behavior still require staging verification.

## Remaining migration and acceptance work

These are real limitations, not completed features:

- Club creation, adviser assignment, academic master data, profile/password management, password recovery/MFA, achievement verification and upload management need complete Laravel web workflows. Some exist only in legacy PHP; do not expose those scripts to fill the gap.
- Student creation/update currently has an API, not an administration form in the Laravel directory page.
- Attendance currently supports operator entry. Camera QR scanning, signed self check-in, and RFID device integration are not implemented. No simulated QR poster is shipped.
- Candidate registration captures a name; verification against the student registry and formal election nomination/closing workflows need completion.
- Historical election_votes.votes_json rows are preserved by the additive migration. They are not automatically converted to candidate_id records; historical tally reconciliation requires reviewing the existing ballot format before deployment.
- Legacy database dumps may contain sample records and known credentials. They are not production installation sources. Existing databases need a schema comparison and controlled data migration.
- Notifications, report exports, file retention, mail delivery, backups, accessibility, browser end-to-end tests and load/concurrency tests still need deployment acceptance.

Do not describe this repository as 100% complete or production certified until these gaps and Hostforge acceptance checks are resolved.
