# Diwan - SaaS Coworking Platform in Gaza

## Project Overview
Diwan is a SaaS platform that gathers multiple coworking spaces/hubs in Gaza.
Clients search hubs by city and book a seat/room by hour/day/month.
Hub owners manage branches, seats, shifts, bookings, and cash payments.
Target: low-bandwidth, mobile-first, Arabic RTL, cash-on-arrival (no online payment in MVP).

## Architecture

### High-Level Overview
Client Browser (Bootstrap RTL, light pages)
  -> Apache/PHP 8 on shared cPanel (public/ + dashboard/)
  -> Logic Layer (includes/auth.php, functions.php)
  -> MySQL via PDO (single DB, multi-tenant by hub_id)

No framework, no composer, no Node, no API server in MVP.
All requests are classic form POST + server render + redirect.

### Layers - 3 Layers Only
1. Presentation Layer: `public/*.php`, `dashboard/*/*.php`, `assets/`
   - Only HTML + Bootstrap + minimal JS.
   - No direct SQL. Call functions from includes/.
   - All pages `lang="ar" dir="rtl"`.

2. Logic Layer: `includes/auth.php`, `includes/functions.php`
   - Auth, role check, validation, pricing, double-booking check.
   - Example: `createBooking()`, `confirmAttendance()`, `getHubIncome()`.
   - This is the only place that talks to DB.

3. Data Layer: `config/db.php`, `sql/schema.sql`
   - PDO singleton, prepared statements only.
   - Schema uses `hub_id` for tenant isolation.

### Request Flow Example - Booking a Seat
1. Client POST to `public/booking.php` (date, shift, seat_id)
2. `booking.php` calls `requireLogin()` + `createBooking()` from logic layer
3. Logic checks: login? seat belongs to hub_id? already booked same seat+date+shift? shift valid?
4. Insert into `bookings` with status=pending + generate qr_code string
5. Redirect to `my-bookings.php` with success message
No AJAX required in MVP except optional availability check.

### SaaS Multi-tenancy Architecture
- Single DB, shared tables. Tenant key = `hubs.id`.
- Every tenant table has `hub_id`: branches, rooms, seats, shifts, bookings, payments_cash, reviews.
- Rule: hub_admin/staff queries MUST include `WHERE hub_id = $_SESSION['hub_id']`.
- Never take hub_id from GET/POST. Take it from session after login.
- super_admin dashboard is the only code allowed to query across hubs.
- New hub onboarding: hubs(status=pending) -> super approves -> create subscription in hub_subscriptions -> status=active.

### Database Architecture
saas_plans (platform plans) -> hub_subscriptions -> hubs -> branches -> rooms -> seats
users -> bookings -> attendance_qr + payments_cash
users -> reviews -> hubs
Core invariant: one seat cannot have 2 rows in bookings with same seat_id + booking_date + shift + status in (pending,confirmed).

### Hosting & Gaza Constraints Architecture
- Shared hosting friendly: no background jobs, no websockets.
- Pages must be <1.5MB, compressed images, lazy load hub photos.
- Cash payment only in MVP, recorded in payments_cash by staff.
- Emergency close: hub_admin inserts into emergency_closures, logic layer blocks new bookings for that date.
- QR attendance is offline-friendly: pre-generated string, staff scans visually or by phone camera, no live API.

## Tech Stack
- Frontend: HTML5, CSS3, JS vanilla, Bootstrap 5 RTL
- Backend: PHP 8 native, PDO only
- DB: MySQL/MariaDB via phpMyAdmin
- Auth: PHP Sessions, password_hash + password_verify

## Local dev (WAMP)
- Docroot: `C:\wamp64\www\Diwan` → `http://localhost/Diwan`
- No `public/` docroot locally in MVP — Apache serves repo root
- Verify with `php -l <file>`, then reload in browser

## Folder Structure Mapped to Architecture
/config/db.php = data connection
/includes/auth.php, functions.php = logic layer
/public/index.php, search.php, hub.php, booking.php = presentation for clients
/dashboard/super/ = presentation for platform owner
/dashboard/hub/ = presentation for hub_admin + staff
/assets/css/style.css, /assets/js/main.js = static
/sql/schema.sql = data definition

## Roles
1. super_admin: manage hubs, plans, subscriptions
2. hub_admin: own branches, seats, prices, reports, emergency close
3. staff: today bookings, confirm attendance
4. client: search, book, cancel, reviews
Enforce via requireRole([]) at top of every dashboard file.

## PHP Rules
- PDO prepared statements only.
- Escape output with htmlspecialchars().
- Validate date/shift server-side.
- POST for state-changing actions + CSRF token.
- Session start only in includes/auth.php.

## Frontend Rules
- Bootstrap 5 RTL CDN, custom CSS only in assets/css/style.css
- Arabic RTL, mobile-first, booking in <3 steps
- Hub card must show: electricity status, internet strength, generator/solar

## Security Rules
- Hash passwords, validate uploads jpg/png/webp max 2MB, rename files.
- Staff cannot access finance reports. Hub cannot see other hub data.

## MVP Scope - Do Not Expand
IN: hub register + approval, 1 branch + seats + 2 shifts, search + book + cancel, attendance confirm, simple income report.
OUT: online payment, mobile app, map, PalPay.

## Definition of Done
- Runs on PHP 8 shared hosting without composer
- Role check on all dashboard pages, no SQLi/XSS
- Correct Arabic RTL on mobile, light pages