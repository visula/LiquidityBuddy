# Architecture

Last reviewed: 2026-11-02 (HEAD `4e63ea6`).

LiquidityBuddy is a CodeIgniter 4.7 app (PHP 8.2+) on MySQL/MariaDB. Pages are
server-rendered views with jQuery and Bootstrap 5.0.2 from CDN. Money moves through
one ledger. Everything else is a read or a presentation of it.

---

## 1. Layers

| # | Layer | Where | What it does |
|---|---|---|---|
| 1 | Routes | `app/Config/Routes.php` | Every URL is listed by hand (auto-routing is off). `GET` pages and `POST /js-request/...` endpoints. |
| 2 | Controllers | `app/Controllers/*Controller.php` | Read the request, check login, call services, return a view or JSON. Thin. |
| 3 | Services (inheritance chain) | `app/Controllers/*Service.php` | Business logic. Each level adds a domain. |
| 4 | Traits | `app/Controllers/Traits/` | Behaviour mixed into the services: recurring, internal transfers, interest, budget rules. |
| 5 | Table maps | `app/Controllers/Traits/DB/*Traits.php` | Per table: front-end keys to database columns, primary keys, foreign keys. |
| 6 | Generic DB access | `BlueprintService::getDatafromDB / insertDatatoDB / updateDBData`, `app/Models/Database/DBAccess.php` | Reads and writes through the table maps. |
| 7 | Libraries | `app/Libraries/` | `BankRateSync` (bank products, rates, charges), `PlanRunner` (plan commands). |
| 8 | Spark commands | `app/Commands/` | `plan:import`, `plan:verify`, `plan:roll`, `bankrates:sync`. |
| 9 | Database | MySQL (34 tables) | Source of truth. Migrations in `DBBackup/Migrations/`. |

Front end: views in `app/Views/`, scripts in `public/assets/js/`, styles in
`public/assets/css/`. Shared helpers live in `public/assets/js/default.js` (`LB.post`,
`LB.showError`, `LB.money`, `LB.amountValue`, `LB.amountFixed`, `LB.refreshRegion`).

---

## 2. Service inheritance chain

```
BaseController (CodeIgniter)
  └─ BlueprintService          generic table access (getDatafromDB, insertDatatoDB, ...)
       └─ MainService          session user, login, menu, commonHead, runAtomic, error redirect
            ├─ Home            login, signup, logout
            ├─ PasswordResetController
            └─ AccountService  accounts, groups, budgets, active-account lists
                 └─ PaymentService   THE LEDGER: recordPayment, settlePayables,
                      │              payment methods, outstandingPayables, paymentHistory,
                      │              linkLabels, transactionSourceLabels
                      ├─ AccountController    accounts, sub-accounts, transaction list
                      ├─ BankController
                      ├─ TransactionController
                      ├─ ReportService        income/expense and purchase reports, purchaseLedger
                      ├─ PurchaseService      purchases, payment plans (installments), external payments
                      │    └─ PurchaseController   /js-request/payment/{expense,income,purchase,dued,settle}
                      ├─ PlanService          monthly plan (≈2,300 lines)
                      │    └─ PlanController
                      └─ CashflowService      cash flow, due handling
                           └─ PaymentController   payment methods, payment history, cash flow page,
                                                  recurring
```

Adding a method to a middle class is visible to every class below it. Check the
chain before adding a method with a common name.

---

## 3. The ledger (the only money path)

Every movement of money is one `payment` row plus one or two `transaction` rows, written
in one database transaction by `PaymentService::recordPayment()`.

```
UI / JSON request
   │
   ▼
PurchaseController / AccountController / PlanController / ...
   │   payOrSchedule()  ── FULL, or a payment plan (installments) with optional first payment
   │   settlePayables() ── pays one or more dues (payables), FULL or PARTIAL, oldest first
   │   internalTransfer() ── transfer kinds, channel, bank charge; then recordPayment
   ▼
recordPayment(userID, p)
   1. validate: direction (OUT | IN | TRANSFER), amount > 0, owned account, owned method,
      owned budget, date not in the future
   2. over-budget check (OUT) unless allow_over_budget
   3. runAtomic: lock the account row, check funds (error 0019), insert payment,
      insert transaction(s), apply allocations (applyAllocation → duepaid, payable status)
   ▼
payment  ──┬── transaction (one per account touched; carries balanceafter)
           ├── duepaid (payment ↔ payable, settled portion)
           └── link: PaymentLinkType + PaymentLinkSessionID → purchase | paymentplan | travel | ...
```

Links that make history readable:

| Link | Written by | Read by |
|---|---|---|
| `payment.linktype/linksessionid` | `recordPayment` (`link_type`, `link_id`) | `linkLabels`, `purchaseLedger`, `transactionSourceLabels` |
| `paymentplan.paymentplanlink/paymentplansessionid` | `paymentPlanInitProccess` (purchase, expense, income, planitem) | `outstandingPayables`, `purchaseLedger` |
| `payable.paymentplansessionid` | plan creation and plan roll | dues, settlement, cash flow |
| `duepaid.paymentsessionid/payablesessionid` | `applyAllocation` | `paymentHistory`, `purchaseLedger` |
| `transaction.paymentsessionid` | `recordPayment` | transaction list source, cash flow |

Rows written before the ledger (2024–2026 data) have no `payment` row. They are
still counted in balances and cash flow, but they have no source.

---

## 4. Monthly plan

- **Plan items** (`planitem`): income, bills, installments, transfers, envelopes. Each item
  has a `paymentplan` row with `paymentplanlink = 'planitem'`.
- **Entries** (`planentry`): one per item per month, with amount and state (overridden,
  skipped, derived shares).
- **Dues** (`payable`): created a year ahead for each entry. Settled through the ledger.
- **Grid**: `planYearGrid` and `planMonthView` build the year and month pages.
- **Allocation**: income and spending percentages per category (`planAllocation`).
- **Card projection**: what each credit card needs (`planCardProjection`).
- Commands: `plan:import` (seed from a sheet), `plan:verify` (check plan against the database),
  `plan:roll` (extend the horizon).

Spec: [plans/MONTHLY_PAYMENT_PLAN.md](plans/MONTHLY_PAYMENT_PLAN.md). User guide: [MONTHLY_PLAN.md](MONTHLY_PLAN.md).

---

## 5. Accounts, cards and bank products

- **Account**: `accounttype` (CASH, SAVINGS, CURRENT, FIXED_DEPOSIT, CREDIT_CARD, WALLET, OTHER),
  `bankname`, `accountnumber`, optional `bankproductsessionid`, optional
  `parentaccountsessionid` (sub-account of a main account), `autointerest`.
- **Main account**: a bank account (SAVINGS, CURRENT or FIXED_DEPOSIT) that is not itself a sub-account.
- **Payment methods** (`paymentmethod`): per account. Debit cards are limited to one per
  account and one per bank group (errors 0068, 0091). A card can be linked to another
  account (`linkedfrom`) and hidden from dropdowns (`showindropdown`).
- **Bank products** (`bankproduct`, `bankproductfee`): synced from providers in
  `app/Config/BankRates.php` by `BankRateSync`. Give interest rate and channel charges.
- **Interest** (`InterestPosting` trait): posted monthly for accounts with auto-interest.

Design notes: [PAYMENT_METHODS.md](PAYMENT_METHODS.md), [BANK_RATES.md](BANK_RATES.md).

---

## 6. Purchases, dues and cash flow (added 2026-10-30)

- **Purchase** (`purchase`, `item`): shop, date, items, discounts. Must match the amount paid.
- **Purchase ledger** (`ReportService::purchaseLedger`): payments made for a purchase, directly
  or through its installment plan, the installments and a status: PAID, SCHEDULED, PART_PAID or UNLINKED.
- **Cash flow** (`CashflowService::cashflowProccess`): money in and out per month from
  `transaction` (transfers excluded), planned dues and expected income per month, and
  `dueHandling` (overdue, this month, next month, later). Shown at `/payment/cashflow`.

---

## 7. Auth, sessions and errors

- **Login**: `Home::loginValidation`. Session keys: `is_login`, `user_id`. `userAccess()` returns the
  logged-in user; every query is filtered by it.
- **Password reset**: `PasswordResetController`. Random 32-byte token, stored as SHA-256 hash,
  30-minute expiry, single use, generic response on request. Emails need SMTP settings in `.env`.
- **Passwords**: `encryptLogic()` is MD5 (legacy, flagged).
- **Errors**: services return `paymentError(id, category, message)` arrays. Controllers
  turn them into `apiError` (HTTP 422), `apiUnauthorised` (401) or `apiResult`. Page
  errors redirect through `errorHandleLogAndPageRedirection`.
- **CSRF**: the `csrf` filter is defined but **not enabled** (see [tasks.md](tasks.md)).

---

## 8. Build, run and verify

- Setup: [LOCAL_SETUP.md](../LOCAL_SETUP.md). The `.env` points at the MySQL container on port 3306.
  Earlier work also used a MariaDB container on port 3307. Confirm which one is running before
  any database check, because the two have held different data.
- Tests: `tests/` (BankRateSyncTest, HealthTest; 14 pass).
- Routes: `php spark routes`.
- Verification pattern used so far: a temporary Spark command that calls controller
  methods with a fake session, wrapped in `transBegin()` / `transRollback()`, then deleted.

---

## 9. Known weak points

Largest classes: `PlanService` (~2,300 lines), `AccountController` (~1,200), `PaymentService`
(~1,200), `AccountService` (~640). Duplicate lookups of the same account list in several
places. MD5 passwords. No feature tests for the ledger, plan or reset flow. Migrations applied
by hand. See [tasks.md](tasks.md) for the planned fixes.
