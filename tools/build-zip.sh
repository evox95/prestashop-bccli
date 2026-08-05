#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
set -euo pipefail

VERSION="$(grep -oP "(?<=\\\$this->version = ')[^']+" bccli.php)"
BUILD="$(mktemp -d)"
OUT="$PWD/dist/bccli-${VERSION}.zip"

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"

mkdir -p "$BUILD/bccli"
rsync -a --exclude='.git' --exclude='.github' --exclude='tests' \
      --exclude='tools' --exclude='dist' --exclude='docs' \
      --exclude='.superpowers' --exclude='*.zip' ./ "$BUILD/bccli/"

(cd "$BUILD" && zip -rq "$OUT" bccli)
rm -rf "$BUILD"

echo "built $OUT"
# "| head -5" is piped for a short preview; with pipefail, head exiting early
# sends unzip a SIGPIPE and the pipeline reports 141 even on a good build, so
# the preview alone must not fail the script.
unzip -l "$OUT" | head -5 || true
