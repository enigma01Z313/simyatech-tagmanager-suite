# Action plan: tracker 1.4.0

**Date:** 2026-11-28 (replaces the 2026-11-25 plan for 1.3.1)
**Compared:** `audit/new/` on `main` at `0c94897`, including the new `12_SCOPE_CONFIRMATION.md`, against plugin 1.3.0.
**Method:** code review plus a syntax check of the supplied `datalayer.js`. Nothing was run against a live Bookly form. The GTM v5 export was not available.
**Status:** ready to implement. Every item below is easy: small, contained edits in this repo, or settings changes in GTM and on the server.

## Goal and scope

Measure traffic share by channel / source-medium, and conversion rate through the booking funnel to `purchase` (`12_SCOPE_CONFIRMATION.md`).

The release is **1.4.0**, not 1.3.1. It renames and removes fields that GTM reads, so it is a breaking change. Wherever the handoff says `1.3.1`, use `1.4.0`.

## Corrections to the supplied docs

- `11_CODE_CHANGES.md` §6a and `12_SCOPE_CONFIRMATION.md` say `stms_order_data` returns only confirmed orders and sends no `confirmed` field. **Both are wrong.** `STMS_Bookly_Data::order_payload()` sends `confirmed` (true or false) and also returns pending orders. The supplied tracker reads that flag first, so the new fallback is harmless and no PHP change is needed.
- So `bookly_booking_pending` **already fires** for pending orders. It is not dormant. The GTM `purchase` tag must not fire on it.
- `booking_start` is also renamed from `language` to `content_language`. `11_CODE_CHANGES.md` does not mention this.

## 1. Plugin code (this repo)

### 1.1 Adopt from the supplied `datalayer.js`

- [ ] Rename `source` to `booking_source`.
- [ ] Rename `language` to `content_language` on `booking_start`.
- [ ] Add `view_booking_form` when the form appears.
- [ ] Fire `booking_start` on the first time-slot click or first Next click, not on form render. This reverses item 4 of the September production handoff.
- [ ] Remove the `init` step.
- [ ] Add the `ecommerce` object, `booking_source` and `content_language` to the purchase push.
- [ ] Add the `transaction_id` guard (order id, then booking id).
- [ ] Stop sending `customer_id`. Send `user_id` for logged-in users only.
- [ ] Let a pending booking be reported again once confirmed on the same page.
- [ ] Derive `confirmed` from the statuses when the flag is missing.
- [ ] Keep `window.STMS.pushRefund()` dormant. Nothing calls it this release.

### 1.2 Fix before release

- [ ] **`money()` can inflate revenue.** It strips commas, so `"150,00"` becomes 15000 and `"1.500,00"` becomes 1.5. The server already sends numbers. Accept plain numeric strings only.
- [ ] **Remove `booking_value`.** It is calculated from `order_total`, so it repeats that value, sometimes a cent off, and is not needed for the goal.
- [ ] **Push `{ecommerce: null}` before each ecommerce push.** GTM merges objects, so stale items could otherwise carry over.
- [ ] **Call `setUserId()` at page load** for visitors already logged in. The supplied file calls it for them only at purchase.
- [ ] **Drop the guest customer lookup** (`resolveCustomer`). Its result is no longer sent. Keep the `stms_customer` endpoint so nothing breaks while an old cached script is still in use.
- [ ] Change the file header comment from `v1.3.1` to `1.4.0`.

### 1.3 Version and docs

- [ ] Set `STMS_VERSION` and the plugin header to `1.4.0` in `simyatech-tagmanager-suite.php`. The enqueue call reads that constant, so it needs no edit.
- [ ] Update `readme.md`: new `view_booking_form` event, `booking_start` timing, renamed fields, removed `init`, new step indexes, removed `customer_id`, the `ecommerce` object.

## 2. GTM (GTM UI, not this repo)

A dataLayer key is inert until a GTM tag sends it. Publish these in the same window as the plugin release.

- [ ] Publish GTM v5, checking its mappings against the released code first.
- [ ] Add a trigger and GA4 tag for `view_booking_form`.
- [ ] Map `booking_source` and `content_language` in place of `source` and `language`.
- [ ] Remove variables and tags that use `customer_id`.
- [ ] Map `user_id` on every GA4 event tag.
- [ ] Fire the `purchase` tag on `bookly_booking_completed` only, reading `ecommerce` from the data layer. GA4 deduplicates `transaction_id` on `purchase` only.
- [ ] Pause the Contact Form 7 lead tag unless it is kept. If kept, check the browser console for CSP errors.
- [ ] Update reports that use `init` or `step_index`:

| Step | 1.3.0 | 1.4.0 |
|---|---|---|
| `init` | 0 | removed |
| `time` | 1 | 0 |
| `cart` | 2 | 1 |
| `details` | 3 | 2 |
| `payment` | 4 | 3 |
| `done` | 5 | 4 |

## 3. Deploy (server, outside this repo)

- [ ] Back up the live plugin, then deploy 1.4.0.
- [ ] Purge LiteSpeed (including combined/minified JS) and Cloudflare.
- [ ] In an incognito window, confirm `datalayer.js` loads with `?ver=1.4.0`.
- [ ] Remove the hardcoded `G-J9041X6HDP` tag from the theme or the plugin that injects it.

## 4. Verify

- [ ] Opening the form sends `view_booking_form` only. The first slot or Next click sends `booking_start` once.
- [ ] A test booking sends one `purchase` with the correct `transaction_id`, value, currency and items.
- [ ] A pending booking sends `bookly_booking_pending` and no `purchase`.
- [ ] DebugView shows `user_id` for a logged-in user and none for a guest.
- [ ] No request to `G-J9041X6HDP` on `/en/appointment/`.

## Accepted limitations

- The `time` step is normally reported on the appointment page but not on therapist pages, where the time screen is the form view. GA4 funnels must start at `view_booking_form` / `booking_start`, not `time`.
- A payment return to a page with the form sends `view_booking_form` again with a new `flow_id`, so form views are somewhat overcounted.
- Orders with several different services are sent as one item named "A, B". This does not affect channel share or conversion rate.

## Deferred (not part of this release)

Per `12_SCOPE_CONFIRMATION.md`: server-side refunds, offline bookings, Consent Mode v2 / CMP. Also deferred: server-built ecommerce items and amount-paid (deposit) reporting.

## Rollback

Restore the 1.3.0 plugin, purge both caches and republish the previous GTM version. Roll back both together, because the field names differ. Keep the old tracker and GTM v4 export for at least two weeks.
