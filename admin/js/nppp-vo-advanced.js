/**
 * Advanced tab scripts for Nginx Cache Purge Preload (Varnish-only mode)
 * Description: Shows live HIT / MISS from Varnish in the Advanced tab table.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * The table itself is built and driven by fastcgi-cache-purge-and-preload-nginx.js. This file does nothing
 * unless the table carries data-nppp-vo="1" (rendered by nppp_premium_html_vo()), and it never changes the
 * shared script: it listens to the AJAX calls the shared script already makes (jQuery ajaxSuccess runs after
 * the shared success callback) and then replaces the Nginx-style status of the affected rows with what
  * Varnish says (nppp_vo_probe, a read-only lookup: nothing is fetched, cached or purged).
 *
 * What it adds to the table:
 *  - Status and Age of each row. Only the rows on screen are asked (pages of 50), and a status younger
 *    than 30 s is not asked again on a page change.
 *  - "Check all" asks every URL of the list and makes the server forget an earlier "VCL without the probe
 *    block" verdict.
 *  - The count on the Preload MISS button (rows that are MISS and not waiting for a preload). It is made from
 *    the statuses this script has seen, so it reads "(12+)" while some rows were never asked and "(12)" once
 *    every row has an answer. The statuses survive a table reload (tab switch, Refresh) for KEEP_MS, so the
 *    count does not start from zero every time the table is rebuilt.
 *  - Purge buttons stay enabled on every row (a Varnish PURGE is idempotent; the shared script disables them).
 *  - A notice line above the table for lookup problems.
 *
 * Status: HIT | STALE | MISS | PASS (answers of Varnish), PROGRESS (a preload was sent, waiting for Varnish
 * to cache it, max 60 s), … (checking), ? (Varnish did not answer or the URL can not be looked up).
 * MISS also covers pages that are not cacheable at all (hit-for-miss).
 */
(function ($) {
    'use strict';

    if (!$ || !window.nppp_admin_data || !window.nppp_vo_data) {
        return;
    }

    var V = window.nppp_vo_data;
    var T = V.i18n || {};
    var AJAX_URL = window.nppp_admin_data.ajaxurl;
    var CHUNK = 50;               // server limit per lookup request
    var FRESH_MS = 30000;         // a status younger than this is not asked again on a page change
    var AWAIT_MS = 60000;         // how long a preloaded URL may stay PROGRESS
    var KEEP_MS = 600000;         // a status survives a table reload (tab switch, Refresh) this long
    var POLL_MS = 5000;

    var TABLE = '#nppp-premium-table[data-nppp-vo="1"]';

    var STATES = {
        HIT:      { cls: 'is-hit',      text: 'HIT',      tip: 'hit' },
        STALE:    { cls: 'is-stale',    text: 'STALE',    tip: 'stale' },
        MISS:     { cls: 'is-miss',     text: 'MISS',     tip: 'miss' },
        PASS:     { cls: 'is-pass',     text: 'PASS',     tip: 'pass' },
        PROGRESS: { cls: 'is-progress', text: 'PROGRESS', tip: 'progress' },
        PENDING:  { cls: 'is-pending',  text: '…',   tip: 'checking' },
        UNKNOWN:  { cls: 'is-unknown',  text: '?',        tip: 'unknown' }
    };
    var ALL_CLASSES = 'is-hit is-miss is-stale is-pass is-progress is-pending is-unknown';
    var ALL_CLASS_LIST = ALL_CLASSES.split(' ');

    var dt = null;
    var byUrl = {};        // encoded URL (data-url) -> <tr>
    var byDisplay = {};    // text of the URL cell   -> <tr>
    var rowIx = {};        // encoded URL -> DataTables row index (the table never gains or loses rows while it lives)
    // info and awaiting belong to the page, not to the table: the shared script rebuilds the table on every visit
    // of the tab, and what was learned about the URLs is still true a few minutes later (see restore()).
    var info = {};         // encoded URL -> { state, at, age }  (age: seconds, only HIT / STALE, as of "at")
    var awaiting = {};     // encoded URL -> deadline (ms)
    var fullRun = 0;       // token of the Check all run that is in progress (0 = none)
    var pollTimer = null;
    var drawTimer = null;
    var generation = 0;    // bumped on every table load: late answers of an older table are dropped

    function text(key) {
        return typeof T[key] === 'string' ? T[key] : '';
    }

    function fmtAge(seconds) {
        if (typeof seconds !== 'number' || !isFinite(seconds) || seconds < 0) {
            return '—';
        }
        if (seconds < 60) { return Math.floor(seconds) + 's'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + 'm'; }
        if (seconds < 86400) { return Math.floor(seconds / 3600) + 'h'; }
        return Math.floor(seconds / 86400) + 'd';
    }

    /* ---- notice ---------------------------------------------------------------------------------------- */

    var noticeTimer = null;

    function notice(msg, warn, autoHideMs) {
        var $n = $('#nppp-vo-probe-notice');
        if (!$n.length) { return; }
        clearTimeout(noticeTimer);
        if (!msg) {
            $n.hide().text('');
            return;
        }
        $n.toggleClass('is-warning', !!warn).text(msg).show();
        if (autoHideMs) {
            noticeTimer = setTimeout(function () { $n.fadeOut(300); }, autoHideMs);
        }
    }

    /* ---- table ----------------------------------------------------------------------------------------- */

    function buildIndex() {
        byUrl = {};
        byDisplay = {};
        rowIx = {};
        dt.rows().every(function () {
            var node = this.node();
            if (!node) { return; }
            var $tr = $(node);
            var enc = $tr.find('.nppp-purge-btn').attr('data-url');
            var disp = $tr.find('td.nppp-url').text().trim();
            if (enc) { byUrl[enc] = $tr; rowIx[enc] = this.index(); }
            if (disp) { byDisplay[disp] = $tr; }
        });
    }

    // Tells DataTables that the Status / Age cells of these rows changed, so sorting and searching see the new
    // text. ONE call for any number of rows: invalidating cell by cell costs a pass over the whole table each time
    // (thousands of rows made the page freeze). Only DataTables' cached sort / search data depends on it; the
    // cells themselves are already painted.
    function commit(batch) {
        if (!dt || !batch.length) { return; }
        try {
            dt.rows(batch).invalidate('dom');
        } catch (e) {
            // cosmetic: the table was reloaded meanwhile
        }
    }

    // Paints one row. With a batch (array) the row is only collected: the caller commit()s the batch once.
    function paint(url, key, age, batch) {
        var $tr = byUrl[url];
        var st = STATES[key] || STATES.UNKNOWN;
        if (!$tr || !$tr.length) { return; }

        var status = $tr[0].querySelector('td.nppp-status');
        var ageCell = $tr[0].querySelector('td.nppp-vo-age');

        if (status) {
            status.classList.remove.apply(status.classList, ALL_CLASS_LIST);
            status.classList.add(st.cls);
            status.setAttribute('title', text(st.tip));
            var strong = status.firstChild;
            if (strong && strong.nodeName === 'STRONG' && status.childNodes.length === 1) {
                strong.textContent = st.text;
            } else {
                status.textContent = '';
                strong = document.createElement('strong');
                strong.textContent = st.text;
                status.appendChild(strong);
            }
        }
        if (ageCell) {
            ageCell.textContent = (key === 'HIT' || key === 'STALE') ? fmtAge(age) : '—';
        }

        if (!dt || rowIx[url] === undefined) { return; }
        if (batch) {
            batch.push(rowIx[url]);
        } else {
            commit([rowIx[url]]);
        }
    }

    // Runs fn after the browser had a chance to draw what was just changed (a hidden tab runs no animation
    // frames, so a timer is the fallback).
    function afterPaint(fn) {
        var done = false;
        function go() {
            if (done) { return; }
            done = true;
            fn();
        }
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { setTimeout(go, 0); });
        }
        setTimeout(go, 150);
    }

    // The Preload All MISS button sends every row that shows MISS (the shared script reads the table, whatever page
    // or filter is on screen), so the count is made over every row too. A row that was never asked, or whose last
    // answer was not a definite one, has no status: while such rows exist the count is only a lower bound and says
    // so with a "+" (and a tooltip), instead of looking final and then jumping on the next page or filter.
    function updateMissButton() {
        var total = 0, known = 0, miss = 0;
        $.each(byUrl, function (url) {
            total++;
            var i = info[url];
            if (!i || i.state === 'UNKNOWN' || i.state === 'PENDING') { return; }
            known++;
            if (i.state === 'MISS' && !awaiting[url]) { miss++; }
        });
        var partial = known < total;
        var $wrap = $('.nppp-preload-miss-wrap');
        $wrap.find('.nppp-vo-miss-count').text(miss ? '(' + miss + (partial ? '+' : '') + ')' : '');
        var $b = $wrap.find('#nppp-preload-miss-all');
        if (partial) {
            var tip = text('missPartial') || '%1$s of %2$s URLs checked so far. Press Check all for the full count.';
            $b.attr('title', tip.replace('%1$s', String(known)).replace('%2$s', String(total)));
        } else {
            $b.removeAttr('title');
        }
        if (miss > 0) { $wrap.show(); } else { $wrap.hide(); }
    }

    // Age of a HIT / STALE row now: the age Varnish reported plus the time that passed since.
    function ageNow(i) {
        if (!i || (i.state !== 'HIT' && i.state !== 'STALE') || typeof i.age !== 'number') { return undefined; }
        return i.age + Math.max(0, Date.now() - i.at) / 1000;
    }

    // Does this Status cell show what `st` says?
    function shows(cell, st) {
        if (cell.textContent !== st.text) { return false; }
        for (var k = 0; k < ALL_CLASS_LIST.length; k++) {
            if (cell.classList.contains(ALL_CLASS_LIST[k]) !== (ALL_CLASS_LIST[k] === st.cls)) { return false; }
        }
        return true;
    }

    // `info` is the one truth about what a row shows. Something else can repaint rows behind its back: after
    // "Preload All MISS" the shared script turns every row that was MISS when the button was pressed into PROGRESS
    // in one go, including rows that Varnish had already cached (those stayed PROGRESS for good, and the rows that
    // were still warming got the wrong colour). Every draw puts the rows that disagree back to what was learned.
    function reconcile() {
        if (!dt || fullRun) { return; }
        var fix = [];
        $.each(info, function (url, i) {
            var $tr = byUrl[url];
            if (!$tr) { return; }
            var cell = $tr[0].querySelector('td.nppp-status');
            if (!cell) { return; }
            var key = (awaiting[url] && Date.now() < awaiting[url]) ? 'PROGRESS' : i.state;
            if (!shows(cell, STATES[key] || STATES.UNKNOWN)) { paint(url, key, ageNow(i), fix); }
        });
        commit(fix);
        updateMissButton();
    }

    /* ---- lookup ---------------------------------------------------------------------------------------- */

    // Applies one answer; returns the state that is now shown.
    function apply(url, r, batch) {
        var state = (r && typeof r.state === 'string') ? r.state : 'UNKNOWN';

        if (state === 'HIT' || state === 'STALE' || state === 'PASS') {
            delete awaiting[url];
            info[url] = { state: state, at: Date.now(), age: (typeof r.age === 'number') ? r.age : undefined };
            paint(url, state, r.age, batch);
        } else if (state === 'MISS') {
            if (awaiting[url] && Date.now() < awaiting[url]) {
                info[url] = { state: 'PROGRESS', at: Date.now() };   // still warming: keep PROGRESS
            } else {
                delete awaiting[url];
                info[url] = { state: 'MISS', at: Date.now() };
                paint(url, 'MISS', undefined, batch);
            }
        } else {
            info[url] = { state: 'UNKNOWN', at: Date.now() };
            // PROGRESS is limited to AWAIT_MS even when Varnish never answers: stop waiting and polling.
            if (awaiting[url] && Date.now() >= awaiting[url]) { delete awaiting[url]; }
            if (!awaiting[url]) { paint(url, 'UNKNOWN', undefined, batch); }
        }
        return state;
    }

    function failChunk(chunk, batch) {
        var own = batch ? null : [];
        $.each(chunk, function (_, url) { apply(url, { state: 'UNKNOWN' }, batch || own); });
        if (own) { commit(own); }
    }

    // Asks Varnish for the given URLs (chunks of 50, one after the other). onChunk(doneCount) is optional.
    // full = true marks the chunks as one Check all run: the server adds them up for the Status tab card.
    // Resolves with the last server status ('ok' | 'unsupported' | ...), never rejects.
    // Resolves with 'aborted' when the table was reloaded meanwhile: callers must not report it.
    function lookup(urls, force, onChunk, full) {
        var d = $.Deferred();
        var gen = generation;
        var run = full ? (Date.now().toString(36) + Math.random().toString(36).slice(2, 10)) : '';
        var chunks = [];
        var idx = 0;
        var done = 0;
        var status = 'ok';

        for (var i = 0; i < urls.length; i += CHUNK) { chunks.push(urls.slice(i, i + CHUNK)); }

        (function next() {
            if (gen !== generation) {
                d.resolve('aborted');
                return;
            }
            if (idx >= chunks.length) {
                d.resolve(status);
                return;
            }
            var chunk = chunks[idx];
            var payload = {
                action: 'nppp_vo_probe',
                _wpnonce: V.probe_nonce,
                urls: chunk,
                force: (force && idx === 0) ? '1' : '0'
            };
            if (run) {
                payload.full_run = run;
                payload.full_idx = idx;
            }

            $.ajax({
                url: AJAX_URL,
                type: 'POST',
                dataType: 'json',
                data: payload
            }).done(function (resp) {
                if (gen !== generation) { return; }
                if (resp && resp.success && resp.data && $.isArray(resp.data.results) && resp.data.results.length === chunk.length) {
                    status = resp.data.status || 'ok';
                    var batch = [];
                    $.each(chunk, function (k, url) { apply(url, resp.data.results[k], batch); });
                    commit(batch);
                } else {
                    status = 'error';
                    failChunk(chunk);
                }
            }).fail(function () {
                if (gen !== generation) { return; }
                status = 'error';
                failChunk(chunk);
            }).always(function () {
                done += chunk.length;
                if (typeof onChunk === 'function' && gen === generation) { onChunk(done); }
                idx++;
                // The same answer would come back for every chunk: stop at the first problem.
                if (status !== 'ok') {
                    var rest = [];
                    for (var j = idx; j < chunks.length; j++) { failChunk(chunks[j], rest); }
                    commit(rest);
                    d.resolve(status);
                    return;
                }
                next();
            });
        })();

        return d.promise();
    }

    function report(status) {
        if (status === 'aborted') { return; }
        if (status === 'ok') {
            notice('');
        } else {
            notice(text(status) || text('error'), true);
        }
        updateMissButton();
    }

    function visibleUrls() {
        var out = [];
        if (!dt) { return out; }
        $(dt.rows({ page: 'current' }).nodes()).each(function () {
            var enc = $(this).find('.nppp-purge-btn').attr('data-url');
            if (enc) { out.push(enc); }
        });
        return out;
    }

    function probeVisible() {
        var now = Date.now();
        var urls = $.grep(visibleUrls(), function (url) {
            var i = info[url];
            return !i || (now - i.at > FRESH_MS && !awaiting[url]);
        });
        if (!urls.length) { return; }
        var pending = [];
        $.each(urls, function (_, url) { if (!info[url]) { paint(url, 'PENDING', undefined, pending); } });
        commit(pending);
        lookup(urls, false).done(report);
    }

    /* ---- preload follow-up ----------------------------------------------------------------------------- */

    function schedulePoll(delay) {
        if (pollTimer) { return; }
        var gen = generation;
        pollTimer = setTimeout(function () {
            pollTimer = null;
            if (gen !== generation) { return; }
            var urls = Object.keys(awaiting);
            if (!urls.length) { updateMissButton(); return; }
            lookup(urls, false).done(function (status) {
                report(status);
                if (gen === generation && Object.keys(awaiting).length) { schedulePoll(POLL_MS); }
            });
        }, delay || POLL_MS);
    }

    function awaitWarm(urls) {
        var deadline = Date.now() + AWAIT_MS;
        var warming = [];
        $.each(urls, function (_, url) {
            if (!byUrl[url]) { return; }
            awaiting[url] = deadline;
            info[url] = { state: 'PROGRESS', at: Date.now() };
            paint(url, 'PROGRESS', undefined, warming);
        });
        commit(warming);
        updateMissButton();
        schedulePoll(3000);
    }

    /* ---- reactions to the shared script's AJAX calls --------------------------------------------------- */

    function onPurged(query, resp) {
        var data = resp.data || {};
        var primary = query.get('cache_url') || '';
        var urls = [];

        if (primary && byUrl[primary]) { urls.push(primary); }

        $.each($.isArray(data.affected_urls) ? data.affected_urls : [], function (_, disp) {
            var $tr = byDisplay[String(disp || '').trim()];
            var enc = $tr && $tr.find('.nppp-purge-btn').attr('data-url');
            if (enc && $.inArray(enc, urls) === -1) { urls.push(enc); }
        });
        if (!urls.length) { return; }

        // Varnish PURGE is idempotent: a purge button stays usable on every row (the shared script disables it).
        $.each(urls, function (_, url) {
            byUrl[url].find('.nppp-purge-btn').prop('disabled', false).removeClass('disabled');
        });

        var warm = [];
        var verify = [];
        $.each(urls, function (_, url) {
            var autoWarm = (url === primary) ? !!data.primary_preload : !!data.preload_auto;
            (autoWarm ? warm : verify).push(url);
        });

        if (warm.length) { awaitWarm(warm); }
        if (verify.length) {
            var checking = [];
            $.each(verify, function (_, url) {
                delete awaiting[url];
                info[url] = { state: 'PENDING', at: Date.now() };
                paint(url, 'PENDING', undefined, checking);
            });
            commit(checking);
            var gen = generation;
            setTimeout(function () {
                if (gen !== generation) { return; }
                lookup(verify, false).done(function (status) {
                    report(status);
                    var stillCached = $.grep(verify, function (url) {
                        return info[url] && (info[url].state === 'HIT' || info[url].state === 'STALE');
                    });
                    if (status === 'ok' && stillCached.length) { notice(text('stillCached'), true, 12000); }
                });
            }, 600);
        }
    }

    $(document).ajaxSuccess(function (event, xhr, settings) {
        var raw = (settings && typeof settings.data === 'string') ? settings.data : '';
        if (raw === '') { return; }

        var q;
        try { q = new URLSearchParams(raw); } catch (e) { return; }
        var action = q.get('action');

        if (action === 'nppp_load_premium_content') {
            init();
            return;
        }
        if (!dt) { return; }

        var resp = xhr && xhr.responseJSON;
        if (!resp || !resp.success) { return; }

        if (action === 'nppp_purge_cache_premium') {
            onPurged(q, resp);
        } else if (action === 'nppp_preload_cache_premium') {
            var url = q.get('cache_url') || '';
            if (url && byUrl[url]) { awaitWarm([url]); }
        } else if (action === 'nppp_preload_miss_batch') {
            awaitWarm(q.getAll('urls[]'));
        }
    });

    /* ---- buttons ---------------------------------------------------------------------------------------- */

    $(document).on('click', '#nppp-vo-check-all', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled') || !dt) { return; }

        var urls = Object.keys(byUrl);
        if (!urls.length) { return; }

        $btn.prop('disabled', true).addClass('nppp-premium-refresh-spinning');
        var token = ++fullRun;

        var total = urls.length;
        notice(text('checkedOf').replace('%1$s', '0').replace('%2$s', String(total)));

        // Marking thousands of rows takes a moment: let the browser draw the spinner and the counter first.
        var gen = generation;
        afterPaint(function () {
            if (gen !== generation) {
                // the table was reloaded meanwhile: nothing to check any more
                if (fullRun === token) { fullRun = 0; }
                $btn.prop('disabled', false).removeClass('nppp-premium-refresh-spinning');
                return;
            }

            var pending = [];
            $.each(urls, function (_, url) { if (!awaiting[url]) { paint(url, 'PENDING', undefined, pending); } });
            commit(pending);

            lookup(urls, true, function (done) {
                if (done < total) {
                    notice(text('checkedOf').replace('%1$s', String(done)).replace('%2$s', String(total)));
                }
            }, true).done(function (status) {
                if (status === 'aborted') { return; }
                if (status === 'ok') { notice(text('checkedAll'), false, 3000); } else { notice(text(status) || text('error'), true); }
                updateMissButton();
            }).always(function () {
                if (fullRun === token) { fullRun = 0; }
                $btn.prop('disabled', false).removeClass('nppp-premium-refresh-spinning');
            });
        });
    });

    /* ---- init ------------------------------------------------------------------------------------------- */

    // The shared script has just built a fresh table: every row says "…". Puts back what this page already learned
    // about these URLs (info / awaiting survive the reload), so the rows and the Preload MISS count do not start
    // from nothing on every visit of the tab. Anything older than KEEP_MS, or without a definite answer, is dropped
    // and asked again when it is on screen.
    function restore() {
        var now = Date.now();
        var back = [];

        $.each(info, function (url, i) {
            var waiting = !!(awaiting[url] && now < awaiting[url]);
            if (!byUrl[url] || now - i.at > KEEP_MS || i.state === 'UNKNOWN' || i.state === 'PENDING' ||
                (i.state === 'PROGRESS' && !waiting)) {
                delete info[url];
                delete awaiting[url];
                return;
            }
            paint(url, waiting ? 'PROGRESS' : i.state, ageNow(i), back);
        });
        $.each(awaiting, function (url) {
            if (!info[url]) { delete awaiting[url]; }
        });
        commit(back);
    }

    function init() {
        generation++;
        clearTimeout(pollTimer);
        clearTimeout(drawTimer);
        pollTimer = null;
        drawTimer = null;
        byUrl = {};
        byDisplay = {};
        rowIx = {};
        dt = null;

        var $t = $(TABLE);
        if (!$t.length || !$.fn.dataTable || !$.fn.dataTable.isDataTable($t)) { return; }

        dt = $t.DataTable();
        buildIndex();
        restore();
        updateMissButton();

        $t.off('draw.dt.npppvo').on('draw.dt.npppvo', function () {
            clearTimeout(drawTimer);
            drawTimer = setTimeout(function () {
                reconcile();
                probeVisible();
            }, 120);
        });

        if (Object.keys(awaiting).length) { schedulePoll(1000); }
        probeVisible();
    }
})(window.jQuery);
