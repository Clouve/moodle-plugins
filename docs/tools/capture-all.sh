#!/usr/bin/env bash
# Retake the screenshots. For each capture script, put its snapshot on the site and
# run the script. Each script names its snapshot in a line "# Snapshot: <name>".
# Usage: docs/tools/capture-all.sh [<capture script>...]   (default: all of them)
set -euo pipefail

tools=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
cd "$tools/../.."
export SITE=${SITE:-2}
# Not every script turns bytecode off before its first import, and the repo must get no __pycache__.
export PYTHONDONTWRITEBYTECODE=1

[ $# -gt 0 ] || set -- "$tools"/capture/*.py
for script in "$@"; do
  snapshot=$(sed -n 's/^# Snapshot: //p' "$script" | head -n 1)
  echo "capture-all.sh: $(basename "$script") from the snapshot ${snapshot:-seed}"
  "$tools/site.sh" restore "${snapshot:-seed}"
  python3 "$script"
done
