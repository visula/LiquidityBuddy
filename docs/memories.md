# Memories: what has been implemented

A dated record of what changed, why, and what is still true about it. Newest first within
each section. Commit hashes are on branch `feature/monthly-payment-plan`. Last updated 2026-11-02.

---

## 2026-10-30 — Purchase history, source labels, cash flow (commit `4e63ea6`)

- **Purchase ledger.** `ReportService::purchaseLedger()` gives each purchase its payments
  (direct, or through its installment plan via `duepaid`), its installments, and a status:
  PAID, SCHEDULED, PART_PAID or UNLINKED. The purchase report shows it.
  *Why:* a purchase was a line on a report with no link to the money that paid for it.
- **Payment link labels.** `PaymentService::linkLabels()` turns a payment's link into a
  readable label ("Purchase: …", "Payment plan: …", "Travel", "Dues settlement").
  Used by the payment history "For" column.
- **Transaction source.** `transactionSourceLabels()` adds a Source column to the transaction
  list. Transfers show "Transfer". Rows without a payment show "—".
- **Cash flow page** (`/payment/cashflow`, menu under Transactions). `CashflowService`:
  - money in and out per month from `transaction`, excluding transfers;
  - planned dues and expected income per month, and the dues rate (dues ÷ expected income);
  - a due-handling list: overdue, this month, next month, later, each with the account's current
    balance and an action.
  - Chart.js 4.4.1 from jsdelivr.
- **Decision — legacy rows.** The live database had 0 `payment` rows and 111 `transaction` rows
  written before the ledger. Old purchases stay unlinked; they are not matched by description.
  The cash flow reads `transaction` so old months still count.
- **Decision — transfers in cash flow.** A transaction is a transfer if its payment has direction
  `TRANSFER`, or, for old unlinked rows, if its description starts with "Transfer". The second rule
  is a heuristic.
- **Verified:** 24 checks in a rolled-back transaction, all passing. Live row counts unchanged.
  The chart has **not** been seen in a browser.

## 2026-10-30 — Account creation form (commit `4e63ea6`)

- **Bank product list** filters by bank (typed name, matched without case) and by bank product
  type. The list is rebuilt from a saved copy, not hidden with `hidden` (inconsistent across
  browsers). An empty result shows a hint.
- **Picking a product** sets the bank name to the product's bank. Typing in the bank field does not
  overwrite it.
- **Editing an account** sets bank and type before the filter runs (it used to drop the product).
- **Stray `<?`** removed from the bank product option labels; unused `$cards` removed.
- **Sub-account parent.** The "Sub-account of a main account?" list shows only the user's own
  bank accounts (SAVINGS, CURRENT, FIXED_DEPOSIT) that are not themselves sub-accounts. The same rule
  is applied on save (`parentCandidate`).
  *Consequence:* the live Sampath accounts are typed OTHER with no bank name, so they do not appear as
  parents until their type is changed. See [tasks.md](tasks.md).
- **Verified:** the product filter in jsdom with real jQuery (14 checks). Parent rule by query.
  Not checked in a browser.

## 2026-10-29 — Account form, password reset and mobile (commit `ef80ef0`, `5c27677`)

- Account creation and update share one form; update loads the account into the same sections.
- Card choice: none or a new card only. Existing-card dropdown removed (no use).
- Bank card rules: one debit card per account (0068), one per bank group (0091).
- Sub-accounts: `parentaccountsessionid`; "Settle sub-accounts" moves each sub-account balance into
  its main account as an internal transfer.
- **Password reset.** Token hashed (SHA-256), 30 minutes, single use, generic response. Email needs
  SMTP settings in `.env` (see LOCAL_SETUP.md).
- **Mobile.** `public/assets/css/mobile.css` (single-column rows, 44px targets, 16px inputs). Not
  checked on a phone.
- Transaction list page restored (`Transaction/list.php` was missing).

## 2026-10-18 to 2026-10-20 — Schema changes applied to the live database

| Migration | Adds |
|---|---|
| `2026_10_16_account_number_card_weekend` | `account.accountnumber`, `paymentmethod.showindropdown`, `planitem.weekendrule` |
| `2026_10_17_account_group_type` | `accountgroup.grouptype` (BANK, OTHER) |
| `2026_10_18_linked_debit_cards` | `paymentmethod.linkedfrom` (not committed at the time) |
| `2026_10_19_sub_accounts` | `account.parentaccountsessionid` |
| `2026_10_20_password_reset` | `passwordreset` table |

Five old linked-card rows (Sampath sub-accounts) remain in `paymentmethod` with `linkedfrom` set.

## 2026-10-16 to 2026-10-17 — Monthly plan and payment ledger (commits `7d8ad1b`, `8dee4fe`)

- Plan phase 1: schema, `PlanService`, `plan:import`, `plan:verify`, `plan:roll`.
- Plan phase 2: plan dues settle through the payment ledger.
- Income cells: overdue shows as expected or late (not red). Weekend rule for income: previous or
  next working day.
- Salary advance (error 0090): reduces the next month's salary.
- Income percentages are now included in the allocation (they were stored but ignored).
- Monthly plan income and expense can be changed for one month or the following months.

## Earlier (commits `c6cf103`, `d2483ad`, `fed0b66`, `ef80ef0`)

- Ledger, budgets, sub-account groundwork, bank rates (`BankRateSync`), interest posting,
  internal transfers with bank charges, recurring payments, purchase and payment plans.
- Amount inputs: two-decimal rule (`LB.amountTyping`, `LB.amountFixed`).

---

## Standing decisions

- **Money path:** only `recordPayment`. See [rules.md](rules.md) §1.
- **MD5 passwords** are kept for now. Decision pending: move to `password_hash` (see tasks).
- **Live database changes** need an explicit yes each time, with a backup taken first.
- **No commits or pushes** unless asked.
- **Bank accounts** are the only possible main accounts for sub-accounts.
