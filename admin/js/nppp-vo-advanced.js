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
 *  - The count on the Preload MISS button (rows that are MISS and not waiting for a preload).
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

    var dt = null;
    var byUrl = {};        // encoded URL (data-url) -> <tr>
    var byDisplay = {};    // text of the URL cell   -> <tr>
    var info = {};         // encoded URL -> { state, at }
    var awaiting = {};     // encoded URL -> deadline (ms)
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
        if (seconds < 60) { return Math.round(seconds) + 's'; }
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
        dt.rows().every(function () {
            var node = this.node();
            if (!node) { return; }
            var $tr = $(node);
            var enc = $tr.find('.nppp-purge-btn').attr('data-url');
            var disp = $tr.find('td.nppp-url').text().trim();
            if (enc) { byUrl[enc] = $tr; }
            if (disp) { byDisplay[disp] = $tr; }
        });
    }

    function paint(url, key, age) {
        var $tr = byUrl[url];
        var st = STATES[key] || STATES.UNKNOWN;
        if (!$tr || !$tr.length) { return; }

        var $status = $tr.find('td.nppp-status');
        var $age = $tr.find('td.nppp-vo-age');

        $status.removeClass(ALL_CLASSES).addClass(st.cls)
            .attr('title', text(st.tip))
            .empty().append($('<strong>').text(st.text));

        $age.text((key === 'HIT' || key === 'STALE') ? fmtAge(age) : '—');

        if (dt) {
            if ($status[0]) { dt.cell($status[0]).invalidate('dom'); }
            if ($age[0]) { dt.cell($age[0]).invalidate('dom'); }
        }
    }

    function updateMissButton() {
        var n = 0;
        $.each(info, function (url, i) {
            if (i.state === 'MISS' && !awaiting[url] && byUrl[url]) { n++; }
        });
        var $wrap = $('.nppp-preload-miss-wrap');
        $wrap.find('.nppp-vo-miss-count').text(n ? '(' + n + ')' : '');
        if (n > 0) { $wrap.show(); } else { $wrap.hide(); }
    }

    /* ---- lookup ---------------------------------------------------------------------------------------- */

    // Applies one answer; returns the state that is now shown.
    function apply(url, r) {
        var state = (r && typeof r.state === 'string') ? r.state : 'UNKNOWN';

        if (state === 'HIT' || state === 'STALE' || state === 'PASS') {
            delete awaiting[url];
            info[url] = { state: state, at: Date.now() };
            paint(url, state, r.age);
        } else if (state === 'MISS') {
            if (awaiting[url] && Date.now() < awaiting[url]) {
                info[url] = { state: 'PROGRESS', at: Date.now() };   // still warming: keep PROGRESS
            } else {
                delete awaiting[url];
                info[url] = { state: 'MISS', at: Date.now() };
                paint(url, 'MISS');
            }
        } else {
            info[url] = { state: 'UNKNOWN', at: Date.now() };
            // PROGRESS is limited to AWAIT_MS even when Varnish never answers: stop waiting and polling.
            if (awaiting[url] && Date.now() >= awaiting[url]) { delete awaiting[url]; }
            if (!awaiting[url]) { paint(url, 'UNKNOWN'); }
        }
        return state;
    }

    function failChunk(chunk) {
        $.each(chunk, function (_, url) { apply(url, { state: 'UNKNOWN' }); });
    }

    // Asks Varnish for the given URLs (chunks of 50, one after the other). onChunk(doneCount) is optional.
    // Resolves with the last server status ('ok' | 'unsupported' | ...), never rejects.
    function lookup(urls, force, onChunk) {
        var d = $.Deferred();
        var gen = generation;
        var chunks = [];
        var idx = 0;
        var done = 0;
        var status = 'ok';

        for (var i = 0; i < urls.length; i += CHUNK) { chunks.push(urls.slice(i, i + CHUNK)); }

        (function next() {
            if (idx >= chunks.length || gen !== generation) {
                d.resolve(status);
                return;
            }
            var chunk = chunks[idx];

            $.ajax({
                url: AJAX_URL,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'nppp_vo_probe',
                    _wpnonce: V.probe_nonce,
                    urls: chunk,
                    force: (force && idx === 0) ? '1' : '0'
                }
            }).done(function (resp) {
                if (gen !== generation) { return; }
                if (resp && resp.success && resp.data && $.isArray(resp.data.results) && resp.data.results.length === chunk.length) {
                    status = resp.data.status || 'ok';
                    $.each(chunk, function (k, url) { apply(url, resp.data.results[k]); });
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
                    for (var j = idx; j < chunks.length; j++) { failChunk(chunks[j]); }
                    d.resolve(status);
                    return;
                }
                next();
            });
        })();

        return d.promise();
    }

    function report(status) {
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
        $.each(urls, function (_, url) { if (!info[url]) { paint(url, 'PENDING'); } });
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
        $.each(urls, function (_, url) {
            if (!byUrl[url]) { return; }
            awaiting[url] = deadline;
            info[url] = { state: 'PROGRESS', at: Date.now() };
            paint(url, 'PROGRESS');
        });
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
            $.each(verify, function (_, url) {
                delete awaiting[url];
                info[url] = { state: 'PENDING', at: Date.now() };
                paint(url, 'PENDING');
            });
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
        $.each(urls, function (_, url) { if (!awaiting[url]) { paint(url, 'PENDING'); } });

        var total = urls.length;
        notice(text('checkedOf').replace('%1$s', '0').replace('%2$s', String(total)));

        lookup(urls, true, function (done) {
            if (done < total) {
                notice(text('checkedOf').replace('%1$s', String(done)).replace('%2$s', String(total)));
            }
        }).done(function (status) {
            if (status === 'ok') { notice(text('checkedAll'), false, 3000); } else { notice(text(status) || text('error'), true); }
            updateMissButton();
        }).always(function () {
            $btn.prop('disabled', false).removeClass('nppp-premium-refresh-spinning');
        });
    });

    /* ---- init ------------------------------------------------------------------------------------------- */

    function init() {
        generation++;
        clearTimeout(pollTimer);
        clearTimeout(drawTimer);
        pollTimer = null;
        drawTimer = null;
        awaiting = {};
        info = {};
        byUrl = {};
        byDisplay = {};
        dt = null;

        var $t = $(TABLE);
        if (!$t.length || !$.fn.dataTable || !$.fn.dataTable.isDataTable($t)) { return; }

        dt = $t.DataTable();
        buildIndex();

        $t.off('draw.dt.npppvo').on('draw.dt.npppvo', function () {
            clearTimeout(drawTimer);
            drawTimer = setTimeout(probeVisible, 120);
        });

        probeVisible();
    }
})(window.jQuery);
