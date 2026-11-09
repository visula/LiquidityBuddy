# Project plan

Status as of 2026-11-02, branch `feature/monthly-payment-plan`, HEAD `4e63ea6`.

Status words: **Done** (built and checked), **Done, not browser-checked** (built and checked
on the server side only), **In progress**, **Planned**, **Blocked** (waiting on a decision or
access). The open items with owners and next steps are in [tasks.md](tasks.md).

---

## Goal

A personal cash-management app that replaces a planning spreadsheet. It should show:
- where money is, per account and per payment method;
- what is planned for each month, what is due, and what has been paid;
- what was bought, how it was paid, and what is still owed;
- where the money is going compared with where it comes from.

---

## Milestones

### M1 — Core ledger and accounts — Done
- Accounts, account groups, budgets, unplanned income budget.
- Payments in, out and between accounts, through one ledger (`recordPayment`).
- Transaction list, income and expense reports, balance per account.
- Bank rates and products (`BankRateSync`), interest posting, bank charges on transfers.
- Recurring transactions (monthly income, bills).

### M2 — Payment methods and account structure — Done
- Payment methods per account: cash, bank transfer, debit card, credit card.
- Card rules: one debit card per account and per bank group; cards hidden from dropdowns when needed.
- Account number, account kind (bank, cash, other), bank product link.
- Sub-accounts under a main bank account; settle sub-accounts into the main account.
- Account create and update share one form.

### M3 — Monthly payment plan — Done (phases 1–2), phase 3 Planned
- Year grid and month view; items for income, bills, installments, transfers and envelopes.
- Each planned month becomes a due settled through the ledger.
- Card projection and category allocation.
- `plan:import` (seed from the planning sheet), `plan:verify`, `plan:roll`.
- Planned: colour states in the month view use the old states (see tasks).
- Planned: HSBC installment decision and card limits (see tasks).

### M4 — Purchases, installments and dues — Done, not browser-checked
- Purchases with items and discounts, paid in full or as installments.
- Purchase ledger: payments and installments per purchase, with a status.
- Dues tab: settle selected dues fully or partly.
- Payment history with the link for each payment.
- Transaction list with a Source column.

### M5 — Cash flow — Done, not browser-checked
- `/payment/cashflow`: money in and out per month, planned dues against expected income,
  dues rate, and a due-handling list.
- Not yet verified: how the chart looks in a browser (Chart.js 4.4.1).
- Not yet decided: whether legacy purchases and transactions should be linked to payments.

### M6 — Password reset and sign-in — Done, email not configured
- Forgot-password and reset pages; token hashed, 30 minutes, single use.
- Email sending needs SMTP settings in `.env`.
- Planned: MD5 password storage replaced (see tasks).

### M7 — Mobile use — Built, not device-checked
- `mobile.css` for phone widths; touch targets, single-column rows, scrolling tables.
- Not yet checked on a phone.

### M8 — Security hardening — Planned
- Enable CSRF protection on forms and POST endpoints.
- Replace MD5 passwords with `password_hash`, with a forced reset or re-hash on login.
- Review every endpoint for owner checks.

### M9 — Quality and maintainability — Planned
- Feature tests for the ledger, plan, purchase ledger, cash flow and reset flow, run against a
  disposable database.
- Migration runner to replace hand-applied SQL.
- Split `PlanService` and `AccountController` into smaller services.
- One error response format for pages and JSON.
- Local fallbacks for CDN scripts.

---

## Principles

1. One money path (see [rules.md](rules.md) §1).
2. The user's data is not changed without approval; a backup comes first.
3. A feature is not done until it is checked end to end and the unverified parts are named.
4. Keep the docs true: [architecture.md](architecture.md), [memories.md](memories.md) and
   [rules.md](rules.md) change with the code.

---

## Documents

| Document | Purpose |
|---|---|
| [architecture.md](architecture.md) | Layers, class chain, the ledger, the data links |
| [rules.md](rules.md) | Conventions for changes |
| [memories.md](memories.md) | What has been implemented, and why |
| [tasks.md](tasks.md) | Open work, decisions and blockers |
| [MONTHLY_PLAN.md](MONTHLY_PLAN.md) | User guide for the monthly plan |
| [plans/MONTHLY_PAYMENT_PLAN.md](plans/MONTHLY_PAYMENT_PLAN.md) | Design spec for the monthly plan |
| [PAYMENT_METHODS.md](PAYMENT_METHODS.md) | Payment methods and card rules |
| [BANK_RATES.md](BANK_RATES.md) | Bank products, rates and charges |
| [LOCAL_SETUP.md](../LOCAL_SETUP.md) | Running it locally, SMTP, phone access |
