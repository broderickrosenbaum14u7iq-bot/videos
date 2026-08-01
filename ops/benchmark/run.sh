#!/usr/bin/env bash
#
# Benchmark harness — DEVELOPMENT_RULES.md §9.
#
# Measures every metric that has a real subsystem to measure yet, and
# reports N/A (not a guess) for anything that doesn't. Run from the repo
# root with the staging stack already up (`docker compose up -d`).
#
# Usage: ops/benchmark/run.sh [video-url-path]
#   video-url-path defaults to /watch/test-video-one/ if omitted.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VIDEO_PATH="${1:-/watch/test-video-one/}"
BASE_URL="http://localhost:${HTTP_PORT:-8080}"

# Times a GET request and reports both the raw seconds curl measures and
# the millisecond figure this report's other metrics are expressed in,
# without pulling curl's -w output apart in awk to do the conversion.
time_request() {
  local label="$1" url="$2" time_total_s http_code
  time_total_s="$(curl -s -o /dev/null -w '%{time_total}' "$url")"
  http_code="$(curl -s -o /dev/null -w '%{http_code}' "$url")"
  printf '%s: http_code=%s time_total_ms=%.2f\n' "$label" "$http_code" \
    "$(awk -v s="$time_total_s" 'BEGIN { printf "%.4f", s * 1000 }')"
}

echo "=== Benchmark run: $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
echo

echo "--- PHP memory / execution time / SQL query count (MigrationRunner::status()) ---"
docker compose exec -T wpcli wp eval-file ops/benchmark/memory-and-queries.php --allow-root

echo
echo "--- Event dispatch cost (1,000 dispatches through the real Dispatcher) ---"
docker compose exec -T wpcli wp eval-file ops/benchmark/event-dispatch.php --allow-root

echo
echo "--- REST latency: GET /wp-json/wp/v2/videos (core endpoint — tube/v1 custom routes don't exist yet) ---"
time_request "REST /wp-json/wp/v2/videos" "${BASE_URL}/wp-json/wp/v2/videos"

echo
echo "--- Page generation time: GET ${VIDEO_PATH} (Phase 1 fallback template — no real theme yet) ---"
time_request "Page ${VIDEO_PATH}" "${BASE_URL}${VIDEO_PATH}"

echo
echo "--- Page generation time: GET / (homepage) ---"
time_request "Page /" "${BASE_URL}/"

echo
echo "--- Cache hits / misses ---"
echo "N/A — no cache layer exists yet (tube-cache is Phase 3)."

echo
echo "--- Import throughput ---"
echo "N/A — no import pipeline exists yet (Phase 5)."

echo
echo "=== End benchmark run ==="
