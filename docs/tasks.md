# Tasks

Open work, decisions and blockers. Last updated 2026-11-02 (HEAD `4e63ea6`).

Priority: **P1** do next · **P2** soon · **P3** later.
Owner: **Me** (the assistant, on request) · **You** (a decision or an action only the user can take).

---

## Decisions needed from you

| # | Priority | Question | Options | Why it matters |
|---|---|---|---|---|
| D1 | P1 | **Sampath and other bank accounts are typed OTHER with no bank name.** Should I change them to a bank type so they can be main accounts? | (a) I update the types with a backup first (needs your yes); (b) you change each one in the Edit form | Until then the sub-account parent list is empty for them. |
| D2 | P2 | **Link old purchases to payments?** 46 purchases and 111 transactions predate the payment ledger. | (a) leave them unlinked (current); (b) backfill by matching description, date and amount, reviewed by you first | Only affects history labels and the purchase status. Cash flow already counts them. |
| D3 | P1 | **Enable CSRF on forms?** The filter exists but is off. | (a) enable globally for POST, add tokens to forms and AJAX; (b) enable only for the forms | Forms accept POSTs without a token today. |
| D4 | P2 | **Replace MD5 passwords with `password_hash`?** | (a) re-hash on next login, with a forced reset for old accounts; (b) keep MD5 | Current hashes are weak. |
| D5 | P3 | **Five old linked-card rows** (Sampath sub-accounts via `linkedfrom`). Keep or remove? | keep / remove | They are inert but clutter the card lists. |
| D6 | P2 | **HSBC instalments**: keep past end dates (`--keep-past-end`) or drop them? | keep / drop | Affects the 2026 plan totals. |
| D7 | P2 | **Cash flow transfer detection** for old rows uses the description prefix "Transfer". Is that enough? | keep / add a manual list of transfer rows | Could overstate money in or out for old months. |

---

## Blocked

| # | Priority | Item | Blocker |
|---|---|---|---|
| B1 | P2 | Create the bank groups Sampath Bank and Commercial Bank | Database permission denied earlier. Needs a user with rights, or a manual insert. |
| B2 | P2 | Password-reset emails | SMTP settings in `.env` (see LOCAL_SETUP.md). Until set, the reset link is only logged in development. |
| B3 | P2 | Map existing Sampath and Combank accounts to a main account | Depends on D1. |

---

## Open, in order

### Verify (no code change expected)

| # | Priority | Task | Owner |
|---|---|---|---|
| V1 | P1 | Open `/payment/cashflow` in a browser: chart draws, tooltips show Rs., the due list reads correctly. | You |
| V2 | P1 | Account creation: bank → product list filters by bank and type; editing an account keeps its product; parent list shows only bank accounts. | You |
| V3 | P2 | Mobile: check the pages listed in `mobile.css` on a phone-width screen. | You |
| V4 | P2 | Reset flow end to end once SMTP is set (request, email, reset, sign in). | You, then me |
| V5 | P3 | `purchaseReportProccess` with a single purchase: confirm `getDatafromDB` returns a list, and that the guard in the loop is not needed. | Me |

### Fix

| # | Priority | Task | Owner |
|---|---|---|---|
| F1 | P1 | Enable CSRF after D3 is decided: tokens in forms, `LB.post` header, the filter on POST routes. | Me |
| F2 | P1 | Month view (`/plan/{year}/{month}`) still uses the old red and amber states for income; align with the year grid (expected and late). | Me |
| F3 | P2 | Monthly plan horizon: 303 open dues total about Rs. 9.37 million. Check the plan horizon and whether `plan:roll` should trim far-future dues. | Me, after review |
| F4 | P2 | Two active accounts named "Cash in Hand" (live data). Check whether one is a duplicate. | You (confirm), then me |
| F5 | P2 | `LOCAL_SETUP.md` says MariaDB in places and MySQL in others. Reconcile with the database actually used. | Me |
| F6 | P3 | Remove dead code: `$cards` (done), unused routes, unused PlanRunner wrappers. | Me |
| F7 | P3 | Legacy card rows (D5). | You decide |

### Build

| # | Priority | Task | Owner |
|---|---|---|---|
| T1 | P2 | Feature tests (PHPUnit or CI's test runner) for `recordPayment`, `settlePayables`, `internalTransfer`, `purchaseLedger`, `cashflowProccess`, password reset. Run against a disposable database. | Me |
| T2 | P2 | Migration runner: record applied migrations in a table; replace the hand-run SQL. | Me |
| T3 | P2 | Split `PlanService` (about 2,300 lines) into import, allocation, projection and envelopes. | Me |
| T4 | P3 | Split `AccountController` (about 1,200 lines): accounts, sub-accounts, transaction list. | Me |
| T5 | P3 | One error format for pages and JSON; one place that turns service errors into responses. | Me |
| T6 | P3 | Local fallbacks for jQuery, Bootstrap, Chart.js and the report libraries when the CDN is unreachable. | Me |
| T7 | P3 | Backfill tool for old purchases (only if D2 = b). | Me |

---

## External review (q.md, 2 Nov 2026)

An outside review of GitHub `main` listed 40 items. It names a branch
(`feature/account-payment-methods`) that is not this one, so its "already fixed on the branch"
claims are **not** taken as true here. Each item was checked against `feature/monthly-payment-plan`
(HEAD `4e63ea6`).

| # | Priority | Item | Checked on this branch | Action |
|---|---|---|---|---|
| R1 | **P1, now** | `.env` is tracked by git (it holds database settings). `.gitignore` lists it, but the file was committed. | **Confirmed.** | Ask you: `git rm --cached .env` and commit, then change the database password. Needs your approval; it changes the repo. |
| R2 | P1 | Query conditions built by string concatenation (`getDatafromDB`, `BlueprintService.php` ~line 113–115; `sqldecrypt` is added raw). | **Confirmed.** Values are quoted but not escaped. | Fix with bound parameters through the query builder; validate ids (`isSessionID`). |
| R3 | P1 | Passwords hashed with MD5 (`MainService::encryptLogic`). | **Confirmed.** Same as D4. | Decision D4. |
| R4 | P1 | JWT signing key is a literal in source (`BlueprintService::getSecretKey`). | **Confirmed.** | Move to `.env` and rotate. Needs R1 done first so the old key is not in history. |
| R5 | P1 | CSRF filter off (`Filters.php`, `csrf` is commented in `$globals`). | **Confirmed** on this branch (line 76). | Decision D3. |
| R6 | P1 | `secureheaders` filter off (`Filters.php` line 81). | **Confirmed.** | Enable with a Content-Security-Policy that allows the CDNs in use. |
| R7 | P1 | Session not regenerated at login. | Only one `regenerate` call in `app/Controllers`; check `loginValidation`. | Verify, then add `session()->regenerate(true)` at login. |
| R8 | P1 | Auto-routing on. | **Not confirmed.** No `setAutoRoute` call found in `app/Config`. | Check the CodeIgniter default in `vendor/` before changing anything. |
| R9 | P2 | 12-hour timestamps `'Y-m-d h:i:s'` (3 places in `app/Controllers`, including `ReportService`). | **Confirmed.** | Change `h` to `H`. Small, safe. |
| R10 | P2 | Hard-coded fallback user id (`MainService`, `protected $user_id = '6397…'`). | **Confirmed.** | Remove the literal; use the session user. |
| R11 | P2 | Misspellings (`Proccess`, `Feild`, `Condtion`). | **Confirmed** (53 occurrences of `Proccess` alone). | Rename only when touching a file; not a standalone task. |
| R12 | P2 | Signup duplicate check compares `'004'`, not `'0004'`. | **Not found** in this branch. | Check `Home.php` signup before deciding. |
| R13 | P2 | DB helpers return `false`, callers test `error_id`. | Not checked yet. | Check the callers in a later pass. |
| R14 | P2 | Money writes without transactions or row locks. | **Not the case** for the ledger: `recordPayment` uses `runAtomic` and locks the account row. Remaining older paths not checked. | Check the older controllers. |
| R15 | P3 | `.env` and `writable/logs` (28 files) tracked; `system/` and `vendor/` committed. | `.env` confirmed. Others not checked. | Clean up with R1. |
| R16 | P3 | No app tests, no CI. | **Confirmed:** only 14 framework/unit tests. | Build T1 and a CI workflow. |

### Checked on `feature/account-payment-methods` (local branch, `b91f5c5`)

The review says this branch already fixes about ten items. Checked with `git show`, without switching branches:

| Item | On that branch | Status |
|---|---|---|
| #4 / R1 `.env` tracked | Still tracked | Not fixed |
| #5 / R5 CSRF | Filter still off | Not fixed |
| #8 / R6 secure headers | Filter still off | Not fixed |
| #2 / R3 MD5 passwords | `MD5(` still in `app/` | Not fixed |
| #3 / R4 JWT key literal | `getSecretKey` still in `BlueprintService` | Not fixed |
| #1 / R2 `sqldecrypt` | Still used in 3 places | Not fixed |
| #6 auto-routing | No `setAutoRoute(false)` found | Not confirmed |
| #7 session regeneration | `session()->regenerate()` in `Home.php:77` | Fixed (default arguments; `regenerate(true)` would also clear old data) |
| #12 12-hour timestamps | Still in `AccountService.php:363–364` and `ReportService.php:57` | **Not fixed**, although the review says it is |
| #16 report view case | `view('Report/page')` | Fixed |
| #21 travel column typo | Migration `2026_10_02` renames it | Fixed |
| #22 `runAtomic` | Defined in `MainService` | Present; coverage of older writes not checked |
| #23 row locks | `FOR UPDATE` in 3 files | Partly |
| #27 `setJSON` | Used in 1 file | Partly |

So the review's "already fixed" list is only partly right. Several items it lists as fixed are not: the 12-hour
timestamps, `.env` tracking, CSRF, secure headers, the MD5 passwords and the JWT key. That branch also tracks `.env`, as ours does.

Order I'd suggest, pending your approval: R1 (remove `.env` from git and rotate the database password),
then R2, R4, R3/D4, R5/D3, R6, R7, R9, R10. Then T1 tests and the CI workflow.

---

## Done recently (for reference)

- Purchase ledger and purchase report with payments and installments — `4e63ea6`
- Source column on transactions; "For" column on payment history — `4e63ea6`
- Cash flow page with chart, dues rate and due handling — `4e63ea6`
- Account form: bank and type filter for products; sub-account parents limited to bank accounts — `4e63ea6`
- Password reset pages and token flow — `5c27677`
- Mobile stylesheet — `5c27677`
- Monthly plan phase 2: dues settle through the ledger — `8dee4fe`
- Monthly plan phase 1: schema, plan service, import and verify — `7d8ad1b`

See [memories.md](memories.md) for the reasons behind each.
