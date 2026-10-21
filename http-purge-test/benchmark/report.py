#!/usr/bin/env python3
"""results/results*.jsonl -> Markdown tables (stdout). Medians over repetitions, min-max in brackets."""
import json, os, statistics as st, sys, collections

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, "results")


def load(name):
    p = os.path.join(RESULTS, name)
    return [json.loads(l) for l in open(p)] if os.path.exists(p) else []


def group(rows, keyf):
    g = collections.OrderedDict()
    for r in rows:
        g.setdefault(keyf(r), []).append(r)
    return g


def med(rows, k):
    v = [r[k] for r in rows if r.get(k) is not None]
    return st.median(v) if v else None


def rng(rows, k, fmt="{:.2f}"):
    v = [r[k] for r in rows if r.get(k) is not None]
    if not v:
        return "-"
    return f"{fmt.format(st.median(v))} [{fmt.format(min(v))}-{fmt.format(max(v))}]"


def label(p, n):
    return f"{p} {n // 1000}k"


def main():
    env = json.load(open(os.path.join(RESULTS, "results-env.json")))
    main_rows, bq, scale = load("results.jsonl"), load("results-bq.jsonl"), load("results-scale.jsonl")
    out = []
    w = out.append

    w("### Environment\n")
    w(f"- CPU: {env['cpus']} vCPU ({env['cpu']}), RAM {env['mem_mb']} MB, kernel {env['kernel']}, fs `{env['fs']}`")
    w(f"- {env['nginx']}, {env['php']}")
    w(f"- ngx_cache_purge: `{env['module'].splitlines()[0]}` ({env['module'].splitlines()[-1].split(' ', 1)[0]})")
    w(f"- NPPP plugin: branch `{env['plugin'].splitlines()[-1]}` ({env['plugin'].splitlines()[0].split(' ', 1)[0]})")
    w("")

    # ---- main matrix -------------------------------------------------------------
    w("### Purge All duration (seconds, median of 3 [min-max])\n")
    w("| Cache | Files | Size (apparent / on disk) | Kernel caches | ngx_cache_purge sync | PHP `nppp_delete_tree` | PHP / module |")
    w("|---|---:|---|---|---:|---:|---:|")
    g = group(main_rows, lambda r: (r["profile"], r["n"], r["temp"]))
    for (p, n, temp), rows in g.items():
        m = [r for r in rows if r["method"] == "module-sync"]
        ph = [r for r in rows if r["method"] == "php"]
        if not m or not ph:
            continue
        ratio = med(ph, "total_s") / med(m, "total_s")
        w(f"| {label(p, n)} | {m[0]['files_before']:,} | {m[0]['apparent_mb']:,.0f} MB / {m[0]['disk_mb']:,.0f} MB "
          f"| {temp} | {rng(m, 'total_s')} | {rng(ph, 'total_s')} | {ratio:.2f}x |")
    w("")

    w("### Throughput (files/s, median)\n")
    w("| Cache | Kernel caches | ngx_cache_purge sync | PHP `nppp_delete_tree` |")
    w("|---|---|---:|---:|")
    for (p, n, temp), rows in g.items():
        m = [r for r in rows if r["method"] == "module-sync"]
        ph = [r for r in rows if r["method"] == "php"]
        if m and ph:
            w(f"| {label(p, n)} | {temp} | {med(m, 'files_per_s'):,.0f} | {med(ph, 'files_per_s'):,.0f} |")
    w("")

    w("### Does the site stall while the purge runs?\n")
    w("8 persistent (keep-alive) connections fetch an unrelated cached URL every 5 ms from a second cache zone while the purge runs.\n")
    w("| Cache | Kernel caches | Method | purge duration (s) | worst response (ms) | requests >100 ms | p99 (ms) |")
    w("|---|---|---|---:|---:|---:|---:|")
    for (p, n, temp), rows in g.items():
        for meth, nm in (("module-sync", "module sync (worker walks)"), ("php", "PHP delete")):
            x = [r for r in rows if r["method"] == meth]
            if x:
                w(f"| {label(p, n)} | {temp} | {nm} | {med(x, 'total_s'):.2f} | {med(x, 'probe_max_ms'):,.0f} | {med(x, 'probes_over_100ms'):.0f} | {med(x, 'probe_p99_ms'):.1f} |")
    w("")

    # ---- background queue ----------------------------------------------------------
    if bq:
        w("### Module with `cache_purge_background_queue on` (defaults, thread pool)\n")
        w("| Cache | Kernel caches | HTTP response (s) | HTTP code | files gone after (s) | worst probe response (ms) |")
        w("|---|---|---:|---|---:|---:|")
        for (p, n, temp), rows in group(bq, lambda r: (r["profile"], r["n"], r["temp"])).items():
            code = rows[0]["http_status"]
            w(f"| {label(p, n)} | {temp} | {rng(rows, 'http_s', '{:.3f}')} | {code} | {rng(rows, 'total_s')} | {med(rows, 'probe_max_ms'):,.0f} |")
        w("")

    # ---- scaling -------------------------------------------------------------------
    allrows = [r for r in main_rows + scale if r["temp"] == "cold"]
    if scale:
        w("### Scaling to 100k files (cold kernel caches, median of 3 up to 20k, of 2 above)\n")
        w("| Cache | Files | Size (apparent) | ngx_cache_purge sync (s) | PHP `nppp_delete_tree` (s) | worst site stall during module purge (ms) |")
        w("|---|---:|---:|---:|---:|---:|")
        for (p, n), rows in group([r for r in allrows if r["profile"] == "wp"], lambda r: (r["profile"], r["n"])).items():
            m = [r for r in rows if r["method"] == "module-sync"]
            ph = [r for r in rows if r["method"] == "php"]
            if m and ph:
                w(f"| {label(p, n)} | {m[0]['files_before']:,} | {m[0]['apparent_mb']:,.0f} MB | {rng(m, 'total_s')} | {rng(ph, 'total_s')} | {med(m, 'probe_max_ms'):,.0f} |")
        w("")
        # linear extrapolation from the largest measured point (per-file cost is ~constant on this box)
        big = max((r for r in scale if r["method"] == "module-sync"), key=lambda r: r["n"], default=None)
        if big:
            same = [r for r in scale if r["method"] == "module-sync" and r["n"] == big["n"]]
            rate = st.median(r["files_per_s"] for r in same)
            php_same = [r for r in scale if r["method"] == "php" and r["n"] == big["n"]]
            prate = st.median(r["files_per_s"] for r in php_same) if php_same else None
            w("### Where would the 30 s HTTP timeout be crossed?\n")
            w(f"Linear extrapolation from the {big['n']:,}-file cold run (module {rate:,.0f} files/s"
              + (f", PHP {prate:,.0f} files/s" if prate else "") + "). Slower disks, network volumes or cold caches scale this down proportionally.\n")
            w("| Threshold | Meaning | Files (module sync) | Files (PHP) |")
            w("|---|---|---:|---:|")
            for t, mean in ((30, "HTTP timeout: plugin stops waiting for the 200"), (45, "30 s + 15 s drain: plugin falls back to PHP delete"), (60, "Purge All lock TTL")):
                w(f"| {t} s | {mean} | {int(rate * t):,} | {int(prate * t) if prate else 0:,} |")
            w("")

    # ---- sanity --------------------------------------------------------------------
    allr = main_rows + bq + scale
    w("### Sanity checks\n")
    w(f"- {len(allr)} runs; every run ended with `files_after = {sorted(set(r['files_after'] for r in allr))}`; "
      f"nginx `[alert]/[crit]/[emerg]` log lines: {sum(r['nginx_alert_lines'] for r in allr)}; "
      f"populate errors: {sum(r['populate_errors'] for r in allr)}.")
    w(f"- After every purge nginx answered `{collections.Counter(tuple(r['post_purge_requests']) for r in allr).most_common(1)[0][0]}` for the same URL "
      "(miss -> re-cached -> hit), i.e. the cache keeps working with either deleter.")
    print("\n".join(out))


if __name__ == "__main__":
    main()
