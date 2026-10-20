#!/usr/bin/env bash
# Free RAM on a Xiaomi TV over WiFi. Usage: ./clean-ram.sh <tv-ip>
# Needs adb, and USB debugging turned on in the TV's Developer options.
set -e
T="$1:5555"
adb connect "$T"
adb -s "$T" wait-for-device
adb -s "$T" shell am kill-all
for p in $(adb -s "$T" shell pm list packages -3 | tr -d '\r' | sed 's/package://'); do
  adb -s "$T" shell am force-stop "$p"
done
echo "Done"
