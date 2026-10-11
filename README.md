# LiquidityBuddy

Personal finance app (CodeIgniter 4 + MariaDB): accounts, budgets, incomes, expenses,
purchases with installment plans, and account-wise payment methods.

**Run it locally:** see [LOCAL_SETUP.md](LOCAL_SETUP.md) (setup scripts, demo login and a
feature walkthrough). Payment design and endpoints: [docs/PAYMENT_METHODS.md](docs/PAYMENT_METHODS.md).
Bank rates, account types and interest: [docs/BANK_RATES.md](docs/BANK_RATES.md).


## Requirements

- PHP 8.2 or newer (tested on 8.5 and 8.3) with `intl`, `mbstring`, `mysqli`, `curl` and `openssl`
- MariaDB 10.3+ (or `scripts/setup-local-db.sh --docker` to run it in Docker)
- Composer 2, which installs [CodeIgniter 4.7](https://codeigniter.com) into `vendor/`

## Layout

| Path | What it holds |
|------|---------------|
| `app/` | Controllers, views, models and config (`app/Config/Routes.php` lists every URL) |
| `public/` | Web root: `index.php`, CSS and JS |
| `DBBackup/` | Database dump and migrations |
| `scripts/` | Local database setup |
| `writable/` | Logs, sessions, cache (not committed) |
