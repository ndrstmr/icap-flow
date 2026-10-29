#!/usr/bin/env bash
# Pest 3 exits with 0 for risky, skipped and incomplete tests and for an empty suite, whatever phpunit.xml.dist and the
# --fail-on-* flags say. The exit code PHPUnit computes from them is the last line of the event log (--log-events-text).
# Usage: phpunit-verdict.sh <event log> <suite>. Adds the result to the job summary and fails unless PHPUnit passed;
# a missing, empty or cut-off log is no pass.
set -euo pipefail

log=$1
summary=${GITHUB_STEP_SUMMARY:-/dev/stdout}
if [ ! -s "$log" ]; then
    echo "- $2: no event log, so no verdict" >> "$summary"
    exit 1
fi
tests=$(sed -n 's/^Test Runner Execution Started (\(.*\))$/\1/p' "$log")
verdict=$(tail -n 1 "$log")
{
    echo "- $2: ${tests:-no tests run}; ${verdict}"
    # The line after such an event is its reason, unless it is the next event: "Name (subject)" or "Name:".
    awk '/^Test (Skipped|Marked Incomplete|Considered Risky) \(/ {
        event = $0
        if ((getline reason) > 0 && reason !~ /^[A-Z][A-Za-z ]+ \(.*\)$/ && reason !~ /^[A-Z][A-Za-z ]+:$/) event = event ": " reason
        print "  - " event
    }' "$log"
} >> "$summary"
[ "$verdict" = 'PHPUnit Finished (Shell Exit Code: 0)' ]
