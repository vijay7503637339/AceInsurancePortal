# Ace Insurance Portal — Phase 1 Foundation

PHP 8.1+ and MySQL foundation for an insurance portal with separate Customer Login and Agency Login (admin/staff share Agency Login).

## Phase 1 includes
- Customer/agency login foundation with password hashing, session hardening, CSRF tokens and role checks.
- DOT-gated customer signup: DOT and email must match the insured record maintained by agency; email verification code is required before password setup.
- Agency CRUD for insured customers, vehicles, policies and drivers.
- Admin-only staff creation and admin-only audit log viewer; staff actions are logged and staff cannot access logs.
- Base `transactions` and `claims` tables pending the detailed client field requirements.
- MySQL schema and cPanel setup guide.

## Install on cPanel
1. Set PHP to 8.1+ with PDO MySQL enabled.
2. Run `composer install --no-dev --optimize-autoloader`.
3. Create database/user in cPanel and import `database/schema.sql` using phpMyAdmin.
4. Copy `config/database.example.php` to `config/database.php` and enter credentials. Copy `config/mail.example.php` to `config/mail.php` and configure a real sender address.
5. Preferred: serve `public/` as the document root and keep `app/`, `config/`, `database/`, `storage/` and `vendor/` outside public HTTP access. For a cPanel subfolder deployment at `public_html/ace`, the root `.htaccess` supports `/ace/login.php` routes and denies direct web access to private project directories; this requires Apache `mod_rewrite` and `AllowOverride`.
6. Create the initial admin using CLI only: `php scripts/create_admin.php \"Portal Admin\" admin@example.com \"A-STRONG-PASSWORD\"`.
7. Configure/verify outbound email. Phase 1 uses PHP `mail()` fallback; production SMTP with SPF/DKIM should be configured before live use.
8. Visit `/login.php`. Both admin and staff use Agency Login; customers use Customer Login.

## Important scope notes
- DOT checks match the locally stored insured/customer record. This is not an external FMCSA verification.
- Transactions and claims fields are starter fields until client confirms the specifications.
- Certificate PDF generation will be connected after policy/vehicle fields and PDF requirements are finalized.
- This foundation needs end-to-end testing with the client hosting, database, and mail settings before production.
- Use HTTPS and backups before handling real insurance/customer data.
