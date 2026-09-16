/**
 * Repeat Offenders — row-aligned bar chart for Nginx Cache Purge Preload
 * Description: Renders a dependency-free bar list, next to the Repeat
 *              Offenders table. Fail2Ban Jail Monitoring Tab.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */
(function (window, document) {
    'use strict';

    function nppp_f2b_obar_color_for(count, maxCount) {
        var t = maxCount > 0 ? count / maxCount : 0;
        var from = [248, 179, 52];
        var to = [234, 25, 25];
        var r = Math.round(from[0] + (to[0] - from[0]) * t);
        var g = Math.round(from[1] + (to[1] - from[1]) * t);
        var b = Math.round(from[2] + (to[2] - from[2]) * t);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    function nppp_f2b_obar_label_text(ip) {
        var s = String(ip);
        return s.length > 40 ? s.slice(0, 39) + '\u2026' : s;
    }

    var nppp_f2b_obar_root      = null;
    var nppp_f2b_obar_container = null;
    var nppp_f2b_obar_head_el   = null;
    var nppp_f2b_obar_row_els   = [];
    var nppp_f2b_obar_resize_t  = null;
    var nppp_f2b_obar_resize_bound = false;

    // Finds the paired table (rendered server-side, same $recidive array)
    // and copies its real row heights onto the chart's head spacer and
    // data rows, in order. Silently no-ops if the table isn't there.
    function nppp_f2b_obar_sync_heights() {
        if (!nppp_f2b_obar_container) {
            return;
        }

        var wrap = nppp_f2b_obar_container.closest('.nppp-f2b-repeat');
        if (!wrap) {
            return;
        }

        var table = wrap.querySelector('.nppp-f2b-repeat-list table.nppp-f2b-table');
        if (!table) {
            return;
        }

        var theadRow = table.querySelector('thead tr');
        if (theadRow && nppp_f2b_obar_head_el) {
            nppp_f2b_obar_head_el.style.height = theadRow.offsetHeight + 'px';
        }

        var bodyRows = table.querySelectorAll('tbody tr');
        for (var i = 0; i < nppp_f2b_obar_row_els.length; i++) {
            if (bodyRows[i]) {
                nppp_f2b_obar_row_els[i].style.height = bodyRows[i].offsetHeight + 'px';
            }
        }
    }

    function nppp_f2b_obar_bind_resize() {
        if (nppp_f2b_obar_resize_bound) {
            return;
        }
        nppp_f2b_obar_resize_bound = true;

        window.addEventListener('resize', function () {
            clearTimeout(nppp_f2b_obar_resize_t);
            nppp_f2b_obar_resize_t = setTimeout(nppp_f2b_obar_sync_heights, 150);
        });
    }

    window.nppp_f2b_render_offenders_chart = function (containerSelector, data) {
        var container = document.querySelector(containerSelector);
        if (!container) {
            return;
        }

        container.innerHTML = '';
        nppp_f2b_obar_root      = null;
        nppp_f2b_obar_container = container;
        nppp_f2b_obar_head_el   = null;
        nppp_f2b_obar_row_els   = [];

        if (!data || !data.length) {
            var empty = document.createElement('p');
            empty.className = 'nppp-obar-empty';
            empty.textContent = (window.nppp_f2b_obar_i18n && window.nppp_f2b_obar_i18n.noData)
                ? window.nppp_f2b_obar_i18n.noData
                : 'No repeat offenders in this window.';
            container.appendChild(empty);
            return;
        }

        var bansLabel = (window.nppp_f2b_obar_i18n && window.nppp_f2b_obar_i18n.bansLabel)
            ? window.nppp_f2b_obar_i18n.bansLabel
            : 'bans';

        var maxCount = 0;
        for (var i = 0; i < data.length; i++) {
            if (data[i].count > maxCount) {
                maxCount = data[i].count;
            }
        }

        var root = document.createElement('div');
        root.className = 'nppp-f2b-obar-inner';

        var head = document.createElement('div');
        head.className = 'nppp-obar-head';
        head.textContent = '\u00A0';
        root.appendChild(head);
        nppp_f2b_obar_head_el = head;

        for (var j = 0; j < data.length; j++) {
            var row = data[j];
            var count = Math.max(0, parseInt(row.count, 10) || 0);
            var pct = maxCount > 0 ? Math.max(3, Math.round((count / maxCount) * 100)) : 3;
            var color = nppp_f2b_obar_color_for(count, maxCount);

            var rowEl = document.createElement('div');
            rowEl.className = 'nppp-obar-row';
            rowEl.title = row.ip + ' \u2014 ' + count.toLocaleString() + ' ' + bansLabel;

            var ipEl = document.createElement('code');
            ipEl.className = 'nppp-obar-ip';
            ipEl.textContent = nppp_f2b_obar_label_text(row.ip);
            rowEl.appendChild(ipEl);

            var trackEl = document.createElement('div');
            trackEl.className = 'nppp-obar-track';

            var barEl = document.createElement('div');
            barEl.className = 'nppp-obar-bar';
            barEl.style.width = pct + '%';
            barEl.style.background = color;
            trackEl.appendChild(barEl);
            rowEl.appendChild(trackEl);

            var countEl = document.createElement('span');
            countEl.className = 'nppp-obar-count';
            countEl.textContent = count.toLocaleString();
            rowEl.appendChild(countEl);

            root.appendChild(rowEl);
            nppp_f2b_obar_row_els.push(rowEl);
        }

        container.appendChild(root);
        nppp_f2b_obar_root = root;

        // Two rAF ticks: first lets this row's own layout commit, second
        // guarantees the sibling table has a final, stable layout to
        // measure — avoids reading a stale offsetHeight mid-reflow.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                nppp_f2b_obar_sync_heights();
            });
        });

        nppp_f2b_obar_bind_resize();
    };

    window.nppp_f2b_destroy_offenders_chart = function () {
        if (nppp_f2b_obar_root && nppp_f2b_obar_root.parentNode) {
            nppp_f2b_obar_root.parentNode.removeChild(nppp_f2b_obar_root);
        }
        nppp_f2b_obar_root      = null;
        nppp_f2b_obar_container = null;
        nppp_f2b_obar_head_el   = null;
        nppp_f2b_obar_row_els   = [];
    };
})(window, document);
