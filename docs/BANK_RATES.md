# Bank rates, account types, charges and interest

Accounts can be linked to a **bank product**: a savings, current or fixed deposit
account with an interest rate. Products come from external **rate providers** that
are mapped onto one catalog.

## Setup

```bash
mysql -u root liquiditybuddy < DBBackup/Migrations/2026_10_12_bank_products.sql   # or scripts/setup-local-db.sh
php spark bankrates:sync          # pull products now (the Bank Rates page also refreshes them)
```

Schedule `php spark bankrates:sync` daily (cron or Task Scheduler) to keep rates current.
Without it, the catalog refreshes itself when someone opens Bank Rates or an account page
and it's older than a day (`$autoSyncAfter`).

## Providers and the field mapping

Providers live in `app/Config/BankRates.php`. Each one says where to fetch from and
which field of the response fills each catalog column:

```php
'mybank' => [
    'name'       => 'My Bank open data',
    'source'     => ['type' => 'http', 'url' => 'https://api.example.com/rates',
                     'headers' => ['Authorization' => 'Bearer {env:BANKRATES_MYBANK_TOKEN}']],
    'itemsPath'  => 'data.products',              // where the list of products is
    'fields'     => [
        'code' => 'id', 'bank' => 'bank.name', 'product' => 'name', 'type' => 'category',
        'rate' => 'interest.rate', 'minBalance' => 'minimumBalance',
        'currency' => 'currency', 'effectiveDate' => 'interest.from',
    ],
    'typeMap'    => ['savings' => 'SAVINGS', 'current' => 'CURRENT', 'fd' => 'FIXED_DEPOSIT'],
    'rateFormat' => 'percent',                    // or 'fraction' when the feed sends 0.0425
],
```

* Paths use dots (`interest.rate`, `rates.0.value`).
* `bank`, `product` and `rate` are required; items missing them are skipped and counted.
* `type` values go through `typeMap`; anything unknown becomes `OTHER`.
* Without `code`, a product is identified by bank + name + type.
* Put API keys in `.env` and reference them as `{env:NAME}`; never in the config file.
* Source `file` reads a JSON file (the bundled `app/Data/bank_rates_sample.json`).

**The bundled sample feed is illustrative, not live data.** No public API for Sri Lankan
deposit rates was found (CBSL and the banks publish them as web pages), so add a provider
when you have a real feed. Each product's rate change keeps the previous rate, and products
that disappear from a feed are marked "no longer offered" but stay linked.

**Security:** only URLs in this config file are fetched (never user input), they must be
`https`, responses are capped at 2 MB with a 10 s timeout, and all feed text is stripped of
markup before it is stored.

## Interest

* Linking a product starts accrual the next day (`account.interestpostedthrough`).
* Interest uses the **daily balance method**: each day's closing balance (negatives count
  as 0) × annual rate / 365, rebuilt from the account's transactions.
* It is credited for **completed months** (or up to today from Quick add). The amount can be
  changed to what the bank actually credited before posting.
* "Post interest automatically each month" posts it when the user next opens the app.
* Changing the product while interest is unposted asks for confirmation (it isn't carried over).

## Account page

Balance, product, rate, minimum balance warning, a better-rate hint (same type and currency),
and **Quick add**: Deposit, Withdraw (to a cash account first), Transfer, Pay and Interest.
Each one uses the same endpoints as the full Transactions forms, so funds, budget and
payment-method checks are identical.

## Account types

Every account has a type: Cash, Savings account, Current account, Fixed deposit, Credit card,
Mobile wallet or Other. It also records the bank it is held at. Choose both on **Add Account**,
or later on the account's page. Linking a bank product sets them from the product (a savings
product makes it a savings account at that bank). A product must match the account type, and
cash accounts have no bank.

The migration guesses the type of existing accounts from their bank product or default payment
method: Cash, Credit card or Wallet, otherwise **Other**. Set the real type on each account's
page; deposits and withdrawals are only recognised once the types are right.

## Bank charges

Providers can also send each product's charges. Add a `fees` block to the provider in
`app/Config/BankRates.php`:

```php
'fees' => [
    'path'   => 'charges',                  // list of charges inside one product
    'fields' => [
        'type'         => 'transaction',    // withdrawal | deposit | transfer (through typeMap)
        'channel'      => 'channel',        // through channelMap
        'amount'       => 'flat',           // fixed charge
        'percent'      => 'percent',        // % of the amount
        'min'          => 'min', 'max' => 'max',   // limits for % charges
        'freePerMonth' => 'freePerMonth',   // first N a month are free
        'note'         => 'note',
    ],
    'typeMap'    => ['withdrawal' => 'WITHDRAWAL', 'deposit' => 'DEPOSIT', 'transfer' => 'TRANSFER'],
    'channelMap' => ['own_atm' => 'OWN_ATM', 'other_atm' => 'OTHER_ATM', 'counter' => 'COUNTER',
                     'cdm' => 'CDM', 'online_own' => 'ONLINE_OWN', 'online_other' => 'ONLINE_OTHER'],
],
```

Each sync replaces a product's charges with what the feed sends. Charges with an unknown
type or channel, a negative amount or a percentage over 100 are skipped.

The bundled sample feed uses Rs 30 for a withdrawal at another bank's ATM, the figure
LankaPay publishes for its shared ATM network. Its other charges are illustrative. Check each
bank's current tariff before relying on them.

## Internal transactions (Dashboard / Transactions -> Internal)

The two account types decide what kind of movement it is:

| From -> to | Kind | Channels | Who pays the charge |
|------------|------|----------|---------------------|
| Cash -> savings / current / FD | Cash deposit | Bank counter, cash deposit machine | the bank account |
| Savings / current / FD -> cash | Cash withdrawal | Own bank ATM, other bank ATM (LankaPay), bank counter | the bank account |
| Bank account -> bank account | Bank transfer | Online same bank, online other bank (CEFTS), counter | the sending account |
| Anything else (e.g. paying a credit card) | Transfer | none | no charge |

* The charge comes from the bank account's product for that channel. "First N a month free"
  counts that account's earlier transactions on the same channel in the same month.
* The form pre-fills the charge, and you can change it to what the bank actually took.
  If you don't change it, the server calculates the charge when saving.
* The transfer and its charge are saved together, or not at all. The charge is a separate
  payment in the **fee** category, linked to the transfer and recorded against the chosen
  budget (it never asks for a budget override).
* For withdrawals, the funds check covers the amount plus the charge, with a clear message
  when only the charge doesn't fit.
* Taking money out of a fixed deposit shows a warning about losing interest.
* Accounts without a bank product get no charge, and a hint to link one.

## Endpoints (POST, JSON, session auth)

| Path | Params |
|------|--------|
| `/js-request/bank-rates/sync` | none |
| `/js-request/account/bank-product` | `account_id, product_id ('' to unlink), account_type?, bank_name?, auto_interest, confirm_reset` |
| `/js-request/account/transfer/quote` | `from_account, to_account, amount, channel?, payment_date?` -> kind, channels, charge |
| `/js-request/account/transferred` | `from_account, to_account, amount, budget, channel?, fee? ('' = calculated), payment_method?, description?, reference?, payment_date?` |
| `/js-request/account/create` | ... plus `account_type, bank_name?, bank_product_id?, auto_interest?` |
| `/js-request/account/interest/quote` | `account_id, through (month_end / today)` |
| `/js-request/account/interest/post` | `account_id, through, amount?, payment_method?` |

Tests: `vendor/bin/phpunit tests/unit/BankRateSyncTest.php` (rate and charge mapping, charge calculation).
