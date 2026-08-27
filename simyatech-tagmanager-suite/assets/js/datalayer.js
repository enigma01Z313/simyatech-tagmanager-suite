/**
 * SimyaTech Tag Manager Suite
 *
 * Watches the Bookly booking form and pushes the funnel into the GTM dataLayer.
 * Bookly is never modified: every step is observed through the public AJAX
 * actions it already fires, and through its rendered markup.
 */
(function ($, window, document) {
    'use strict';

    var cfg = window.STMSData || {};
    window.dataLayer = window.dataLayer || [];

    // ------------------------------------------------------------------ flow

    /**
     * One id per page view. Every event of the booking process running on this
     * page carries it; reloading or navigating away starts a new one.
     */
    var flowId = createFlowId();

    function createFlowId() {
        var chars = '0123456789abcdefghijklmnopqrstuvwxyz',
            crypto = window.crypto || window.msCrypto,
            out = '',
            i;

        if (crypto && crypto.getRandomValues) {
            var bytes = new Uint8Array(32);
            crypto.getRandomValues(bytes);
            for (i = 0; i < 32; i++) {
                out += chars.charAt(bytes[i] % chars.length);
            }
        } else {
            for (i = 0; i < 32; i++) {
                out += chars.charAt(Math.floor(Math.random() * chars.length));
            }
        }

        return out;
    }

    // ----------------------------------------------------------------- steps

    var STEPS = ['init', 'time', 'cart', 'details', 'payment', 'done'];

    // Bookly AJAX action -> tracked step. Its extras / repeat / next-time
    // actions are deliberately absent: they are not part of this funnel.
    var STEP_BY_ACTION = {
        bookly_render_service: 'init',
        bookly_render_time: 'time',
        bookly_render_cart: 'cart',
        bookly_render_details: 'details',
        bookly_render_payment: 'payment',
        bookly_render_complete: 'done'
    };

    // Actions that change the cart total, so the cached snapshot goes stale.
    var REFRESH_ACTIONS = [
        'bookly_coupons_apply_coupon',
        'bookly_gift_cards_apply_gift_card',
        'bookly_pro_apply_tips',
        'bookly_deposit_payments_apply_payment_method',
        'bookly_cart_drop_item'
    ];

    var GATEWAY_MAP = {
        card: 'stripe',
        stripe: 'stripe',
        cloud_stripe: 'stripe',
        paypal: 'paypal',
        paypal_checkout: 'paypal'
    };

    // ------------------------------------------------------------ page facts

    /**
     * The hidden markers are the source of truth; the localized config is the
     * fallback for the window before they are parsed into the DOM.
     */
    function pageMeta() {
        var page = cfg.page || {};

        return {
            slug: hiddenValue('stms-page-slug', page.page_slug),
            baseSlug: hiddenValue('stms-base-slug', page.base_slug),
            language: hiddenValue('stms-page-language', page.language)
        };
    }

    function hiddenValue(id, fallback) {
        var el = document.getElementById(id);

        return el && el.value !== '' ? el.value : (fallback || '');
    }

    function pathSegments() {
        return window.location.pathname.split('/').filter(function (segment) {
            return segment !== '';
        });
    }

    /** Current URL without the domain. */
    function entryPage() {
        return window.location.pathname + window.location.search;
    }

    /**
     * Therapist single pages live under /<segment>/<name>, and that last part is
     * already the slugified therapist name.
     */
    function therapistFromUrl() {
        var segments = pathSegments(),
            marker = cfg.therapistSegment || 'team',
            index = segments.indexOf(marker);

        if (index === -1 || !segments[index + 1]) {
            return '';
        }

        return slugify(segments[index + 1]);
    }

    function slugify(value) {
        try {
            value = decodeURIComponent(value);
        } catch (e) {
            // keep the raw value when it is not valid percent-encoding
        }

        return String(value)
            .toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function bookingSource(meta) {
        if (therapistFromUrl() !== '') {
            return 'therapist_page';
        }
        if (meta.baseSlug && meta.baseSlug === (cfg.appointmentSlug || 'appointment')) {
            return 'appointment_page';
        }

        return 'other';
    }

    // ------------------------------------------------------------ dataLayer

    function push(payload) {
        const url = window.location.href;
        if (url.includes("staging")) {
            console.log('%c ------------', 'color: red; font-size: 40px');
            console.log(payload);
        }
        
        window.dataLayer.push(payload);

        if (cfg.debug && window.console && window.console.log) {
            window.console.log('[SimyaTech TMS]', payload);
        }
    }

    function pushStepView(step) {
        var index = STEPS.indexOf(step);

        if (index === -1) {
            return;
        }

        push({
            flow_id: flowId,
            event: 'bookly_step_view',
            step: step,
            step_index: index
        });
    }

    var bookingStartSent = false;

    function pushBookingStart() {
        if (bookingStartSent) {
            return;
        }
        bookingStartSent = true;

        var meta = pageMeta();

        push({
            flow_id: flowId,
            event: 'booking_start',
            entry_page: entryPage(),
            therapist: therapistFromUrl(),
            language: meta.language,
            source: bookingSource(meta)
        });
    }

    // Keyed by payment method, so a retry with a different method is reported
    // once per method instead of once per click.
    var paymentStartedSent = {};

    function pushPaymentStarted(formId, method) {
        var key = method || 'unknown';

        if (paymentStartedSent[key]) {
            return;
        }
        paymentStartedSent[key] = true;

        whenState(formId).done(function (state) {
            state = state || {};

            push({
                flow_id: flowId,
                event: 'bookly_payment_started',
                payment_method: method,
                total: typeof state.total === 'number' ? state.total : 0,
                currency: state.currency || '',
                coupon: state.coupon || '',
                sessions: state.sessions || 0
            });
        });
    }

    var bookingCompletedSent = false;

    function pushBookingCompleted(formId, orderToken) {
        if (bookingCompletedSent) {
            return;
        }
        bookingCompletedSent = true;

        $.post(cfg.ajaxurl, {
            action: 'stms_order_data',
            nonce: cfg.nonce,
            form_id: formId || '',
            order_token: orderToken || ''
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                bookingCompletedSent = false;
                return;
            }

            var data = response.data;

            push({
                flow_id: flowId,
                event: 'bookly_booking_completed',
                booking_id: data.booking_id,
                status: data.status,
                payment_status: data.payment_status,
                order_id: data.order_id,
                sessions_in_order: data.sessions_in_order,
                order_total: data.order_total,
                session_value: data.session_value,
                currency: data.currency,
                service: data.service,
                therapist: data.therapist,
                slot_start: data.slot_start,
                payment_method: data.payment_method,
                coupon: data.coupon
            });
        }).fail(function () {
            bookingCompletedSent = false;
        });
    }

    // --------------------------------------------------------- cart snapshot

    // Totals, coupon and session count come from the server: on the payment
    // step nothing is saved yet, and the rendered prices are locale-formatted.
    var stateCache = {};

    function refreshState(formId) {
        var request = $.Deferred();

        $.post(cfg.ajaxurl, {
            action: 'stms_flow_state',
            nonce: cfg.nonce,
            form_id: formId || ''
        }).done(function (response) {
            request.resolve(response && response.success ? response.data : null);
        }).fail(function () {
            request.resolve(null);
        });

        stateCache[formId] = request.promise();

        return stateCache[formId];
    }

    function whenState(formId) {
        return stateCache[formId] || refreshState(formId);
    }

    // ------------------------------------------------------------ observers

    /**
     * Bookly renders every step over admin-ajax, so its own requests are the
     * step signal — including the very first one after the form boots.
     */
    $(document).ajaxSuccess(function (event, xhr, settings) {
        var params = requestParams(settings),
            action = params.action || '';

        if (action.indexOf('bookly') !== 0) {
            return;
        }

        var formId = params.form_id || '',
            response = xhr.responseJSON,
            step = STEP_BY_ACTION[action];

        if (step) {
            if (!response || response.success === false) {
                return;
            }
            // Bookly skips the payment step when nothing is payable.
            if (step === 'payment' && response.disabled) {
                return;
            }

            pushStepView(step);

            if (step === 'payment') {
                refreshState(formId);
            } else if (step === 'done') {
                pushBookingCompleted(formId, response.bookly_order);
            }

            return;
        }

        if ($.inArray(action, REFRESH_ACTIONS) !== -1) {
            refreshState(formId);
        }
    });

    /**
     * Bookly sends every step but payment as a GET, and jQuery moves the
     * payload of a GET into the query string and then deletes settings.data.
     * So the action has to be read from the URL too, not only from the body --
     * otherwise the payment step is the only one ever seen.
     */
    function requestParams(settings) {
        var params = {},
            url = String((settings && settings.url) || ''),
            data = settings && settings.data,
            mark = url.indexOf('?');

        if (mark !== -1) {
            parseQuery(url.slice(mark + 1), params);
        }

        if (typeof data === 'string') {
            parseQuery(data, params);
        } else if (data && typeof data === 'object') {
            if (typeof data.forEach === 'function' && typeof data.append === 'function') {
                // FormData
                data.forEach(function (value, key) {
                    params[key] = value;
                });
            } else {
                $.each(data, function (key, value) {
                    params[key] = value;
                });
            }
        }

        return params;
    }

    function parseQuery(query, params) {
        String(query).split('&').forEach(function (pair) {
            if (!pair) {
                return;
            }
            var index = pair.indexOf('='),
                key = index === -1 ? pair : pair.slice(0, index),
                value = index === -1 ? '' : pair.slice(index + 1);

            params[decodeParam(key)] = decodeParam(value);
        });
    }

    function decodeParam(value) {
        try {
            return decodeURIComponent(String(value).replace(/\+/g, ' '));
        } catch (e) {
            return String(value);
        }
    }

    /**
     * Both listeners capture: Bookly stops propagation on its own handlers, so
     * a delegated bubble listener would never see these clicks.
     */
    document.addEventListener('click', function (event) {
        var $slot = $(event.target).closest('button.bookly-hour');

        if ($slot.length && !$slot.prop('disabled')) {
            pushBookingStart();
        }
    }, true);

    document.addEventListener('click', function (event) {
        // The gateway wrapper only exists on the payment step.
        var $next = $(event.target).closest('.bookly-gateway-buttons .bookly-js-next-step');

        if (!$next.length || $next.prop('disabled')) {
            return;
        }

        var $form = $next.closest('.bookly-form');

        pushPaymentStarted(String($form.data('form_id') || ''), selectedGateway($form));
    }, true);

    function selectedGateway($form) {
        var $radio = $('.bookly-js-payment:checked', $form);

        if (!$radio.length) {
            $radio = $('.bookly-js-payment', $form).first();
        }

        var gateway = String($radio.data('gateway') || $radio.val() || '');

        return GATEWAY_MAP[gateway] || gateway;
    }
})(jQuery, window, document);
