# Action plan: tracker v1.3.1

**Date:** 2026-11-25
**Compared:** `audit/new/` (`03_DEVELOPER_HANDOFF.md`, `11_CODE_CHANGES.md`, `datalayer.js`) against plugin 1.3.0.
**Method:** code review plus a syntax check of the supplied `datalayer.js`. Nothing was run against a live Bookly form. The GTM v5 export and the Bookly source were not available.
**Status:** proposed. No plugin code has been changed.

## Summary

Adopt the supplied `datalayer.js` with the fixes in phase 1, and publish it together with GTM v5. Most of the work is easy. Server-side refunds are the one heavy plugin item, and Consent Mode is heavy but mostly outside the plugin.

**Cost scale:** *Easy* = small, contained edit. *Medium* = touches the server payload or several files. *Heavy* = new storage, outside dependencies or unresolved decisions.

## Decisions needed first

| # | Question | Effect |
|---|---|---|
| D1 | Does the site take deposits? | No: drop `booking_value`. Yes: send the amount paid from the server, and decide whether purchase `value` is the full total (as now) or the amount paid |
| D2 | Can one order contain different services? | Yes: build ecommerce items on the server (2.2) |
| D3 | Does a cancellation always return money? | Defines when a refund event is sent (3.1) |

## Phase 1: plugin release 1.3.1

### 1.1 Adopt from the supplied file

- [ ] **Easy.** Rename `source` to `booking_source`.
- [ ] **Easy.** Rename `language` to `content_language` on `booking_start`. The supplied code does this, but its change notes do not mention it.
- [ ] **Easy.** Add `view_booking_form` when the form appears.
- [ ] **Easy.** Fire `booking_start` on the first slot or Next click. This reverses item 4 of the September production handoff.
- [ ] **Easy.** Remove the `init` step.
- [ ] **Easy.** Add the `ecommerce` object, `booking_source` and `content_language` to the purchase push.
- [ ] **Easy.** Add the `transaction_id` guard. The server already always sends `order_id`, so this is only a safety net.
- [ ] **Easy.** Stop sending `customer_id`. This reverses the earlier plan to register it as a GA4 dimension.
- [ ] **Easy.** Let a pending booking be reported again once confirmed on the same page.
- [ ] **Easy, optional.** Add `window.STMS.pushRefund()`. Nothing calls it yet.

### 1.2 Fix before release

- [ ] **Easy. `money()` can inflate revenue.** It strips commas, so `"150,00"` becomes 15000 and `"1.500,00"` becomes 1.5. The server already sends numbers. Accept plain numeric strings only.
- [ ] **Easy. Remove `booking_value`** unless D1 is yes. It is calculated from `order_total`, so it repeats that value, sometimes a cent off, and cannot show deposits.
- [ ] **Easy. Push `{ecommerce: null}` before each ecommerce push.** GTM merges objects, so a refund could otherwise carry over the purchase's items.
- [ ] **Easy. Call `setUserId()` at page load** for visitors already logged in. The supplied file calls it for them only at purchase. It also needs `window.gtag`, which may disappear with the old GA4 snippet, so the GTM `user_id` mapping is required either way.
- [ ] **Easy. Drop the guest customer lookup.** Its result is no longer sent.

### 1.3 Release

- [ ] **Easy.** Set `STMS_VERSION` and the plugin header to 1.3.1 in `simyatech-tagmanager-suite.php`. The enqueue call reads that constant, so it needs no edit.
- [ ] **Easy.** Update `readme.md` for the renamed fields, new event, step indexes and removed `customer_id`.
- [ ] Back up the live `datalayer.js`, deploy, then purge LiteSpeed (including combined JS) and Cloudflare.
- [ ] In an incognito window, confirm the URL ends in `?ver=1.3.1`.

### 1.4 GTM, same release window

A dataLayer key is inert until a GTM tag sends it. These are GTM UI changes, not plugin code.

- [ ] Publish GTM v5, checking its mappings against the released code first.
- [ ] Map `booking_source` and `content_language` in place of `source` and `language`.
- [ ] Remove variables and tags that use `customer_id`.
- [ ] Map `user_id` on every GA4 event tag.
- [ ] Read `ecommerce` from the data layer on the `purchase` tag. GA4 deduplicates `transaction_id` on that event only.
- [ ] Update reports that use `init` or `step_index`:

| Step | 1.3.0 | 1.3.1 |
|---|---|---|
| `init` | 0 | removed |
| `time` | 1 | 0 |
| `cart` | 2 | 1 |
| `details` | 3 | 2 |
| `payment` | 4 | 3 |
| `done` | 5 | 4 |

### 1.5 Verify

- [ ] Form view sends `view_booking_form` only. The first slot or Next click sends `booking_start` once.
- [ ] A test booking sends one `purchase` with the correct value, currency, items and `transaction_id`.
- [ ] A pending booking sends no `purchase`.
- [ ] DebugView shows `user_id` for a logged-in user and none for a guest.

## Phase 2: conditional plugin work

- [ ] **2.1 Easy to medium, if D1 is yes.** Send the amount paid from the server. Cost depends on how Bookly stores it.
- [ ] **2.2 Medium, if D2 is yes.** Build items per service on the server. The supplied code sends one item named "A, B".

## Phase 3: larger work

- [ ] **3.1 Heavy. Server-side refunds** through the Measurement Protocol.
  - `ga_client_id`, `order_id`, `event_name` and `flow_id` are already stored in the events table.
  - Needed: the Bookly source to find the real hook (the names in the handoff are unverified), an API secret outside the repo, a refunds table to prevent duplicates, and partial refund rules.
  - The handoff's example treats every cancellation as a refund. Settle D3 first.
- [ ] **3.2 Medium to heavy, skip for now. Offline bookings.** Most phone customers have no stored GA4 client ID, so few would be attributed.
- [ ] **3.3 Heavy, mostly outside the plugin. Consent Mode v2 and a CMP.** The plugin currently stores `ga_client_id` without checking consent. Adding that check is easy to medium once a CMP is chosen.

## Outside this repo

- [ ] **Easy.** Remove `G-J9041X6HDP` from the theme or the plugin that injects it.
- [ ] **Easy.** Check the browser console for CSP errors after GTM v5 is published.

## Accepted limitations

- The `time` step is normally reported on the appointment page but not on therapist pages. GA4 funnels must not require it.
- A payment return to a page with the form sends `view_booking_form` again with a new `flow_id`, so form views are somewhat overcounted.

## Rollback

Restore the 1.3.0 plugin, purge both caches and republish the previous GTM version. Roll back both together, because the field names differ. Keep the old tracker and GTM v4 export for at least two weeks.
