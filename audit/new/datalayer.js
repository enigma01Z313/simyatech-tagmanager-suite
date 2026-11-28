/**
 * SimyaTech Tag Manager Suite — Daroon v1.3.1
 *
 * Watches the Bookly booking form and pushes the funnel into the GTM dataLayer.
 * Bookly is never modified: every step is observed through the public AJAX
 * actions it already fires, and through its rendered markup.
 *
 * v1.3.1 changes
 * - `source` renamed to `booking_source` so it can never be mistaken for a
 *   traffic-source / campaign field by a tag or by GA4.
 * - New `view_booking_form` event fired when the Bookly form appears.
 * - `booking_start` now fires on the first real interaction (time-slot click
 *   or first Next click), not on form render.
 * - `init` removed from the step list; `bookly_step_view` only reports real
 *   Bookly steps.
 * - `purchase` now pushes an `ecommerce` object with items, a guaranteed
 *   transaction id, value, currency, coupon and full booking value.
 * - `customer_id` is no longer pushed to the dataLayer; logged-in users get
 *   `user_id` via gtag('set') and the server keeps the client-id join.
 * - Added `window.STMS.pushRefund()` for cancellation/refund pages.
 */
(function ($, window, document) {
    'use strict';

    // The listeners are installed once per page view. A second copy of the
    // asset (a caching / optimiser plugin re-emitting it, a theme enqueuing it
    // again) would otherwise register a second set of observers and double
    // every push.
    if (window.STMSTracker) {
        return;
    }
    window.STMSTracker = true;

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

    // `init` is deliberately absent: it is the form boot, not a funnel step.
    // `service` is also absent: on the appointment page it is the first screen
    // of the form, and it is captured by view_booking_form instead.
    var STEPS = ['time', 'cart', 'details', 'payment', 'done'];

    // Bookly AJAX action -> tracked step. Its extras / repeat / next-time
    // actions are deliberately absent: they are not part of this funnel.
    var STEP_BY_ACTION = {
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

    // ------------------------------------------------------------- identity

    /**
     * The Bookly customer behind this flow. A logged-in visitor is known from
     * the very first push; a guest only becomes identifiable once the details
     * step has handed Bookly an email / phone, so the id is looked up from
     * that step on and then rides along on every later push.
     *
     * We intentionally do NOT push customer_id to GA4. The server-side
     * stms_record_event table already stores the GA4 client id, so the booking
     * can be joined there without exposing a customer identifier as an event
     * parameter. Logged-in users get a proper user_id instead.
     */
    var identity = {
        customerId: String(cfg.customerId || ''),
        loggedIn: !!cfg.loggedIn
    };

    var customerLookup = null;

    function resolveCustomer(formId) {
        if (identity.customerId !== '' || customerLookup) {
            return;
        }

        customerLookup = $.post(cfg.ajaxurl, {
            action: 'stms_customer',
            nonce: cfg.nonce,
            form_id: formId || ''
        }).done(function (response) {
            var data = response && response.success ? response.data : null;

            if (data) {
                identity.customerId = data.customer_id ? String(data.customer_id) : '';
                identity.loggedIn = !!data.logged_in;
                setUserId(identity.customerId, identity.loggedIn);
            }
            // Nobody matched yet -- let a later step try again.
            if (identity.customerId === '') {
                customerLookup = null;
            }
        }).fail(function () {
            customerLookup = null;
        });
    }

    /**
     * Sets GA4's user_id for every hit after this point. This is the correct
     * place for a Bookly customer id: user_id is a user property, not an
     * event parameter, and it must only be sent for authenticated users.
     */
    function setUserId(id, loggedIn) {
        if (!loggedIn || !id || typeof window.gtag !== 'function') {
            return;
        }

        try {
            window.gtag('set', 'user_id', String(id));
        } catch (e) {
            // never let identity work break a booking
        }
    }

    function withIdentity(payload) {
        if (identity.customerId !== '' && identity.loggedIn) {
            payload.user_id = identity.customerId;
        }

        return payload;
    }

    // ----------------------------------------------------------- GA4 client

    /**
     * The GA4 client id, which GA4 never reports back inside an event: it is
     * asked of gtag when the measurement id is configured, and read out of the
     * _ga cookie otherwise. Both give the same value; the cookie is simply not
     * written until GA4 has run at least once.
     *
     * The callback always runs, with an empty string when nothing answered in
     * time, so a booking is never left unrecorded over a missing analytics id.
     */
    function gaClientId(callback) {
        var settled = false,
            timer;

        function settle(value) {
            if (settled) {
                return;
            }
            settled = true;
            window.clearTimeout(timer);
            callback(value || cookieClientId());
        }

        if (typeof window.gtag === 'function' && cfg.measurementId) {
            timer = window.setTimeout(function () {
                settle('');
            }, 1000);

            try {
                window.gtag('get', cfg.measurementId, 'client_id', settle);
                return;
            } catch (e) {
                settle('');
                return;
            }
        }

        settle('');
    }

    /** "_ga=GA1.1.1234567890.1234567890" -> "1234567890.1234567890" */
    function cookieClientId() {
        var match = String(document.cookie).match(/(?:^|;\s*)_ga=GA\d+\.\d+\.(\d+\.\d+)/);

        return match ? match[1] : '';
    }

    /**
     * Stores the booking against that GA4 client id, so the booking can later
     * be joined back to the session -- and the channel -- that produced it.
     * The server re-reads the booking itself; only what the browser alone knows
     * is sent.
     */
    function recordEvent(eventName, formId, orderToken) {
        gaClientId(function (clientId) {
            $.post(cfg.ajaxurl, {
                action: 'stms_record_event',
                nonce: cfg.nonce,
                form_id: formId || '',
                order_token: orderToken || '',
                flow_id: flowId,
                ga_client_id: clientId,
                event_name: eventName
            });
        });
    }

    // ------------------------------------------------------------ dataLayer

    function push(payload) {
        withIdentity(payload);

        var url = window.location.href;
        if (url.includes('staging')) {
            console.log('%c ------------', 'color: red; font-size: 40px');
            console.log(payload);
        }

        window.dataLayer.push(payload);

        if (cfg.debug && window.console && window.console.log) {
            window.console.log('[SimyaTech TMS]', payload);
        }
    }

    /**
     * Amounts always ride as numbers. The server is supposed to send numbers,
     * but Bookly locale formatting sometimes arrives as a string; parse it
     * rather than losing the whole revenue value.
     */
    function money(value) {
        if (typeof value === 'number') {
            return isFinite(value) ? value : 0;
        }

        var parsed = parseFloat(String(value == null ? '' : value).replace(/[^0-9.\-]/g, ''));

        return isFinite(parsed) ? parsed : 0;
    }

    function normalizeCurrency(value) {
        var currency = String(value || 'USD').toUpperCase();

        return /^[A-Z]{3}$/.test(currency) ? currency : 'USD';
    }

    // ------------------------------------------------------- form / funnel

    var formViewSent = false;

    function pushViewBookingForm() {
        if (formViewSent) {
            return;
        }
        formViewSent = true;

        var meta = pageMeta();

        push({
            flow_id: flowId,
            event: 'view_booking_form',
            entry_page: entryPage(),
            therapist: therapistFromUrl(),
            content_language: meta.language,
            booking_source: bookingSource(meta)
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
            content_language: meta.language,
            booking_source: bookingSource(meta)
        });
    }

    var lastStep = '';
    var hasInteracted = false;

    function pushStepView(step) {
        var index = STEPS.indexOf(step);

        // On therapist pages Bookly renders the time step as soon as the form
        // appears. That is a form impression, not a funnel step. Wait for the
        // first real click before reporting the time step; view_booking_form
        // already covers the impression.
        if (step === 'time' && !hasInteracted) {
            return;
        }

        if (index === -1) {
            return;
        }

        // Bookly re-renders the step it is already on whenever that markup has
        // to change -- a cart line that failed to save, a declined card, a
        // gateway switch -- and each of those renders is another AJAX success.
        // Only a move to a different step is a step view, so the same step is
        // never reported twice in a row.
        if (step === lastStep) {
            return;
        }

        lastStep = step;

        push({
            flow_id: flowId,
            event: 'bookly_step_view',
            step: step,
            step_index: index
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
                subtotal: money(state.subtotal),
                total: money(state.total),
                currency: normalizeCurrency(state.currency),
                coupon: state.coupon || '',
                coupon_discount: money(state.coupon_discount),
                sessions: Number(state.sessions) || 0
            });
        });
    }

    var bookingResultSent = false;
    var bookingPendingSent = false;

    function pushBookingResult(formId, orderToken) {
        if (bookingResultSent) {
            return;
        }
        bookingResultSent = true;

        $.post(cfg.ajaxurl, {
            action: 'stms_order_data',
            nonce: cfg.nonce,
            form_id: formId || '',
            order_token: orderToken || ''
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                bookingResultSent = false;
                return;
            }

            var data = response.data;
            var meta = pageMeta();
            var sessions = Number(data.sessions_in_order) || 1;
            var value = money(data.order_total);
            var sessionValue = money(data.session_value);
            var currency = normalizeCurrency(data.currency);
            var transactionId = String(data.order_id || data.booking_id || '').replace(/^\s+|\s+$/g, '');
            // The existing stms_order_data endpoint only returns confirmed
            // orders (status=approved AND payment_status=completed), but it
            // does not send a dedicated `confirmed` flag. Keep support for an
            // explicit flag if the plugin adds one later; otherwise derive it
            // from the server-verified statuses.
            var confirmed;
            if (typeof data.confirmed === 'boolean') {
                confirmed = data.confirmed;
            } else {
                var serverStatus = String(data.status || '').toLowerCase();
                var serverPaymentStatus = String(data.payment_status || '').toLowerCase();
                confirmed = serverStatus === 'approved'
                    && (serverPaymentStatus === 'completed' || serverPaymentStatus === 'paid');
            }

            // A pending booking may later be confirmed on the same done page
            // (gateway return, retry, bank transfer). Allow that confirmed
            // render through, but never push the pending event twice.
            if (!confirmed) {
                if (bookingPendingSent) {
                    bookingResultSent = false;
                    return;
                }
                bookingPendingSent = true;
                bookingResultSent = false;
            }

            // The saved order names its customer, so this event and every push
            // after it carry the id even when nothing resolved it earlier.
            if (data.customer_id) {
                identity.customerId = String(data.customer_id);
            }
            setUserId(identity.customerId, identity.loggedIn);

            // The done step is reached by a booking still waiting on its
            // gateway just as much as by a paid one, so the server decides
            // which of the two this is.
            var eventName = confirmed ? 'bookly_booking_completed' : 'bookly_booking_pending';

            var payload = {
                flow_id: flowId,
                event: eventName,
                booking_id: data.booking_id,
                status: data.status,
                payment_status: data.payment_status,
                order_id: data.order_id,
                transaction_id: transactionId,
                sessions_in_order: sessions,
                subtotal: money(data.subtotal),
                order_total: value,
                // GA4 knows value as the revenue for purchase; keep the full
                // booking value separate so deposits/bundles stay analysable.
                value: value,
                booking_value: sessionValue > 0 ? sessionValue * sessions : value,
                session_value: sessionValue,
                currency: currency,
                service: data.service,
                therapist: data.therapist,
                slot_start: data.slot_start,
                payment_method: data.payment_method,
                coupon: data.coupon,
                coupon_discount: money(data.coupon_discount),
                booking_source: bookingSource(meta),
                content_language: meta.language
            };

            // GA4 purchase ecommerce object. The GTM purchase tag is set to
            // "Data Layer" ecommerce, so this is what produces items, revenue
            // and the transaction id. Only attach it to confirmed bookings:
            // pending bookings must not create revenue.
            if (confirmed && transactionId !== '') {
                payload.ecommerce = {
                    transaction_id: transactionId,
                    value: value,
                    currency: currency,
                    coupon: data.coupon || '',
                    items: [{
                        item_id: slugify(data.service || 'therapy-session') || 'therapy-session',
                        item_name: data.service || 'Therapy session',
                        item_category: data.therapist || 'unknown',
                        price: sessions > 0 ? value / sessions : value,
                        quantity: sessions
                    }]
                };
            }

            push(payload);

            recordEvent(eventName, formId, orderToken);
        }).fail(function () {
            bookingResultSent = false;
        });
    }

    /**
     * Public helper for cancellation/refund pages or any custom code that can
     * run after a refund is recorded. It must be called exactly once per
     * refund, with the ORIGINAL transaction id.
     *
     * For admin-side refunds that never touch the browser, use the GA4
     * Measurement Protocol instead (see 03_DEVELOPER_HANDOFF.md).
     */
    function pushRefund(data) {
        data = data || {};

        var transactionId = String(data.transaction_id || data.order_id || '').replace(/^\s+|\s+$/g, '');
        var value = money(data.value != null ? data.value : data.order_total);
        var currency = normalizeCurrency(data.currency);

        if (transactionId === '' || value <= 0) {
            return;
        }

        push({
            flow_id: flowId,
            event: 'bookly_booking_refunded',
            transaction_id: transactionId,
            value: value,
            currency: currency,
            booking_id: data.booking_id || '',
            ecommerce: {
                transaction_id: transactionId,
                value: value,
                currency: currency
            }
        });
    }

    window.STMS = window.STMS || {};
    window.STMS.pushRefund = pushRefund;

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
     * view_booking_form belongs to the form appearing, not to the visitor
     * doing something with it. The form comes from a shortcode, so it is either
     * in the markup at ready or inserted later by a script. Both are watched,
     * and the observer drops off as soon as it has seen one.
     */
    function watchForBooklyForm() {
        if ($('.bookly-form').length) {
            pushViewBookingForm();

            return;
        }

        if (typeof MutationObserver !== 'function') {
            return;
        }

        var observer = new MutationObserver(function () {
            if (!$('.bookly-form').length) {
                return;
            }

            observer.disconnect();
            pushViewBookingForm();
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    $(watchForBooklyForm);

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

        if (!step) {
            if ($.inArray(action, REFRESH_ACTIONS) !== -1) {
                refreshState(formId);
            }

            return;
        }

        if (!response || response.success === false) {
            return;
        }

        // Bookly skips the payment step when nothing is payable.
        if (step === 'payment' && response.disabled) {
            return;
        }

        pushStepView(step);

        // From the details step on, Bookly knows who a guest is.
        if (step === 'details' || step === 'payment' || step === 'done') {
            resolveCustomer(formId);
        }

        if (step === 'payment') {
            refreshState(formId);
        } else if (step === 'done') {
            pushBookingResult(formId, response.bookly_order);
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
            hasInteracted = true;
            pushBookingStart();
        }
    }, true);

    /**
     * Fallback for the appointment page: the service step has no time slot to
     * click, so the first Next click is the first real booking interaction.
     */
    document.addEventListener('click', function (event) {
        var $next = $(event.target).closest('.bookly-js-next-step');

        if ($next.length && !$next.prop('disabled')) {
            hasInteracted = true;
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
