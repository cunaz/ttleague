#!/usr/bin/env bash
# Installierbares WordPress-Paket aus dem Worktree oder einer angegebenen Revision bauen.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
paths=(ttleague.php uninstall.php src blocks assets LICENSE)
mkdir -p dist
scratch=$(mktemp -d dist/.package.XXXXXXXX)
trap 'rm -rf "$scratch"' EXIT

if (( $# == 0 )); then
    # Eigenen Index verwenden, damit lokale Änderungen ins Paket kommen und der Git-Index erhalten bleibt.
    export GIT_INDEX_FILE="$PWD/$scratch/index"
    git read-tree HEAD
    git add -- "${paths[@]}"
    tree=$(git write-tree)
    revision=HEAD
elif (( $# == 1 )); then
    revision=$(git rev-parse --verify --end-of-options "$1^{commit}")
    tree=$(git rev-parse "$revision^{tree}")
else
    echo "Aufruf: bin/package.sh [revision]" >&2
    exit 2
fi
timestamp=$(git show -s --format=%cI "$revision")
TZ=UTC git archive --mtime="$timestamp" --format=zip --prefix=ttleague/ --output="$scratch/ttleague.zip" "$tree" -- "${paths[@]}"
mv -f "$scratch/ttleague.zip" dist/ttleague.zip
echo "dist/ttleague.zip (Baum $tree)"
