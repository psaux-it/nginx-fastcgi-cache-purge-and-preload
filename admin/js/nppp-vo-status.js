/**
 * Status tab in Varnish-only mode: "Check now" in the health summary at the top of the card.
 *
 * The card is rendered by PHP from stored state and never waits on Varnish. This button asks the server to
 * run the read-only lookups (nppp_vo_status_check: the home page plus a sample of the last Preload All; nothing
 * is fetched, cached or purged) and swaps in the refreshed card (summary, measure boxes and sections change
 * together), which the server renders with the same code as the first paint. A section the person opened or
 * closed by hand keeps that state across the swap, except that a section that needs attention (data-state warn
 * or bad) always stays open. The Status tab is loaded by AJAX, so the handlers are delegated.
 */
(function ($) {
    'use strict';

    if (!$ || !window.nppp_admin_data || !window.nppp_vo_status_data) {
        return;
    }

    var D = window.nppp_vo_status_data;
    var T = D.i18n || {};
    var ROOT = '#nppp-vo-root';
    var busy = false;
    var touched = {};   // section id -> open (true) or closed (false), as the person last left it

    function text(key) {
        return typeof T[key] === 'string' ? T[key] : '';
    }

    function message(msg) {
        $(ROOT + ' .nppp-vo-checkmsg').text(msg || '');
    }

    // A click on a section title (mouse, Enter or Space) is the person's choice. The browser flips the state
    // after this handler, so the new state is the opposite of the current one.
    $(document).on('click', ROOT + ' details.nppp-vo-acc > summary', function () {
        var section = this.parentNode;
        if (section && section.id) {
            touched[section.id] = !section.open;
        }
    });

    $(document).on('click', '#nppp-vo-check-now', function (e) {
        e.preventDefault();
        if (busy) {
            return;
        }
        busy = true;

        var $btn = $(this);
        var $label = $btn.find('.nppp-vo-btntext');
        var label = $label.text();

        $btn.prop('disabled', true).addClass('is-busy');
        $label.text(text('checking'));
        message('');
        $(ROOT).attr('aria-busy', 'true');

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
            if (resp && resp.success && resp.data && typeof resp.data.card === 'string') {
                try {
                    // The answer is the whole card (own server markup); the button inside it is the fresh one.
                    var $card = $($.parseHTML(resp.data.card.trim()));
                    $card.find('details.nppp-vo-acc').each(function () {
                        var attention = this.getAttribute('data-state') === 'warn' || this.getAttribute('data-state') === 'bad';
                        if (!attention && this.id && Object.prototype.hasOwnProperty.call(touched, this.id)) {
                            this.open = touched[this.id];
                        }
                    });
                    $(ROOT).replaceWith($card);
                    return;
                } catch (err) {
                    // fall through: the old card stays, the button comes back and the error is shown
                }
            }
            $btn.prop('disabled', false).removeClass('is-busy');
            $label.text(label);
            message(resp && typeof resp.data === 'string' && resp.data !== '' ? resp.data : text('error'));
        }).fail(function () {
            $btn.prop('disabled', false).removeClass('is-busy');
            $label.text(label);
            message(text('error'));
        }).always(function () {
            busy = false;
            $(ROOT).removeAttr('aria-busy');
        });
    });
})(window.jQuery);
