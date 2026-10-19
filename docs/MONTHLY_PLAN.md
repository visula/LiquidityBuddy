# Monthly Payment Plan

The **Monthly Plan** menu replaces the *Planned Total Expenses* spreadsheet. You plan
each month's income, bills, installments, transfers and spending envelopes. Every
planned month becomes a due you settle through the payment ledger. The plan then shows
plan against actual, what each credit card will need, and how spending compares with
the category allocations.

Design spec: [`docs/plans/MONTHLY_PAYMENT_PLAN.md`](plans/MONTHLY_PAYMENT_PLAN.md).
Payment ledger: [`docs/PAYMENT_METHODS.md`](PAYMENT_METHODS.md).

---

## For users

### Words used

| Word | Meaning |
|---|---|
| **Item** | One line of the plan: *Salary*, *Hutch*, *Weekly Expense*. It is income (**I receive**), a payment (**I pay**) or a transfer between your own accounts (**I move money**). |
| **Month** (entry) | One item in one month: a cell of the year grid. |
| **Due** | The payable behind a month. You settle it in full or in part. |
| **Envelope** | A spending cap instead of a due, for example *Groceries*. What you spent is the expenses tagged with its budget that month. |
| **Share** | An item worth a ratio of another item, for example *E-Bill* = 0.5 × *Electricity Bill*. It follows its source unless you change a month by hand. |
| **Tracking month** | The first month that gets dues. Earlier months are **history**: shown in grey, never due, never overdue. |

### Pages

- **Year grid** (`/plan/2026`):
  - Rows are grouped Income first, then each category; columns are the months, the year total and the end date.
  - Colours: settled (green), part paid (amber), overdue (red), upcoming (white), envelope (blue), history (grey), skipped (struck through).
  - Click an amount to change it, optionally for the following months too, or to skip or restore that month. Click a month heading to open that month.
  - Footer rows: planned income, planned outflow, **planned net**, actual net, and the cumulative planned and actual nets.
  - **Card settlement projection**: what each credit card needs each month.
  - **Allocation**: each category's planned spending against its % of the month's planned income. A banner shows when the percentages don't add up to 100.
- **Month view** (`/plan/2026/10`):
  - Totals: planned income, planned commitments, what is left, and the actual so far.
  - The month's dues. Pick payables, receivables or transfers, tick dues and **Settle Selected**. The account, method and budget come from the item.
  - Envelopes with spent and remaining.
- **Plan items** (`/plan/items`):
  - Add and edit items.
  - **End** an item: the unpaid months after the end are skipped.
  - **Delete** an item: only while nothing on it has been paid.
  - Edit category names and allocation %.

### Rules worth knowing

- **Settled money never changes.**
  - A settled month can't be edited, skipped or ended before.
  - A part-paid month can't go below what is paid. Editing it down to the paid amount settles it.
  - A part-paid month can't be skipped.
- **Skipping** cancels the month's due. **Restore** brings the same due back.
- **Repeating items** are planned 12 months ahead. Each time a plan page opens, the next months are added: at the latest amount, or for a share, at its ratio of the source.
- **One-off items** (insurance, arrears) are not repeated. Add next year's month from the items page.
- **Salary advance** (Items page, *Salary advance* card): pick an income item, the month the advance is received and the amount. The advance is a one-off income due in that month, and the next month's amount of the item goes down by the same amount. Both changes are plan edits, so the advance settles through the payment ledger like any income.
- **Weekend due** (income items only): *No change*, *Previous working day* or *Next working day*. A Saturday or Sunday due moves to the chosen working day. Changing the setting moves open months only; settled months don't move.
- **The Dued tab** lists plan dues up to the end of next month; the month view has the rest.
- **Card dues need a credit limit.** A card paid with a 0 limit fails with *insufficient funds*, so set its limit under **Payment methods**.
- **Transfer dues** move money from the item's account to its target account, for example Standby → WE Handler. They run the internal transaction process, so the transfer kind and the bank's charge are applied as for any internal transfer. Budgets and envelopes don't count them.

---

## For developers

### Data model

Migrations: `DBBackup/Migrations/2026_10_14_monthly_payment_plan.sql` (tables) and
`2026_10_15_monthly_plan_indexes.sql` (indexes for the grid, month view and envelopes), and
`2026_10_16_account_number_card_weekend.sql` (`planitem.weekendrule`, which is also used by the weekend rule above).
Both are idempotent.

| Table | Purpose |
|---|---|
| `plancategory` | Category per user (`direction` IN/OUT, `allocationpercent`). |
| `planitem` | Item: `direction` IN/OUT/TRANSFER, `kind` DUE/ENVELOPE, `dueday` (NULL = last day), `startmonth`, `trackfrom`, `recurring`, `enddate`, account / method / to-account / budget, `sourceplanitemsessionid` + `shareratio`, `paymentplansessionid`. Status A active, E ended, D deleted (key renamed so it can be reused). |
| `planentry` | One per (item, month): `plannedamount` (= `payable.dueamount` for DUE entries), `duedate`, `payablesessionid`, `overridden`. Status A active, H history, X skipped. |
| `paymentplan` | One per item (`paymentplanlink = 'planitem'`). `direction` drives settlement. PlanService keeps `period`, `amount`, `startdate`/`enddate` and the status in line with the non-cancelled payables. |

### Code

| File | Role |
|---|---|
| `app/Controllers/PlanService.php` | All plan logic (protected methods): generation and roll forward, import / verify, entry edit / skip / restore, item create / update / end / delete, categories, year grid, month view, card projection, allocation. |
| `app/Controllers/PlanController.php` | Thin pages and JSON endpoints. |
| `app/Libraries/PlanRunner.php` | Public wrappers for spark commands (no routes). |
| `app/Commands/PlanImport.php`, `PlanVerify.php`, `PlanRoll.php` | `plan:import`, `plan:verify`, `plan:roll`. |
| `app/Views/Plan/*`, `public/assets/js/plan/*`, `public/assets/css/plan.css` | Pages. |
| `app/Controllers/PaymentService.php` | Dues carry their plan's direction. TRANSFER dues settle to the item's to-account. `duedTabPayables()` limits the Dued tab. |

Money only moves through `settlePayables()` → `recordPayment()`, or for a TRANSFER due through `settlePayables()` → `internalTransfer()` → `recordPayment()`; PlanService writes plan
rows, payment plans and payables only. Every write runs in `runAtomic()` with the
changed rows locked (`FOR UPDATE`). Every incoming id is checked with `isSessionID()` and
looked up with the user's id.

### Endpoints

All JSON endpoints return 401 without a session, and 422 `{success:false, code, message}`
on a business error.

| Route | Body |
|---|---|
| `GET /plan`, `/plan/(:num)`, `/plan/(:num)/(:num)`, `/plan/items` | Pages (`?m=YYYY-MM` picks the allocation month on the grid) |
| `POST /js-request/plan/item/create` | `name, direction, kind, category_id, amount_type, default_amount, due_day, start_month (YYYY-MM), end_date, recurring, account_id, payment_method, to_account_id, budget_id, source_item_id, share_ratio, stakeholder, notes` |
| `POST /js-request/plan/item/update` | `item_id` + any create field except `direction`, `kind` and `start_month`. A shorter `end_date` ends the item; a later one or none extends it. |
| `POST /js-request/plan/item/end` | `item_id, end_date` |
| `POST /js-request/plan/item/delete` | `item_id` |
| `POST /js-request/plan/entry/update` | `entry_id, amount, apply_forward` |
| `POST /js-request/plan/entry/skip`, `/restore` | `entry_id` |
| `POST /js-request/plan/salary-advance` | `item_id` (income item), `month` (YYYY-MM), `amount` |
| `POST /js-request/plan/category/create` | `name, direction, allocation_percent` |
| `POST /js-request/plan/category/update` | `category_id, name, allocation_percent` (empty = no target) |
| `POST /js-request/payment/settle` | Existing settle endpoint, used by the month view |

### Error codes

| Code | Meaning |
|---|---|
| 0070–0073 | Import: bad seed format, import failed (rolled back), bad item key, no account group for a new card account |
| 0074 / 0075 | Settling transfers with different targets / a transfer without a target |
| 0076 / 0077 / 0078 | Item / month / category not found (or not the user's) |
| 0079 | Month settled or skipped: can't change |
| 0080 | Amount below what is paid, or zero on an open due (skip instead) |
| 0081 | Can't skip (part paid, settled, already skipped) / not skipped (restore) |
| 0082 | Invalid item input (name, direction / kind change, amounts, due day) |
| 0083 | Payment method not of the item's account |
| 0084 | Transfer without both accounts, or to the same account |
| 0085 | Budget not found, or an envelope without a budget |
| 0086 | Delete refused: something on the item was paid |
| 0087 | Share rules: no share of a share, of itself, or deleting a share's source |
| 0088 | End date invalid, before the first month, or before a settled month |
| 0089 | Category name or allocation % invalid / duplicate |
| 0090 | Salary advance: amount or month invalid, item not income, no next-month salary to reduce, or the advance is more than that salary |

### Totals

- **Planned** income and outflow count active and history months; skipped months are left out.
- **Settled** is the payable's paid amount, counted in the month of its entry.
- **Envelope spending** is the sum of the month's `expense` transactions tagged with the envelope's budget, over the user's accounts. Transfer legs and due settlements are left out.
- **Actual net** = settled in − settled out − envelope spending, shown for tracked months up to now.
- **Card projection.** Per credit card method, it adds non-income months whose effective method is the card (the month's override, else the item's method, else the item account's default method), plus transfers into the card's account.
- **Allocation targets** are the category's % of that month's planned income (decision 11f).

### Commands

```bash
php spark plan:import <json> --user=<email> [--from=YYYY-MM] [--map=<json>] [--update] [--keep-past-end] [--create-missing-accounts [--card-limit=N]] [--dry-run]
php spark plan:verify --user=<email> --year=2026 [--seed=<json>] [--keep-past-end]   # exit 1 on any failure
php spark plan:roll [--user=<email>] [--months=12]                                 # safe to run any time
```

`plan:verify` compares each month's planned net with the seed in integer cents. It also
checks the invariants:

- every month's amount equals its due;
- every tracked DUE month has a due, and no history month has one;
- no months after an end date, and no duplicate months;
- no orphan plan dues;
- every payment plan agrees with its dues.

Once months are edited on purpose, the nets will differ from the seed, but the
invariants must still pass.

The optional xlsx importer (`plan:import-xlsx`, spec section 6) is not built.
