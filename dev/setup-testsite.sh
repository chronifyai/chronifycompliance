#!/usr/bin/env bash
# Sets up a local Moodle test site with PostgreSQL for developing this plugin.
#
# Usage: dev/setup-testsite.sh [branch] [workdir]
#   branch   Moodle branch, default MOODLE_405_STABLE
#   workdir  where Moodle and its data live, default ~/moodle-dev
#
# Result: a Moodle site with this plugin installed (admin / Admin123!),
# PHPUnit initialised, ready for `dev/run-tests.sh`.
# This script is development tooling and is not part of the plugin ZIP.

set -euo pipefail

BRANCH="${1:-MOODLE_405_STABLE}"
WORK="${2:-$HOME/moodle-dev}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPONENT="$(grep -oP "component\s*=\s*'\K[a-z_]+" "$PLUGIN_DIR/version.php")"
TYPE="${COMPONENT%%_*}"
NAME="${COMPONENT#*_}"
TAG="$(echo "$BRANCH" | grep -oP '\d+')"
MOODLE="$WORK/moodle$TAG"
DATA="$WORK/data$TAG"
DB="moodle$TAG"

echo "Plugin $COMPONENT, Moodle $BRANCH in $MOODLE"
mkdir -p "$WORK"

# 0. PHP settings Moodle requires.
INI_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
if [ -n "$INI_DIR" ] && [ ! -f "$INI_DIR/99-moodle.ini" ]; then
    printf 'max_input_vars = 5000\nmemory_limit = 1G\n' | sudo tee "$INI_DIR/99-moodle.ini" >/dev/null
fi

# PHPUnit needs the en_AU locale.
locale -a | grep -qi en_AU.utf8 || sudo locale-gen en_AU.UTF-8 >/dev/null

# 1. PostgreSQL.
sudo service postgresql start >/dev/null
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='moodle'" | grep -q 1 ||
    sudo -u postgres psql -c "CREATE USER moodle WITH PASSWORD 'moodle' SUPERUSER;" >/dev/null
for d in "$DB" "${DB}_test"; do
    sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='$d'" | grep -q 1 ||
        sudo -u postgres createdb -O moodle -E UTF8 "$d"
done

# 2. Moodle code.
if [ ! -d "$MOODLE/.git" ]; then
    git clone -q --depth 1 -b "$BRANCH" https://github.com/moodle/moodle.git "$MOODLE"
fi

# Moodle 5.1+ keeps plugins under public/.
ROOT="$MOODLE"
[ -d "$MOODLE/public" ] && ROOT="$MOODLE/public"
case "$TYPE" in
    local) TARGET="$ROOT/local/$NAME" ;;
    tool) TARGET="$ROOT/admin/tool/$NAME" ;;
    *) echo "Unsupported plugin type $TYPE"; exit 1 ;;
esac
ln -sfn "$PLUGIN_DIR" "$TARGET"

# 3. config.php.
mkdir -p "$DATA/site" "$DATA/phpunit"
cat > "$MOODLE/config.php" <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype = 'pgsql';
\$CFG->dblibrary = 'native';
\$CFG->dbhost = 'localhost';
\$CFG->dbname = '$DB';
\$CFG->dbuser = 'moodle';
\$CFG->dbpass = 'moodle';
\$CFG->prefix = 'mdl_';
\$CFG->dboptions = ['dbport' => 5432];
\$CFG->wwwroot = 'http://localhost:8000';
\$CFG->dataroot = '$DATA/site';
\$CFG->admin = 'admin';
\$CFG->directorypermissions = 02777;
\$CFG->phpunit_prefix = 'phpu_';
\$CFG->phpunit_dataroot = '$DATA/phpunit';
\$CFG->phpunit_dbname = '${DB}_test';
\$CFG->debug = E_ALL;
\$CFG->debugdisplay = 1;
require_once(__DIR__ . '/lib/setup.php');
PHP

# 4. Install the site if needed.
cd "$MOODLE"
if ! php admin/cli/cfg.php --name=version >/dev/null 2>&1; then
    php admin/cli/install_database.php --agree-license --fullname="Chronify dev" \
        --shortname=dev --adminpass='Admin123!' --adminemail=admin@example.com | tail -3
else
    php admin/cli/upgrade.php --non-interactive | tail -3
fi

# 5. PHPUnit.
[ -d vendor ] || composer install --quiet --no-interaction
php admin/tool/phpunit/cli/init.php >/dev/null 2>&1 || php admin/tool/phpunit/cli/init.php

echo "Done. Moodle: $MOODLE  Plugin linked at: $TARGET"
