# AkuapemConnect — Project Instructions

## Database Migrations
All migrations live in `install.sql` at the project root.

- Use `CREATE TABLE IF NOT EXISTS` for every new table.
- Use `INSERT IGNORE INTO` for all seed / default data.
- Use `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for new columns on existing tables (MySQL 8.0+ syntax).
- Use `ALTER TABLE ... MODIFY COLUMN` to expand ENUM values (safe to repeat).
- Every new migration goes at the bottom of `install.sql` under the next version block (v012, v013, …) following the existing format.
- **Never create a separate `migrate_*.php` for new features** — add directly to `install.sql` instead.
- The file must remain safe to run multiple times without corrupting data or duplicating records.

## Site Logo
The logo files are:
- `assets/images/ac logo.png` — solid-background version (use in nav headers on white backgrounds)
- `assets/images/ac logo removedbg.png` — transparent-background version (use on coloured backgrounds, hero sections, auth pages)

Use the logo image wherever the app name or a brand icon is displayed (nav headers, login/register pages, etc.).
HTML path (URL-encode the spaces): `assets/images/ac%20logo.png` / `assets/images/ac%20logo%20removedbg.png`

## General
- PHP/MySQL stack running on XAMPP locally.
- The primary working directory is `c:\xampp\htdocs\Akuapemconnect`.
- Community landing page: `index.php` (logged-in users land here after sign-in).
- Admin dashboard: `admin/index.php` (uses AJAX to load sub-pages).
- Shared rich-text editor: `assets/js/rich-editor.js` — attach `class="rich-editor"` to any textarea.
