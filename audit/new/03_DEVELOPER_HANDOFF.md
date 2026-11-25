# Developer Handoff — Daroon Tracking v1.3.1

**Goal:** Deploy the updated tracker, clear caches, and add refund/offline events safely.

---

## 1. Deploy the updated tracker

### Files

- New file: `datalayer.js` (in this folder)
- Target path: `wp-content/plugins/simyatech-tagmanager-suite/assets/js/datalayer.js`

### Steps

1. Back up the current `datalayer.js`.
2. Replace it with the new file.
3. Find the plugin’s `wp_enqueue_script()` call for `stms-datalayer-js` and bump the version from `1.3.0` to `1.3.1`.

Example:

```php
wp_enqueue_script(
    'stms-datalayer-js',
    plugin_dir_url(__FILE__) . 'assets/js/datalayer.js',
    array('jquery'),
    '1.3.1',
    true
);
```

If the plugin already uses `filemtime()` for the version, it will update automatically:

```php
filemtime(plugin_dir_path(__FILE__) . 'assets/js/datalayer.js')
```

4. Purge **LiteSpeed Cache**:
   - WordPress Admin → **LiteSpeed Cache** → **Toolbox** → **Purge All**.
5. Purge **Cloudflare**:
   - Cloudflare dashboard → your zone → **Caching** → **Configuration** → **Purge Everything**.
   - Or use the Cloudflare WordPress plugin’s purge button.
6. If LiteSpeed **JS Combine** or **JS Minify** is enabled:
   - Exclude `datalayer.js` from combine, **or** purge the combined JS cache.
   - Otherwise the old combined bundle can keep serving the old tracker.
7. Verify in an incognito window:
   - DevTools → Network → filter `datalayer.js`.
   - The URL must end with `?ver=1.3.1`.
   - If it still shows `1.3.0`, the cache was not purged.

---

## What “bump the script version” means

WordPress serves the file as:

```
https://daroon.me/wp-content/plugins/simyatech-tagmanager-suite/assets/js/datalayer.js?ver=1.3.0
```

Browsers, LiteSpeed, and Cloudflare cache that URL. If you replace the file but keep `?ver=1.3.0`, many visitors will keep running the old JavaScript for days or weeks.

Changing the version to `1.3.1` changes the URL. That forces a fresh download. It is a cache-busting technique, nothing more.

**Checklist:**

- [ ] File replaced
- [ ] Version string changed to `1.3.1` (or `filemtime()` used)
- [ ] LiteSpeed purge all
- [ ] Cloudflare purge everything
- [ ] JS combine/minify cache purged or file excluded
- [ ] Incognito Network tab shows `?ver=1.3.1`

---

## 2. Remove the old GA4 tag `G-J9041X6HDP` (low priority)

You said you do not use the old property. The tag is hardcoded on `/en/appointment/` and is not in GTM.

Search for `G-J9041X6HDP` in:

- `wp-content/themes/optima-child/functions.php`
- `wp-content/themes/optima/functions.php`
- WPCode / Insert Headers and Footers / “Insert Headers and Footers” plugin
- Site Kit by Google settings
- The appointment page content (WPBakery raw HTML block)
- Any custom plugin that injects the Google tag

Remove both lines:

```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-J9041X6HDP"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-J9041X6HDP');
</script>
```

Verify: open `/en/appointment/` in an incognito window, Network tab, search `G-J9041X6HDP`. There should be no request.

This does not affect `G-NT8THMV82G`. It only removes duplicate collection and a second gtag.js download.

---

## 3. Refund / cancellation events

You have two options. Use **Option B** for admin-side refunds, because the browser is not involved.

### Option A — client-side (only if the refund happens on a page the customer visits)

The new tracker exposes:

```js
window.STMS.pushRefund({
  transaction_id: 'ORDER_ID',   // the original order_id
  value: 100.00,                // refund amount, USD
  currency: 'USD',
  booking_id: '12345'
});
```

Call it **exactly once** after the refund is saved. Do not call it on page load, or a refresh will send it again.

### Option B — server-side Measurement Protocol (recommended)

This works for admin refunds, cancellations, and offline bookings.

#### B1. Create an API secret

GA4 → **Admin** → **Data streams** → select the web stream → **Measurement Protocol API secrets** → **Create**.

Store it securely. Do **not** commit it to the repository.

Example in `wp-config.php`:

```php
define('DAROON_GA4_MEASUREMENT_ID', 'G-NT8THMV82G');
define('DAROON_GA4_MP_SECRET', 'the-secret-from-ga4');
```

#### B2. Find the original GA4 client id

The tracker sends `ga_client_id` to the `stms_record_event` AJAX action on every booking event. The plugin should already store it. Look for the row for the original `bookly_booking_completed` event for that order.

If it is not stored yet, add a column/table for:

- `booking_id` or `order_id`
- `event_name`
- `ga_client_id`
- `flow_id`
- `created_at`

The tracker already sends all of these.

#### B3. Send the refund

Endpoint:

```
POST https://www.google-analytics.com/mp/collect?measurement_id=G-NT8THMV82G&api_secret=YOUR_SECRET
```

Payload:

```json
{
  "client_id": "1234567890.1234567890",
  "events": [
    {
      "name": "refund",
      "params": {
        "transaction_id": "ORDER_ID",
        "value": 100.00,
        "currency": "USD",
        "engagement_time_msec": 1
      }
    }
  ]
}
```

Rules:

- Use the **original** `transaction_id` (`order_id`), so GA4 matches the purchase.
- `value` is the **refund amount**, not the original order total. For a full refund, use the full amount.
- `currency` must be `USD`.
- `engagement_time_msec` is required for MP events to appear in standard reports.
- Send **once per refund**. Add an idempotency flag (booking meta or a `refund_events` table keyed by gateway refund ID).
- For partial refunds, send one event per refund with the partial value. Do not send the full amount twice.

#### B4. Test with the debug endpoint

```
POST https://www.google-analytics.com/debug/mp/collect?measurement_id=G-NT8THMV82G&api_secret=YOUR_SECRET
```

The response shows validation messages. A valid request returns `validationMessages: []`.

Then check GA4 **DebugView** and **Realtime** for the `refund` event.

#### B5. Bookly hook

Find the cancellation/refund hook in your Bookly version. Common hooks include:

- `bookly_booking_status_changed`
- `bookly_booking_cancelled`

The exact signature may differ. A generic example:

```php
add_action('bookly_booking_status_changed', function ($booking_id, $new_status, $old_status) {
    if ($new_status !== 'cancelled') {
        return;
    }

    // 1. Load the booking/order.
    // 2. Skip if a refund event was already sent for this booking/refund.
    // 3. Read the original order_id, total, currency, and ga_client_id.
    // 4. POST the Measurement Protocol payload above.
    // 5. Mark the refund as sent.
}, 10, 3);
```

Do not guess the hook signature. Check the Bookly plugin source or ask Bookly support for the exact cancellation/refund hook in your version.

---

## 4. Offline bookings (optional but valuable)

If staff create bookings manually or take payment by phone, GA4 currently never sees them.

If you have the customer’s original `ga_client_id` from a previous web session, send a Measurement Protocol `purchase`:

```json
{
  "client_id": "1234567890.1234567890",
  "events": [
    {
      "name": "purchase",
      "params": {
        "transaction_id": "ORDER_ID",
        "value": 150.00,
        "currency": "USD",
        "items": [
          {
            "item_id": "therapy-session",
            "item_name": "Therapy session",
            "item_category": "therapist-slug",
            "price": 50.00,
            "quantity": 3
          }
        ],
        "engagement_time_msec": 1
      }
    }
  ]
}
```

Rules:

- GA4 dedupes by `transaction_id`. If the web purchase already fired, the MP copy is discarded.
- If there is no known `client_id`, do not invent one. Use a separate Google Ads offline conversion import instead, or accept that the booking is unattributed.
- Send once per order. Add an idempotency flag.

---

## 5. `user_id` and `customer_id`

The new tracker already handles this. No extra developer work beyond deploying the file. GTM v5 also maps `user_id` → `{{dlv - user_id}}` on every GA4 event tag, so the user ID no longer depends on `gtag('set')` timing.

- Logged-in users: the tracker calls `gtag('set', 'user_id', <Bookly customer id>)` when the identity resolves and again before the purchase.
- Guests: no `user_id`.
- `customer_id` is no longer pushed to the dataLayer. The server-side `ga_client_id` join is still available for reconciliation.
- Do not send email, phone, name, or address to GA4.

Verify in GA4 **DebugView** that a logged-in test user shows a `user_id` and a guest does not.

---

## 6. Content Security Policy note

The Contact Form 7 lead listener is a GTM **Custom HTML** tag. If the site uses a strict Content Security Policy, inline scripts may be blocked.

Check the browser console after publishing GTM v5. If you see a CSP error for the GTM Custom HTML tag:

- Allow the GTM inline script via the site’s CSP (nonce/hash or `unsafe-inline` for GTM), **or**
- Move the `wpcf7mailsent` listener into the child theme’s JavaScript file instead of GTM.

Do not weaken the CSP globally just for this. Use the narrowest allowlist that works.

---

## 7. Consent Mode v2 / CMP (separate task)

Do this before running ads in the EU/UK, and ideally before scaling in Canada.

Checklist for the developer:

- [ ] Install a CMP (CookieYes, Complianz, Cookiebot, etc.).
- [ ] Set Google Consent Mode v2 defaults **before** GTM loads:
  - `ad_storage`
  - `analytics_storage`
  - `ad_user_data`
  - `ad_personalization`
- [ ] Update consent on user interaction.
- [ ] In GTM, add consent settings to every tag:
  - Analytics tags require `analytics_storage`.
  - Ads tags require `ad_storage`, `ad_user_data`, `ad_personalization`.
- [ ] Test with GTM Preview and the CMP’s consent state.
- [ ] Do not fire analytics or ads tags before consent in EEA/UK.

This is not in the current GTM v5 file; all tags are `consentStatus: NOT_SET`. That is fine for now, but it is a compliance gap if you serve EEA/UK users.

---

## 8. Rollback

If something breaks:

1. Restore the old `datalayer.js`.
2. Purge LiteSpeed + Cloudflare.
3. GTM → **Versions** → select the previous version → **Publish**.
4. GA4 settings (session timeout, unwanted referrals, enhanced measurement) can be reverted in Admin.

Keep the v4 container export and the old tracker file until the new setup has been stable for at least two weeks.
