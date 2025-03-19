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
RELEASE_BRANCH="0.1"
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
BASEPATH=$(cd `dirname $0`; cd ../src/; pwd)
VERSION=$1
FORCE_DELETE=false

# Check if -f flag is provided as second argument
if [[ "$2" == "-f" ]]; then
    FORCE_DELETE=true
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

# Make sure that release branch is in sync with origin.
if [[ $(git rev-parse HEAD) != $(git rev-parse origin/$RELEASE_BRANCH) ]]
then
    echo "Your branch is out of date with its upstream. Did you forget to pull or push any changes before releasing?"

    exit 1
fi

# Always prepend with "v"
if [[ $VERSION != v*  ]]
then
    VERSION="v$VERSION"
fi

REMOTES=$(ls $BASEPATH)

# Delete the old release tag only if -f flag is provided
if [ $FORCE_DELETE = true ]; then
    echo "Forcing delete of existing tags..."
    git push --delete origin $VERSION 2>/dev/null || true
    git tag --delete $VERSION 2>/dev/null || true
fi

# Tag Framework
git tag $VERSION
git push origin --tags

# Tag Components
for REMOTE in $REMOTES
do
    echo ""
    echo ""
    echo "Cloning $REMOTE";

    TMP_DIR="/tmp/hypervel-split"
    REMOTE_URL="git@github.com:hypervel/${REMOTE}.git"

    rm -rf $TMP_DIR;
    mkdir $TMP_DIR;

    (
        cd $TMP_DIR;

        git clone $REMOTE_URL .
        git checkout "$RELEASE_BRANCH";

        echo "Releasing $REMOTE";
        if [ "$FORCE_DELETE" = true ]; then
            git push --delete origin $VERSION 2>/dev/null || true
            git tag --delete $VERSION 2>/dev/null || true
        fi
        git tag $VERSION
        git push origin --tags
    )
done

TIME=$(echo "$(date +%s) - $NOW" | bc)

printf "Execution time: %f seconds" $TIME