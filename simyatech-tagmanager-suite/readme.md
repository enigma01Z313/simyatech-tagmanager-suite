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

## client_id / user_id

Every push carries the Bookly customer id as `client_id`, from the moment the
visitor is known:

* **Logged in** — the customer linked to the WordPress account is resolved on
  the server, so `client_id` rides on the very first push of the page. The same
  value is also sent as `user_id`.
* **Guest** — nobody is identifiable until the details step has put an email /
  phone into the Bookly session, so the id is looked up from that step on and
  then joins every later push. Only `client_id` is sent: a guest has no account,
  so there is no `user_id`.

A guest booking for the first time has no Bookly customer record at all until
Bookly saves the booking, so their `client_id` appears with
`bookly_booking_completed` and not before. Pushes made while the visitor is
still unknown simply carry neither field.

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
  total: 39.95,
  currency: 'USD',
  coupon: 'DAROON23',
  sessions: 1
});
```

* `payment_method` — `stripe` or `paypal` (Bookly's `card` / `cloud_stripe`
  gateways are normalised to `stripe`). Other gateways keep their own slug
  (`local`, `free`, …).
* `total`, `currency`, `coupon`, `sessions` come from the server rather than
  from the rendered price, which is locale-formatted. The snapshot is taken
  when the payment step renders and refreshed whenever a coupon, gift card,
  tips or deposit mode changes it.

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
  order_id: '2021-09-02 02:19:00|sheida@example.com',
  sessions_in_order: 1,
  order_total: 33,
  session_value: 33,
  currency: 'USD',
  service: 'Adults - Decision Making Problems',
  therapist: 'Farzaneh Bidari',
  slot_start: '2021-09-07T21:00:00',
  payment_method: 'stripe',
  coupon: ''
});
```

Read back from the saved order:

| field | source |
| --- | --- |
| `booking_id` | id of the first customer appointment in the order |
| `client_id` | Bookly customer the order belongs to (also fills in the id for a first-time guest) |
| `status` | its Bookly status (`approved`, `pending`, …) |
| `payment_status` | payment status (`completed`, `pending`, …) |
| `order_id` | `created_at` of the booking + `|` + customer email |
| `sessions_in_order` | booked sessions in the order (compound / collaborative services count once) |
| `order_total` | payment total |
| `session_value` | per-session price stored with the payment, or the total split evenly |
| `service` | comma-separated service names (deduplicated) |
| `therapist` | staff full name(s) |
| `slot_start` | comma-separated session start times, `Y-m-dTH:i:s` |
| `payment_method` | `stripe` / `paypal` / gateway slug |
| `coupon` | applied coupon code, empty when none |

Fires once per flow.

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
| `stms_js_config` | adjust the whole JS config object |
| `stms_debug` | turn console logging on/off |
