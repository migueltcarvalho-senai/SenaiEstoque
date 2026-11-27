# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

SENAI 2D warehouse/stock management system ("Gestão de Estoque e Endereçamento 2D"). A single-page frontend shows a top-down floor plan of storage positions, a product catalog, lots with expiry tracking, movement history, and XLSX/CSV/JSON import/export. Access requires username/password login. UI text, code comments and identifiers are in Portuguese (pt-BR); keep that convention.

No build step, package manager, linter or test suite exists. Edit the files directly and reload. Syntax-check inline JS by extracting the `<script>` blocks and running `node --check` on them. PHP is not installed on the dev machine, so PHP can't be linted there.

## Running locally

Needs PHP 8.x and MySQL (Laragon/XAMPP):

1. Create an empty database (e.g. `senai_estoque_2d`). `schema.php` → `ensureSchema()` (run by `login.php`) creates every table and migrates older databases, so importing `database.sql` is optional. That file only adds demo data and has no `CREATE DATABASE`: import it into an already-selected DB, e.g. `mysql -u root -p senai_estoque_2d < database.sql`.
2. Copy `.env.example` to `.env` and set the DB credentials. `config.php` reads `.env` without `putenv()` (free hosts disable it). Real environment variables take precedence over it.
3. Serve the folder:
   - Apache (`.htaccess` blocks sensitive files), or
   - `php -S localhost:8080 router.php`. The router applies the same protections, which the built-in server would otherwise ignore.
4. First access: `login.php` shows a one-time setup screen to create the first admin, shown only while `users` is empty.

`server.ps1` and `python -m http.server` cannot run PHP.

## Deployment (InfinityFree)

Production is InfinityFree free hosting. A push to `main` runs `.github/workflows/deploy.yml`. `database.sql` and `plano_de_implementacao.txt` are gitignored on purpose and exist only locally.

The workflow:

- It writes `.env` from repository Secrets (`FTP_*`, `DB_*`), then uploads via FTP to `/htdocs/`, excluding docs, `*.sql`, `router.php` and `.github/`.
- Setup steps are in `DEPLOY.md`.

Hosting constraints to respect:

- `Options` directives in `.htaccess` cause a 500 error there.
- `CREATE DATABASE` is not allowed.
- `putenv` may be disabled.
- `GET_LOCK` is treated as optional: `runWrite()` falls back to the transaction alone.
- When adding a new PHP file that is only included by other files, also add it to the blocked list in `.htaccess` and `router.php`.

## Architecture

- **Server is the source of truth.** The browser never persists state by itself. Each user action calls one business operation, and the response carries the full updated state (`buildState()`), which the client renders.
- **Entry points**:
  - `index.php` is the app (HTML, CSS and inline JS), guarded by `requirePageUser()`. `index.html` is only a redirect to it.
  - `login.php` handles login, first-admin setup and the forced change of a temporary password. `logout.php` accepts POST with CSRF only.
- **`auth.php`**:
  - Session cookie (HttpOnly, SameSite=Lax) with an 8-hour idle timeout. `currentUser()` re-reads the user from the DB on every request.
  - Passwords stored with `password_hash`. An account is locked for 15 minutes after 5 wrong passwords.
  - CSRF helpers live here.
  - `AppException($msg, $httpStatus)` marks an expected error whose message is safe to show to the user.
  - Roles are `admin` and `operador`. The full permission matrix is in `SEGURANCA.md`.
- **`api.php`**: a single endpoint switched on `?action=`.
  - `READ_ACTIONS` are GET. Everything else is POST and needs the `X-CSRF-Token` header.
  - Stock operations are mapped in `STOCK_OPERATIONS` (action → `[opFunction, adminOnly]`) and answer with `{success, message, data}`.
  - User-management actions are handled inline in the same file.
  - Exceptions other than `AppException` are logged with `error_log` and return a generic 500.
- **`estoque.php`** holds all business rules (`op*` functions).
  - Each function validates its input first, using the `input*` helpers. Then, inside `runWrite()` (a `GET_LOCK` plus a transaction, so all writes are serialized), it re-checks state, mutates, and calls `logHistory()`, which stamps the session user.
  - Invariants:
    - no negative balances;
    - a withdrawal or transfer never exceeds the source balance;
    - SKU is unique (checked in the API and enforced by a DB index);
    - integer quantities from 1 to 1,000,000;
    - ISO dates.
  - Replacing an occupied slot requires `displace: true`. The previous contents move to `unallocated_lots` ("Estoque Geral"), never deleted.
  - Imports and backup restores validate the whole payload before writing anything.
- **Frontend**:
  - The global `appState` = `{ products[], slots{slotCode: {...}}, unallocatedLots[], customSlots[], history[] }`. It is replaced wholesale by `applyServerData()`, and then `renderAll()` re-renders every view.
  - Every write goes through `runOperation(action, body, {form})`. It disables the form's submit buttons and returns the response, or `null` after showing the error as a toast. Callers close their modal only on success.
  - Keep client-side checks such as balance and SKU duplicates for instant feedback, but never rely on them.
  - The client polls `get_all` every 6 s. The poll is skipped while an operation is in flight or a `div[id^="modal-"]` is open, and a result is discarded if an operation started meanwhile (`stateVersion`).
- **Rendering safety**: markup is built with template strings set via `innerHTML`.
  - Text must go through `escapeHtml()`.
  - Values passed to inline handlers must use `jsArg()`, e.g. `onclick="fn(${jsArg(id)})"`. `escapeHtml` alone is not safe inside a JS string in an attribute.
  - Prefer `data-*` attributes plus event delegation for new code (see the user-management list).
- **Admin-only UI** gets the class `admin-only`, which CSS hides for `body.role-operador`. Its handlers call `requireAdminAction()`. The server enforces the same rules independently.
- **Floor plan**: the 15 fixed positions exist in two places that must stay in sync: `BASE_FLOORPLAN` in `index.php` and `BASE_SLOT_CODES` in `estoque.php` (same order). Custom positions live in the `custom_slots` table.
- **Adding a product/slot field** means updating, together:
  - the `SCHEMA_TABLES` DDL in `schema.php`, plus an `ALTER` migration in `ensureSchema()` for existing databases, and `database.sql`;
  - `buildState()`;
  - the relevant `op*` functions and `validateProductFields()`;
  - the frontend forms and renderers, and the XLSX import/export if the field is spreadsheet-facing.
- Dates are stored as ISO `YYYY-MM-DD` strings (VARCHAR) and shown with `formatDateBR`. In `getExpiryStatus`, a missing expiry date is grey, distinct from "far from expiry" (green).

## Notes

- External scripts are pinned. Lucide and SheetJS have SRI hashes. The Tailwind Play CDN sends no CORS header, so it is pinned by version only. Changing a version requires recomputing the SRI hash.
- `firestore.rules` is a leftover from an old Firebase backend and is unused.
