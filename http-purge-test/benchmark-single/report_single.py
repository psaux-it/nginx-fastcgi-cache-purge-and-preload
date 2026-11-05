#!/usr/bin/env python3
"""
Report for bench_single.py: Markdown tables on stdout, optionally a self-contained HTML page with charts.

    python3 report_single.py [results/single.jsonl] [--all-runs] [--html report.html]

By default only the LATEST run in the file is used (the harness appends); --all-runs pools everything.
Every ratio is PAIRED: within one round both methods purged the same URLs of the same cache, so the ratio of the two
latencies is taken per round and summarised by its median with a bootstrap 95 % CI (2000 resamples, fixed seed).
Warm-up rounds and steps that failed a correctness check are excluded (their number is printed at the end).
"""
import argparse, html, json, math, os, random, statistics, sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))


# ----------------------------------------------------------------------------------------------- stats
def pct(vals, q):
    s = sorted(vals)
    if not s:
        return None
    k = (len(s) - 1) * q
    lo, hi = math.floor(k), math.ceil(k)
    return s[lo] + (s[hi] - s[lo]) * (k - lo)


def med(vals):
    return statistics.median(vals) if vals else None


def boot_ci(vals, seed=1, n=2000):
    if len(vals) < 4:
        return None
    rng = random.Random(seed)
    meds = sorted(statistics.median(rng.choices(vals, k=len(vals))) for _ in range(n))
    return meds[int(0.025 * n)], meds[int(0.975 * n) - 1]


def fmt_ms(v):
    if v is None:
        return "–"
    if v < 10:
        return f"{v:.2f}"
    if v < 1000:
        return f"{v:.1f}"
    return f"{v:,.0f}"


def fmt_x(v):
    if v is None:
        return "–"
    return f"{v:.1f}x" if v < 100 else f"{v:,.0f}x"


def linfit(xs, ys):
    """y = a + b*x, R^2. None when it cannot be fitted."""
    if len(xs) < 2 or len(set(xs)) < 2:
        return None
    mx, my = statistics.mean(xs), statistics.mean(ys)
    sxx = sum((x - mx) ** 2 for x in xs)
    b = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx
    a = my - b * mx
    ss_tot = sum((y - my) ** 2 for y in ys)
    ss_res = sum((y - (a + b * x)) ** 2 for x, y in zip(xs, ys))
    return a, b, (1 - ss_res / ss_tot) if ss_tot else 1.0


# ----------------------------------------------------------------------------------------------- data
def load(path, all_runs):
    rows = [json.loads(l) for l in open(path) if l.strip()]
    if not all_runs:
        runs = sorted({r.get("run", "") for r in rows})
        rows = [r for r in rows if r.get("run", "") == runs[-1]]
    env_path = path.replace(".jsonl", "-env.json")
    env = json.load(open(env_path)) if os.path.exists(env_path) else {}
    return rows, env


def table(headers, rows, align=None):
    align = align or ["l"] + ["r"] * (len(headers) - 1)
    sep = ["---:" if a == "r" else ":---" for a in align]
    out = ["| " + " | ".join(headers) + " |", "|" + "|".join(sep) + "|"]
    out += ["| " + " | ".join(str(c) for c in r) + " |" for r in rows]
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("path", nargs="?", default=os.path.join(HERE, "results", "single.jsonl"))
    ap.add_argument("--all-runs", action="store_true")
    ap.add_argument("--html", default="", help="also write a self-contained HTML report with charts")
    a = ap.parse_args()

    rows, env = load(a.path, a.all_runs)
    steps_all = [r for r in rows if r["type"] == "step" and not r.get("warmup")]
    steps = [r for r in steps_all if r["ok"]]
    failed = len(steps_all) - len(steps)
    meta = {r["n"]: r for r in rows if r["type"] == "cell_meta"}
    ends = {r["n"]: r["files_end"] for r in rows if r["type"] == "cell_end"}
    ends_want = {r["n"]: r.get("files_expected", r["n"]) for r in rows if r["type"] == "cell_end"}
    methods = sorted({r["method"] for r in steps}, key=["http", "rg", "scan"].index)
    ns = sorted({r["n"] for r in steps})
    temps = [t for t in ("warm", "cold") if any(r["temp"] == t for r in steps)]
    scens = [s for s in ("hit", "miss", "mixed") if any(r["scenario"] == s for r in steps)]
    tgts = sorted({r["targets"] for r in steps})

    G = defaultdict(list)       # (n,temp,scen,T,method) -> [step]
    P = defaultdict(dict)       # (n,temp,scen,T,round) -> {method: step}
    for r in steps:
        G[(r["n"], r["temp"], r["scenario"], r["targets"], r["method"])].append(r)
        P[(r["n"], r["temp"], r["scenario"], r["targets"], r["round"])][r["method"]] = r

    def lat(n, temp, sc, t, m, field="layer_ms"):
        return [r[field] for r in G.get((n, temp, sc, t, m), []) if field in r]

    def ratios(n, temp, sc, t, num="rg", den="http"):
        return [p[num]["layer_ms"] / p[den]["layer_ms"] for k, p in P.items()
                if k[:4] == (n, temp, sc, t) and num in p and den in p and p[den]["layer_ms"] > 0]

    out = []
    w = out.append
    w("# Purge Single: HTTP purge vs ripgrep purge" + (" vs PHP scan" if "scan" in methods else ""))
    w("")
    w("Measured by `bench_single.py`: the plugin's real `nppp_purge_fp1_http()` (HTTP, one request per target, ngx_cache_purge) and "
      "`nppp_purge_fp3_rg()` (one ripgrep pass over the cache for all targets, then unlink)" +
      (", and `nppp_purge_fp4_scan()` (PHP iterator)" if "scan" in methods else "") +
      ", each in a fresh PHP process against a real nginx and real cache files. Times are the layer call only (PHP start-up excluded). "
      "Latencies are medians; `p99` is the 99th percentile of the rounds; ratios are paired per round (median, 95 % bootstrap CI).")
    w("")

    # ------------------------------------------------------------------ setup
    w("## Setup")
    w("")
    if env:
        w(table(["", ""], [
            ["Machine", f"{env.get('cpus')} vCPU {env.get('cpu', '')}, {env.get('mem_mb')} MB RAM, {env.get('fs')}" +
             (f" (kernel rotational flag {env.get('rotational')}; virtio disks often report 1 even on SSD)" if env.get("rotational") != "" else "")],
            ["Kernel", env.get("kernel")],
            ["nginx", f"{env.get('nginx', '').replace('nginx version: ', '')}, worker_processes {env.get('workers')}"],
            ["Module", str(env.get("module", "")).replace("\n", " ")],
            ["ripgrep", env.get("rg")],
            ["PHP", env.get("php")],
            ["Plugin", str(env.get("plugin", "")).replace("\n", " ")],
            ["Cold =", env.get("cold_method")],
            ["Worker CPUs", env.get("worker_cpus")],
            ["URL->path index entries (DB write-back sim.)", env.get("index_entries")],
        ], ["l", "l"]))
        w("")
    w(table(["N", "Files", "Apparent MB", "Avg KB", "Fits in RAM"],
            [[f"{n:,}", f"{m['files']:,}", f"{m['apparent_mb']:,}", m["avg_file_kb"], "yes" if m.get("fits_in_ram") else "**no (warm is partly cold)**"]
             for n, m in sorted(meta.items())]))
    w("")

    # ------------------------------------------------------------------ headline
    def headline():
        n = ns[-1]
        lines = []
        for temp in temps:
            h, r = med(lat(n, temp, "hit", tgts[0], "http")), med(lat(n, temp, "hit", tgts[0], "rg"))
            if h and r:
                rr = ratios(n, temp, "hit", tgts[0])
                ci = boot_ci(rr)
                lines.append(f"- **{temp}, {n:,} files, cached page, {tgts[0]} target:** HTTP **{fmt_ms(h)} ms**, ripgrep **{fmt_ms(r)} ms** "
                             f"-> ripgrep is {fmt_x(med(rr))} slower" + (f" (95 % CI {fmt_x(ci[0])}-{fmt_x(ci[1])})" if ci else ""))
            tm = tgts[-1]
            for sc in ("mixed", "hit"):
                h, r = med(lat(n, temp, sc, tm, "http")), med(lat(n, temp, sc, tm, "rg"))
                if h and r and tm > tgts[0]:
                    rr = ratios(n, temp, sc, tm)
                    ci = boot_ci(rr)
                    lines.append(f"- **{temp}, {n:,} files, {sc}, {tm} targets:** HTTP **{fmt_ms(h)} ms**, ripgrep **{fmt_ms(r)} ms** "
                                 f"-> ripgrep is {fmt_x(med(rr))} {'slower' if med(rr) >= 1 else 'FASTER (ratio < 1)'}"
                                 + (f" (95 % CI {fmt_x(ci[0])}-{fmt_x(ci[1])})" if ci else ""))
                    break
        return lines
    if "http" in methods and "rg" in methods and ns:
        w("## Headline")
        w("")
        out.extend(headline())
        w("")

    # ------------------------------------------------------------------ main tables
    for sc in scens:
        for temp in temps:
            title = {"hit": "Cached page (the purge has something to delete)", "miss": "Page not in the cache (nothing to delete)",
                     "mixed": "Mixed: cached primary + related-shaped URLs, only some cached (home, feeds, archives, comment pages)"}[sc]
            w(f"## {title} - {temp} kernel caches")
            w("")
            hdr = ["N files", "Targets"]
            for m in methods:
                hdr += [f"{m} p50 ms", f"{m} p99 ms"]
            if "http" in methods and "rg" in methods:
                hdr += ["rg / http (paired)"]
            if "http" in methods and "scan" in methods:
                hdr += ["scan / http (paired)"]
            hdr += ["rounds"]
            body = []
            for n in ns:
                for t in tgts:
                    if not any(G.get((n, temp, sc, t, m)) for m in methods):
                        continue
                    row = [f"{n:,}", t]
                    for m in methods:
                        v = lat(n, temp, sc, t, m)
                        row += [fmt_ms(med(v)), fmt_ms(pct(v, 0.99))]
                    for num in [x for x in ("rg", "scan") if x in methods and "http" in methods]:
                        rr = ratios(n, temp, sc, t, num)
                        ci = boot_ci(rr)
                        row.append(f"{fmt_x(med(rr))}" + (f" [{fmt_x(ci[0])}-{fmt_x(ci[1])}]" if ci else ""))
                    row.append(len(lat(n, temp, sc, t, methods[0])))
                    body.append(row)
            w(table(hdr, body))
            w("")

    # ------------------------------------------------------------------ rg anatomy
    if "rg" in methods:
        w("## Where the ripgrep time goes (cached page, 1 target)")
        w("")
        t1 = tgts[0]
        body = []
        for temp in temps:
            for n in ns:
                L = lat(n, temp, "hit", t1, "rg")
                if not L:
                    continue
                ex, non = med(lat(n, temp, "hit", t1, "rg", "rg_exec_ms")), med(lat(n, temp, "hit", t1, "rg", "non_rg_ms"))
                m = meta.get(n, {})
                mbps = (m.get("apparent_mb", 0) / (ex / 1000)) if ex else None
                fps = (m.get("files", 0) / (ex / 1000)) if ex else None
                body.append([temp, f"{n:,}", fmt_ms(med(L)), fmt_ms(ex), fmt_ms(non),
                             f"{mbps:,.0f}" if mbps else "–", f"{fps:,.0f}" if fps else "–"])
        w(table(["Kernel caches", "N files", "layer ms", "rg exec ms", "non-rg ms", "scan MB/s", "scan files/s"], body))
        w("")
        w("`non-rg` = `command -v rg` shell spawn + parsing rg's output + the unlink + the index write-back + post-purge hooks (stubbed). "
          "`scan MB/s` = cache size / rg exec time: rg has to read EVERY file (`-m 1` stops a file at its first match, not the scan), "
          "so its cost follows the amount of cached data, not the number of targets.")
        w("")

    if "http" in methods:
        w("## Where the HTTP time goes (cached page)")
        w("")
        body = []
        for temp in temps:
            for t in tgts:
                n = ns[-1]
                L = lat(n, temp, "hit", t, "http")
                if not L:
                    continue
                req = med(lat(n, temp, "hit", t, "http", "http_ms"))
                non = med(lat(n, temp, "hit", t, "http", "non_http_ms"))
                body.append([temp, f"{n:,}", t, fmt_ms(med(L)), fmt_ms(req), fmt_ms(req / t if req else None), fmt_ms(non)])
        w(table(["Kernel caches", "N files", "Targets", "layer ms", "HTTP total ms", "per request ms", "plugin PHP ms"], body))
        w("")
        w("One request per target, sequential (FP1 loops over the targets). `plugin PHP` is URL checks + response classification, "
          "without WordPress' own `wp_remote_request()` overhead (not reproducible in a stub; add roughly 1 ms per request).")
        w("")

    # ------------------------------------------------------------------ scaling
    if len(ns) >= 2:
        w("## Scaling with cache size (linear fit of the p50 against N)")
        w("")
        body = []
        for sc in scens:
            for temp in temps:
                for t in tgts:
                    for m in methods:
                        pts = [(n, med(lat(n, temp, sc, t, m))) for n in ns if lat(n, temp, sc, t, m)]
                        fit = linfit([p[0] for p in pts], [p[1] for p in pts]) if len(pts) >= 2 else None
                        if fit:
                            body.append([sc, temp, t, m, fmt_ms(fit[0]), f"{fit[1] * 1000:+.3f}", f"{fit[2]:.3f}"])
        w(table(["Scenario", "Kernel caches", "Targets", "Method", "fixed ms (N=0)", "ms per +1,000 files", "R²"], body,
                ["l", "l", "r", "l", "r", "r", "r"]))
        w("")
        w("`ms per +1,000 files` is the cost of every 1,000 extra cached pages. A flat method (~0) is independent of cache size.")
        w("")

    # ------------------------------------------------------------------ break-even
    if "http" in methods and "rg" in methods and len(tgts) >= 2:
        w("## Break-even: how many targets before one rg pass beats N HTTP requests?")
        w("")
        tmax = tgts[-1]
        body = []
        for sc in scens:
            for temp in temps:
                for n in ns:
                    pts = [(t, med(lat(n, temp, sc, t, "http"))) for t in tgts if lat(n, temp, sc, t, "http")]
                    rg_pts = [(t, med(lat(n, temp, sc, t, "rg"))) for t in tgts if lat(n, temp, sc, t, "rg")]
                    fit = linfit([p_[0] for p_ in pts], [p_[1] for p_ in pts]) if len(pts) >= 2 else None
                    if not fit or not rg_pts:
                        continue
                    a_, b, r2 = fit
                    rg_flat = med([v for _, v in rg_pts])
                    rg_drift = (rg_pts[-1][1] / rg_pts[0][1] - 1) * 100 if rg_pts[0][1] else None
                    be = (rg_flat - a_) / b if b > 0 else None
                    at_max = f"T={pts[-1][0]}: {fmt_ms(pts[-1][1])} / {fmt_ms(rg_pts[-1][1])}" + (" **rg ahead**" if rg_pts[-1][1] < pts[-1][1] else "")
                    body.append([sc, temp, f"{n:,}", f"{fmt_ms(a_)} + {b:.2f}·T (R² {r2:.3f})", fmt_ms(rg_flat),
                                 f"{rg_drift:+.0f} %" if rg_drift is not None else "–", f"{be:,.0f}" if be and be > 0 else "–", at_max])
        w(table(["Scenario", "Kernel caches", "N files", "HTTP ms (least-squares fit over all T)", "rg ms (median over T)",
                 f"rg change T={tgts[0]}→{tmax}", "break-even targets (extrapolated)", "measured at max T: http / rg ms"], body,
                ["l", "l", "r", "r", "r", "r", "r", "r"]))
        w("")
        w(f"HTTP cost is modelled as `fixed + per-target·T`, a least-squares line through the medians of every measured T ({', '.join(map(str, tgts))}). "
          "`rg change` is the measured drift of the rg median between the smallest and the largest T: the 'rg is flat in T' assumption is "
          "checked here, not assumed. The last column is MEASURED (no extrapolation); the break-even column is the line extended past the data.")
        w("")

    # ------------------------------------------------------------------ stall
    stalls = [r for r in rows if r["type"] == "stall"]
    bases = [r for r in rows if r["type"] == "stall_baseline"]
    if stalls:
        w("## Does the site stall while the purge runs?")
        w("")
        body = []
        for temp in sorted({r["temp"] for r in stalls}):
            b = next((x for x in bases if x["temp"] == temp), None)
            if b:
                body.append([temp, "–", f"idle baseline ({b['probes']} probes)", "–", b["samples"], fmt_ms(b["p50"]), fmt_ms(b["p99"]), fmt_ms(b["max"]), b["over_50ms"]])
            for tg in sorted({r.get("targets", 1) for r in stalls if r["temp"] == temp}):
                for m in methods:
                    S = [r for r in stalls if r["temp"] == temp and r["method"] == m and r.get("targets", 1) == tg]
                    if not S:
                        continue
                    n_ = sum(r["samples"] for r in S)
                    mx = max((r["max"] for r in S if r.get("max") is not None), default=None)
                    p50 = med([r["p50"] for r in S if "p50" in r])
                    p99 = max((r["p99"] for r in S if "p99" in r), default=None)
                    body.append([temp, tg, f"during {m}", fmt_ms(med([r["window_ms"] for r in S])), n_, fmt_ms(p50), fmt_ms(p99), fmt_ms(mx),
                                 sum(r.get("over_50ms", 0) for r in S)])
        w(table(["Kernel caches", "Targets", "Window", "purge window ms (median)", "probe samples", "p50 ms", "worst round p99 ms", "max ms", "probes >50 ms"], body,
                ["l", "r", "l", "r", "r", "r", "r", "r", "r"]))
        w("")
        w(f"Keep-alive probes fetch a cached URL of an UNRELATED zone while the purge runs (largest cache, {stalls[0].get('n'):,} files). "
          "With several targets the purge is a mixed one (cached primary + related shapes). A short HTTP purge window collects few samples: "
          "read the `probe samples` column before comparing.")
        w("")

    # ------------------------------------------------------------------ integrity
    w("## Correctness and integrity")
    w("")
    noise = defaultdict(int)
    for r in steps_all:
        for k, v in (r.get("nginx_log") or {}).items():
            noise[(r["method"], k)] += v
    w(f"- measured steps: {len(steps_all)}, passed every check: {len(steps)}, **failed: {failed}**"
      " (checks: targets gone / still uncached, decoy pages untouched (mixed: also untargeted cached home / feeds / archives), layer reported success, "
      "ripgrep matched exactly the cached targets, next request is a MISS, re-cached as HIT)")
    if noise:
        w("- nginx error-log lines during steps: " + ", ".join(f"{m}/{k}={v}" for (m, k), v in sorted(noise.items())))
    else:
        w("- nginx error log: no warn/error/crit/alert lines during any step (including requests for pages whose file was deleted behind nginx's back by ripgrep)")
    codes = {c for r in steps if r["method"] == "http" for c in r.get("http_code", [])}
    if codes:
        w("- HTTP purge answers seen (`Cache-Purge-Code`): " + ", ".join(sorted(c or "(none)" for c in codes)))
    if ends:
        w("- cache entries at the end of each size (expected = N + the related-shape pages when the mixed scenario ran): "
          + ", ".join(f"{n:,}→{f:,}" + ("" if f == ends_want.get(n, n) else f" **MISMATCH (expected {ends_want.get(n, n):,})**") for n, f in sorted(ends.items())))
    w("")
    print("\n".join(out))

    if a.html:
        write_html(a.html, "\n".join(out), G, ns, temps, methods, lat)
        print(f"\n(HTML written to {a.html})", file=sys.stderr)


# ----------------------------------------------------------------------------------------------- HTML + SVG charts
COL = {"http": "#2f7d32", "rg": "#c62828", "scan": "#1565c0"}


def svg_chart(title, series, xs, w=560, h=300):
    """series: {label: [(x, y)]}; log-y, linear-x. Pure SVG, no dependencies."""
    pts = [y for s in series.values() for _, y in s if y and y > 0]
    if not pts or len(xs) < 2:
        return ""
    ml, mr, mt, mb = 56, 14, 30, 42
    lo, hi = 10 ** math.floor(math.log10(min(pts))), 10 ** math.ceil(math.log10(max(pts)))
    x0, x1 = min(xs), max(xs)
    X = lambda x: ml + (x - x0) / (x1 - x0) * (w - ml - mr)
    Y = lambda y: mt + (1 - (math.log10(y) - math.log10(lo)) / (math.log10(hi) - math.log10(lo))) * (h - mt - mb)
    o = [f'<svg viewBox="0 0 {w} {h}" width="{w}" height="{h}" role="img" aria-label="{html.escape(title)}" font-family="system-ui,sans-serif" font-size="11">',
         f'<text x="{w / 2}" y="16" text-anchor="middle" font-size="13" font-weight="600" fill="currentColor">{html.escape(title)}</text>']
    d = int(math.log10(lo))
    while 10 ** d <= hi:
        y = Y(10 ** d)
        o.append(f'<line x1="{ml}" x2="{w - mr}" y1="{y:.1f}" y2="{y:.1f}" stroke="currentColor" stroke-opacity=".15"/>'
                 f'<text x="{ml - 6}" y="{y + 4:.1f}" text-anchor="end" fill="currentColor" fill-opacity=".7">{10 ** d:g} ms</text>')
        d += 1
    for x in xs:
        o.append(f'<text x="{X(x):.1f}" y="{h - mb + 16}" text-anchor="middle" fill="currentColor" fill-opacity=".7">{x:,}</text>')
    o.append(f'<text x="{(ml + w - mr) / 2}" y="{h - 6}" text-anchor="middle" fill="currentColor" fill-opacity=".7">cached files</text>')
    for lab, s in series.items():
        s = [(x, y) for x, y in s if y and y > 0]
        if not s:
            continue
        c = COL.get(lab.split()[0], "#555")
        dash = ' stroke-dasharray="5 3"' if "cold" in lab else ""
        o.append(f'<polyline fill="none" stroke="{c}" stroke-width="2"{dash} points="{" ".join(f"{X(x):.1f},{Y(y):.1f}" for x, y in s)}"/>')
        for x, y in s:
            o.append(f'<circle cx="{X(x):.1f}" cy="{Y(y):.1f}" r="3" fill="{c}"/>')
    lx = ml + 8
    for i, lab in enumerate(series):
        c = COL.get(lab.split()[0], "#555")
        o.append(f'<rect x="{lx}" y="{mt + 4 + i * 14}" width="10" height="3" fill="{c}"/>'
                 f'<text x="{lx + 14}" y="{mt + 9 + i * 14}" fill="currentColor">{html.escape(lab)}</text>')
    o.append("</svg>")
    return "".join(o)


def write_html(path, md, G, ns, temps, methods, lat):
    charts = []
    for sc, pick in (("hit", "min"), ("miss", "min"), ("mixed", "min"), ("mixed", "max")):
        ts = [k[3] for k in G if k[2] == sc]
        if not ts:
            continue
        tt = min(ts) if pick == "min" else max(ts)
        if pick == "max" and tt == min(ts):
            continue
        series = {}
        for temp in temps:
            for m in methods:
                series[f"{m} {temp}"] = [(n, med(lat(n, temp, sc, tt, m))) for n in ns if lat(n, temp, sc, tt, m)]
        charts.append(svg_chart(f"{sc}: median latency, {tt} target{'s' if tt > 1 else ''} (log scale)", series, ns))
    body = html.escape(md)
    page = f"""<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purge Single benchmark</title>
<style>:root{{color-scheme:light dark;--bg:#fff;--fg:#1b1b1b}}@media(prefers-color-scheme:dark){{:root{{--bg:#16181b;--fg:#e6e6e6}}}}
body{{background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif;max-width:1000px;margin:2rem auto;padding:0 1rem}}
.charts{{display:flex;flex-wrap:wrap;gap:1rem}}pre{{white-space:pre-wrap;font:13px/1.45 ui-monospace,monospace}}</style>
<h1>Purge Single benchmark</h1><div class="charts">{''.join(charts)}</div><pre>{body}</pre>"""
    open(path, "w").write(page)


if __name__ == "__main__":
    main()
