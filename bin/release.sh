#!/usr/bin/env bash

set -e

# Usage:
#  ./bin/release.sh v[version]
#
# Example:
# ./bin/release.sh v1.0.0
# ./bin/release.sh v1.0.0 -f

# Make sure the release tag is provided.
if (( "$#" < 1 ))
then
    echo "Tag has to be provided."
    exit 1
fi

# Initialize variables
NOW=$(date +%s)
RELEASE_BRANCH="0.3"
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
RELEASE_COMMIT=$(git rev-parse HEAD)
VERSION=$1
SPLIT_OPTIONS=()
TAG_OPTIONS=()

# Check if -f flag is provided as second argument
if [[ "${2:-}" == "-f" ]]; then
    SPLIT_OPTIONS=(--force-tag)
    TAG_OPTIONS=(--force)
fi

# Make sure current branch and release branch match.
if [[ "$RELEASE_BRANCH" != "$CURRENT_BRANCH" ]]
then
    echo "Release branch ($RELEASE_BRANCH) does not match the current active branch ($CURRENT_BRANCH)."

    exit 1
fi

# Make sure the working directory is clear.
if [[ ! -z "$(git status --porcelain)" ]]
then
    echo "Your working directory is dirty. Did you forget to commit your changes?"

    exit 1
fi

# Make sure latest changes are fetched first.
git fetch origin

# The release commit must be published; later commits do not change this release.
if ! git merge-base --is-ancestor "$RELEASE_COMMIT" "origin/$RELEASE_BRANCH"; then
    echo "The release commit is not on origin/$RELEASE_BRANCH. Did you forget to push before releasing?"

    exit 1
fi

# Always prepend with "v"
if [[ $VERSION != v*  ]]
then
    VERSION="v$VERSION"
fi

# Refresh every component and tag its exact split before publishing the framework tag.
bash ./bin/split.sh --ref "$RELEASE_COMMIT" --tag "$VERSION" "${SPLIT_OPTIONS[@]}"

git tag "${TAG_OPTIONS[@]}" "$VERSION" "$RELEASE_COMMIT"
git push "${TAG_OPTIONS[@]}" origin "refs/tags/$VERSION"

TIME=$(( $(date +%s) - NOW ))

printf 'Execution time: %s seconds\n' "$TIME"
