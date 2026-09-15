#!/usr/bin/env bash
#
# Builds the React app and mirrors the plugin into the local SFTP working
# copy, so a change can go out without hand-building a zip and uploading
# it through wp-admin every time.
#
#   ./scripts/sync-to-dev.sh
#
# The destination is the local folder the VS Code SFTP extension watches
# (see .vscode/sftp.json in that project). Override it if the checkout
# lives somewhere else:
#
#   EVENTS_SHOWCASE_DEV_PATH=/some/other/plugins/events-showcase \
#     ./scripts/sync-to-dev.sh
#
# IMPORTANT: this script only writes to the local mirror. The SFTP
# extension uploads on VS Code's *save* event, which an external write
# like rsync does not trigger — so nothing reaches the server until you
# run "SFTP: Sync Local -> Remote" on the plugin folder (or right-click
# it and choose Upload Folder). See README.md, "Deployment".

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO_ROOT/wordpress-plugin/events-showcase/"
DST="${EVENTS_SHOWCASE_DEV_PATH:-$HOME/Desktop/work/yasiru-dev/public_html/wp-content/plugins/events-showcase}"

if [ ! -d "$DST" ]; then
  echo "error: destination not found: $DST" >&2
  echo "Set EVENTS_SHOWCASE_DEV_PATH to your local SFTP working copy." >&2
  exit 1
fi

echo "==> Building React app"
( cd "$REPO_ROOT/react-app" && npm run build )

echo
echo "==> Mirroring plugin to $DST"
# -c compares by checksum, not timestamp: the build rewrites every file's
# mtime whether or not its contents changed, so a time-based comparison
# would report the whole plugin as modified on every run and leave you
# unable to see what actually moved.
#
# --delete keeps old content-hashed bundles from piling up locally. Note
# that it cannot clean the *remote* — the extension only ever uploads —
# so stale bundles accumulate server-side until you run a Sync Local ->
# Remote with deletion enabled. They are inert either way: class-assets.php
# enqueues whatever .vite/manifest.json names, never whatever is lying
# around in assets/.
#
# -t preserves mtimes. Without it rsync stamps every destination file
# with the current time on each run, so the next run sees them all as
# differing-by-time and reports the entire plugin as touched — which
# makes the change list useless for spotting what actually moved.
#
# -O drops directory mtimes from the comparison — they change on every
# build and say nothing about what moved.
rsync -rtcO --delete --exclude '.DS_Store' --itemize-changes "$SRC" "$DST"

echo
echo "Done. Now run 'SFTP: Sync Local -> Remote' on the plugin folder in"
echo "VS Code — rsync writes do not trigger uploadOnSave."
