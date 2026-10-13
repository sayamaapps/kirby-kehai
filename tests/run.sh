#!/bin/sh
# Runs all checks of the package: `composer test` or `sh tests/run.sh`.
# Every PHP file is a standalone script (exit code 0 = pass), not PHPUnit —
# so they also run on a hoster PHP without a test framework. The bubble
# script is tested with Node's built-in test runner when Node is there.
set -eu
cd "$(dirname "$0")/.."
echo "PHP $(php -r 'echo PHP_VERSION;')"
for f in tests/*Test.php; do
    [ -e "$f" ] || continue
    echo "→ $f"
    php "$f"
done
if command -v node > /dev/null 2>&1; then
    echo "→ tests/*.test.mjs (Node $(node --version))"
    node --test --test-reporter=dot tests/*.test.mjs
else
    echo "→ tests/*.test.mjs skipped (no node)"
fi
