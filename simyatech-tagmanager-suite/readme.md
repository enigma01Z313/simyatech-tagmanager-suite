# SimyaTech Tag Manager Suite

A small WordPress plugin that pushes the **Bookly** booking funnel into the
Google Tag Manager `dataLayer`, and marks every page with its slug and its
English "base" slug so GTM can tell pages apart across languages.

The plugin **never modifies Bookly**. It observes the AJAX actions Bookly
already fires, reads its rendered markup, and looks booking data up through
Bookly's own entity API.

## Requirements

* WordPress with Bookly (tested against Bookly 28.0) and jQuery.
* Optional: WPML or Polylang — needed only for the English base slug on
  translated pages.
* Optional: Bookly Coupons / Stripe / PayPal add-ons, for the coupon and
  payment-method values.

## Install

Copy the `simyatech-tagmanager-suite` folder into `wp-content/plugins/` and
activate it. There is no settings screen — behaviour is adjusted with the
filters listed at the bottom.

## Page markers

Every front-end page renders a hidden block (in `wp_body_open`, falling back to
`wp_footer`):

```html
<div id="stms-page-meta" class="stms-page-meta" style="display:none" aria-hidden="true">
    <input type="hidden" id="stms-page-slug"     class="stms-page-slug"     value="…">
    <input type="hidden" id="stms-base-slug"     class="stms-base-slug"     value="…">
    <input type="hidden" id="stms-page-language" class="stms-page-language" value="…">
</div>
```

* **page slug** — the current page's own slug.
* **base slug** — the slug of the page's **English** version:
  * on an English page it is that page's own slug,
  * on a translated page it is the slug of its English translation,
  * when the page has no English version it is **empty**.
* **language** — the current language code (`en`, `de`, `fa`, …).

The same three values are also exposed to JS as `STMSData.page`; the hidden
inputs win when both are present.

## flow_id

A 32-character id generated once per page view. Every event of the booking
process running on that page carries the same `flow_id`; reloading the page or
navigating to another page starts a new one.

Note: a payment gateway that redirects off-site (PayPal Standard) brings the
visitor back on a **new page load**, so the completion event of such a booking
carries a new `flow_id`.

## customer_id / user_id

Every push carries the Bookly customer id as `customer_id`, from the moment the
visitor is known:

* **Logged in** — the customer linked to the WordPress account is resolved on
  the server, so `customer_id` rides on the very first push of the page. The same
  value is also sent as `user_id`.
* **Guest** — nobody is identifiable until the details step has put an email /
  phone into the Bookly session, so the id is looked up from that step on and
  then joins every later push. Only `customer_id` is sent: a guest has no account,
  so there is no `user_id`.

A guest booking for the first time has no Bookly customer record at all until
Bookly saves the booking, so their `customer_id` appears with
`bookly_booking_completed` and not before. Pushes made while the visitor is
still unknown simply carry neither field.

✅ **The field used to be called `client_id`, and was renamed in 1.2.0.** GA4
owns that name for its own visitor id — a long decimal string like
`1234567890.1234567890` that GA4 attaches by itself — so a Bookly customer id
travelling under it shadowed the real one and would have shown the wrong value
in any custom dimension registered for `client_id`. The GA4 client id is not
pushed to the dataLayer at all; it is stored server-side, in the events table
below.

## The events table

`{prefix}daroon_booking_events` holds one row per booking, written when the
booking is reported:

| column | holds |
| --- | --- |
| `booking_id` | the booking the row belongs to — unique, so a reload or a retry updates the row instead of adding one |
| `order_id` | Bookly order id |
| `order_key` | `created_at` of the first appointment + `\|` + the customer's email — the reconciliation key, **server-side only** |
| `flow_id` | the flow that produced the booking |
| `customer_id` | Bookly customer |
| `ga_client_id` | ✅ the **real GA4 client id**, asked of `gtag('get', …)` when a measurement id is configured, otherwise read from the `_ga` cookie |
| `event_name` | which event was pushed — `bookly_booking_completed` or `bookly_booking_pending` |
| `status`, `payment_status`, `order_total`, `currency` | as reported |
| `event_sent` | the dataLayer push happened before the row was written, so this is `1` |

This is the bridge the channel/source report needs: GA4 never reports its own
client id back inside an event, and an email address must never be sent to GA4,
so the join between a booking and the session that produced it can only be made
here. The table is created on activation, and on the first load after an update
for a plugin upgraded in place.

An existing `ga_client_id` is never overwritten with an empty one, so a later
write from a context that could not read the cookie (a gateway return, say)
cannot erase what an earlier one stored.

## Events

### 1. `bookly_step_view` — every time a step renders

```js
dataLayer.push({
  flow_id: '…',
  event: 'bookly_step_view',
  step: 'payment',
  step_index: 4
});
```

Steps and their indexes: `init` 0, `time` 1, `cart` 2, `details` 3,
`payment` 4, `done` 5. Bookly's *extras* and *repeat* steps are not tracked.
When Bookly skips the payment step (nothing payable), no payment step view is
pushed.

✅ **The same step is never pushed twice in a row.** Bookly re-renders the step
it is already on whenever that markup has to change — a cart line that failed
to save, a declined card, a gateway switch — and every one of those re-renders
is another AJAX success. Only a move to a *different* step counts as a step
view, so a re-render of the current step is ignored. Going back and forward
again is a real move and is still reported. The tracker also installs its
listeners only once per page view, so a second copy of the script (a caching or
optimiser plugin re-emitting it) cannot double the pushes either.

`init` marks the form booting rather than the service step in particular: on a
therapist page the staff is pre-selected, so Bookly skips the service step and
boots straight into the time step. Whichever step Bookly renders first counts
as the boot, so `init` is pushed once per page view on every page carrying the
form — immediately before that first real step view.

### 2. `booking_start` — first time slot click of the flow

```js
dataLayer.push({
  flow_id: '…',
  event: 'booking_start',
  entry_page: '/team/farzaneh-bidari',
  therapist: 'farzaneh-bidari',
  language: 'en',
  source: 'therapist_page'
});
```

* `entry_page` — current URL without the domain (path + query string).
* `therapist` — on a therapist single page (`/team/<name>`) the slugified
  therapist name, otherwise empty.
* `source` — `therapist_page` when the URL is under `team/`,
  `appointment_page` when the page's **base slug** is `appointment`,
  otherwise `other`.

Fires once per flow, so clicking several slots does not repeat it.

### 3. `bookly_payment_started` — "next" on the payment step

```js
dataLayer.push({
  flow_id: '…',
  event: 'bookly_payment_started',
  payment_method: 'stripe',
  subtotal: 39.95,
  total: 0.8,
  currency: 'USD',
  coupon: 'DAROON23',
  coupon_discount: 39.15,
  sessions: 1
});
```

* `payment_method` — `stripe` or `paypal` (Bookly's `card` / `cloud_stripe`
  gateways are normalised to `stripe`). Other gateways keep their own slug
  (`local`, `free`, …).
* `subtotal` — cart value before any discount.
* `total` — what the visitor is about to pay, after every discount.
* `coupon_discount` — ✅ how much money the applied coupon takes off, `0` when
  no coupon is applied. Only the coupon: a gift card, a customer-group discount
  or the discounts add-on are not counted in it.
* `subtotal`, `total`, `currency`, `coupon`, `coupon_discount`, `sessions` come
  from the server rather than from the rendered price, which is
  locale-formatted. The snapshot is taken when the payment step renders and
  refreshed whenever a coupon, gift card, tips or deposit mode changes it.

Fires once per payment method per flow, so a failed card attempt retried with
the same method is not counted twice.

### 4. `bookly_booking_completed` — booking saved (done step)

```js
dataLayer.push({
  flow_id: '…',
  event: 'bookly_booking_completed',
  booking_id: 125,
  status: 'approved',
  payment_status: 'completed',
  order_id: '412',
  sessions_in_order: 1,
  subtotal: 39.95,
  order_total: 33,
  session_value: 33,
  currency: 'USD',
  service: 'Adults - Decision Making Problems',
  therapist: 'Farzaneh Bidari',
  slot_start: '2021-09-07T21:00:00',
  payment_method: 'stripe',
  coupon: '',
  coupon_discount: 6.95
});
```

Read back from the saved order:

| field | source |
| --- | --- |
| `booking_id` | id of the first customer appointment in the order |
| `customer_id` | Bookly customer the order belongs to (also fills in the id for a first-time guest) |
| `status` | its Bookly status (`approved`, `pending`, …) |
| `payment_status` | payment status (`completed`, `pending`, …) |
| `order_id` | ~~`created_at` of the booking + `|` + customer email~~ ✅ **corrected:** Bookly's own **order id**. The old composite carried the customer's raw email into GA4, which [Google's PII policy](https://support.google.com/analytics/answer/10374426) forbids outright. The order id is numeric, carries no personal data, and groups every session of a multi-session order under one value by itself. The `created_at\|email` key is still kept, in the `order_key` column of the events table, where the reconciliation query needs it. |
| `sessions_in_order` | booked sessions in the order (compound / collaborative services count once) |
| `subtotal` | ✅ order value before any discount, as Bookly stored it with the payment |
| `order_total` | payment total |
| `session_value` | ~~per-session price stored with the payment, or the total split evenly~~ ✅ **corrected:** always `order_total / sessions_in_order`. The per-item `service_price` Bookly stores with the payment is the service's *list* price, before any coupon, gift card or group discount, so reporting it here inflated revenue by the whole discount (a 0.80 order was reported as 39.95). |
| `service` | comma-separated service names (deduplicated) |
| `therapist` | staff full name(s) |
| `slot_start` | comma-separated session start times, `Y-m-dTH:i:s` |
| `payment_method` | `stripe` / `paypal` / gateway slug |
| `coupon` | applied coupon code, empty when none |
| `coupon_discount` | ✅ money the coupon took off, `0` when no coupon. Bookly stores the coupon's rule (percentage + fixed deduction) rather than the amount, so the amount is recomputed the way Bookly applied it: `subtotal − max(subtotal × (100 − percent) / 100 − deduction, 0)`. Coupon only — gift card, customer-group and add-on discounts are not counted. Exact whenever the coupon covers every item in the order. |

Fires once per flow — ✅ and only for a booking that is really booked and paid
for. See `bookly_booking_pending` below for the rest.

### 5. ✅ `bookly_booking_pending` — done step reached, booking not confirmed

Same payload as `bookly_booking_completed`, under a different event name:

```js
dataLayer.push({
  flow_id: '…',
  event: 'bookly_booking_pending',
  booking_id: 126,
  status: 'pending',
  payment_status: 'pending',
  order_id: '413',
  …
});
```

A booking that is still waiting on its gateway reaches the done step exactly
like a paid one — a PayPal payment the visitor abandoned at the last screen, a
card left pending — so reporting every done step as a completed booking counted
money that was never taken. The server decides which of the two events this is,
and the browser only reports what it is told.

A booking counts as **completed** when its appointment is `approved` (or `done`)
and its payment is `completed`. **A free order is confirmed too:** a coupon that
takes the whole price off leaves Bookly with nothing to charge and often no
payment row at all, and that booking is as real as any other — so "nothing
payable" counts as confirmed rather than pending.

The two events share their `flow_id` and their `booking_id`, so a booking that
is pending at the done step and completes later reconciles against itself.

## How the data is collected

* **Steps** — Bookly renders every step over `admin-ajax.php`
  (`bookly_render_service`, `…_time`, `…_cart`, `…_details`, `…_payment`,
  `…_complete`). A jQuery `ajaxSuccess` observer maps those actions to steps,
  so the very first render counts too.
* **Time slot click** — a *capturing* click listener for `button.bookly-hour`.
  Capture is required because Bookly's own handler stops propagation.
* **Payment next** — a capturing click listener for
  `.bookly-gateway-buttons .bookly-js-next-step`; the gateway comes from the
  checked `.bookly-js-payment` radio (`data-gateway` first, then its value).
* **Amounts** — `wp_ajax_stms_flow_state` returns a cart snapshot for the
  caller's own Bookly session (`form_id`).
* **Completed booking** — `wp_ajax_stms_order_data` resolves the order from the
  caller's Bookly session, falling back to the order token Bookly returns with
  the complete step, then reads the appointments and the payment.
* ✅ **GA4 client id** — `wp_ajax_stms_record_event` stores the booking against
  it. The browser is trusted for exactly three things only it can know — the
  GA4 client id, the flow id, and which of the two booking events it pushed;
  the booking itself is re-read from Bookly on the server, so a caller cannot
  record an order it holds neither the session nor the token for. A malformed
  client id is dropped rather than stored.
* **Visitor identity** — a logged-in visitor's customer id comes straight from
  the localized JS config. For a guest, `wp_ajax_stms_customer` looks the
  customer up from the caller's own Bookly session (`form_id`) once the details
  step has run; it only ever reads, since Bookly creates the customer record
  itself at save time.

Every endpoint only returns data the caller already owns: the Bookly form id is
the caller's own session token, and the order token is the unguessable token
Bookly itself hands to the browser.

## Debugging

Add `?stms_debug=1` to a page to log every push to the browser console, or
force it on with `add_filter( 'stms_debug', '__return_true' );`.

## Filters

| filter | purpose |
| --- | --- |
| `stms_page_slug` | override the detected page slug |
| `stms_base_slug` | override the detected English base slug |
| `stms_current_language` | override the detected language code |
| `stms_english_language_code` | language code that means English (default `en`) |
| `stms_therapist_path_segment` | URL segment of therapist pages (default `team`) |
| `stms_appointment_base_slug` | base slug of the booking page (default `appointment`) |
| `stms_gateway_map` | Bookly gateway slug → reported payment method |
| `stms_flow_state` | adjust the cart snapshot |
| `stms_order_payload` | adjust the completed-booking payload |
| `stms_ga_measurement_id` | ✅ GA4 measurement id (`G-…`), so the tracker can ask `gtag` for the client id instead of falling back to the `_ga` cookie |
| `stms_events_table` | ✅ name of the events table, without the WordPress prefix (default `daroon_booking_events`) |
| `stms_js_config` | adjust the whole JS config object |
| `stms_debug` | turn console logging on/off |
