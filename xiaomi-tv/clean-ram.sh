#!/usr/bin/env bash
# Free RAM on a Xiaomi (Mi) TV over WiFi using ADB.
#
# Usage: ./clean-ram.sh <tv-ip> [port]
#   e.g. ./clean-ram.sh 192.168.1.42
#
# One-time TV setup:
#   1. Settings > Device Preferences > About > click "Build" 7 times (enables Developer options)
#   2. Settings > Device Preferences > Developer options > turn on "USB debugging"
#      (and "Network debugging" / "ADB over network" if your model shows it)
#   3. Settings > Network & Internet > your WiFi > note the IP address
#   4. On first connect, accept the "Allow debugging?" prompt on the TV

set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: $0 <tv-ip> [port]" >&2
  exit 1
fi

TARGET="$1:${2:-5555}"

if ! command -v adb >/dev/null 2>&1; then
  echo "adb not found. Install Android platform-tools:" >&2
  echo "  macOS:   brew install android-platform-tools" >&2
  echo "  Linux:   sudo apt install adb" >&2
  echo "  Windows: https://developer.android.com/tools/releases/platform-tools" >&2
  exit 1
fi

tv() { adb -s "$TARGET" shell "$@" | tr -d '\r'; }

mem_avail_mb() {
  tv cat /proc/meminfo | awk '/MemAvailable/ { printf "%d", $2 / 1024 }'
}

echo "Connecting to $TARGET ..."
adb connect "$TARGET" >/dev/null
for _ in $(seq 1 15); do
  state=$(adb -s "$TARGET" get-state 2>/dev/null || true)
  [ "$state" = "device" ] && break
  echo "  waiting for the TV (accept the debugging prompt on screen if shown)..."
  sleep 2
done
if [ "$(adb -s "$TARGET" get-state 2>/dev/null || true)" != "device" ]; then
  echo "Could not connect to $TARGET. Check the IP and that debugging is on." >&2
  exit 1
fi

before=$(mem_avail_mb)
echo "Available RAM before: ${before} MB"

# Keep the home screen running so the TV doesn't flash a blank screen.
launcher=$(tv cmd package resolve-activity --brief -c android.intent.category.HOME 2>/dev/null \
  | tail -n 1 | cut -d/ -f1 || true)

echo "Stopping background apps..."
tv am kill-all
for pkg in $(tv pm list packages -3 | sed 's/^package://'); do
  [ "$pkg" = "$launcher" ] && continue
  tv am force-stop "$pkg"
  echo "  stopped $pkg"
done

sleep 2
after=$(mem_avail_mb)
echo "Available RAM after:  ${after} MB (freed $((after - before)) MB)"

adb disconnect "$TARGET" >/dev/null
