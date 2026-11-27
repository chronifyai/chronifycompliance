#!/usr/bin/env bash
# Runs this plugin's PHPUnit tests on the local test site.
#
# Usage: dev/run-tests.sh [moodle dir] [extra phpunit args]
# Example: dev/run-tests.sh ~/moodle-dev/moodle405 --filter=score

set -euo pipefail

MOODLE="${1:-$HOME/moodle-dev/moodle405}"
shift || true
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPONENT="$(grep -oP "component\s*=\s*'\K[a-z_]+" "$PLUGIN_DIR/version.php")"

sudo service postgresql start >/dev/null
cd "$MOODLE"
php admin/tool/phpunit/cli/util.php --buildcomponentconfigs >/dev/null
# Reinitialise when the plugin version changed.
php admin/tool/phpunit/cli/init.php >/dev/null 2>&1 || php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite "${COMPONENT}_testsuite" "$@"
