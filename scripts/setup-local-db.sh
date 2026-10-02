#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# LiquidityBuddy - local database setup (Linux / macOS / Git Bash on Windows)
#
#   scripts/setup-local-db.sh                      # back up + migrate an existing DB
#   scripts/setup-local-db.sh --fresh              # recreate DB from the dump, then migrate
#   scripts/setup-local-db.sh --fresh --group-orphans
#                                                  # ...and make the dump's ungrouped
#                                                  #    accounts visible (demo data only)
#
# Options: --host H --port P --user U --password P --db NAME --no-backup --yes
# Connection defaults come from .env (database.default.*), else localhost/root/''.
# Requires MariaDB 10.3+ (the XAMPP default); the migration uses MariaDB-only
# "IF NOT EXISTS" column/index syntax.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUMP="$ROOT/DBBackup/Liquidity_Buddy.sql"
MIGRATION="$ROOT/DBBackup/Migrations/2026_10_02_account_payment_methods.sql"
GROUP_ORPHANS_SQL="$ROOT/scripts/sql/dev_group_orphan_accounts.sql"
BACKUP_DIR="$ROOT/writable/backups"

env_value() {  # env_value <key> <default> : read a key from .env
    local value=""
    if [[ -f "$ROOT/.env" ]]; then
        value="$(grep -E "^[[:space:]]*$1[[:space:]]*=" "$ROOT/.env" | tail -1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e "s/^['\"]//" -e "s/['\"]$//")"
    fi
    echo "${value:-$2}"
}

HOST="$(env_value database.default.hostname localhost)"
USER_NAME="$(env_value database.default.username root)"
PASSWORD="$(env_value database.default.password '')"
DB="$(env_value database.default.database liquiditybuddy)"
PORT="$(env_value database.default.port 3306)"
FRESH=0; BACKUP=1; GROUP_ORPHANS=0; ASSUME_YES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --fresh) FRESH=1 ;;
        --no-backup) BACKUP=0 ;;
        --group-orphans) GROUP_ORPHANS=1 ;;
        --yes|-y) ASSUME_YES=1 ;;
        --host) HOST="$2"; shift ;;
        --port) PORT="$2"; shift ;;
        --user) USER_NAME="$2"; shift ;;
        --password) PASSWORD="$2"; shift ;;
        --db) DB="$2"; shift ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 1 ;;
    esac
    shift
done

if [[ ! "$DB" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Database name may only contain letters, digits and _ (got '$DB')." >&2; exit 1
fi

CLIENT="$(command -v mariadb || command -v mysql || true)"
DUMPER="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$CLIENT" ]] || { echo "mysql/mariadb client not found on PATH." >&2; exit 1; }

# Password goes through the environment, never the command line (ps would show it).
export MYSQL_PWD="$PASSWORD"
CONN=(-h "$HOST" -P "$PORT" -u "$USER_NAME")
sql() { "$CLIENT" "${CONN[@]}" "$@"; }
# The dump and migration name the schema `liquiditybuddy`; retarget when --db differs.
retarget() { sed "s/\`liquiditybuddy\`/\`$DB\`/g" "$1"; }

echo "==> Connecting to $USER_NAME@$HOST:$PORT (database: $DB)"
sql -N -e "SELECT CONCAT('    server: ', VERSION())" || { echo "Cannot connect. Check .env or pass --host/--user/--password." >&2; exit 1; }
sql -N -e "SELECT VERSION()" | grep -qi mariadb || echo "    WARNING: this is not MariaDB - the migration needs MariaDB 10.3+."

DB_EXISTS="$(sql -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$DB'")"

if [[ "$FRESH" == 1 ]]; then
    if [[ "$DB_EXISTS" == 1 && "$ASSUME_YES" != 1 ]]; then
        read -r -p "    --fresh will DROP database '$DB' and reload the demo dump. Type the database name to continue: " answer
        [[ "$answer" == "$DB" ]] || { echo "Aborted."; exit 1; }
    fi
    if [[ "$DB_EXISTS" == 1 && "$BACKUP" == 1 && -n "$DUMPER" ]]; then
        mkdir -p "$BACKUP_DIR"
        file="$BACKUP_DIR/${DB}_before_fresh_$(date +%Y%m%d_%H%M%S).sql"
        echo "==> Backing up current database to ${file#$ROOT/}"
        "$DUMPER" "${CONN[@]}" --routines --triggers --databases "$DB" > "$file"
    fi
    echo "==> Recreating '$DB' from DBBackup/Liquidity_Buddy.sql"
    sql -e "DROP DATABASE IF EXISTS \`$DB\`"
    retarget "$DUMP" | sql
else
    if [[ "$DB_EXISTS" != 1 ]]; then
        echo "Database '$DB' does not exist. Run again with --fresh to create it from the dump." >&2; exit 1
    fi
    if [[ "$BACKUP" == 1 ]]; then
        [[ -n "$DUMPER" ]] || { echo "mysqldump/mariadb-dump not found; install it or pass --no-backup." >&2; exit 1; }
        mkdir -p "$BACKUP_DIR"
        file="$BACKUP_DIR/${DB}_before_payment_methods_$(date +%Y%m%d_%H%M%S).sql"
        echo "==> Backing up '$DB' to ${file#$ROOT/}"
        "$DUMPER" "${CONN[@]}" --routines --triggers --databases "$DB" > "$file"
    fi
fi

echo "==> Applying DBBackup/Migrations/2026_10_02_account_payment_methods.sql"
retarget "$MIGRATION" | sql

if [[ "$GROUP_ORPHANS" == 1 ]]; then
    echo "==> Assigning ungrouped accounts to each user's first account group (demo data)"
    retarget "$GROUP_ORPHANS_SQL" | sql
fi

echo "==> Checking the result"
sql -t "$DB" -e "
SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
      AND table_name IN ('paymentmethod','payment','duepaid'))              AS new_tables_of_3,
  (SELECT COUNT(*) FROM paymentmethod WHERE status = 'A')                    AS active_payment_methods,
  (SELECT COUNT(*) FROM account a WHERE a.status = 'A' AND NOT EXISTS
      (SELECT 1 FROM paymentmethod pm WHERE pm.accountsessionid = a.sessionid)) AS accounts_without_method,
  (SELECT COUNT(*) FROM account a WHERE a.status = 'A' AND a.accountgroupid IS NULL) AS hidden_ungrouped_accounts;"

cat <<EOF

Done. Next:
  composer install          # first time only
  php spark serve           # then open http://localhost:8080
See LOCAL_SETUP.md for the walkthrough of the payment features.
EOF
