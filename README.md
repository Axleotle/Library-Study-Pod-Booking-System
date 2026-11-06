# Library Pod System (PHP + MySQL)

Study-pod booking prototype for BulSU, built from our Figma design and the Mini-SRS.

## Run it (XAMPP)
1. Copy this folder into `C:\xampp\htdocs\library-pod-system`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open phpMyAdmin (`http://localhost/phpmyadmin`) → **Import** → choose `schema.sql`.
4. If your MySQL password is not empty, edit `config.php`.
5. Open `http://localhost/library-pod-system/` (not by double-clicking `index.html`).

The first request seeds demo accounts. The **Reset data** button re-seeds them.

| Role | ID | Password |
|---|---|---|
| Student | 2023-00123 / 2023-00456 | pass123 |
| Librarian | L-001 | lib123 |
| Admin | A-001 | admin123 |

## Architecture
```
index.html  (screens + business rules)  --fetch POST-->  api.php (PDO)  -->  MySQL (library_pods)
```
- `index.html`: all screens. Storage goes through one `DB` adapter object (`Api`), so the UI never touches SQL. `Local` (browser localStorage) is kept as an offline fallback.
- `api.php`: JSON API. Operations: `init, login, logout, signup, all, insert, update, kv, reset`.
- `schema.sql`: tables `students, staff, sessions, incidents, overrides, kv` with foreign keys.

## What the server enforces
- Passwords are hashed (`password_hash`) and never sent to the browser.
- Login uses PHP sessions; suspended or deactivated accounts are refused.
- Role checks: students can only create, cancel or end their own sessions; only staff approve sessions, change student status and write overrides and incidents; only admins change settings and staff accounts.
- **Booking rules run on the server** (`eligible()` in `api.php`): active registration, maximum session length, cooldown, and terminal hours (Asia/Manila time). The browser repeats the checks only for faster messages.
- **Server clock:** session start, end and finish times are set by the server, and overdue sessions beyond the grace period create the incident log entry there.
- **No double-booking, enforced by MySQL:** generated columns with `UNIQUE` keys on `sessions` make it impossible to hold one pod, or one student, in two current sessions, even with simultaneous requests. Booking also runs inside a transaction.
- **Least data exposed:** students can read only their own record, their own sessions in full (other sessions appear only as "pod taken"), and never the student list, staff list, incidents or overrides.
- All queries use prepared statements with a column whitelist.

## Known limitations
- The **Skip +30 min** demo button changes a shared demo clock. Remove it (and the `offset` exception in `api.php`) for production.
- Staff sign-up requires an admin session by design.
- If you change `schema.sql` after importing, drop the `library_pods` database and re-import it.
