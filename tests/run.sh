#!/usr/bin/env bash
# Run every tests/*.test.sh file and report. The exit status is non-zero when any
# test file fails. Usage: tests/run.sh
set -euo pipefail

tests_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

count=0
failed=""

for file in "$tests_dir"/*.test.sh; do
  if [ ! -e "$file" ]; then
    continue
  fi
  name=$(basename "$file" .test.sh)
  count=$((count + 1))
  printf '==== %s\n' "$name"
  if ! bash "$file"; then
    failed="$failed $name"
  fi
  printf '\n'
done

if [ "$count" -eq 0 ]; then
  echo "run.sh: no *.test.sh files in $tests_dir" >&2
  exit 1
fi

if [ -n "$failed" ]; then
  printf 'FAILED:%s\n' "$failed"
  exit 1
fi
printf 'All %d test files passed.\n' "$count"
