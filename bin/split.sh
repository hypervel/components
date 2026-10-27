#!/usr/bin/env bash

# Usage:
# ./bin/split.sh [name ...] [--ref commit] [--tag version] [--force-tag]
#
# Example:
# ./bin/split.sh support

set -e
set -x

CURRENT_BRANCH=$(git symbolic-ref --short HEAD)
SOURCE_COMMIT=HEAD
VERSION=
TAG_PUSH_OPTIONS=()
REPOSITORIES=()
SPLIT_COMMITS=()

while (( $# > 0 )); do
    case "$1" in
        --ref)
            SOURCE_COMMIT=${2:?A source commit is required.}
            shift 2
            ;;
        --tag)
            VERSION=${2:?A release tag is required.}
            shift 2
            ;;
        --force-tag)
            TAG_PUSH_OPTIONS=(--force)
            shift
            ;;
        --*)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
        *)
            REPOSITORIES+=("$1")
            shift
            ;;
    esac
done

SOURCE_COMMIT=$(git rev-parse --verify "$SOURCE_COMMIT^{commit}")

# Splitsh requires a named reference; keep it fixed for this source commit.
SOURCE_REFERENCE="refs/hypervel-split/$SOURCE_COMMIT"
git update-ref "$SOURCE_REFERENCE" "$SOURCE_COMMIT"
trap 'git update-ref -d "$SOURCE_REFERENCE"' EXIT

if [[ -n "$VERSION" ]]; then
    git check-ref-format "refs/tags/$VERSION"
fi

if (( ${#REPOSITORIES[@]} == 0 )); then
    PACKAGE_NAMES=$(git ls-tree --name-only "$SOURCE_COMMIT:src")
    mapfile -t REPOSITORIES <<< "$PACKAGE_NAMES"
fi

for REPOSITORY in "${REPOSITORIES[@]}"; do
    git cat-file tree "$SOURCE_COMMIT:src/$REPOSITORY" > /dev/null
    SPLIT_COMMIT=$(./bin/splitsh-lite --prefix="src/$REPOSITORY" --origin="$SOURCE_REFERENCE")
    SPLIT_COMMITS+=("$SPLIT_COMMIT")
    git push "git@github.com:hypervel/$REPOSITORY.git" "$SPLIT_COMMIT:refs/heads/$CURRENT_BRANCH" --force
done

# Tag the recorded split commits, even if a destination branch moves afterwards.
if [[ -n "$VERSION" ]]; then
    for INDEX in "${!REPOSITORIES[@]}"; do
        git push "${TAG_PUSH_OPTIONS[@]}" "git@github.com:hypervel/${REPOSITORIES[$INDEX]}.git" "${SPLIT_COMMITS[$INDEX]}:refs/tags/$VERSION"
    done
fi
