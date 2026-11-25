# Code Changes — `datalayer.js` v1.3.1

This is a review guide for the developer.

---

## 1. `source` → `booking_source`

**Before:**

```js
push({
  event: 'booking_start',
  source: bookingSource(meta)
});
```

**After:**

```js
push({
  event: 'booking_start',
  booking_source: bookingSource(meta)
});
```

The same rename applies to `view_booking_form`. The GTM variable is now `dlv - booking_source`.

**Why:** `source` is a traffic-source-style name. The old setup wrote it into the session campaign source, which created Unassigned sessions. The new name is a clear custom dimension and cannot be mistaken for acquisition data.

---

## 2. New `view_booking_form` event

**Before:** `booking_start` fired when the Bookly form appeared.

**After:** `view_booking_form` fires when the form appears. `booking_start` fires on the first real interaction.

```js
var formViewSent = false;

function pushViewBookingForm() {
  if (formViewSent) return;
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
```

`watchForBooklyForm()` now calls `pushViewBookingForm()` instead of `pushBookingStart()`.

---

## 3. `booking_start` redefined

`pushBookingStart()` is now called by:

- the time-slot click listener (`button.bookly-hour`)
- the first `.bookly-js-next-step` click

`bookingStartSent` keeps it to one per page view.

It is no longer called from `pushStepView()` or on form render.

---

## 4. `init` removed from step views

**Before:**

```js
var STEPS = ['init', 'time', 'cart', 'details', 'payment', 'done'];
```

**After:**

```js
var STEPS = ['time', 'cart', 'details', 'payment', 'done'];
```

`STEP_BY_ACTION` no longer includes `bookly_render_service`. The AJAX handler no longer calls `pushStepView('init')`.

The `time` step is suppressed until the first interaction:

```js
var hasInteracted = false;

if (step === 'time' && !hasInteracted) {
  return;
}
```

`hasInteracted` is set by the slot click and next-step click listeners.

**Result:** On a therapist page, the form render is `view_booking_form`, not a fake step. The time selection is `booking_start`. Step views start at `details`.

---

## 5. Purchase ecommerce object

`pushBookingResult()` now builds:

```js
var sessions = Number(data.sessions_in_order) || 1;
var value = money(data.order_total);
var sessionValue = money(data.session_value);
var currency = normalizeCurrency(data.currency);
var transactionId = String(data.order_id || data.booking_id || '').trim();

var ecommerce = {
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
```

It is attached only to confirmed bookings with a non-empty transaction ID:

```js
if (confirmed && transactionId !== '') {
  payload.ecommerce = ecommerce;
}
```

The payload also includes `booking_source` and `content_language` (from `pageMeta()`), so purchases can be sliced by form location and language.

**Why `price = value / sessions`:** It keeps `items[].price × quantity` equal to the event `value`. If you sell 3 sessions for $150, the item is 3 × $50, not 3 × $150.

**Full booking value:** `booking_value = session_value × sessions` (or `value` if `session_value` is missing). This is separate from `value` so deposits/bundles are analysable.

---

## 6. Transaction ID guard

- The tracker uses `order_id` first, then `booking_id`.
- The GTM purchase trigger requires `CJS - transaction id` to match `.+`.
- If both are empty, the `purchase` tag does not fire.

This prevents purchases without a dedupe key.

---

## 7. Refund helper

```js
window.STMS.pushRefund({
  transaction_id: 'ORDER_ID',
  value: 100,
  currency: 'USD',
  booking_id: '123'
});
```

It pushes `bookly_booking_refunded` with an `ecommerce` object. The GTM refund tag sends `refund`.

For admin-side refunds, use the Measurement Protocol instead (see `03_DEVELOPER_HANDOFF.md`).

---

## 8. `user_id` and `customer_id`

- `withIdentity()` no longer adds `customer_id`.
- Logged-in users get `user_id` in the payload and via `gtag('set', 'user_id', ...)`.
- Guests get no `user_id`.
- The server-side `ga_client_id` join is unchanged.

---

## 9. Number and currency hardening

**Before:**

```js
function money(value) {
  return typeof value === 'number' ? value : 0;
}
```

**After:**

```js
function money(value) {
  if (typeof value === 'number') {
    return isFinite(value) ? value : 0;
  }

  var parsed = parseFloat(String(value == null ? '' : value).replace(/[^0-9.\-]/g, ''));
  return isFinite(parsed) ? parsed : 0;
}
```

`normalizeCurrency()` uppercases and falls back to USD.

**Why:** Bookly locale formatting can send `"150.00"` or `"1,500,000"` as a string. The old code would turn that into 0 and lose revenue.

---

## 10. `flow_id` remains in the dataLayer, not in GA4

The tracker still generates and pushes `flow_id` for the server-side `stms_record_event` table and for GTM Preview.

The v5 GTM tags do **not** send `flow_id` to GA4. It is a 32-character random value and would exceed GA4’s custom-dimension cardinality.

---

## 11. What did **not** change

- The flow ID generation.
- The AJAX step detection.
- The customer lookup.
- The GA4 client ID capture.
- The payment method mapping.
- The `window.STMSTracker` double-load guard.
- The `stms_flow_state` snapshot.

Keep those; they are sound.
