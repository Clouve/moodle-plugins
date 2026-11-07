#!/usr/bin/env bash
# Make the PNG files under docs/ small enough to keep in the repo.
# Usage: docs/tools/optimize-images.sh [<file or folder>...]   (default: docs/user)
#
# It needs pngquant. Set PNGQUANT to use a copy that isn't on your PATH.
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pngquant=${PNGQUANT:-pngquant}
command -v "$pngquant" >/dev/null 2>&1 || {
  echo "optimize-images.sh: pngquant is not installed" >&2
  exit 2
}
[ $# -gt 0 ] || set -- "$repo/docs/user"

find "$@" -name '*.png' -print0 | while IFS= read -r -d '' file; do
  # Exit status 98 and 99 mean the file is fine as it is.
  "$pngquant" --force --skip-if-larger --strip --quality=60-90 --speed 1 --ext .png "$file" || [ $? -ge 98 ]
done
