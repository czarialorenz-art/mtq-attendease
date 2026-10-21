# MTQ-ATTENDEASE — Project Notes

Summary of the development and deployment work done on 14–15 October 2026.

## Where things are

| What | Where |
|---|---|
| Live system | https://mtq-attendease.free.nf (InfinityFree, PHP 8.4) |
| Source code | https://github.com/czarialorenz-art/mtq-attendease (private) |
| Local copy | http://mtq-attendease.localhost:8000 — start with `php artisan serve --host=127.0.0.1 --port=8000` |
| Local database | `laravel` (MySQL). `mtq_attendease` exists but is empty and unused. |

The live and local copies have **separate databases**. Real classes should use the live address only.

## Architecture (as it actually is)

- **Backend:** Laravel 13 JSON API (`routes/api.php`), Sanctum **bearer tokens** (no sessions, no CSRF).
- **Frontend:** one static page, `public/index.html` (plain JavaScript, inline CSS). Loads jsQR, qrcodejs and Google Fonts from CDNs. Vite, Tailwind, Blade and `package.json` are leftovers and are **not used** — nothing needs `npm`.
- **Photos:** saved privately in `storage/app/private/submissions/…` and served only through the logged-in route `GET /api/submissions/{id}/image` (teacher of the class or the student who submitted). No `storage:link`. Older photos (`/storage/submissions/…` on the public disk) are still read through the same route.
- **Times:** stored in UTC, shown and compared in `Asia/Manila`.

## Features added or changed

**Attendance**
- *Attendance sessions* (`class_sessions`): one per class meeting, with an editable date and optional title. Teachers create, edit the date of, and delete sessions (deleting removes its QR windows and records).
- Each session shows every enrolled student; no Time In = **absent**. Teachers can set any student's status by hand, even without a scan.
- Time In / Time Out QR codes are valid **only on the session's date** (expire at 11:59 PM Manila time); expired codes are refused by the server with a clear message.
- **Section filter** (A/B/C or as typed) on the sessions list, session roster and All Records.
- Students see every session of a subject with their own status only.

**Assessments (quizzes, exams, activities)**
- Assessment QR codes removed: students submit with a **Submit Photo** button (camera only) while the assessment is open.
- Scores default to **0**; teacher enters `20` or `20/50`; validated against the assessment's **maximum score**, which the teacher must set (no upper limit other than the database's).
- **Student-entered score + Accept / Not Accepted** (15 Oct, replaced Release / Hide scores) — see *Score submission and Accept / Not Accepted* below.
- Photo viewer: full-size, rotate, zoom; thumbnails no longer cropped; captures at ~1920×1440.

**Report card** (per subject, computed live from the records — no copy)
- Every enrolled student with student ID, each score grouped by type with subtotals, total and %.
- Attendance counts per student: Present / Late / Excused / Absent and rate (sessions dated in the future are not counted).
- Teachers can edit a score by clicking it (saved as Accepted); students see only their own row and only accepted scores.
- Totals: teachers' totals count every assessment (anything not accepted = 0); students' totals count only accepted scores. Cells show "Pending" / "Not Accepted" instead of a number.

**Students and accounts**
- Sign-up: Course (BSIT), Year Level (1st–4th) and Section (A/B/C) are dropdowns; the server accepts only these values (lists in `app/Models/User.php` and near the top of the script in `public/index.html` — keep both in sync).
- Teachers can **remove a student** from a class; their history is kept and returns if they rejoin and are re-approved.
- Student names are alphabetical everywhere (lists, rosters, report card, CSVs).
- **Security alert emails** (new sign-in, password changed, email changed). They never block the action.
- Email verification codes were built and then **removed** at the owner's request; accounts work immediately after sign-up.

**Removed**
- Calendar from both dashboards, menus and class tabs (the `calendar_events` table and API remain).
- Scores column from the assessment lists.
- Release / Hide scores (15 Oct): button, `POST /api/assessments/{id}/release` (now 404), `Assessment::scoresReleased()` and the `assessments.scores_released_at` column.

## Score submission and Accept / Not Accepted (15 October)

**Student**
- After taking the photo, the student must type the **score written on the paper** (0 to the assessment's maximum, at most 2 decimals) before Submit. Checked in the page and again by the server (`AssessmentController::submit`).
- **One submission per assessment:** after sending, the photo and score can't be changed (server answers 409; the Retake/Submit button disappears).
- The subject page shows: **Pending** + "Your score: x / total" → **Score: x / total** once accepted → **"Not accepted - please see your teacher"** if not accepted.

**Teacher** (assessment detail page and the Review pop-up)
- Each submitted student: name, photo (click to enlarge), the score the student entered, status pill and **Accept** / **Not Accepted** buttons. Replaced "Save & Verify".
- **Accept** → the entered score becomes the official `score`. **Not Accepted** → official score 0, not counted. The decision can be changed any time; the student gets a notification.
- Status filter: All students / Submitted / Pending / Accepted / Not Accepted / Not submitted.
- Students without a photo: the teacher can still type a score (counts as Accepted).
- No "verify / verified / verification" wording anywhere on screen.

**Data and API**
- `submissions.claimed_score` (decimal 8,2, nullable) — the score the student entered.
- `submissions.status` — `pending` (default) / `accepted` / `rejected`. New column: no existing one could hold three values. `is_verified` / `verified_at` are kept in step (true only when accepted) for anything still reading them; old verified rows were set to `accepted`.
- `submissions.score` changed from whole number to **decimal(12,2)** so an accepted 17.5 isn't cut to 17. Whole scores are still returned as plain numbers (`20`, not `"20.00"`).
- `POST /api/submissions/{id}/decision` with `{ "status": "accepted" | "rejected" }` — class teacher only.
- `POST /api/submissions/{id}/verify` still works and now records an Accept.
- Scores CSV: one row per submission; **Score filled only when accepted**; new **Status** column (Pending / Accepted / Not Accepted) replaces "Verified".

**Files changed:** `app/Http/Controllers/Api/AssessmentController.php`, `app/Http/Controllers/Api/ReportController.php`, `app/Models/Submission.php`, `app/Models/Assessment.php`, `routes/api.php`, `public/index.html`, `tests/Feature/AttendEaseFeaturesTest.php`, new migration `2026_10_15_100000_add_claimed_score_and_status_to_submissions`.

**Tests:** 14/14 pass, including a new submit → accept → reject test. The UI was not clicked through in a browser yet.

**Effects on existing live data** (from the 15 Oct export)
- Scores that were checked but never released become visible to students right away (they count as Accepted) — e.g. 50 on "quiz 2" and 25 on "quiz 4". Mark them Not Accepted if students shouldn't see them yet.
- Photos sent before this change (submissions #6 and #7) have no entered score ("Not entered"); Accept would record 0 — score them from the Report Card instead.

## Bugs fixed

- Photos never displayed: `routes/web.php` started with spaces before `<?php`, corrupting every image (and `public/storage` was an empty folder, not a link).
- Lost photos when Submit was tapped twice in the same second (same file name → the new photo was deleted). Files now get a random suffix; the Submit button locks while uploading.
- `AttendanceSession` and `Classroom` models had wrong/missing `$fillable` fields (QR tokens, year/section were silently not saved).
- `ClassSessionController` not imported in routes; student Attendance page crashed (`escesc`); students were shown the teacher's status dropdown.
- Photo cache could show a teacher-viewed photo to the next user on the same browser (`Cache-Control: no-store` now).
- Release scores failed because a migration had not been run.

## Database migrations added

- `2026_10_14_100000_add_qr_validity_and_score_release` — score default 0, `assessments.scores_released_at`, QR expiry backfill.
- `2026_10_15_000000_add_email_verification_to_users` — verification columns (now unused) and marks existing users verified.
- `2026_10_15_100000_add_claimed_score_and_status_to_submissions` — `claimed_score`, `status` (old verified rows → accepted), `score` to decimal(12,2), drops `assessments.scores_released_at`.

The first two have been run on the local `laravel` database and are included in the live database. The third has been run only on `laravel_test` (migrate, rollback and the phpMyAdmin SQL were all tested there): run `php artisan migrate` locally, and the SQL file below on live.

**Note:** the older migration files no longer match the real database structure (e.g. one refers to a `subject_id` column that doesn't exist). A fresh `php artisan migrate` on a new machine would build the wrong tables — copy the database instead (`export-db.ps1`), or regenerate the migrations from the live schema.

## Deployment (InfinityFree)

**Files added for deployment**
- `.htaccess` (project root → goes in `htdocs/`): forces https, passes the login header, blocks private folders/files, serves `public/index.html` directly for the home page, routes everything else into `public/`.
- `.env.production.example`: production settings template (file sessions/cache, sync queue, mail to log, debug off).
- `deploy-build.ps1`: builds `deploy\mtq-attendease` + `deploy\mtq-attendease.zip` without touching the project (production packages only, regenerated package list, placeholder files so empty `storage` folders survive the ZIP). No npm and no `config:cache` (it would store this PC's `C:\` paths).
- `export-db.ps1`: exports the database as `deploy\mtq-attendease.sql`, cleaned for older MySQL/MariaDB; login tokens, sessions and cache exported empty.
- `deploy/` and `*.sql` are git-ignored (they contain student data and the server `.env`).

**Compatibility:** `composer.json` sets `config.platform.php = 8.3.0`, so packages (Symfony 7.4) run on PHP 8.3–8.5. Local development stays on PHP 8.5.

**How to update the live site after a code change**
1. Run the tests (below).
2. `powershell -ExecutionPolicy Bypass -File .\deploy-build.ps1`
3. In InfinityFree's File Manager, inside `htdocs`, upload the changed files (or Upload & Extract the ZIP with "Extract to folder" empty while inside `htdocs`). The server's `.env` and uploaded photos are not in the ZIP and stay in place.
4. Database changes: apply them in phpMyAdmin (there is no terminal on InfinityFree).

**Update package for the 15 Oct score change** — `deploy\update-2026-10-15-accept-scores\`
- `RUN-IN-PHPMYADMIN.sql` — the migration as plain SQL (also records it in the `migrations` table).
- `htdocs\` — only the 7 changed files, in their `htdocs` paths; `update-files.zip` is the same files for Upload & Extract.
- Order: **back up the live database** (phpMyAdmin → Export) → run the SQL → upload the files → open the site and press Ctrl+F5. The SQL must go first; the new code fails without the new columns. Uploading never deletes the server's `.env` or photos.

**Problems met during deployment (and fixes)**
- "Extract path must be within an htdocs folder" → go inside `htdocs` before extracting.
- HTTP 500 everywhere → `.env` was missing, and the empty `storage/framework/*` and `storage/logs` folders were not in the ZIP (build script fixed).
- HTTP 500 on the home page only → it went through Laravel's file sessions; now served directly as static HTML by `.htaccess`.
- Checked from outside: `.env`, `.htaccess`, `storage`, `vendor`, `artisan`, `composer.json` and the scripts are all refused or blocked.

## Running tests

```
DB_CONNECTION=mysql DB_DATABASE=laravel_test php artisan test
```
`laravel_test` is a local copy of the schema used only for tests (safe to drop). A plain `php artisan test` skips the feature tests because the old migration files can't rebuild the schema on SQLite.

## Still open

- [ ] **Apply the 15 Oct score update:** `php artisan migrate` on the local `laravel` database, then on live: back up → `RUN-IN-PHPMYADMIN.sql` → upload `update-files.zip` (see Deployment).
- [ ] After that, review the old checked-but-unreleased scores (now visible to students) and score submissions #6 and #7 from the Report Card.
- [ ] **Test the live site with real accounts:** teacher login, class join + approval, Time In/Out QR scan on a phone, Submit Photo with a score, Accept / Not Accepted, report card, CSV export.
- [ ] If photo uploads fail online ("Could not save the photo"), set `storage` and its folders to permission 755 in the File Manager.
- [ ] The photo submitted on 15 Oct at 1:27 PM (student #1, assessment #1) was lost by the double-tap bug — that student must submit it again.
- [ ] Keep `APP_DEBUG=false` in the server's `.env`.
- [ ] Commit and push the 15 October changes to GitHub (PHP 8.3 compatibility, photo fix, deployment files, these notes). `.env`, `deploy/` and `*.sql` stay excluded.
- [ ] Email alerts are written to `storage/logs` (no sending account; InfinityFree's free plan has no PHP `mail()`). To send real emails, set Gmail SMTP with an App Password in the server `.env`.
- [ ] InfinityFree's free plan has daily usage limits; heavy class use may pause the site temporarily.
