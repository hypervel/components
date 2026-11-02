#!/usr/bin/env bash

# Usage:
# ./bin/split.sh [name ...] [--ref commit] [--check] [--tag version] [--force-tag]
#
# Example:
# ./bin/split.sh support

set -e
set -x

CURRENT_BRANCH=$(git symbolic-ref --short HEAD)
SOURCE_COMMIT=HEAD
CHECK_ONLY=false
VERSION=
TAG_PUSH_OPTIONS=()
REPOSITORIES=()
REPOSITORY_URLS=()
UNAVAILABLE_REPOSITORIES=()
SPLIT_COMMITS=()

while (( $# > 0 )); do
    case "$1" in
        --check)
            CHECK_ONLY=true
            shift
            ;;
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

if [[ -n "$VERSION" ]]; then
    git check-ref-format "refs/tags/$VERSION"
fi

if (( ${#REPOSITORIES[@]} == 0 )); then
    PACKAGE_NAMES=$(git ls-tree --name-only "$SOURCE_COMMIT:src")
    mapfile -t REPOSITORIES <<< "$PACKAGE_NAMES"
fi

for REPOSITORY in "${REPOSITORIES[@]}"; do
    git cat-file tree "$SOURCE_COMMIT:src/$REPOSITORY" > /dev/null
    REPOSITORY_URL="git@github.com:hypervel/$REPOSITORY.git"
    REPOSITORY_URLS+=("$REPOSITORY_URL")
    if ! git ls-remote "$REPOSITORY_URL" > /dev/null; then
        UNAVAILABLE_REPOSITORIES+=("hypervel/$REPOSITORY")
    fi
done

if (( ${#UNAVAILABLE_REPOSITORIES[@]} > 0 )); then
    printf 'Missing or inaccessible split repositories:\n' >&2
    printf '  %s\n' "${UNAVAILABLE_REPOSITORIES[@]}" >&2
    exit 1
fi

if [[ "$CHECK_ONLY" == true ]]; then
    printf 'All %s split repositories are accessible.\n' "${#REPOSITORIES[@]}"
    exit 0
fi

# Splitsh requires a named reference; keep it fixed for this source commit.
SOURCE_REFERENCE="refs/hypervel-split/$SOURCE_COMMIT"
git update-ref "$SOURCE_REFERENCE" "$SOURCE_COMMIT"
trap 'git update-ref -d "$SOURCE_REFERENCE"' EXIT

for INDEX in "${!REPOSITORIES[@]}"; do
    REPOSITORY=${REPOSITORIES[$INDEX]}
    SPLIT_COMMIT=$(./bin/splitsh-lite --prefix="src/$REPOSITORY" --origin="$SOURCE_REFERENCE")
    SPLIT_COMMITS+=("$SPLIT_COMMIT")
    git push "${REPOSITORY_URLS[$INDEX]}" "$SPLIT_COMMIT:refs/heads/$CURRENT_BRANCH" --force
done

# Tag the recorded split commits, even if a destination branch moves afterwards.
if [[ -n "$VERSION" ]]; then
    for INDEX in "${!REPOSITORIES[@]}"; do
        git push "${TAG_PUSH_OPTIONS[@]}" "${REPOSITORY_URLS[$INDEX]}" "${SPLIT_COMMITS[$INDEX]}:refs/tags/$VERSION"
    done
fi
