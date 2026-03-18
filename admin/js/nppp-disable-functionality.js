/**
 * Unsupported-environment guards for Nginx Cache Purge Preload
 * Description: Disables plugin actions in admin when required runtime conditions are not met.
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Disable NPP functionality in unsupported environments
(function($) {
    'use strict';

    $(document).ready(function() {
        // Select the buttons in the admin bar with unique names
        var npppAllButtons = {
            npppPreload: $('#wp-admin-bar-preload-cache'),
            npppPurge: $('#wp-admin-bar-purge-cache'),
            npppStatus: $('#wp-admin-bar-fastcgi-cache-status'),
            npppAdvanced: $('#wp-admin-bar-fastcgi-cache-advanced')
        };

        // Function to disable a single button
        function npppDisableButtonSingle(npppButton) {
            if (npppButton.length) {
                npppButton.off('click');
                $(document).off('click', `#${npppButton.attr('id')}`);

                npppButton.find('a')
                    .removeAttr('href')
                    .css({
                        'opacity': '0.5',
                        'cursor': 'not-allowed'
                    })
                    .on('click', function(event) {
                        event.preventDefault();
                    });
            }
        }

        // Function to disable all buttons at once
        function npppDisableButtonAll() {
            $.each(npppAllButtons, function(_, npppBtn) {
                npppDisableButtonSingle(npppBtn);
            });
        }

        // Disable all buttons
        npppDisableButtonAll();

        // Disable WP dashboard widget buttons
        $('.nppp-action-button').addClass('disabled').removeAttr('href');

        // Check if we're on the plugin settings page and disable plugin functionality
        if ($('#nppp-nginx-tabs').length > 0) {
            // Disable Purge and Preload buttons on settings page
            $('.nppp-button').addClass('disabled').removeAttr('href');

            // Disable status and advanced tabs
            $('#nppp-nginx-tabs').tabs({
                disabled: [1, 2]
            });

            // Disable the schedule checkbox
            $('#nginx_cache_schedule').prop('disabled', true);

            // disable the rest api checkbox
            $('#nginx_cache_api').prop('disabled', true);

            // disable the schedule set cron button
            $('#nginx-cache-schedule-set').prop('disabled', true);

            // disable auto preload checkbox
            $('#nginx_cache_auto_preload').prop('disabled', true);

            // disable preload proxy checkbox
            $('#nginx_cache_preload_enable_proxy').prop('disabled', true);

            // disable preload mobile checkbox
            $('#nginx_cache_auto_preload_mobile').prop('disabled', true);

            // disable send mail checkbox
            $('#nginx_cache_send_mail').prop('disabled', true);

            // disable auto purge checkbox
            $('#nginx_cache_purge_on_update').prop('disabled', true);

            // disable reset regex button
            $('#nginx-regex-reset-defaults').prop('disabled', true);

            // disable reset file extension button
            $('#nginx-extension-reset-defaults').prop('disabled', true);

            // disable reset cache key regex button
            $('#nginx-key-regex-reset-defaults').prop('disabled', true);

            // disable clear logs button
            $('#clear-logs-button').prop('disabled', true);

            // disable generate API key button
            $('#api-key-button').prop('disabled', true);

            // Related purge checkboxes (lock + detach)
            function npppLockCheckbox(name){
                const $cb = $(`input[type="checkbox"][name="${name}"]`);
                if (!$cb.length) return;

                // force on, grey out, make non-interactive
                $cb.prop('checked', true)
                    .prop('disabled', true)
                    .attr({'aria-disabled':'true', 'title':'Locked in unsupported environment'})
                    .off('click change')
                    .on('click.npppLock change.npppLock', function(e){ e.preventDefault(); return false; });

                // grey the label/row too (optional)
                $cb.closest('label, .form-table tr, p').css({ opacity:.5, cursor:'not-allowed' });

                // disabled inputs don't submit; ensure "yes" still posts
                const $form = $cb.closest('form');
                if ($form.length && !$form.find(`input[type="hidden"][name="${name}"]`).length){
                    $('<input>', {type:'hidden', name, value:'yes'}).appendTo($form);
                }
            }

            // call for each setting
            npppLockCheckbox('nginx_cache_settings[nppp_related_include_home]');
            npppLockCheckbox('nginx_cache_settings[nppp_related_include_category]');
            npppLockCheckbox('nginx_cache_settings[nppp_related_preload_after_manual]');
            npppLockCheckbox('nginx_cache_settings[nppp_related_apply_manual]');

            // Add hidden mirror so disabled controls still submit a value
            function ensureHiddenMirror($form, name, value){
                if (!$form.length || !name) return;
                const sel = `input[type="hidden"][name="${name}"]`;
                if (!$form.find(sel).length){
                    $('<input>', { type:'hidden', name, value }).appendTo($form);
                } else {
                    $form.find(sel).val(value);
                }
            }

            // Disable the pctnorm radiogroup cleanly and preserve its value
            (function disablePctNorm(){
                const $fs = $('#nppp-pctnorm');
                if (!$fs.length) return;

                const $form = $fs.closest('form');
                const $radios = $fs.find('input[type="radio"]');
                const name = $radios.first().attr('name');
                const currentVal = $radios.filter(':checked').val();

                // visuals + semantics
                $fs.attr({'aria-disabled':'true'}).css({ opacity:.5, cursor:'not-allowed' });
                $fs.find('label, .nppp-segcontrol-thumb').css('pointer-events','none');

                // make non-interactive
                $radios.prop('disabled', true).attr('tabindex','-1').off('.nppp')
                    .on('click.nppp change.nppp', function(e){ e.preventDefault(); return false; });

                // hidden mirror for submit
                ensureHiddenMirror($form, name, currentVal);
            })();

            // Disable Cloudflare APO sync toggle and preserve current value
            (function disableCloudflareApoSync(){
                const $cloudflareToggle = $('#nppp_cloudflare_apo_sync');
                if (!$cloudflareToggle.length) return;

                const $form = $cloudflareToggle.closest('form');
                const name = $cloudflareToggle.attr('name');
                const currentVal = $cloudflareToggle.is(':checked') ? 'yes' : 'no';

                $cloudflareToggle
                    .prop('disabled', true)
                    .attr({'aria-disabled':'true', 'tabindex':'-1'})
                    .off('.nppp')
                    .on('click.nppp change.nppp', function(e){ e.preventDefault(); return false; });

                $cloudflareToggle
                    .closest('.nppp-onoffswitch-cloudflare')
                    .css({ opacity:.5, cursor:'not-allowed' })
                    .find('.nppp-onoffswitch-label-cloudflare')
                    .css({ 'pointer-events':'none', 'cursor':'not-allowed' });

                ensureHiddenMirror($form, name, currentVal);
            })();

            // Disable Redis Object Cache sync toggle and preserve current value
            (function disableRedisCacheSync(){
                const $redisToggle = $('#nppp_redis_cache_sync');
                if (!$redisToggle.length) return;

                const $form = $redisToggle.closest('form');
                const name = $redisToggle.attr('name');
                const currentVal = $redisToggle.is(':checked') ? 'yes' : 'no';

                $redisToggle
                    .prop('disabled', true)
                    .attr({'aria-disabled':'true', 'tabindex':'-1'})
                    .off('.nppp')
                    .on('click.nppp change.nppp', function(e){ e.preventDefault(); return false; });

                $redisToggle
                    .closest('.nppp-onoffswitch-redis')
                    .css({ opacity:.5, cursor:'not-allowed' })
                    .find('.nppp-onoffswitch-label-redis')
                    .css({ 'pointer-events':'none', 'cursor':'not-allowed' });

                ensureHiddenMirror($form, name, currentVal);
            })();

            // Disable HTTP purge fast-path toggle and sub-fields
            (function disableHttpPurge(){
                const $toggle = $('#nppp_http_purge_enabled');
                if (!$toggle.length) return;

                const $form     = $toggle.closest('form');
                const name      = $toggle.attr('name');
                const currentVal = $toggle.is(':checked') ? 'yes' : 'no';

                $toggle
                    .prop('disabled', true)
                    .attr({'aria-disabled':'true', 'tabindex':'-1'})
                    .off('.nppp')
                    .on('click.nppp change.nppp', function(e){ e.preventDefault(); return false; });

                $toggle
                    .closest('.nppp-onoffswitch-httppurge')
                    .css({ opacity:.5, cursor:'not-allowed' })
                    .find('.nppp-onoffswitch-label-httppurge')
                    .css({ 'pointer-events':'none', 'cursor':'not-allowed' });

                ensureHiddenMirror($form, name, currentVal);

                // Disable Test Connection button
                $('#nppp-test-http-purge').prop('disabled', true).css({ opacity:.5, cursor:'not-allowed' });

                // Disable sub-fields
                $('#nppp_http_purge_suffix, #nppp_http_purge_custom_url')
                    .prop('disabled', true)
                    .attr('readonly', 'readonly')
                    .css({ opacity:.5, cursor:'not-allowed' });
            })();

            // Disable watchdog toggle and preserve current value
            (function disableWatchdog(){
                const $watchdogToggle = $('#nginx_cache_watchdog');
                if (!$watchdogToggle.length) return;

                const $form = $watchdogToggle.closest('form');
                const name = $watchdogToggle.attr('name');
                const currentVal = $watchdogToggle.is(':checked') ? 'yes' : 'no';

                $watchdogToggle
                    .prop('disabled', true)
                    .attr({'aria-disabled':'true', 'tabindex':'-1'})
                    .off('.nppp')
                    .on('click.nppp change.nppp', function(e){ e.preventDefault(); return false; });

                $watchdogToggle
                    .closest('.nppp-onoffswitch-watchdog')
                    .css({ opacity:.5, cursor:'not-allowed' })
                    .find('.nppp-onoffswitch-label-watchdog')
                    .css({ 'pointer-events':'none', 'cursor':'not-allowed' });

                ensureHiddenMirror($form, name, currentVal);
            })();

            // Make REST API helper elements non-clickable.
            $('#nppp-api-key .nppp-tooltip, #nppp-purge-url .nppp-tooltip, #nppp-preload-url .nppp-tooltip').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // Ensure parent <p> containers are also non-clickable.
            $('#nppp-api-key, #nppp-purge-url, #nppp-preload-url').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            }).each(function() {
                $(this).off('click');
            });

            // style cron status heading
            $('.nppp-active-cron-heading').css({
                'opacity': '0.5',
                'cursor': 'not-allowed'
            });

            // disable main form submit button
            $('input[type="submit"][name="nppp_submit"].button-primary').prop('disabled', true);
        }
    });
})(jQuery);
