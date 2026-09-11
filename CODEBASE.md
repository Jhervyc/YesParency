# YesParency — Codebase Reference

> Navigation document for AI agents and developers.
> Read this before touching any file in the project.

---

## Table of Contents

1. [What This System Is](#1-what-this-system-is)
2. [Tech Stack](#2-tech-stack)
3. [Environment & Configuration](#3-environment--configuration)
4. [Folder Structure](#4-folder-structure)
5. [Role System & Access Control](#5-role-system--access-control)
6. [Database Schema](#6-database-schema)
7. [Procurement Lifecycle](#7-procurement-lifecycle)
8. [Bid Opening Lifecycle](#8-bid-opening-lifecycle)
9. [Key Files — Quick Reference](#9-key-files--quick-reference)
10. [Shared Components](#10-shared-components)
11. [Email System](#11-email-system)
12. [Notification System](#12-notification-system)
13. [Live Streaming](#13-live-streaming)
14. [Cron Jobs](#14-cron-jobs)
15. [Security & File Access](#15-security--file-access)
16. [CSS & Frontend Conventions](#16-css--frontend-conventions)
17. [Audit Logging](#17-audit-logging)
18. [Known Issues & Pending Work](#18-known-issues--pending-work)
19. [Session & Auth Flow](#19-session--auth-flow)
20. [Changelog — What Was Built / Changed](#20-changelog--what-was-built--changed)

---

## 1. What This System Is

**YesParency** is a government procurement transparency portal for **Southern Luzon State University (SLSU)**. It digitizes the full procurement lifecycle under Philippine R.A. 9184, including:

- Public posting of procurement opportunities
- Bidder accreditation and document management
- Electronic bid submission (encrypted)
- Live bid opening sessions with dual-key BAC signing
- Contract awarding and public transparency

**Live URL:** `yesparency.site`
**Local dev:** `http://localhost/YesParency/`

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 — procedural, no framework |
| Database | MySQL/MariaDB via MySQLi (no ORM) |
| Frontend | Vanilla JS, no build tools |
| Icons | Bootstrap Icons 1.11.3 (CDN) |
| Fonts | Poppins, Space Grotesk (Google Fonts CDN) |
| Real-time | Pusher (bid opening events, live chat) |
| Live Stream | MediaMTX — HLS protocol |
| Email | PHPMailer 7.x via SMTP, async queue |
| PDF | DomPDF 3.x |
| Env Config | vlucas/phpdotenv 5.x |
| Hosting | Hestia CP / Agila Host (Apache + cPanel) |

**Composer dependencies** (`composer.json`):
```json
{
  "vlucas/phpdotenv": "^5.6",
  "dompdf/dompdf": "^3.1",
  "pusher/pusher-php-server": "^7.3",
  "phpmailer/phpmailer": "^7.1"
}
```

> ⚠️ `vendor/` is NOT in git. Run `composer install` after cloning.

---

## 3. Environment & Configuration

**File:** `.env` (root)

```
APP_NAME=YesParency
APP_URL=https://yesparency.site        # Must be set on live — used for email links

DB_HOST=localhost
DB_NAME=yesparency_db
DB_USER=root                           # Change for production
DB_PASS=

MEDIAMTX_HOST=                         # HLS stream server hostname
MEDIAMTX_HLS_PORT=8888                 # HLS port
MEDIAMTX_RTMP_PORT=1935
MEDIAMTX_DEFAULT_PATH=live

FINANCIAL_KEY=                         # 64-char hex — bid encryption key
ELIGIBILITY_KEY=                       # 64-char hex — bid encryption key

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=ap3

MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=YesParency

CRON_KEY=yesparency_cron_secure_key    # Used to protect web-triggered cron calls
```

**`bootstrap.php`** — loads dotenv via `safeLoad()` (won't crash on CRLF issues from Windows uploads).

**`config/db_connect.php`** — reads `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` from `$_ENV` (via its own `require_once __DIR__ . '/../bootstrap.php'`, so it works whether or not the caller loaded dotenv first), falling back to local defaults (`localhost` / `root` / `''` / `yesparency_db`) if a key is unset.

**`config/mediamtx.php`** — MediaMTX config helper. Reads from `system_settings` DB table first, falls back to `.env`, falls back to hardcoded defaults. Has in-memory cache with sentinel file invalidation at `storage/mediamtx_cache.bust`.

**`config/pusher.php`** — Pusher client factory.

---

## 4. Folder Structure

```
YesParency/
│
├── index.php                   # Public landing page — SELF-CONTAINED (no style.css)
├── login.php                   # Login — all roles share this
├── logout.php
├── register.php                # Public access request form (not direct registration)
├── forgot-password.php         # Password reset request form
├── reset-password.php          # Password reset via token
├── accept_invitation.php       # Invitation token → account creation
├── live.php                    # Public live bid opening viewer
├── live_chat_api.php           # Pusher chat API for live.php
├── stream_health.php           # Public server-side HLS health check — ?path=X → {"online": bool}
├── bid_schedule.php            # Public procurement schedule page
├── bid_view.php / bid_view.php # Public bid detail page
├── bootstrap.php               # Dotenv loader (safeLoad)
├── style.css                   # Shared CSS — login, register, public pages
├── dashboard.css               # Shared CSS — ALL dashboard panels (admin/bidder/user)
├── query.sql                   # ⭐ Full DB schema — read this for table definitions
│
├── admin/                      # Admin panel (Secretariat + BAC + TWG + Superadmin)
│   ├── components/
│   │   ├── sidebar.php         # Role-aware sidebar (BAC/TWG restricted, superadmin extras)
│   │   ├── topbar.php
│   │   └── notifications.php   # Topbar notification bell
│   ├── utils/
│   │   ├── protect-page.php    # Auth guard — allows role=admin AND role=superadmin
│   │   ├── protect-secretariat.php  # Additional guard — blocks BAC/TWG from secretariat pages
│   │   └── audit_helper.php    # audit_log() helper function
│   ├── dashboard.php           # Secretariat/Superadmin dashboard
│   ├── dashboard-twd-bac.php   # BAC/TWG restricted dashboard
│   ├── procurement.php         # Procurement list (Secretariat)
│   ├── procurement-twd-bac.php # Procurement list (BAC/TWG read-only)
│   ├── create_procurement.php
│   ├── review_procurement.php  # Review + publish procurement
│   ├── manage_lots.php
│   ├── view_procurement.php
│   ├── procurement-view.php
│   ├── bid_opening.php         # Bid opening management hub
│   ├── bid_session.php         # Live bid opening session room
│   ├── bid_session_api.php     # ⭐ All bid session actions (AJAX) — signing, awarding, ending
│   ├── bid-session-list.php
│   ├── schedule_bid_opening.php
│   ├── bid_submissions.php
│   ├── bid-submission-view.php
│   ├── quotation_management.php
│   ├── checklist_pdf.php       # DomPDF bid opening checklist report
│   ├── account-management.php  # Bidder account management
│   ├── approve_bidder.php
│   ├── reject_bidder.php
│   ├── bidder-profile.php
│   ├── get_bidder.php
│   ├── get_procurements.php
│   ├── announcements.php
│   ├── invitation_requests.php # Approve/reject access requests
│   ├── live_event.php
│   ├── audit_trail.php
│   ├── notification.php        # Full notifications page
│   ├── notifications_api.php   # Notifications AJAX API
│   ├── settings.php            # ⭐ Settings — Profile, Checklist, Live, System Config*, Maintenance*
│   │                           #    (* superadmin only tabs)
│   └── user-role-management.php # Superadmin only — admin account CRUD
│
├── bidder/                     # Bidder portal
│   ├── components/             # sidebar, topbar, notifications
│   ├── utils/protect-page.php  # Auth guard — role=bidder only
│   ├── dashboard.php
│   ├── procurement.php
│   ├── view_procurement.php
│   ├── submit_bid.php
│   ├── submit_quotation.php
│   ├── my_bids.php
│   ├── bid_opening.php         # Bidder view of bid opening
│   ├── bid-session-list.php
│   ├── notification.php
│   ├── notifications_api.php
│   └── settings.php            # Profile + document management
│
├── user/                       # General public / pre-bidder
│   ├── components/
│   ├── utils/protect-page.php  # Auth guard — role=user only
│   ├── dashboard.php
│   ├── procurement.php
│   ├── view_procurement.php
│   ├── bidder-registration.php # Apply for bidder accreditation
│   └── settings.php
│
├── config/
│   ├── db_connect.php          # Reads DB_* from .env via $_ENV
│   ├── mediamtx.php            # Stream config helper with DB cache
│   └── pusher.php
│
├── utils/
│   ├── mailer.php              # ⭐ All email functions + queue system
│   ├── bidder_document_helper.php
│   ├── crypto.php              # Bid encryption/decryption
│   ├── procurement_mode_helper.php
│   └── email_templates/
│       ├── layout.php          # Master email layout — dark green #06251b header
│       ├── bidder_approved.php
│       ├── bidder_rejected.php
│       ├── bid_verified.php
│       ├── bid_rejected.php
│       ├── award_notification.php
│       ├── session_scheduled.php
│       ├── session_concluded.php
│       ├── doc_expiring_30.php
│       ├── doc_expiring_7.php
│       ├── doc_expired.php
│       ├── invitation_approved.php
│       ├── invitation_rejected.php
│       ├── secretariat_doc_reuploaded.php
│       └── password_reset.php
│
├── cron/
│   ├── close_procurements.php          # Auto-close overdue procurements
│   ├── process_email_queue.php         # SMTP email sender worker
│   └── check_document_expirations.php  # Bidder document expiry notifier
│
├── includes/
│   └── navbar.php              # Public navbar component + CSS
│
├── uploads/
│   ├── .htaccess               # Allows images/PDFs, blocks all script execution
│   ├── profile/                # Avatars — publicly accessible
│   ├── bidders/{user_id}/      # Eligibility docs — ⚠️ needs PHP proxy (pending)
│   └── procurements/           # Procurement docs — publicly accessible
│
├── storage/
│   └── mediamtx_cache.bust     # Sentinel file for MediaMTX config cache invalidation
│
├── config/
│   └── .htaccess               # Blocks all direct HTTP access to config files
│
├── debug/                      # Dev-only debug scripts — remove before production
│   ├── add-account.php
│   ├── live_debug.php
│   ├── show_bids.php
│   └── show_procurement.php
│
└── vendor/                     # Composer — NOT in git, run composer install
```

---

## 5. Role System & Access Control

### Roles (stored in `users.role`)

| Role | Folder | Description |
|---|---|---|
| `superadmin` | `admin/` | Full system access. Same panel as admin, extra tabs in settings, User & Role Management page |
| `admin` | `admin/` | Secretariat / BAC / TWG — sub-role from `admin_roles.admin_type` |
| `bidder` | `bidder/` | Accredited supplier |
| `user` | `user/` | General public / pending bidder applicant |

### Admin Sub-Roles (`admin_roles.admin_type`)

| Sub-role | Access |
|---|---|
| `SECRETARIAT` | Full admin access — can manage procurements, bids, bidders, settings |
| `BAC` | Read-only — bid opening participation, restricted dashboard |
| `TWG` | Same as BAC |

### Guard Files

| File | Protects | Allows |
|---|---|---|
| `admin/utils/protect-page.php` | All admin pages | `role=admin` AND `role=superadmin` |
| `admin/utils/protect-secretariat.php` | Secretariat-only pages | `admin_type=SECRETARIAT` only |
| `bidder/utils/protect-page.php` | All bidder pages | `role=bidder` only |
| `user/utils/protect-page.php` | All user pages | `role=user` only |

### Sidebar Visibility Rules (`admin/components/sidebar.php`)

- BAC/TWG (`$is_restricted = true`): sees limited nav — no account management, no announcements, no invitations
- Secretariat: sees full nav
- Superadmin (`$_SESSION['role'] === 'superadmin'`): sees everything + **Super Admin** section with User & Role Management

### Settings Tab Visibility (`admin/settings.php`)

- All admin/superadmin: Profile, Checklist, Live tabs
- Superadmin only: System Config tab, Maintenance tab

---

## 6. Database Schema

> Full DDL in `query.sql`. Read that file for exact column types and constraints.

### Core Tables

| Table | Key Columns | Notes |
|---|---|---|
| `users` | `user_id`, `role`, `status`, `email`, `profile_picture_url` | All roles in one table |
| `admin_roles` | `user_id`, `admin_type` | BAC / TWG / SECRETARIAT |
| `procurements` | `id`, `status`, `closing_date`, `opening_date`, `philgeps_ref_no` | Status: draft/open/closed/awarded/cancelled |
| `lots` | `id`, `procurement_id`, `status` | Status: pending/awarded/failed |
| `bids` | `id`, `bidder_id`, `procurement_id`, `status` | Status: submitted/opened/pending/awarded/rejected |
| `bid_lots` | `bid_id`, `lot_id`, `eligibility_status`, `financial_status` | Bridge table |
| `bid_opening_sessions` | `id`, `procurement_id`, `stream_path`, `status`, `signing_status` | Session lifecycle |
| `bid_lot_signatures` | `bid_lot_id`, `user_id`, `signed_at` | Dual-key BAC signing |
| `awards` | `lot_id`, `bid_lot_id`, `awarded_amount` | Contract awards |
| `bidder_profiles` | `user_id`, `business_name`, `tin_number`, `application_status` | |
| `bidder_documents` | `document_id`, `user_id`, `document_type`, `file_path`, `expiration_date` | |
| `system_notifications` | `notification_id`, `target_type`, `target_role`, `target_user_id`, `created_at` | target_type: all/role/user |
| `user_notification_reads` | `notification_id`, `user_id`, `read_at` | Read tracking |
| `system_settings` | `setting_key`, `setting_value` | Key-value: org info, MediaMTX config |
| `email_queue` | `id`, `recipient_email`, `template`, `payload`, `status`, `attempts` | Async email queue |
| `password_resets` | `email`, `token_hash`, `expires_at`, `used` | SHA-256 hash, 15-min expiry, DELETE on use |
| `user_invitations` | `email`, `token`, `status`, `expires_at` | DELETE on use after account created |
| `invitation_requests` | `id`, `email`, `status`, `admin_notes` | DELETE after approve/reject |
| `audit_logs` | `user_id`, `action`, `module`, `record_id`, `old_values`, `new_values`, `ip_address` | |
| `checklist_templates` | `id`, `procurement_type`, `checklist_type`, `item_name`, `is_required` | Configurable per procurement type |
| `bid_checklist` | `template_item_id`, `bid_lot_id` | Evaluation checklist per lot |
| `announcement` / `announcements` | | System-wide announcements |

### Important Column Notes

- `procurements.philgeps_ref_no` — PHP code uses this name; SQL schema has an ALTER renaming it from `philgeps_ref_no` to `procurement_ref_no` that was never applied to PHP
- `bidder_documents.file_path` — stored as `../uploads/bidders/{id}/filename` — relative path for server-side use, NOT a direct browser URL
- `system_notifications.created_at` — used to filter notifications for new accounts (only show notifs created after user's `users.created_at`)

---

## 7. Procurement Lifecycle

```
(create) → draft
draft    → open         [admin: review_procurement.php — confirm_publish POST]
open     → closed       [auto: cron/close_procurements.php when closing_date <= NOW()]
                        [manual: admin/schedule_bid_opening.php when session is created]
closed   → awarded      [admin: bid_session_api.php — end_session action, if ≥1 lot awarded]
closed   → closed       [admin: bid_session_api.php — end_session action, 0 lots awarded]
any      → cancelled    [no code currently implements this transition]

Lots:    pending → awarded / failed
Bids:    submitted → opened → pending → awarded / rejected
```

### Auto-Close Logic (`cron/close_procurements.php`)

- Runs every minute
- Finds `status='open'` procurements where `closing_date <= NOW()`
- Sets procurement `status='closed'`
- Sets lots `status='failed'` where no `bid_lots` entries exist for that lot
- Lots with bids stay `pending` — await bid opening session
- Writes `PROCUREMENT_AUTO_CLOSED` or `PROCUREMENT_AUTO_CLOSED_NO_BIDS` audit log
- Full transaction — rolls back on any error

---

## 8. Bid Opening Lifecycle

```
Session statuses: scheduled → started → eligibility → financial → awarding → ended

1. Secretariat creates session (schedule_bid_opening.php)
   → bid_opening_sessions row inserted, status='scheduled'
   → procurement status set to 'closed'
   → invited BAC/TWG members notified by email

2. Secretariat clicks "Open Now" (bid_opening.php or bid-session-list.php)
   → status='started'

3. Live stream begins (live.php — HLS via MediaMTX)
   → Public can watch
   → Chat via Pusher

4. Secretariat signals open (bid_session_api.php — action=signal_open)
   → signing_status='signing'

5. BAC members sign lots with their password (bid_session_api.php — action=sign_lot)
   → bid_lot_signatures rows inserted
   → Quorum check — requires majority of invited BAC members

6. Evaluation phases: eligibility → financial
   → bid_lots.eligibility_status, bid_lots.financial_status updated

7. Awarding (bid_session_api.php — action=award_lot)
   → awards row inserted
   → lot.status='awarded'
   → bid.status='awarded' for winner

8. End session (bid_session_api.php — action=end_session)
   → session.status='ended'
   → Remaining pending lots → status='failed'
   → Non-awarded bids → status='rejected'
   → procurement status → 'awarded' (if any lot awarded) or 'closed'
   → Email notification to all participants
```

**Key file:** `admin/bid_session_api.php` — all session actions are AJAX POST to this file with `$_POST['action']`

---

## 9. Key Files — Quick Reference

| File | What It Does |
|---|---|
| `query.sql` | Complete DB schema — always check here first for table structure |
| `admin/bid_session_api.php` | All bid opening session logic — signing, awarding, ending |
| `admin/settings.php` | Settings page — Profile/Checklist/Live tabs (all), System Config/Maintenance (superadmin) |
| `admin/components/sidebar.php` | Role-aware sidebar — read this to understand nav visibility logic |
| `admin/utils/protect-page.php` | Admin auth guard — allows both `admin` and `superadmin` |
| `admin/utils/audit_helper.php` | `audit_log()` function — call this for every significant action |
| `utils/mailer.php` | All email send functions + queue system + `process_email_queue()` |
| `config/mediamtx.php` | Stream URL builder — `mediamtx_url($path)`, `mediamtx_config()` |
| `stream_health.php` | Public server-side HLS stream health check — `?path=X` → `{"online": bool}` |
| `config/db_connect.php` | DB connection — reads `DB_*` from `.env` via `$_ENV` |
| `bootstrap.php` | Dotenv loader — `safeLoad()` to avoid CRLF crashes |
| `dashboard.css` | Shared dashboard styles used by ALL panel pages |
| `style.css` | Shared public page styles — login, register, NOT index.php |
| `index.php` | Self-contained — has all CSS inline, does NOT load style.css |

---

## 10. Shared Components

### Dashboard Layout Pattern
Every dashboard page follows this structure:
```php
include("utils/protect-page.php");   // Auth guard + session + DB connection
// ... PHP logic ...
?>
<!DOCTYPE html>
<head>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../dashboard.css">
</head>
<body class="dash-body">
<?php include("components/sidebar.php"); ?>
<?php include("components/topbar.php"); ?>
<main class="dash-content">
    <!-- page content -->
</main>
```

### Sidebar Active State
Set `$active_nav` before including the sidebar to override auto-detection:
```php
$active_nav = 'procurement.php';
include("components/sidebar.php");
```

### Notifications
- `components/notifications.php` — bell icon in topbar, fetches from `notifications_api.php`
- Admin: `admin/notifications_api.php`
- Bidder: `bidder/notifications_api.php`
- All notification queries include: `AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)` — new accounts don't see old notifications

### Avatar Pattern
```php
// From any subfolder (admin/, bidder/, user/)
$avatar_url = !empty($_SESSION['profile_picture_url'])
    ? '../' . ltrim($_SESSION['profile_picture_url'], '/')
    : '';
// profile_picture_url stored as: uploads/profile/filename.ext
```

---

## 11. Email System

### Architecture
1. Code calls a `notify_*()` or `send_*_email()` function in `utils/mailer.php`
2. Function calls `queue_email()` → inserts into `email_queue` table
3. For time-sensitive emails (password reset), `send_queued_email()` is called immediately after queuing
4. `cron/process_email_queue.php` runs every minute to flush pending queue items

### Template System
```php
// In utils/mailer.php
mailer_render_template('template_name', [
    'subject'        => 'Email Subject',
    'recipient_name' => 'User Name',
    // ... template-specific vars
]);
```
Templates live in `utils/email_templates/`. `layout.php` wraps all templates.

### Email Functions in `utils/mailer.php`

| Function | Trigger |
|---|---|
| `notify_bidder_approved()` | Admin approves bidder account |
| `notify_bidder_rejected()` | Admin rejects bidder account |
| `notify_bid_verified()` | Admin verifies bid submission |
| `notify_bid_rejected()` | Admin rejects bid |
| `notify_lot_awarded()` | Lot is awarded to winning bidder |
| `notify_bid_session_scheduled()` | Bid opening session created |
| `notify_bid_session_concluded()` | Bid opening session ends |
| `notify_invitation_approved()` | Admin approves access request |
| `notify_invitation_rejected()` | Admin rejects access request |
| `notify_bidder_document_expiring()` | 30/7 days before doc expiry (from cron) |
| `notify_bidder_document_expired()` | On/after doc expiry (from cron) |
| `notify_secretariat_document_reuploaded()` | Bidder uploads new doc |
| `send_password_reset_email()` | Password reset requested |

### Email Template Design System
- Header: `#06251b` dark green background, "YesParency" with `#ffc107` yellow accent
- Button: `#06251b` background, `#ffc107` text
- Info cards: `#f4f8f5` background, `#d4e0d8` border
- Font: Poppins
- Footer: Brand name + Portal Home + Sign In links only — no legal disclaimers

---

## 12. Notification System

### Types
- `target_type='all'` — everyone sees it
- `target_type='role'` — specific role (bidder, admin, superadmin)
- `target_type='user'` — specific user

### Read Tracking
`user_notification_reads` table — `notification_id + user_id` unique pair.

### Important Behavior
New accounts only see notifications created **after** their `users.created_at`. This filter is applied in ALL notification queries:
```sql
AND sn.created_at >= (SELECT created_at FROM users WHERE user_id = ?)
```
This affects: `notifications_api.php` (fetch, mark_read, mark_all_read) and `notification.php` (all stat queries + paged query) — in both `admin/` and `bidder/`.

---

## 13. Live Streaming

### Technology
- **Protocol:** HLS (HTTP Live Streaming) — NOT WebRTC
- **Server:** MediaMTX
- **Player:** `<iframe>` pointed at the HLS manifest URL, gated by a server-side health check (`stream_health.php`) before it's rendered. Migration to `<video>` + hls.js is still pending — see Known Issues.

### Config Keys in `system_settings`
| Key | Default | Description |
|---|---|---|
| `mediamtx_host` | `localhost` | MediaMTX server hostname |
| `mediamtx_hls_port` | `8888` | HLS playback port |
| `mediamtx_rtmp_port` | `1935` | RTMP ingest port |
| `mediamtx_default_path` | `live` | Default stream path used when creating new bid sessions |
| `mediamtx_manifest` | `index.m3u8` | HLS manifest filename appended to the stream path |

All editable from Admin/Superadmin → Settings → Live tab (`admin/settings.php`), backed by `config/mediamtx.php`'s DB→`.env`→hardcoded fallback chain (see §3).

### HLS URL Format
```
http://{host}:{hls_port}/{stream_path}/{manifest}
```
Built by `mediamtx_url($streamPath)` in `config/mediamtx.php`.

### Stream Health Check
`stream_health.php` (root, public/unauthenticated like `live.php`) — `GET ?path=<stream_path>` does a short-timeout server-side `HEAD` request against the computed HLS manifest URL and returns `{"online": true|false}`. Every page that embeds the stream calls this before pointing an `<iframe>` at MediaMTX, instead of rendering the iframe unconditionally and hoping the stream is actually up.

### Stream Path
Stored in `bid_opening_sessions.stream_path`. Used to build the playback URL via `mediamtx_url($path)` in `config/mediamtx.php`.

### Files That Embed the Stream
- `live.php` — public viewer. Shows a "connecting…" state and polls `stream_health.php` every 5s until online, then swaps in the iframe.
- `admin/bid_opening.php` — "Open Now" modal preview, health-checked on open.
- `admin/bid-session-list.php` — same modal (duplicated markup/JS).
- `admin/live_event.php` — stream preview card, health-checked on load. Note: this file currently `header()`-redirects to `bid_opening.php` unconditionally near the top, so this code path is presently unreachable — kept correct for if/when that redirect is removed.

`admin/bid_session.php` references `stream_path` (for other session logic) but does not embed a player.

---

## 14. Cron Jobs

All cron files follow the same pattern:
1. Security gate: CLI only, or web with `CRON_KEY` query param
2. `require_once '../config/db_connect.php'`
3. Validate `$conn`
4. Process
5. Print summary report to stdout
6. `exit(0)` on success, `exit(1)` on errors

### Hestia CP Setup

| File | Schedule | Description |
|---|---|---|
| `cron/process_email_queue.php` | `* * * * *` | Send pending emails from `email_queue` |
| `cron/close_procurements.php` | `* * * * *` | Auto-close overdue open procurements |
| `cron/check_document_expirations.php` | `0 6 * * *` | Notify bidders of expiring docs |

**Command format:**
```bash
php /home/USERNAME/web/yesparency.site/public_html/cron/FILENAME.php
```

---

## 15. Security & File Access

### `.htaccess` Files

**`uploads/.htaccess`**
- Allows direct browser access to: jpg, jpeg, png, gif, webp, svg, ico, pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv
- Blocks ALL script execution (PHP engine disabled, `.php` files explicitly denied)
- Blocks direct access to everything else

**`config/.htaccess`**
- Denies all direct HTTP access to the config folder
- PHP `include`/`require` still works fine

### Pending Security Work
- `uploads/bidders/` — bidder eligibility documents (tax IDs, government IDs) are currently accessible via direct URL if the path is known. **Needs a PHP proxy** (`bidder/get_document.php?id=X`) that checks auth before streaming the file. A separate `.htaccess` in `uploads/bidders/` should block direct access entirely.

### Password Reset Security
- Raw token: `bin2hex(random_bytes(32))` — 64 hex chars, URL only
- Stored: `hash('sha256', $rawToken)` — never raw token in DB
- Expiry: `DATE_ADD(NOW(), INTERVAL 15 MINUTE)` — pure MySQL, no PHP date math
- After use: `DELETE FROM password_resets WHERE token_hash = ?`
- No email enumeration: same success message shown regardless of whether email exists

### Invitation Security
- Token deleted from `user_invitations` after account creation (not just marked used)
- `invitation_requests` row deleted after approve or reject (audit log covers the record)

---

## 16. CSS & Frontend Conventions

### File Responsibilities
| File | Used By |
|---|---|
| `style.css` | `login.php`, `register.php`, `accept_invitation.php`, `forgot-password.php`, `reset-password.php`, `bid_schedule.php`, `live.php` |
| `dashboard.css` | ALL pages inside `admin/`, `bidder/`, `user/` |
| Inline `<style>` | `index.php` (self-contained, does NOT load style.css) |

### Color System
| Token | Hex | Usage |
|---|---|---|
| Primary dark green | `#06251b` | Background, buttons, nav |
| Medium green | `#1f7a3d` | Accents, active states |
| Yellow accent | `#ffc107` | Button text, highlights, active nav |
| Light green bg | `#f4f8f5` | Page backgrounds, card backgrounds |
| Border | `#eaeeec` / `#d4e0d8` | Card borders |
| Text muted | `#55665a` / `#88968d` | Secondary text |

### Card Pattern
```css
.set-card {
    background: #ffffff;
    border: 1px solid #eaeeec;
    border-radius: 18px;
    box-shadow: 0 1px 2px rgba(16,36,26,.03), 0 10px 24px -14px rgba(16,36,26,.06);
}
```

### Button Pattern
```css
/* Primary */
background: #06251b; color: #ffc107;
/* Hover */
background: #144937; color: #ffffff;
```

---

## 17. Audit Logging

**Function:** `audit_log()` in `admin/utils/audit_helper.php`

**Signature:**
```php
audit_log(
    mysqli $conn,
    string $action,       // e.g. 'PROCUREMENT_CREATED', 'BID_SESSION_ENDED'
    string $module,       // e.g. 'procurements', 'bids', 'users', 'settings'
    ?int   $record_id,    // PK of affected row, or null
    string $description,  // Human-readable summary
    mixed  $old_values,   // State before (array or null)
    mixed  $new_values,   // State after (array or null)
    ?int   $user_id       // Defaults to $_SESSION['user_id']
);
```

**Call it for every significant action** — procurement publish, bid session start/end, award, password change, settings update, etc. The audit trail is the primary record for actions that clean up their source rows (e.g. invitation requests deleted after approval).

---

## 18. Known Issues & Pending Work

### High Priority
1. **`uploads/bidders/` direct URL access** — eligibility documents (tax IDs, gov IDs) accessible via direct URL. Fix: add `.htaccess` to `uploads/bidders/` blocking all access, create PHP proxy `bidder/get_document.php?id=X` and `admin/get_document.php?id=X`.

2. **Live stream still uses iframe** — should be migrated to `<video>` + hls.js. Files affected: `live.php`, `admin/bid_opening.php`, `admin/bid-session-list.php`, `admin/live_event.php`. (Deliberately deferred — the iframe is now gated by a server-side health check instead, see §13.)

### Medium Priority
3. **`invitation_requests` delete on reject** — currently only approved rows get deleted. Rejected rows should also be deleted (or decide to keep for record — audit log covers it either way).

4. **Superadmin folder (`superadmin/`)** — was the original superadmin panel, now merged into `admin/`. The `superadmin/` folder no longer exists but may still have references in old bookmarks. Confirm all old routes are handled.

5. **`debug/` folder** — contains `add-account.php`, `show_bids.php` etc. These are development utilities that should be removed or protected before production.

6. **`admin/live_event.php` is dead code** — it `header()`-redirects to `bid_opening.php` unconditionally near the top of the file, so everything below it (including its stream preview card) never executes. Confirm it's safe to delete, or remove the redirect if it's meant to be reachable.

### Low Priority / Future
7. **Procurement `cancelled` status** — enum value exists in schema but no code transitions a procurement to `cancelled`.

8. **Forgot password for inactive users** — `forgot-password.php` only finds users with `status='active'`. Inactive bidders pending approval can't reset their password.

---

## 19. Session & Auth Flow

### Login (`login.php`)
1. POST `username` + `password`
2. Query `users` by username
3. `password_verify()` against stored hash
4. `session_regenerate_id(true)` — prevents session fixation
5. Set `$_SESSION['user_id']`, `['username']`, `['role']`, `['last_activity']`
6. Redirect by role

### Session Guards (all `protect-page.php` files)
1. `session_start()` with secure cookie params
2. Include `config/db_connect.php`
3. Check `$_SESSION['user_id']` + `$_SESSION['role']` match
4. Inactivity timeout: 900 seconds (15 min) — redirect to `login.php?error=session_timeout`
5. Hydrate `$_SESSION['profile_picture_url']` from DB if not set

### Password Reset Flow
```
forgot-password.php (POST email)
  → DELETE old tokens for email
  → Generate: $rawToken = bin2hex(random_bytes(32))
  → Store:    hash('sha256', $rawToken) + DATE_ADD(NOW(), INTERVAL 15 MINUTE)
  → Email:    APP_URL/reset-password.php?token={rawToken}
  → Always show success (no enumeration)

reset-password.php (GET token)
  → hash('sha256', token) → lookup password_resets WHERE expires_at > NOW()
  → If not found: show error + link to forgot-password.php
  → If found: show password form

reset-password.php (POST)
  → Re-validate token (race condition guard)
  → Validate password rules (min 8, uppercase, lowercase, number)
  → UPDATE users SET password = hash
  → DELETE FROM password_resets WHERE token_hash = ?
  → audit_log(PASSWORD_RESET)
  → Redirect to login.php with success flash
```

### Invitation Flow
```
admin/invitation_requests.php (approve)
  → INSERT user_invitations (token, expires_at = +7 days)
  → UPDATE invitation_requests SET status='approved'
  → Email: APP_URL/accept_invitation.php?token={raw_token}

accept_invitation.php (GET)
  → Validate token: SELECT WHERE token=? AND status='pending' AND expires_at > NOW()

accept_invitation.php (POST — create account)
  → INSERT users
  → DELETE FROM user_invitations WHERE token=?
  → DELETE FROM invitation_requests WHERE email=? AND status='approved'
  → Redirect to login.php
```

---

## 20. Changelog — What Was Built / Changed

### Auth & Access
- Fixed `admin/utils/protect-page.php` role check — was `||` (always true), fixed to `&&` so superadmin sessions pass through
- Implemented `forgot-password.php` + `reset-password.php` with 15-min token expiry, SHA-256 hashing, MySQL-computed expiry (no PHP date math to avoid timezone mismatch)
- Added "Forgot password?" link to `login.php`

### Superadmin Integration into Admin Panel
- Added **System Config** and **Maintenance** tabs to `admin/settings.php` — superadmin only, guarded by `$admin_role === 'superadmin'`
- Added **Super Admin** section to `admin/components/sidebar.php` with User & Role Management link — superadmin only
- Added `admin/user-role-management.php` (copy of superadmin version)
- `save_org` POST handler added to `admin/settings.php` for org settings

### Notifications
- Added `created_at` date filter to ALL notification queries (fetch, mark_read, mark_all_read, all stat queries, paged query) in `admin/notifications_api.php`, `bidder/notifications_api.php`, `admin/notification.php`, `bidder/notification.php`
- Fixed `stat_broadcast` query — converted from raw `query()` to `prepare()` to support the date filter

### Invitation Cleanup
- `accept_invitation.php` — changed `UPDATE user_invitations SET status='accepted'` to `DELETE FROM user_invitations WHERE token=?`
- Added `DELETE FROM invitation_requests WHERE email=? AND status='approved'` in the same transaction

### Email System
- Moved `utils/email_worker.php` → `cron/process_email_queue.php` — proper cron structure, removed `--loop` mode, added CLI/CRON_KEY security gate
- Added `send_password_reset_email()` to `utils/mailer.php`
- Added `utils/email_templates/password_reset.php`
- **Redesigned all 14 email templates** — new layout matching YesParency design (dark green header, yellow accent, Poppins, clean footer with no legal disclaimers)

### Cron
- Created `cron/close_procurements.php` — auto-closes overdue procurements, marks lots failed per-lot based on bid coverage, full transaction, audit logged
- Fixed `cron/check_document_expirations.php` — already existed, confirmed compatible with Hestia

### Security
- Created `uploads/.htaccess` — blocks script execution, allows images/PDFs, blocks everything else
- Created `config/.htaccess` — denies all direct HTTP access to config files
- Fixed `forgot-password.php` expiry — was using `strtotime('+15 minutes')` in PHP (timezone mismatch risk), changed to `DATE_ADD(NOW(), INTERVAL 15 MINUTE)` in MySQL

### Frontend
- `index.php` — removed `<link rel="stylesheet" href="style.css">` dependency. Page is now fully self-contained with inline CSS. The old `style.css` had a conflicting `.hero` rule with `background: url("images/slsu.jpg")` that caused the hero section to collapse on live server.

### Database Config
- `config/db_connect.php` — no longer hardcodes DB credentials. Now `require_once`s `bootstrap.php` itself (safe even if a caller already loaded dotenv) and reads `DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME` from `$_ENV`, with the old hardcoded values kept only as `??` fallbacks. Also fixed the previously invalid `catch(mysqli_sql_exception)` syntax (missing exception variable) and now surfaces the real connection error message.

### Live Streaming — HLS rename + health check
- `config/mediamtx.php` — renamed `mediamtx_webrtc_port` → `mediamtx_hls_port` (env fallback `MEDIAMTX_HLS_PORT`, default `8888`); added `mediamtx_manifest` key (default `index.m3u8`). `mediamtx_url()` now appends the manifest filename, so it actually returns a playable HLS URL (`http://host:port/path/index.m3u8`) instead of a bare `http://host:port/path`.
- `admin/settings.php` — Live tab: "WebRTC Port" field renamed to "HLS Port", new "Manifest File" field added; one-time migration carries forward any previously-saved `mediamtx_webrtc_port` DB value as the seed for the new `mediamtx_hls_port` key so an existing production port isn't reset.
- `.env` — `MEDIAMTX_WEBRTC_PORT` renamed to `MEDIAMTX_HLS_PORT` (value preserved).
- Added `stream_health.php` (root, public) — server-side HEAD check against the computed HLS manifest URL, returns `{"online": bool}`. Used by `live.php`, `admin/bid_opening.php`, `admin/bid-session-list.php`, and `admin/live_event.php` to gate their `<iframe>` embeds instead of rendering them unconditionally.
- `live.php` — now shows a "connecting…" state and polls `stream_health.php` every 5s until the stream is confirmed online before swapping in the iframe.
- `admin/bid_opening.php` / `admin/bid-session-list.php` — the "Open Now" modal previously showed the stream iframe unconditionally (the "not live yet" state was dead code); now it health-checks first and only shows the preview once confirmed online.
