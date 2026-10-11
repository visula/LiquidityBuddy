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
#   scripts/setup-local-db.sh --docker --port 3307 --fresh --group-orphans
#                                                  # no MariaDB installed? run it in Docker
#
# Options: --host H --port P --user U --password P --db NAME --no-backup --yes
#          --docker [NAME]  use (or create) MariaDB container NAME (default
#                           liquiditybuddy-db) published on --port; needs no
#                           mysql/mariadb client on this machine
# Connection defaults come from .env (database.default.*), else localhost/root/''.
# Requires MariaDB 10.3+ (the XAMPP default); the migration uses MariaDB-only
# "IF NOT EXISTS" column/index syntax.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUMP="$ROOT/DBBackup/Liquidity_Buddy.sql"
MIGRATIONS_DIR="$ROOT/DBBackup/Migrations"
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
FRESH=0; BACKUP=1; GROUP_ORPHANS=0; ASSUME_YES=0; DOCKER=""

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
        --docker)
            if [[ $# -gt 1 && "$2" != -* ]]; then DOCKER="$2"; shift; else DOCKER="liquiditybuddy-db"; fi ;;
        -h|--help) sed -n '2,23p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 1 ;;
    esac
    shift
done

if [[ ! "$DB" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Database name may only contain letters, digits and _ (got '$DB')." >&2; exit 1
fi

# Password goes through the environment, never the command line (ps would show it).
export MYSQL_PWD="$PASSWORD"

if [[ -n "$DOCKER" ]]; then
    command -v docker >/dev/null || { echo "docker not found on PATH." >&2; exit 1; }
    [[ "$DOCKER" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo "Invalid container name '$DOCKER'." >&2; exit 1; }

    if ! docker container inspect "$DOCKER" >/dev/null 2>&1; then
        if netstat -an 2>/dev/null | grep -Eq "[:.]$PORT[[:space:]].*LISTEN"; then
            echo "Port $PORT is already in use. Pick a free one, e.g. --port 3307, and set" >&2
            echo "database.default.port to the same value in .env." >&2
            exit 1
        fi
        echo "==> Creating MariaDB 10.11 container '$DOCKER' on port $PORT (data in volume '$DOCKER-data')"
        if [[ -n "$PASSWORD" ]]; then ROOTPW=(-e MARIADB_ROOT_PASSWORD); else ROOTPW=(-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1); fi
        MARIADB_ROOT_PASSWORD="$PASSWORD" docker run -d --name "$DOCKER" "${ROOTPW[@]}"             -p "$PORT:3306" -v "$DOCKER-data:/var/lib/mysql" --restart unless-stopped mariadb:10.11 >/dev/null
    elif [[ "$(docker container inspect -f '{{.State.Running}}' "$DOCKER")" != "true" ]]; then
        echo "==> Starting container '$DOCKER'"
        docker start "$DOCKER" >/dev/null
    fi

    printf "==> Waiting for MariaDB in '%s'" "$DOCKER"
    for _ in $(seq 1 60); do
        docker exec -e MYSQL_PWD "$DOCKER" mariadb -h 127.0.0.1 -P 3306 -u "$USER_NAME" -e "SELECT 1" >/dev/null 2>&1 && break
        printf "."; sleep 1
    done
    echo
    # The client runs inside the container, so it connects to the container's own server.
    CLIENT=(docker exec -i -e MYSQL_PWD "$DOCKER" mariadb)
    DUMPER=(docker exec -e MYSQL_PWD "$DOCKER" mariadb-dump)
    CONN=(-h 127.0.0.1 -P 3306 -u "$USER_NAME")
else
    client="$(command -v mariadb || command -v mysql || true)"
    dumper="$(command -v mariadb-dump || command -v mysqldump || true)"
    if [[ -z "$client" ]]; then
        echo "mysql/mariadb client not found on PATH." >&2
        echo "No MariaDB installed? Run it in Docker instead:  $0 --docker --port 3307 ${*:-}" >&2
        exit 1
    fi
    CLIENT=("$client"); DUMPER=(); [[ -n "$dumper" ]] && DUMPER=("$dumper")
    CONN=(-h "$HOST" -P "$PORT" -u "$USER_NAME")
fi

sql() { "${CLIENT[@]}" "${CONN[@]}" "$@"; }
dump() { "${DUMPER[@]}" "${CONN[@]}" "$@"; }
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
    if [[ "$DB_EXISTS" == 1 && "$BACKUP" == 1 && ${#DUMPER[@]} -gt 0 ]]; then
        mkdir -p "$BACKUP_DIR"
        file="$BACKUP_DIR/${DB}_before_fresh_$(date +%Y%m%d_%H%M%S).sql"
        echo "==> Backing up current database to ${file#$ROOT/}"
        dump --routines --triggers --databases "$DB" > "$file"
    fi
    echo "==> Recreating '$DB' from DBBackup/Liquidity_Buddy.sql"
    sql -e "DROP DATABASE IF EXISTS \`$DB\`"
    retarget "$DUMP" | sql
else
    if [[ "$DB_EXISTS" != 1 ]]; then
        echo "Database '$DB' does not exist. Run again with --fresh to create it from the dump." >&2; exit 1
    fi
    if [[ "$BACKUP" == 1 ]]; then
        [[ ${#DUMPER[@]} -gt 0 ]] || { echo "mysqldump/mariadb-dump not found; install it or pass --no-backup." >&2; exit 1; }
        mkdir -p "$BACKUP_DIR"
        file="$BACKUP_DIR/${DB}_before_payment_methods_$(date +%Y%m%d_%H%M%S).sql"
        echo "==> Backing up '$DB' to ${file#$ROOT/}"
        dump --routines --triggers --databases "$DB" > "$file"
    fi
fi

# Every migration, oldest first (file names start with the date). All are idempotent.
for migration in "$MIGRATIONS_DIR"/*.sql; do
    echo "==> Applying DBBackup/Migrations/$(basename "$migration")"
    retarget "$migration" | sql
done

if [[ "$GROUP_ORPHANS" == 1 ]]; then
    echo "==> Assigning ungrouped accounts to each user's first account group (demo data)"
    retarget "$GROUP_ORPHANS_SQL" | sql
fi

echo "==> Checking the result"
sql -t "$DB" -e "
SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
      AND table_name IN ('paymentmethod','payment','duepaid','recurring'))  AS new_tables_of_4,
  (SELECT COUNT(*) FROM paymentmethod WHERE status = 'A')                    AS active_payment_methods,
  (SELECT COUNT(*) FROM account a WHERE a.status = 'A' AND NOT EXISTS
      (SELECT 1 FROM paymentmethod pm WHERE pm.accountsessionid = a.sessionid)) AS accounts_without_method,
  (SELECT COUNT(*) FROM account a WHERE a.status = 'A' AND a.accountgroupid IS NULL) AS hidden_ungrouped_accounts;"

cat <<EOF

Done. Database: $HOST:$PORT/$DB$( [[ -n "$DOCKER" ]] && echo " (Docker container '$DOCKER')" )
$( [[ "$PORT" != "$(env_value database.default.port 3306)" ]] && echo "  -> set database.default.port = $PORT in .env so the app uses this database" )
Next:
  composer install          # first time only
  php spark serve           # then open http://localhost:8080
See LOCAL_SETUP.md for the walkthrough of the payment features.
EOF
