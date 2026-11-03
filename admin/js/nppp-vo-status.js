/**
 * Status tab in Varnish-only mode: "Check now" of the LIVE CACHE CHECK section.
 *
 * The card is rendered by PHP from stored state and never waits on Varnish. This button asks the server to
 * run the read-only lookups (nppp_vo_status_check: the home page plus a sample of the last Preload All; nothing
 * is fetched, cached or purged) and swaps in the refreshed section, which the server renders with the same
 * code as the first paint. The Status tab is loaded by AJAX, so the handler is delegated.
 */
(function ($) {
    'use strict';

    if (!$ || !window.nppp_admin_data || !window.nppp_vo_status_data) {
        return;
    }

    var D = window.nppp_vo_status_data;
    var T = D.i18n || {};
    var SECTION = '#nppp-vo-live';
    var busy = false;

    function text(key) {
        return typeof T[key] === 'string' ? T[key] : '';
    }

    function message(msg) {
        $(SECTION + ' .nppp-vo-checkmsg').text(msg || '');
    }

    $(document).on('click', '#nppp-vo-check-now', function (e) {
        e.preventDefault();
        if (busy) {
            return;
        }
        busy = true;

        var $btn = $(this);
        var label = $btn.text();
        $btn.prop('disabled', true).text(text('checking'));
        message('');
        $(SECTION).attr('aria-busy', 'true');

        $.ajax({
            url: window.nppp_admin_data.ajaxurl,
            type: 'POST',
            dataType: 'json',
            timeout: 60000,   // the server stops by itself: 10 s per lookup batch, two batches
            data: {
                action: 'nppp_vo_status_check',
                _wpnonce: D.nonce
            }
        }).done(function (resp) {
            if (resp && resp.success && resp.data && typeof resp.data.html === 'string') {
                // The answer is the whole section (own server markup); the button inside it is the fresh one.
                $(SECTION).replaceWith(resp.data.html);
                return;
            }
            $btn.prop('disabled', false).text(label);
            message(resp && typeof resp.data === 'string' && resp.data !== '' ? resp.data : text('error'));
        }).fail(function () {
            $btn.prop('disabled', false).text(label);
            message(text('error'));
        }).always(function () {
            busy = false;
            $(SECTION).removeAttr('aria-busy');
        });
    });
})(window.jQuery);
