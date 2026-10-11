# Running LiquidityBuddy locally (payment methods branch)

This branch adds **payment methods for each account**: cash, debit card, credit card with a
limit, bank transfer and more. Every expense, purchase, income, internal transfer and due
settlement is recorded as a **payment** with its method, and that payment is linked to its
**transactions**. Dues can be paid **in full or in part**.

This guide gets the branch running on your machine in about 10 minutes. It then walks
through each feature so you can check it works.

---

## 1. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.2 or newer | Extensions: `intl`, `mbstring`, `mysqli`, `curl`, `openssl`. Tested on 8.5 and 8.3. The app runs on CodeIgniter 4.7, which Composer installs. |
| Composer | 2.x | |
| MariaDB | 10.3+ | XAMPP ships MariaDB, which works. **MySQL is not supported by the migration**: it uses MariaDB's `ADD COLUMN IF NOT EXISTS`. |
| Git | any | Git Bash on Windows also runs the `.sh` script. |

On Windows with XAMPP, start **MySQL** in the XAMPP Control Panel. Apache is optional,
see step 5.

## 2. Get the branch

```bash
git clone https://github.com/visula-kingdom360/LiquidityBuddy.git   # skip if you already have it
cd LiquidityBuddy
git fetch origin
git checkout feature/account-payment-methods
```

## 3. Install PHP dependencies

```bash
composer install
```

This restores packages that aren't committed, including `firebase/php-jwt`, which login
needs.

> `vendor/` isn't committed (it's in `.gitignore`), so `composer install` is required
> after every fresh clone. It also installs CodeIgniter itself, into
> `vendor/codeigniter4/framework`. Use `install`, not `composer update`: `update`
> moves packages past the versions in `composer.lock`, which everyone else uses.

## 4. Configure `.env`

The repo already has a `.env`. Check the database block matches your server:

```ini
CI_ENVIRONMENT = development

database.default.hostname = localhost
database.default.database = liquiditybuddy
database.default.username = root
database.default.password =
```

If you'll open the app at any URL other than `http://localhost:8080/`, also set
`app.baseURL`. The page scripts send their requests to this URL, so it must match what's
in your browser's address bar:

```ini
app.baseURL = 'http://localhost/LiquidityBuddy/public/'
```

## 5. Set up the database

Choose **one** of the options below.

### Option A: demo data (fresh database)

This recreates `liquiditybuddy` from `DBBackup/Liquidity_Buddy.sql` and applies the new
migration. It also moves the dump's ungrouped accounts into a group so they appear in the
UI. The script asks you to type the database name before it drops anything, and backs up
the old database first.

```bash
# Linux / macOS / Git Bash
scripts/setup-local-db.sh --fresh --group-orphans
```

```bat
:: Windows (XAMPP) - edit MYSQL_BIN / DB_PASS at the top of the file if needed
scripts\setup-local-db.bat fresh
```

### Option B: your existing database (keep your data)

This backs up the database to `writable/backups/` (git-ignored), then applies the
migration. The migration is **idempotent**, so running it twice is harmless.

```bash
scripts/setup-local-db.sh            # Linux / macOS / Git Bash
```

```bat
scripts\setup-local-db.bat           :: Windows (XAMPP)
```

### No MariaDB installed? Use Docker

If the script says `mysql/mariadb client not found on PATH`, or the only database server
you have is MySQL, add `--docker`. The script creates a MariaDB 10.11 container named
`liquiditybuddy-db` (data kept in the Docker volume `liquiditybuddy-db-data`) and uses the
client inside it, so nothing else needs installing. Pick a port that's free; 3306 is often
taken by another MySQL:

```bash
scripts/setup-local-db.sh --docker --port 3307 --fresh --group-orphans   # demo data
scripts/setup-local-db.sh --docker --port 3307                           # keep data, migrate
```

Then point the app at it in `.env`:

```ini
database.default.port = 3307
```

The container restarts with Docker. Stop it with `docker stop liquiditybuddy-db`.

### Option C: by hand (HeidiSQL / phpMyAdmin)

1. Back up your database.
2. Run every file in `DBBackup/Migrations/` against `liquiditybuddy`, oldest first.

**Check it worked.** The scripts finish with a table like the one below. `new_tables_of_4`
should be `4`, and every account should have at least one method:

```
| new_tables_of_4 | active_payment_methods | accounts_without_method | hidden_ungrouped_accounts |
|               4 |                     13 |                       0 |                         0 |
```

Script options (`.sh`): `--docker [NAME]`, `--host`, `--port`, `--user`, `--password`, `--db NAME`,
`--no-backup`, `--yes`. Defaults come from `.env`.

## 6. Start the app

```bash
php spark serve
```

Open **http://localhost:8080** and log in with an email or a username. With the demo
data from option A, use:

- Email `svisula@gmail.com` (or username `svisula` once the demo username is renamed,
  see below), password `Abc@12345`

Usernames are unique. The dump's demo user has the username `Visula`, so to sign up a
new user called `visula`, first rename it:
`UPDATE user SET username = 'svisula' WHERE email = 'svisula@gmail.com';`

**With XAMPP Apache instead:** put the project under `htdocs`, set `app.baseURL` (step 4)
to `http://localhost/LiquidityBuddy/public/`, and open that URL.

---

## 7. Try the payment features

Each step below lists what you should see. The numbers assume the demo data.

| # | Where | Do this | Expect |
|---|-------|---------|--------|
| 1 | **Payment Methods** (top menu) | On **Sampath: Credit Card**, click **Edit**. Set the name to `Sampath Visa`, the last 4 digits to `1234` and the credit limit to `50000`, then save. | The row shows a limit of 50,000.00 and available 50,000.00. |
| 2 | **Payment Methods** | Add a method for **Sampath: Day to Day**: type *Debit Card*, name `Sampath Debit`, and tick *Default*. | It becomes the account's default. The old default loses its badge. |
| 3 | **Transactions → External** | Pick the account **Sampath: Credit Card**. | *Pay with* lists only that account's card, with the hint "available 50,000.00 (incl. credit limit …)". |
| 4 | same | Travel: `Tuk`, from `Home` to `Office`, amount `60000`, then **Make Payment**. | Red error: *Insufficient funds … available 50,000.00*. Nothing is saved. |
| 5 | same | Change the amount to `1500` and pay again. | The page reloads. The card account balance is **-1,500.00**: it owes 1,500. |
| 6 | **Transactions → Purchases** | Description `Phone`, amount `9000`, account **Sampath: Day to Day**. Tick **Schedule this Payment** (Monthly, period 3) and **Make the initial payment**, then click **Purchase**. | 3,000.00 is taken from Day to Day. Three installments are due a month apart. |
| 7 | **Transactions → Dued** | Tick both **Phone** rows, choose **Partial payment**, enter `100`, then **Settle Selected**. | The oldest installment shows 100.00 paid (part paid). |
| 8 | **Dued** | Tick the remaining Phone rows, keep **Pay in full**, pay from **Sampath: Credit Card**. | All installments are settled and the plan disappears from Dued. |
| 9 | **Transactions → Internal** | Transfer `500` from **Sampath: Standby** to **Sampath: Credit Card** with the description `Credit card settlement`. | The card's debt goes down by 500. |
| 10 | **Reports → Payment History** | Click any row. | It expands to show its transactions (with the balance after each) and, for settlements, the dues it paid. The top table shows totals per account and payment method. |
| 11 | **Add New Account** | Name `HSBC Credit Card`, amount `0`, default method *Credit Card*, limit `300000`. | The account is created with that card as its default method. |

To see what was recorded in the database:

```sql
SELECT pay.date, pay.direction, pay.category, pay.settlementtype, pay.amount,
       a.name AS account, pm.name AS method, t.description, t.paymenttype, t.balanceafter
  FROM payment pay
  JOIN account a ON a.sessionid = pay.accountsessionid
  LEFT JOIN paymentmethod pm ON pm.sessionid = pay.paymentmethodsessionid
  JOIN transaction t ON t.paymentsessionid = pay.sessionid
 ORDER BY pay.id DESC, t.id;
```

To start the demo over, run option A again.

---

## 8. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `Class "Firebase\JWT\JWT" not found` on login | Run `composer install`. |
| `composer install` says `requires php ^8.2` | Your PHP is older than 8.2. Install a current PHP (8.5 works). |
| `composer install` says `requires PHP extension ext-intl` | In that PHP's `php.ini`, set `extension_dir = "ext"` and uncomment `extension=intl` (also `curl`, `mbstring`, `mysqli`, `openssl`). |
| Buttons do nothing, or requests fail / 404 | `app.baseURL` doesn't match the URL in the browser (step 4). |
| A URL that used to work now returns **404** | Auto-routing is **off** on this branch for security. Every page must be listed in `app/Config/Routes.php`. |
| Accounts missing from lists | The account has no account group. Assign one, or use `--group-orphans` on demo data. |
| Migration errors such as `near 'IF NOT EXISTS'` | You're on MySQL. Use MariaDB (XAMPP ships it), or `--docker` (step 5). |
| `mysql/mariadb client not found on PATH` | Add `--docker --port 3307` (step 5), or install XAMPP / MariaDB. |
| Payment dates are a day off | `app/Config/App.php` has `appTimezone = 'America/Chicago'`. For Sri Lanka, set `'Asia/Colombo'`. |
| Google login fails | `google/apiclient` isn't installed: `composer require google/apiclient`. This isn't related to this branch. |
| Need to undo the migration | Restore the backup the script made: `mysql -u root < writable/backups/<file>.sql` |

## 9. What changed

Bank rates, account types, interest and Quick add: [`docs/BANK_RATES.md`](docs/BANK_RATES.md)
(run `php spark bankrates:sync` once after the migrations).

See [`docs/PAYMENT_METHODS.md`](docs/PAYMENT_METHODS.md) for the data model, the rules
(ownership checks, credit limits, atomic payments, installments) and the JSON endpoints.

| Area | Files |
|------|-------|
| Database | `DBBackup/Migrations/2026_10_02_account_payment_methods.sql` |
| Payment logic | `app/Controllers/PaymentService.php`, `PaymentController.php`, `PurchaseService.php`, `PurchaseController.php` |
| Pages | `app/Views/Payment/*`, `app/Views/Transaction/dued_trans_module.php`, `payment_method_select.php` |
| Front end | `public/assets/js/default.js` (shared helpers), `public/assets/js/payment/methods.js`, `public/assets/js/transaction/*` |
| Local setup | `scripts/setup-local-db.sh`, `scripts/setup-local-db.bat`, `scripts/sql/dev_group_orphan_accounts.sql` |
