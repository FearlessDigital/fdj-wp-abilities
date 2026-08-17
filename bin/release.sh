#!/usr/bin/env bash
#
# Pre-release check and build.
#
#   ./bin/release.sh
#
# Verifies the things that silently break a Git Updater release, then builds a
# zip for manual installs. Does not commit, tag, or push. It prints the commands
# for that so you stay in control of what hits the remote.

set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_FILE="fdj-wp-abilities.php"
SLUG="fdj-wp-abilities"
fail=0

say()  { printf "  %-42s %s\n" "$1" "$2"; }
bad()  { printf "  %-42s %s\n" "$1" "$2"; fail=1; }

echo
echo "Checking $SLUG"
echo

# --- 1. syntax -------------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  errs=$(find . -name '*.php' -not -path './.git/*' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors' || true)
  if [ -z "$errs" ]; then
    say "PHP syntax" "OK"
  else
    bad "PHP syntax" "ERRORS"
    echo "$errs"
  fi
else
  say "PHP syntax" "skipped (no php on PATH)"
fi

# --- 2. version consistency ------------------------------------------------
# Git Updater compares the Version header against the tag. A mismatch means the
# update silently never appears, with no error anywhere.
VERSION=$(grep -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
CONST=$(grep -E "define\( 'FDJ_MCP_VERSION'" "$PLUGIN_FILE" | sed -E "s/.*'([0-9.]+)'.*/\1/")
STABLE=$(grep -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')

say "Version header" "$VERSION"

[ "$CONST"  = "$VERSION" ] && say "FDJ_MCP_VERSION constant" "matches" \
                           || bad "FDJ_MCP_VERSION constant" "$CONST does not match $VERSION"
[ "$STABLE" = "$VERSION" ] && say "readme.txt Stable tag" "matches" \
                           || bad "readme.txt Stable tag" "$STABLE does not match $VERSION"

# --- 3. Git Updater headers ------------------------------------------------
for h in "Update URI" "GitHub Plugin URI" "Primary Branch"; do
  if grep -qE "^\s*\*\s*$h:" "$PLUGIN_FILE"; then
    say "header: $h" "present"
  else
    bad "header: $h" "MISSING"
  fi
done

# --- 4. tag availability ---------------------------------------------------
if git rev-parse "$VERSION" >/dev/null 2>&1; then
  bad "tag $VERSION" "already exists, bump the version first"
else
  say "tag $VERSION" "available"
fi

DIRTY=0
if [ -n "$(git status --porcelain)" ]; then
  DIRTY=1
  say "working tree" "has uncommitted changes"
else
  say "working tree" "clean"
fi

# --- 5. build a zip for manual installs ------------------------------------
# Built with git archive rather than zip, so export-ignore is honoured and the
# result matches byte for byte what Git Updater will hand to a client site.
OUT="../${SLUG}-${VERSION}.zip"
rm -f "$OUT"

if git archive --format=zip --prefix="${SLUG}/" -o "$OUT" HEAD 2>/dev/null; then
  if [ "$DIRTY" -eq 1 ]; then
    bad "zip" "$(basename "$OUT") built from HEAD, NOT your uncommitted changes"
  else
    say "zip" "$(basename "$OUT")"
  fi
else
  bad "zip" "git archive failed"
fi

echo
if [ "$fail" -ne 0 ]; then
  echo "  Not ready. Fix the above."
  exit 1
fi

cat <<EOF
  Ready. To publish $VERSION:

    git add -A
    git commit -m "Release $VERSION"
    git tag $VERSION
    git push origin main --tags

  Then create a GitHub Release for tag $VERSION. Sites running Git Updater will
  offer the update on their next check.
EOF
