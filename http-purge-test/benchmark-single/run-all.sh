#!/bin/bash
# Full Purge Single matrix: HTTP purge (FP1) vs ripgrep purge (FP3) vs PHP scan (FP4).
# Roughly 1.5-2 hours on a small VM (4 sizes x 3 scenarios x 4 target counts x warm/cold + stall pass; an estimate scaled from the
# older 2 scenarios x 2 target counts matrix, which took ~40 minutes, not a measured time).
# Needs root (drop_caches, php-fpm as root) and the nginx build from ../build-nginx.sh.
#
#   ./run-all.sh                      # defaults below
#   ./run-all.sh --taskset 0          # same matrix, PHP worker + rg pinned to 1 CPU (emulate a small server)
#   ./run-all.sh --index-entries 20000   # also simulate the DB cost of the index write-back (rg/scan only)
#   ./run-all.sh --targets 1,5 --scenarios hit,miss --sizes wp:1000,wp:5000   # a quick cut (~10 min)
#
# Scenarios: hit / miss (all targets cached / none) and mixed (cached primary + related-shaped URLs: /, feeds, term archives,
# comment pages, only --mixed-hit-frac of them cached). Targets 1,5,20,60: a plain page, a typical post, a post with many
# terms, a post with many terms AND comment pages (related.php has no upper bound). Stall pass: single purge and 60 targets.
# Any extra argument is passed to bench_single.py (later flags win). Results: results/single.jsonl (+ -env.json),
# report: results/REPORT.md and results/report.html.
set -e
cd "$(dirname "$0")"
python3 bench_single.py \
    --sizes wp:1000,wp:5000,wp:20000,wp:50000 \
    --methods http,rg,scan \
    --scenarios hit,miss,mixed --targets 1,5,20,60 \
    --cell-budget-s 150 --stall-rounds 3 --stall-targets 1,60 "$@"
python3 report_single.py --html results/report.html > results/REPORT.md
echo ALLDONE
