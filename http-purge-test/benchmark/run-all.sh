#!/bin/bash
# Full matrix. Everything runs sequentially.
cd "$(dirname "$0")"
python3 bench.py --profiles wp:5000,wp:10000,wp:20000,heavy:20000 --temps warm,cold --methods module-sync,php --reps 3 --out results/results.jsonl
python3 bench.py --profiles wp:20000,heavy:20000 --temps warm,cold --methods module-bq --reps 3 --out results/results-bq.jsonl
python3 bench.py --profiles wp:50000,wp:100000 --temps cold --methods module-sync,php --reps 2 --out results/results-scale.jsonl
echo ALLDONE
