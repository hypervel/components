#!/usr/bin/env bash

# Run database integration tests for one Hypervel database connection.
#
# Usage:
#   ./bin/run-database-tests.sh mysql
#   ./bin/run-database-tests.sh pgsql -p 3 --filter=EloquentPrunableTest
#
# The shared suite covers core database tests outside the driver directories
# and package Database/*Test.php files. The connection's suite adds tests under
# its matching driver directories.

set -euo pipefail

case "${1:-}" in
    mysql|mariadb|pgsql|sqlite) export DB_CONNECTION="$1" ;;
    *)
        echo "Usage: $0 <mysql|mariadb|pgsql|sqlite> [ParaTest options]" >&2
        exit 2
        ;;
esac
shift

cd "$(dirname "${BASH_SOURCE[0]}")/.."

exec vendor/bin/paratest --testsuite="database,$DB_CONNECTION" "$@"
