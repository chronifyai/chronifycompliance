#!/usr/bin/env bash
# Rebuilds amd/build with Moodle's Grunt, the way CI checks it.
#
# Usage: dev/build-js.sh [moodle dir]
# Needs `npm ci` in the Moodle dir once. Grunt must run on a real copy inside Moodle
# (not a symlink), otherwise ESLint does not find Moodle's configuration.

set -euo pipefail

MOODLE="${1:-$HOME/moodle-dev/moodle405}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
NAME="$(grep -oP "component\s*=\s*'local_\K[a-z_]+" "$PLUGIN_DIR/version.php")"
TARGET="$MOODLE/local/$NAME"

cd "$MOODLE"
[ -d node_modules ] || npm ci --no-audit --no-fund --loglevel=error
rm -rf "$TARGET"
cp -r "$PLUGIN_DIR" "$TARGET"
node_modules/.bin/grunt amd --root="local/$NAME"
cp "$TARGET"/amd/build/* "$PLUGIN_DIR/amd/build/"
rm -rf "$TARGET"
ln -sfn "$PLUGIN_DIR" "$TARGET"
echo "amd/build updated."
