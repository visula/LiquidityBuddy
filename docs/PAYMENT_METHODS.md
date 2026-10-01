# Account-wise payment methods & payment ledger

## Setup

```bash
mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_02_account_payment_methods.sql
```

The migration is idempotent (safe to re-run). It also adds the columns the code already
expected but `DBBackup/Liquidity_Buddy.sql` lacked, and seeds one default payment method
per existing account (`Cash`, `Credit Card` or `Bank Transfer`, guessed from the account
name). Set real credit limits afterwards under **Payment Methods**.

## Model

| Table           | Purpose |
|-----------------|---------|
| `paymentmethod` | Methods owned by an account: CASH, DEBIT_CARD, CREDIT_CARD, BANK_TRANSFER, ONLINE, CHEQUE, WALLET. One default per account. Only the last 4 card digits are stored. |
| `payment`       | One row per payment, receipt or internal transfer: account, method, direction (OUT / IN / TRANSFER), category, settlement type (FULL / PARTIAL / INSTALLMENT), reference, link to what it paid for. |
| `duepaid`       | Allocation of a payment against payables (ERD *Due_Paid*), giving full and partial settlement. |
| `transaction`   | Now carries `paymentsessionid`, `paymentmethodsessionid` and `balanceafter`. |

**Credit cards:** an account may go below zero down to `-creditlimit` when paying with a
credit card method. Paying the card off is an internal transfer into the card account.
A limit can't be lowered below what's owed, and the card can't be removed or retyped while
the account owes money.

## Rules enforced by `PaymentService::recordPayment()`

* The account, the transfer target, the payment method and any dues must belong to the
  logged-in user, and the method must belong to the paying account.
* Funds are checked on a locked account row (`SELECT ... FOR UPDATE`).
* Payment row, transactions, balance moves and due allocations happen in one DB
  transaction. If any step fails, nothing is written. This includes purchase and plan
  creation when the first installment fails.
* Installments use calendar months (31 Jan, then 28 Feb, then 31 Mar). The last
  installment takes the rounding remainder, so the installments add up exactly to the total.
* A partial settlement goes to the oldest selected due first. Payables and receivables
  (income plans) are settled separately.

## Endpoints (all POST, JSON, session auth)

| Path | Main params |
|------|-------------|
| `/js-request/payment/expense`  | `amount, current_account, payment_method?, budget, external_pay_type (T/C/O), scheduled_info?, reference?, payment_date?` |
| `/js-request/payment/purchase` | `amount, current_account, payment_method?, budget, shop, item_list?, scheduled_info?` |
| `/js-request/payment/income`   | `type (one-time/monthly/till), amount, account_id, payment_method?, budget_id, collection_plan?` |
| `/js-request/account/transferred` | `amount, from_account, to_account, payment_method?, budget, description?` |
| `/js-request/payment/settle` (alias `/payment/dued`) | `payable_ids[] or payment_plan_id, mode (full/partial), amount (partial), account_id, payment_method?, budget_id` |
| `/js-request/payment-method/create` | `account_id, name, type, reference?, credit_limit?, is_default?` |
| `/js-request/payment-method/update` | `payment_method_id` + any of the create fields except account |
| `/js-request/payment-method/delete` | `payment_method_id` (soft delete) |

`payment_method` is optional. When it's omitted, the account's default method is used.
Errors return HTTP 422 (401 when the session has expired) with
`{success:false, code, message}`.

Pages: `/payment-method/list`, `/payment/history[?account=]`, and the **Dued** tab on
the transaction screens.

## Note

Auto routing is now **off** (`app/Config/Routes.php`). With it on, any public controller
method could be reached by URL without logging in. Add new pages and endpoints to
`Routes.php` explicitly.
