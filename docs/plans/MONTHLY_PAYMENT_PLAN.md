# Monthly Payment Plan: implementation spec

**Status:** ready to implement. **Base branch:** `feature/account-payment-methods`.
**Work branch:** `feature/monthly-payment-plan`.
**Seed data:** `DBBackup/Seeds/planned_expenses_2026.json`. It was extracted from
`2025_New_Cost_handler.xlsx`, sheet *Planned Total Expenses*. Its 35 items reproduce the
sheet's monthly **Total** row to the cent.

This feature replaces the *Planned Total Expenses* spreadsheet inside LiquidityBuddy. The
app should hold a year grid of planned income and payments per month. The grid
generates the month's dues, and you pay them through the payment ledger built on
`feature/account-payment-methods`. The app then shows plan against actual, how much to pay
each credit card, and how spending compares with the category allocations on the
*Planning* sheet.

> **For Claude Code:**
> 1. Read this whole file, `docs/PAYMENT_METHODS.md` and the seed JSON first.
> 2. Ask the **Open decisions** (section 11) before the phase that needs each answer.
> 3. Implement the phases in order and make one commit per phase.
> 4. After each phase, run that phase's checks and report the results.

---

## 1. What the spreadsheet does, and what replaces it

| Sheet element | Meaning | In the system |
|---|---|---|
| Rows 6–10 (*Income*) | Planned money in each month: salary, investment return, bill shares | `planitem` with direction `IN` → monthly receivable dues |
| Rows 12–41 (*Expense*) | Planned commitments each month | `planitem` with direction `OUT` / `TRANSFER` → monthly payable dues, or an `ENVELOPE` |
| Columns B–M | Planned amount per month (can vary month to month) | `planentry.plannedamount` (one row per item per month) |
| Column O | Installment end date | `planitem.enddate`. No entries are generated after it. |
| Row 42 *Total* | Income − expenses for each month | Grid **Planned net** row. It must equal the sheet (section 10). |
| Column N | Annual total | Grid **Year** column (always computed; the sheet has two typed totals that are wrong) |
| Rows 44–47 | How much to pay each credit card (installments + arrears) | **Card settlement projection** grouped by payment method |
| `J8 = J31/2` (E-Bill) | Half the electricity bill is reimbursed | Derived item: `sourceplanitemsessionid` + `shareratio = 0.5` |
| Side block *Nov Plan* (Q–S) | One month: salary − each commitment = what's left | **Month view** |
| *Planning* E1:G10 | Allocation % per category | `plancategory.allocationpercent` + allocation table |
| *Salary Plan* | One month's list of the same items | Covered by the month view |

**Not in scope:**
- The *Nikhila Settlement* netting block.
- The *Calculation* sheet (house build tracker).
- The 50/30/20 scheme on *Planning*.
- Automatic payments: dues are always settled by the user.
- Spreadsheet export.

## 2. What already exists (reuse it, don't rebuild it)

- **Payment ledger:** `app/Controllers/PaymentService.php`.
  - `recordPayment()` is the **only** path that moves money. It validates ownership,
    checks funds on a locked row (balance + credit limit), writes a `payment` row, posts
    `transaction` rows and allocates against payables (`duepaid`), all atomically.
  - `settlePayables()` settles selected dues in full or in part, oldest first.
  - `outstandingPayables()` feeds the **Dued** tab.
- **Installments:** `paymentplan` + `payable` tables; `PurchaseService::paymentPlanInitProccess()`.
- **Payment methods per account:** the `paymentmethod` table. Credit cards carry
  `creditlimit`.
- **Helpers in `MainService`:**
  - `runAtomic()`: nested-safe DB transactions.
  - `addPeriod()`: calendar months, clamped to month end.
  - `isSessionID()`, `toAmount()`, `validDate()`, `rowsOf()`.
- **API helpers in `PaymentService`:** `apiUser()`, `apiError()` (422), `apiUnauthorised()`
  (401), `apiResult()`.
- **Front end:**
  - `public/assets/js/default.js` has `LB.post`, `LB.showError` and
    `LB.syncPaymentMethods`.
  - Views use Bootstrap 5.0.2 + jQuery and the `layouts/default` layout. Output is
    escaped with `esc()`.
  - The transaction forms use the partial `app/Views/Transaction/payment_method_select.php`.
- **Data structures:** `app/Controllers/Traits/DB/*Traits.php` map front-end keys to
  columns for `getDatafromDB()` / `insertDatatoDB()` / `updateDBData()`.
- **Setup:** `scripts/setup-local-db.sh|.bat` and `LOCAL_SETUP.md`. **Both scripts
  hard-code the single migration file today**, so phase 1 changes them to apply every
  file in `DBBackup/Migrations/` in name order.

## 3. Conventions (must follow)

1. **Stack:** CodeIgniter 4.1.9, PHP 8.0–8.3 syntax (no enums, no `readonly`, no
   PHP 8.1+ only functions), MariaDB 10.3+.
2. **Schema changes:** add a new **idempotent** SQL file in `DBBackup/Migrations/`.
   - Use `IF NOT EXISTS` and guarded `UPDATE`s; it must be safe to run twice.
   - Session IDs come from `BEFORE INSERT` triggers (`MD5(UUID())`), like the existing tables.
   - Use `char(32)` session-id foreign keys.
3. **Ownership:** every read and write filters by `usersessionid` of the logged-in user.
   Validate every incoming ID with `isSessionID()` before it reaches
   `getDatafromDB()`, which builds raw SQL.
4. **Queries:** use the query builder or `query($sql, $bindings)` for joins and aggregates.
   Use the trait structures for simple CRUD (add structures for the new tables).
5. **Money moves** only through `recordPayment()` / `settlePayables()`. Wrap multi-step
   writes in `runAtomic()` and lock the rows being changed (`SELECT … FOR UPDATE`).
6. **Visibility:** service methods are `protected`. Controllers stay thin. Auto-routing
   is **off**: add every route to `app/Config/Routes.php`.
7. **JSON endpoints:** use `apiUser()` (401 when there's no session) and return
   business errors as 422 with `{success:false, code, message}`. Use new error codes
   from `0050` up.
8. **Money and dates:** money is `decimal(15,2)` and rounded to cents in PHP. Dates are
   `Y-m-d`. Months are stored as the first day of the month (`DATE`).
9. **No new Composer dependency** without asking first (the `vendor/` folder is partly
   committed).
10. **Docs:** update `LOCAL_SETUP.md` and add `docs/MONTHLY_PLAN.md` (user and developer
    notes).

## 4. Data model (migration `DBBackup/Migrations/2026_10_xx_monthly_payment_plan.sql`)

### `plancategory`
Groups items and holds the *Planning* allocation %.

| column | type | notes |
|---|---|---|
| id, sessionid | int / char(32) | trigger-generated sessionid |
| usersessionid | char(32) FK user | |
| name | varchar(50) | unique per user |
| direction | enum('IN','OUT') | |
| allocationpercent | decimal(5,2) NULL | % of planned monthly income; NULL = no target |
| sortorder | int | |
| status | char(1) default 'A' | |
| createddatetime, updateddatetime | int | |

### `planitem`
One row per line of the sheet.

| column | type | notes |
|---|---|---|
| id, sessionid | | |
| usersessionid | char(32) FK user | |
| itemkey | varchar(60) | slug (`laptop-ndb-card`), unique per user; makes the import idempotent |
| name | varchar(100) | |
| direction | enum('IN','OUT','TRANSFER') | TRANSFER = money moved to another of the user's accounts (card pay-down, funding the weekly-expense account) |
| kind | enum('DUE','ENVELOPE') | DUE creates a payable each month. ENVELOPE is a spending cap: actual = expenses tagged with `budgetsessionid` that month, and no payable. |
| plancategorysessionid | char(32) FK | |
| amounttype | enum('FIXED','ESTIMATE','VARIABLE') | display hint |
| defaultamount | decimal(15,2) | fallback amount for roll forward (seed `default_amount` = last seeded amount) |
| dueday | tinyint NULL | 1–31, clamped to month end. NULL = last day of the month. |
| startmonth | date NULL | first month with an entry; NULL = no entries yet |
| trackfrom | date | tracking start: entries before it are historical (`H`, no payable); from it onward DUE entries get payables. Import sets `--from`; the UI sets max(startmonth, current month). |
| recurring | tinyint(1) default 1 | 1 = roll forward keeps adding months. Import takes the seed's `recurring` (0 for one-offs, ended items, and rows with no amounts). |
| enddate | date NULL | last installment date (sheet column O) |
| accountsessionid | char(32) NULL | account to pay from / receive into |
| paymentmethodsessionid | char(32) NULL | method to pay with; must belong to `accountsessionid` |
| toaccountsessionid | char(32) NULL | TRANSFER only |
| budgetsessionid | char(32) NULL | budget used to tag the payments; required for ENVELOPE |
| sourceplanitemsessionid | char(32) NULL | derived item: amount = source amount × `shareratio` |
| shareratio | decimal(6,4) NULL | |
| stakeholder | varchar(60) NULL | free-text note (who shares/owes); linking to `stackholder` is out of scope |
| paymentplansessionid | char(32) NULL | the `paymentplan` that holds this item's payables |
| notes | varchar(255) | |
| sortorder | int | |
| status | char(1) default 'A' | A = active, E = ended, D = deleted |
| createddatetime, updateddatetime | int | |

### `planentry`
One row per item per month: a cell of the grid.

| column | type | notes |
|---|---|---|
| id, sessionid | | |
| usersessionid | char(32) | |
| planitemsessionid | char(32) FK | unique with `month` |
| month | date | first day of the month |
| plannedamount | decimal(15,2) | for DUE entries this equals `payable.dueamount` (kept in sync by the service) |
| duedate | date | from `dueday` |
| paymentmethodsessionid | char(32) NULL | override for this month only (the sheet's Dec `M44` puts most items on the NDB card) |
| payablesessionid | char(32) NULL | DUE entries from the tracking start month onward |
| overridden | tinyint(1) default 0 | user edited a derived item's amount |
| status | char(1) default 'A' | A = active, H = historical (before `trackfrom`; no payable), X = skipped (payable cancelled). Don't use `S`, which means *settled* on `payable`/`paymentplan`. |
| createddatetime, updateddatetime | int | |

### Changes to existing tables
- **`paymentplan`:**
  - Add `direction enum('OUT','IN','TRANSFER') NULL` and backfill: `paymentplanlink = 'income'` → `IN`, everything else → `OUT`.
  - Plan items use `paymentplanlink = 'planitem'`, `paymentplansessionid = planitem.sessionid`,
    `paymentplan = 'M'` and `description = item name`.
- **Plan-item paymentplans stay open and consistent.** Whenever PlanService adds or
  reopens a payable, it sets the item's paymentplan `status='A'`. `applyAllocation()`
  closes a plan (`status='S'`) when nothing is open, and `outstandingPayables()` only
  lists `status='A'` plans, so dues added later would otherwise be hidden. It also keeps
  these fields in line with the non-cancelled payables:
  - `startdate` / `enddate` (both NOT NULL) = the first / last `duedate`;
  - `period` = their count;
  - `amount` = the SUM of `dueamount`.
  Each payable gets `duedate` = `planentry.duedate` (NOT NULL) and `duedatetime` =
  `strtotime(duedate)`.
- **`PaymentService`:** wherever direction is derived today (`outstandingPayables()` line
  ~749 and `applyAllocation()` line ~654), use `COALESCE(pp.direction, IF(link='income','IN','OUT'))`.
- **`settlePayables()`:** support `TRANSFER` dues. Call `recordPayment()` with
  `direction = TRANSFER` and `to_account` = the item's `toaccountsessionid`. Reject a
  selection that mixes TRANSFER dues with different target accounts.

## 5. Behaviour

**Generating entries**
- **Import creates entries only for the months listed in each item's `months`**, trimmed by
  `enddate`. It never fills gaps: *Electrocity Bill* has no May–Aug entries and *KOKO*
  has none for Mar–Jun. One-off months stay one-off: *Insurance for Bike* (Sep),
  *HSBC Credit Card Arrears* (Jan) and *Collect Return Amount* (Jan).
- **Roll forward** (import, `plan:roll`, and lazily when a grid year is opened) extends
  **only `recurring = 1` items**. It runs from the month after the item's last entry
  through the horizon (current month + 12, inclusive), and never past `enddate`. So the
  loan running to 2030 isn't generated in full up front.
  - The amount is the last entry's amount, else `defaultamount`.
  - Derived items use `shareratio` × the source amount.
  - *Laptop NDB Card* in 2027 is a typical example.
- Annual items such as *Insurance for Bike* aren't recurring in this version. The next
  year's entry is added from the items page.
- `duedate` = that month + `dueday`, clamped (use `addPeriod()` semantics). When
  `dueday` is NULL, use the month's last day.
- **Tracking start** (`planitem.trackfrom`; set by `--from` on import, default the current
  month): months before it are created as `H` entries with no payable. They show in the
  grid as history and never become overdue.
- From the tracking start onward, every DUE entry gets a payable (`dueamount = plannedamount`,
  `duedatetime`, status `P`) on the item's paymentplan.

**Editing**
- **Edit a cell:** allowed only while its payable is `P`, or `T` with the new amount ≥ the
  paid amount. Update `planentry` + `payable` in one `runAtomic()`. Optionally *apply to
  all following unsettled months*.
- **Skip a month:** only while its payable is `P`. The entry becomes `X` and the payable `C`.
  A part-paid (`T`) payable must first be edited down to its paid amount, which marks it
  `S` and sets `paiddate`. `C` would lose the paid portion, because `applyAllocation()`
  treats `C` as not found.
- **End an item** (set or shorten `enddate`): future entries whose payables are `P` are
  skipped (`T` follows the skip rule above). The item becomes `E` once nothing is open.
- **Extend:** entries are generated for the new months.
- **Delete** is allowed only when nothing on the item was ever settled. Otherwise, end it.
- **Derived items** (`e-bill-share`): planned = `shareratio` × the source item's planned
  amount for that month. Recalculate when the source changes, unless `overridden = 1`.
- **Roll forward:** see *Generating entries*. It only extends `recurring = 1` items.

**Settling**
- Plan dues are ordinary payables, so they appear in the **Dued** tab. The tab currently
  filters on `IN`/`OUT` only (`dued_trans_module.php`, `dued.js`), so phase 2 adds a third
  **Transfers** choice. Selecting TRANSFER dues together with OUT dues is rejected (0040).
- The month view settles through the same `settlePayables()`. Defaults come from the
  item: account, method (entry override first), budget, and the to-account for TRANSFER.
- `resolvePaymentMethod()` rejects a method from another account (0023). So when a
  month's override method belongs to another account (the sheet's December plan puts
  Standby bills on the NDB card), the payment is made **from that method's account**.

**Envelopes:** actual = SUM(`transaction.amount`) over the user's accounts. `transaction`
has no `usersessionid`, so join `account`. Count only rows that match all of:
- `t.status='A'`;
- `paymenttype='expense'`;
- `budgetsessionid = item.budgetsessionid`;
- `date` within the month;
- not a TRANSFER leg, and not part of a payment with `category='settlement'` (otherwise
  plan payments tagged with the same budget would be counted twice).

Remaining = planned − actual.

**Totals (for each month in the grid)**
- Planned income: sum of IN entries.
- Planned outflow: OUT + TRANSFER entries, including envelopes.
- **Planned net** = income − outflow. With the seed loaded and end dates ignored, this
  equals the sheet's row 42.
- **Settled** for a month = SUM(`payable.paidamount`) of that month's entries (by entry month).
- Actual net: settled IN − settled OUT/TRANSFER − envelope actuals.
- Totals include `A` and `H` entries and exclude skipped (`X`) entries.
- Cumulative planned and actual nets.

**Card settlement projection** (sheet rows 44–47). For each payment method of type
`CREDIT_CARD`, per month:
- the sum of planned entries whose **effective method** is that card. The effective
  method is the entry override, else the item's method, else the item account's default
  method. Plus:
- TRANSFER entries whose `toaccountsessionid` is that card's account (arrears pay-down).

**Allocation**
- For each category: planned outflow vs `allocationpercent` × planned income of the month.
- Show a banner when the active percentages don't add up to 100 (the sheet's add up to 99.75).

## 6. Commands (`app/Commands/`)

`PlanService extends PaymentService` and keeps its methods `protected`, like every
service here. Spark commands can't call protected methods, so they go through
`app/Libraries/PlanRunner.php` (`class PlanRunner extends \App\Controllers\PlanService`).
It exposes public wrappers and isn't routable, because auto-routing is off.

| Command | Purpose |
|---|---|
| `php spark plan:import <json> --user=<email> [--from=YYYY-MM] [--map=<json>] [--update] [--keep-past-end] [--create-missing-accounts [--card-limit=<amount>]] [--dry-run]` | Import the seed |
| `php spark plan:verify --user=<email> --year=2026 [--seed=<json>] [--keep-past-end]` | Reconcile the database against the seed |
| `php spark plan:roll [--user=<email>] [--months=12]` | Extend open-ended items |

**Import rules**
- **Idempotent:** items match on `(user, itemkey)` and entries on `(item, month)`. A
  second run creates nothing new. `--update` changes the amounts of unsettled entries
  only. Settled payables are never touched.
- **Categories:** create or update from `categories[]` (allocation %).
- **Resolving hints:**
  - `account_hint` / `to_account_hint` match the user's active accounts by name,
    case-insensitively.
  - `method_hint` matches a method of the resolved account by name, else **that account's
    default method**. The payment-methods migration named every seeded card method plain
    *Credit Card*, so the name alone won't match.
  - `budget_hint` finds a budget, or creates one (periodic `M`).
  - `--map` is a JSON object that maps hint names to the user's real account or method
    names.
  - Anything unresolved is left NULL and listed in the report. With
    `--create-missing-accounts`, missing card accounts are created at a zero balance with
    a `CREDIT_CARD` default method whose limit is `--card-limit` (default 0).
  - The report lists every credit-card method with a 0 limit. Dues paid from such an
    account fail with 0019 until a limit is set.
- **End dates:** entries after an item's end date are **not** created, and each trimmed
  month is reported.
- **`--keep-past-end`** (decision 11a = keep): items with months after their `end` get
  `enddate` moved to the 28th of their last seeded month (2026-12-28 for the HSBC items).
  The report says so.
- **Report:** counts created / updated / skipped, unresolved hints, the seed `warnings[]`,
  and the trimmed months.

**Verify** checks that:
- each month's planned net matches `checks.net_if_end_dates_are_respected`, or
  `checks.sheet_total_row_42` with `--keep-past-end`. Compare in **integer cents**:
  `abs(round(net*100) - round(expected*100)) <= round(tolerance*100)`. A float
  comparison rejects a 0.01 difference.
- `planentry.plannedamount = payable.dueamount` for every DUE entry;
- there are no entries after end dates and no duplicate `(item, month)`;
- every payable on a planitem paymentplan has a matching entry.

It exits non-zero on any failure.

**Optional, last phase, needs approval:** `php spark plan:import-xlsx <file> --sheet="Planned Total Expenses" --year=2026`.
It converts the sheet into the same JSON shape (reading row labels between *Income* /
*Expense* / *Total*, columns B–M and O), then runs the importer. Prefer a dependency-free
reader (`ZipArchive` + `SimpleXML`, using cached values) and fall back to
`phpoffice/phpspreadsheet` only if I approve.

## 7. Pages and endpoints

**Menu:** add **Monthly Plan**.

| Route | Purpose |
|---|---|
| `GET /plan` | Redirect to the current year |
| `GET /plan/(:num)` | **Year grid** (see below) |
| `GET /plan/(:num)/(:num)` | **Month view**, like the sheet's *Nov Plan* |
| `GET /plan/items` | **Items**: list, create and edit form (direction, kind, category, amount type, default amount, due day, start, end, account, method filtered by account via `LB.syncPaymentMethods`, to-account, budget, derived source + ratio, notes), end and delete |
| `POST /js-request/plan/item/create`, `/update`, `/end`, `/delete` | Item CRUD |
| `POST /js-request/plan/entry/update` | Body: `entry_id, amount, apply_forward` |
| `POST /js-request/plan/entry/skip` | Skip a month |
| `POST /js-request/plan/category/update` | Body: `category_id, allocation_percent, name` |
| (existing) `POST /js-request/payment/settle` | Settle dues |

**Year grid** (`/plan/(:num)`)
- Rows are grouped Income → each expense category. Columns Jan–Dec, Year, End date.
- Cell colours: settled (green), part paid (amber), overdue (red), upcoming (neutral),
  historical (grey), skipped (struck through), after the end date (blank).
- Clicking an editable cell edits it inline, with *apply to following months*.
- Footer rows:
  - Planned income
  - Planned outflow
  - **Planned net**
  - Actual net
  - Cumulative
- Then the card settlement projection and the allocation table (category: planned vs target).

**Month view** (`/plan/(:num)/(:num)`)
- The month's planned income and the list of dues: status, account, method, outstanding.
- Select and settle in full or in part (payables and receivables separately, as the Dued
  tab already enforces).
- Envelopes with spent / remaining.
- **Left after commitments:** planned income − planned outflow, and the actual equivalent.

Every page follows section 3 (auth redirect, `esc()`, explicit routes).

## 8. Phases (one commit each)

1. **Schema + structures + import/verify.**
   - The migration, trait structures, a `PlanService` (protected methods) for generation,
     and `plan:import` + `plan:verify`.
   - Update both setup scripts to apply all migrations in order.
   - *Check:* the migration runs twice cleanly; `plan:import --dry-run` then a real run;
     a second run creates 0; `plan:verify` passes.
2. **Settlement integration.**
   - `paymentplan.direction`, TRANSFER settlement, and plan dues in the Dued tab with the
     item name, including the new **Transfers** choice.
   - *Check:* settle Oct dues for salary (IN), Hutch (OUT) and weekly expense (TRANSFER).
     Balances, payments and `duepaid` reconcile; nothing else regresses.
3. **Year grid + month view + cell edit / skip.**
4. **Item management page** (create / edit / end / delete, derived items, roll forward).
5. **Allocation table + card settlement projection** on the grid.
6. **Docs** (`docs/MONTHLY_PLAN.md`, `LOCAL_SETUP.md` section), then the optional xlsx
   importer if approved.

## 9. Security checklist (test each one)

- A second user cannot read or edit another user's items, entries, categories or the grid
  (expect 404 or 422, never data).
- Expired session → 401 on every `/js-request/plan/*`.
- IDs that don't match `isSessionID()` → 422 with no SQL run.
- A payment method on an item must belong to the item's account. The to-account must
  differ from the account.
- Editing an entry can never change a settled payable or push due below paid.
- No new route is reachable without being listed in `Routes.php`.

## 10. Acceptance numbers (seed loaded for `svisula@gmail.com`, `--from=2026-10`)

| Check | Expected |
|---|---|
| Items imported | 35 (30 have 2026 amounts; *Extra Hrs Worked*, *ChatGPT* and the Sampath / ComBank / NDB arrears rows have none) |
| Categories | 16. The *Planning* % add up to 99.75, so the banner shows. |
| Oct 2026 planned income | **391,504.64** |
| Oct 2026 planned outflow | **392,404.61** if end dates are ignored (net **−899.97** = sheet `K42`). **366,904.61** if end dates are respected (net **24,600.03**). |
| Oct card projection | Sampath **6,035.00**, ComBank **8,333.33** (resolved through the card accounts' default methods). NDB **14,200.00** only when an NDB card account exists (`--create-missing-accounts`, or one the user created and mapped). HSBC **25,500.00** with `--keep-past-end`, else **0.00**. These match sheet `B44:B47`, which are October formulas. |
| Oct DUE entries with payables | 23 if end dates are respected (Groceries is an envelope) |
| Jan–Sep 2026 | historical `H` entries; the Dued tab shows nothing overdue for them |
| `plan:verify --year=2026` | passes all 12 months exactly. The seed stores the sheet's 7,620.455 half-up as 7,620.46, and verify compares in cents. |
| One-off items after import | no October entry for *Insurance for Bike*, *HSBC Credit Card Arrears* or *Collect Return Amount*; no May–Aug *Electrocity Bill* |

## 11. Open decisions (ask Visula before the phase that needs them)

| # | Question | Default if not answered |
|---|---|---|
| a | *Laptop HSBC Card* and *Games Installment for HSBC Card* end **28 Jun 2026**, but the sheet still plans them Jul–Dec (153,000). Stop at the end date, or keep them (`--keep-past-end`)? | Respect the end date |
| b | Tracking start: create payables from which month? | Current month (2026-10). Earlier months are historical. |
| c | *Weekly Expense*: TRANSFER Standby → WE Handler (like the bank ledger), or an ENVELOPE? | TRANSFER |
| d | NDB and HSBC card accounts aren't in the demo dump. Create them on import, or leave the items unassigned? | Leave unassigned and report (`--create-missing-accounts` is available) |
| e | `app/Config/App.php` has `appTimezone = 'America/Chicago'`, which affects "today" and overdue. Switch to `'Asia/Colombo'`? | Ask. Don't change silently. |
| f | Allocation base: each month's planned income, or the fixed 376,525 from *Planning!B4* (which includes 7,500 "Nikhila's Payable", not a plan item)? | Each month's planned income |

## 12. Files likely added or changed

- **Added:**
  - `DBBackup/Migrations/2026_10_xx_monthly_payment_plan.sql`
  - `app/Controllers/PlanService.php`
  - `app/Controllers/PlanController.php`
  - `app/Controllers/Traits/DB/PlanTraits.php`
  - `app/Commands/PlanImport.php`, `PlanVerify.php`, `PlanRoll.php`
  - `app/Libraries/PlanRunner.php`
  - `app/Views/Plan/year.php`, `month.php`, `items.php`
  - `public/assets/js/plan/*.js`
  - `docs/MONTHLY_PLAN.md`
- **Changed:**
  - `app/Controllers/PaymentService.php` (direction and TRANSFER settlement)
  - `app/Views/Transaction/dued_trans_module.php`, `public/assets/js/transaction/dued.js` (Transfers choice)
  - `app/Controllers/BlueprintService.php` (use the new trait)
  - `app/Controllers/MainService.php` (menu)
  - `app/Config/Routes.php`
  - `scripts/setup-local-db.sh` / `.bat` (all migrations)
  - `LOCAL_SETUP.md`
