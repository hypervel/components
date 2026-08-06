#!/usr/bin/env bash

# Run database integration tests for one Hypervel database connection.
#
# Usage:
#   ./bin/run-database-tests.sh mysql
#   ./bin/run-database-tests.sh pgsql -p 3 --filter=EloquentPrunableTest
#
# Supported connection names are mysql, mariadb, pgsql, and sqlite. The runner
# sets DB_CONNECTION from this argument. Every run executes the shared tests in
# tests/Integration/Database first. It then discovers package-specific tests
# using this exact directory convention:
#
#   tests/Integration/<Package>/Database/<DriverDirectory>
#
# DriverDirectory is MySql, MariaDb, Postgres, or Sqlite, as mapped below. The
# package name must occupy exactly one directory segment below Integration;
# deeper directory structures are not discovered. For example, PostgreSQL
# tests for a Permission package belong in:
#
#   tests/Integration/Permission/Database/Postgres
#
# Arguments after the connection name are forwarded to every ParaTest command.
# Runs with extra options tolerate an empty individual suite because selection
# options may match only one directory; runs without extra options remain strict.

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <mysql|mariadb|pgsql|sqlite> [ParaTest options]" >&2
    exit 2
fi

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_directory="$(dirname "$script_directory")"
connection=$1
shift

case "$connection" in
    mysql) driver_directory=MySql ;;
    mariadb) driver_directory=MariaDb ;;
    pgsql) driver_directory=Postgres ;;
    sqlite) driver_directory=Sqlite ;;
    *)
        echo "Unsupported database connection: $connection" >&2
        echo "Usage: $0 <mysql|mariadb|pgsql|sqlite> [ParaTest options]" >&2
        exit 2
        ;;
esac

export DB_CONNECTION="$connection"

paratest_options=("$@")

# CI passes no extra options, so an unexpectedly empty suite still fails there.
# Ad-hoc selection options can legitimately match only one independently invoked suite.
if [[ $# -gt 0 ]]; then
    paratest_options+=(--do-not-fail-on-empty-test-suite)
fi

cd "$project_directory"

printf 'Running %s\n' 'tests/Integration/Database'
vendor/bin/paratest "${paratest_options[@]}" tests/Integration/Database

shopt -s nullglob
candidate_directories=(tests/Integration/*/Database/"$driver_directory")
test_directories=()

for test_directory in "${candidate_directories[@]}"; do
    if [[ -d "$test_directory" ]]; then
        test_directories+=("$test_directory")
    fi
done

if [[ ${#test_directories[@]} -eq 0 ]]; then
    printf 'No package-specific %s database test directories found.\n' "$driver_directory"
fi

for test_directory in "${test_directories[@]}"; do
    printf 'Running %s\n' "$test_directory"
    vendor/bin/paratest "${paratest_options[@]}" "$test_directory"
done
