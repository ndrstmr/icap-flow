#!/bin/sh
# Starts the verified service: no ICAP service without a scan engine that answers (c-icap without one passes everything).
set -eu
mkdir -p /var/lib/clamav/tmp
freshclam --quiet || echo "WARNING: freshclam failed, using the signatures that are there"
freshclam -d &
/usr/sbin/clamd &
clamdscan --ping 120:1 || { echo "ERROR: clamd does not answer, c-icap is not started" >&2; exit 1; }
exec /opt/c-icap/bin/c-icap -N -d 1
