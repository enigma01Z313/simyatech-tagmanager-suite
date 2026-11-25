# Bookly Data Layer Test Evaluation & Migration Plan

**Project:** Daroon.me Booking Funnel Analytics (`daroon-bookly-tracking`)
**Reference Spec:** `BOOKLY_TRACKING_SPEC.md`
**Date:** 2026-09-12
**Captures reviewed:** `data layer-test.txt` (Sep 9, flow `1keipjn…`) / `new-funnel.txt` (Sep 11, flow `3cged4…`)

> **Code review added 2026-09-14** — every item below has been checked against the actual
> plugin source (`simyatech-tagmanager-suite`, HEAD `d2a711b`). Verdict markers were appended
> in place; nothing in the original text was removed. See **§5** for the full verification.
>
> **Legend:** ✅ already implemented (duplicate task) · 🔴 real gap, confirmed in code ·
> ⚪ premise does not match this implementation (do not implement) · 🟡 outside this repo
> (GTM / GA4 / ops)

---

## 0. Decision table — what to implement

| # | Issue | Status | How it was fixed | Missing or duplicate? | Can it be implemented? | OK to implement? |
|---|---|---|---|---|---|---|
| 1 | Raw customer email inside `order_id` (§2.5, P1 #4) | ✅ **DONE — 1.2.0** | `order_payload()` now returns the numeric Bookly order id. The `c.email` select and the `Customer` join were deleted, so the address is absent from the AJAX response too; `STMS_Bookly_Data::order_key()` rebuilds the `created_at \| email` key server-side for the events table | 🔴 Missing | Yes — 1 line; the numeric order id was already resolved | Yes — done first. Live GA4 PII exposure |
| 2 | `client_id` carries the Bookly customer id (§2.4, P1 #3) | ✅ **DONE — 1.2.0** | Renamed to `customer_id` in `withIdentity()` (`datalayer.js`), the `stms_customer` response and `order_payload()`. `user_id` left as it was | 🔴 Missing — and wider: it rides on *every* push | Yes — across JS + PHP + readme | Yes — landed before the GTM rebuild. Breaking rename for existing GTM variables |
| 3 | `bookly_booking_pending` event (§2.6, P2 #9) | ✅ **DONE — 1.2.0** | `pushBookingCompleted()` → `pushBookingResult()`; the server returns a `confirmed` flag from `is_confirmed()` and the browser picks the event name from it. A free order counts as confirmed, since a fully-couponed booking often has no payment row at all | 🔴 Missing | Yes — `status`/`payment_status` were already in hand | Yes — cheap, feeds the §9.3 gap report |
| 4 | GA4 `client_id` store `daroon_booking_events` (§2.6, P2 #7) | ✅ **DONE — 1.2.0** | New `class-stms-events-store.php` + `stms_record_event` endpoint. Table with `UNIQUE(booking_id)`, `ga_client_id`, `event_sent`, `order_key`; id read from `gtag(get…)` or the `_ga` cookie; upsert per booking, never overwritten by an empty value; the booking is re-read from Bookly server-side | 🔴 Missing — no table, no `gtag(get)` | Yes — new subsystem: table, writer, dedupe | Yes — built; P5 #21 is now unblocked |
| 12 | `client_id` typed `int` or `''` (§5.3, *not in the audit*) | ✅ **DONE — 1.2.0** | Cast to string on the same line the rename touched | 🔴 Missing — two types for one GA4 parameter | Yes — 1 line | Yes — came along with row 2, same expression |
| 6 | Dedupe `bookly_step_view` (§2.2, P1 #1) | ✅ **ALREADY DONE — `d2a711b`** | Nothing to do — the `lastStep` guard and the `window.STMSTracker` double-load guard were already shipped on 2026-09-11 | ✅ Duplicate | Already done | ❌ No work. Re-capture on a current build before re-raising |
| 7 | `session_value` math + `subtotal` + `coupon_discount` (§4.3, P1 #5) | ✅ **ALREADY DONE — `d2a711b`** | Nothing to do — `session_value()`, `details_subtotal()` and the two coupon-discount helpers were already shipped | ✅ Duplicate | Already done | ❌ No work |
| 5 | `approved + completed` gating (§2.6, P2 #6) | ⬜ **OPEN** | — *to do:* condition the GA4 tag in GTM on `status` / `payment_status`, or on the new `confirmed` flag. No plugin change | ◐ Half — the server-side read exists, the gate does not | Yes | Yes — **but gate the GTM tag, not the push.** Dropping the push hides pending/failed from reconciliation |
| 11 | Staging `console.log` leak in `push()` (§5.3, *not in the audit*) | ⬜ **OPEN** | — *to do:* delete the `url.includes("staging")` branch and use the existing `?stms_debug=1` | 🔴 Missing | Yes — one branch to delete | Yes — also removes ES6 from an ES5 file |
| 13 | Nonce fallback accepts an invalid nonce (§5.3, *not in the audit*) | ⬜ **OPEN** — doc only | — *to do:* a readme note. The behaviour itself is deliberate | ◐ Deliberate — nonces die on cached pages | n/a | No code change — document the residual risk |
| 8 | `booking_start` before the first step view (§2.3, P1 #2) | ❌ **WON'T DO** | — deliberately unchanged | ✅ Duplicate in substance — `step_view / init / 0` already marks form boot | Yes — ~3 lines | ❌ No. Would double-fire one moment and destroy the "picked a slot" signal |
| 9 | Tighten the `MutationObserver` onto `.bookly-step-active` (§2.6, P2 #8) | ❌ **WON'T DO** | — nothing exists to change | ⚪ N/A — there is no MutationObserver | No target to change | ❌ No. The AJAX-action approach supersedes it |
| 10 | `/wp-json/daroon-bookly/v1/verify` endpoint (§2.6, P2 #6) | ❌ **WON'T DO** | — `stms_order_data` already does this job | ⚪ N/A | n/a | ❌ Build nothing; see row 5 for the real half |
| 14 | Baseline SQL (P0) | 🟡 **OUT OF SCOPE** — ops | — run it in the DB | 🟡 Out of scope — read-only | n/a | Yes, safe to run |
| 15 | GTM container rebuild (P3 #10–14) | 🟡 **OUT OF SCOPE** — GTM console | — build against `customer_id`, and add variables for `flow_id`, `subtotal`, `coupon_discount`, `user_id` | 🟡 Out of scope | n/a | Yes — row 2 has landed |
| 16 | GA4 admin setup (P4 #15–17) | 🟡 **OUT OF SCOPE** — GA4 console | — register `customer_id`, and map `order_total` → `value` or revenue stays empty | 🟡 Out of scope | n/a | Yes |
| 17 | QA matrix, rollout, reconciliation (P5 #18–22) | 🟡 **OUT OF SCOPE** — ops | — re-capture for #3; #6 is satisfied by the order-id fix | 🟡 Out of scope | n/a | Yes — ~~#21 blocked by row 4~~ unblocked, row 4 shipped |

**Reading the table:** *Status* is the only column that says what is built. *Missing or duplicate?*
answers the original question — whether the audit found something genuinely absent from the plugin
(🔴 Missing), something the plugin already does (✅ Duplicate), or something written against a
different implementation (⚪ N/A). A row can be both "🔴 Missing" **and** "✅ DONE": it was really
missing when the audit was written, and it has since been built.

**Original order:** ~~1 → 2 (+12) → 11 → 3 → 5 → then 4 only if §9.4 is going ahead.~~
✅ **1, 2, 3, 4 and 12 are built (1.2.0).** **Remaining: 11, then 5 in GTM, then 13 as a readme note.**
**Do nothing for:** 6, 7, 8, 9, 10.

✅ **Rows 1, 2, 3 and 4 were implemented on 2026-09-14 and shipped as plugin version 1.2.0**
(row 12 came along with row 2, since it is the same expression). What each change actually
does is written up in **§6**. Still open: row 5 (gate the GTM tag), row 11 (the staging
`console.log`), row 13 (a readme note), and the out-of-scope GTM / GA4 / ops rows 14–17.

---

## 1. Comparison: Previous Version (`data layer-test.txt`) vs. Current Version (`new-funnel.txt`)

### Previous Version (`data layer-test.txt`)

| Category | Finding |
|---|---|
| **JS Errors** | Two errors captured: `SyntaxError: Identifier 'errorTitle' has already been declared` (from `bookly.min.js` validation re-render) and `TypeError: Cannot set properties of undefined (setting 'innerHTML')` (from an inline `setInterval` script at `mahshid-naseri/:2279` — unrelated to the tracker) 🟡 *neither error originates in this plugin — no code change possible here* |
| **`session_value` Bug** | `session_value: 39.95` while `order_total: 0.8` — the pre-discount price was pushed instead of `order_total / sessions_in_order`. This would overstate GA4 revenue vs. the Bookly DB and break reconciliation (spec §4.3, §9.2) ✅ *fixed in `d2a711b`* |
| **`client_id` on Step Views** | Bookly customer ID (`"7555"`) leaked onto cart/details/payment/done `bookly_step_view` events after the form submit — clogging funnel data with a non-funnel parameter ⚠️ *still possible — see §5.2 item 3* |
| **Missing Payment Detail** | `bookly_payment_started` lacked `subtotal` and `coupon_discount` — reduced coupon analysis depth ✅ *both added in `d2a711b`* |
| **Duplicate Step Views** | `bookly_step_view` for payment fired twice (uniqueEventId 34 and 45) — same inflation problem the old DOM-visibility triggers caused ✅ *consecutive-repeat guard added in `d2a711b`* |
| **Event Ordering** | `booking_start` fired after `init` and `time` step views (uniqueEventId 23 vs. 20/21) ⚪ *by design — see §5.2 item 2* |

### Current Version (`new-funnel.txt`)

| Category | Finding |
|---|---|
| **Flow Consistency** | Consistent `flow_id` (`3cged4…`) across all events — good session stitching ✅ |
| **`session_value` Fix** | `session_value: 0.8` = `order_total / sessions_in_order` — now spec-compliant (§4.3) ✅ |
| **Payment Detail** | `bookly_payment_started` now includes `subtotal: 39.95`, `coupon_discount: 39.15` — improved coupon/revenue analysis ✅ |
| **Step View Cleanup** | `client_id` no longer leaks onto mid-funnel step views — cleaner funnel data ⚠️ **correction:** not fixed, only unobserved — this guest had no Bookly customer record yet. `withIdentity()` stamps *every* push, so a logged-in visitor still carries `client_id` from the first step view on. See §5.2 item 3 |
| **JS Errors** | Zero JavaScript errors in this capture (single session — not generalizable) ✅ |
| **Duplicate Step Views — NOT FIXED** | `bookly_step_view` for payment still fires twice (uniqueEventId 51 and 57, identical payloads). This is the most critical remaining bug — it reproduces the exact step-inflation disease the project was created to cure ⚪ **re-verify:** the dedupe guard *is* in the shipped code (`d2a711b`, 2026-09-11 02:41 — 11 h before this 13:57 capture). Two payment views ~6 event-ids apart is the signature of a legitimate payment → details → payment re-entry, which the spec permits. See §5.1 |

### Side-by-Side: Event Parameters Compared

| Parameter | Old (`data layer-test.txt`) | New (`new-funnel.txt`) | Spec (§4) | Status |
|---|---|---|---|---|
| `session_value` | `39.95` (wrong) | `0.8` (correct) | `order_total / sessions_in_order` | ✅ Fixed — confirmed at `STMS_Bookly_Data::session_value()` |
| `subtotal` | absent | `39.95` | Not in spec (optional) | ✅ Added — confirmed |
| `coupon_discount` | absent | `39.15` | Not in spec (optional) | ✅ Added — confirmed |
| `client_id` on step views | present (`"7555"`) | absent | §6.3: not in GA4 events | ⚠️ Not fixed in code — absent only for unknown guests (§5.2 item 3) |
| `client_id` on `booking_completed` | `"7555"` (Bookly customer ID) | `"7556"` (Bookly customer ID) | §6.3: GA4 client_id only in DB table | 🔴 **Misnamed — confirmed** — see §2.4 |
| `order_id` | `"2026-09-09 09:40:43\|test2020@gmail.com"` | `"2026-09-11 13:57:12\|test20252@gmail.com"` | §5.3: fallback key | 🔴 **PII in GA4 — confirmed in source** — see §2.5 |
| Duplicate `payment` step_view | Yes (2×) | Yes (2×) | §5.2: "push only when step changes" | ⚪ Guard present in code — see §5.1 |
| `booking_start` ordering | After step views | After step views | §5.2: when `.bookly-form` appears (first) | ⚪ Intentional design difference — see §5.2 item 2 |

---

## 2. Audit against `BOOKLY_TRACKING_SPEC.md`

### 2.1 Spec Event Taxonomy vs. Staging Reality

| Spec Event | Spec Params | Staging Status | Issues |
|---|---|---|---|
| `booking_start` | `entry_page`, `therapist`, `language`, `source` | ✅ All present and correct | Fires after `init`/`time` step views instead of first — minor ordering ⚪ *deliberate: it fires on the first time-slot click, not on form boot* |
| `bookly_step_view` | `step`, `step_index` (1–5) | ⚠️ `init`/`0` added per project owner's design (acceptable); **payment fires twice** | Dedupe broken — §2.2 below ⚪ *dedupe is present in the shipped code* |
| `bookly_payment_started` | `payment_method`, `total`, `currency`, `coupon`, `sessions` | ✅ All present + extra `subtotal`/`coupon_discount` | Clean ✅ |
| `bookly_booking_completed` | `booking_id`, `status`, `payment_status`, `order_id`, `sessions_in_order`, `order_total`, `session_value`, `currency`, `service`, `therapist`, `slot_start`, `payment_method`, `coupon` | ⚠️ All present + extra fields; **`client_id` present but misnamed; `order_id` contains PII** | §2.4, §2.5 below 🔴 *both confirmed in source* |

### 2.2 Critical Bug: Duplicate `bookly_step_view` on Payment Step

> ⚪ **Verdict: premise does not hold against this codebase — do not implement.** The plugin
> contains **no `MutationObserver`**; it observes Bookly's own admin-ajax actions
> (`$(document).ajaxSuccess`). The proposed fix ("compare current step index against the
> last-pushed value") is **already implemented** — `lastStep` in `datalayer.js`, shipped in
> `d2a711b` on 2026-09-11 02:41, i.e. *before* the 13:57 capture. Nothing to change. See §5.1.

Both captures show `bookly_step_view` for `step: "payment"` firing twice with identical payloads (old: uniqueEventId 34→45; new: 51→57). Per spec §5.2: *"Push only when the step value changes (never twice for the same step in a row)."*

**Impact:** Inflates the payment funnel step count. If 10% of sessions trigger the duplicate, GA4 payment-step count will be ~110% of the details-step count — breaking the monotonic funnel property (spec §1.3 criterion #2).

**Root cause (likely):** The `MutationObserver` fires on every DOM mutation inside `.bookly-form`, not just stepper-class changes. The payment step re-renders its gateway block (Stripe/PayPal radios) which triggers the observer again without the active step actually changing. ⚪ ~~*there is no MutationObserver in this plugin*~~ → the real signal is `STEP_BY_ACTION[action]` on Bookly's AJAX success, and a re-render of the same step is already suppressed.

**Fix:** Guard the observer callback to compare the current step index against the last-pushed value; push only when they differ. ✅ *already done (`if (step === lastStep) { return; }`)*

### 2.3 `booking_start` Firing Order

> ⚪ **Verdict: implementable in ~3 lines, but not advisable.** `booking_start` deliberately
> fires on the first `button.bookly-hour` click — real engagement, not form presence. Moving it
> to `.bookly-form` detection would make it fire at the same instant as
> `bookly_step_view / step: "init" / step_index: 0`, i.e. a **duplicate of an event you already
> have**. See §5.2 item 2.

In both captures, `booking_start` fires after the `init` (index 0) and `time` (index 1) step views. Per spec §5.2, `booking_start` should fire when `.bookly-form` is detected — before any step view. This is a minor sequencing issue that affects funnel analysis if `booking_start` is used as the funnel entry point.

**Fix:** Ensure the `booking_start` push in the tracker fires on `.bookly-form` DOM insertion/detection, before the `MutationObserver` processes the first step. ⚪ *if the funnel needs a "form was seen" entry point, use `bookly_step_view step_index = 0` — that is exactly what `init` is for*

### 2.4 `client_id` Misnaming on `bookly_booking_completed` — ✅ FIXED in 1.2.0

> ✅ **Implemented 2026-09-14:** renamed to `customer_id` in `withIdentity()`, the
> `stms_customer` response and `order_payload()`. `user_id` is unchanged. The GA4 client id
> is now stored server-side instead (§6).
>
> 🔴 **Verdict: real, confirmed — and wider than described. Implement (rename).** `withIdentity()`
> in `datalayer.js` stamps `client_id` onto **every** push, not just the completed event, and
> also emits `user_id` for logged-in visitors. Safe to change; the only coupling is the GTM
> container, so do it *before* the Phase 3 rebuild, not after.

The field `client_id` in `bookly_booking_completed` carries the **Bookly customer ID** (e.g., `"7556"`), not the GA4 `client_id`. This creates two problems:

1. **GA4 pollution:** Spec §6.3 says *"Do not pass `client_id` in these events."* The GA4 client_id is a long decimal string (e.g., `"123456789.987654321"`) attached automatically by GA4; it must not be sent as a dataLayer parameter.
2. **Conceptual collision:** If a GA4 custom dimension is ever registered for `client_id`, it would show the Bookly customer ID, not the GA4 client_id — breaking the channel-reconciliation bridge (spec §9.4).

**Fix:** Either remove the field from GA4-bound events entirely (keep it server-side only in `daroon_booking_events`), or rename it to `customer_id` and register it as a custom dimension if Bookly customer ID analysis is desired. The GA4 ↔ DB bridge uses the real GA4 `client_id` stored only in the custom table. ✅ *recommended: **rename** — `client_id` → `customer_id` in `withIdentity()`, `STMS_Ajax::customer()`, `STMS_Bookly_Data::order_payload()` and `readme.md`. Keep `user_id`: that one is correct as-is.*

### 2.5 🔴 PII Violation: Raw Email in `order_id` — ✅ FIXED in 1.2.0

> ✅ **Implemented 2026-09-14:** `order_id` is now Bookly's numeric order id, and the email
> no longer leaves the server at all — the `created_at|email` composite is written to
> `order_key` in the events table and was dropped from the AJAX response as well, so it is
> not even visible in the browser's network tab (§6).
>
> 🔴 **Verdict: real, confirmed in source, highest priority. Implement.**
> `class-stms-bookly-data.php` builds it literally as
> `trim( $first['created_at'] ) . '|' . $first['customer_email']`. The numeric Bookly **order
> id** is already resolved a few lines above (`resolve_order_id()`), so the fix is a one-line
> substitution with no new lookup. See §5.2 item 4.

Both captures push `order_id` containing a raw customer email address:
```
order_id: "2026-09-11 13:57:12|test20252@gmail.com"
```

This violates [Google's PII policy](https://support.google.com/analytics/answer/10374426): *"You will not send any information to Google Analytics that Google could use or recognize as personally-identifiable information."* An email address in a GA4 event parameter is a clear violation that risks account action.

Note: this flaw originates in the spec itself (§5.3 defines the fallback `order_key` as `CONCAT(ca.created, '|', c.email)` — correct for the DB, but the raw value must never reach GA4).

**Fix:** Two options (developer's choice):
- **Option A (recommended):** Use the Bookly payment ID (`p.id`) as `order_id` in GA4 events. It's numeric, unique, and contains no PII. Keep the `created|email` composite in the DB table only.
- **Option B:** SHA-256 hash the composite before pushing to GA4 (`SHA256("2026-09-11 13:57:12|test20252@gmail.com")`). This preserves bundle grouping in GA4 without exposing PII.
- ✅ **Option A′ (preferred in this codebase):** use the Bookly **order id** rather than the payment id. The completed-event query already keys on `ca.order_id`, so it is free, numeric, PII-free, and groups a multi-session bundle under one value by construction — which is exactly what QA test #6 checks.

### 2.6 Architectural Verification Needed (Cannot Confirm from Captures Alone)

The following spec requirements cannot be verified from dataLayer captures and require source-code / database inspection: ✅ *source inspection done 2026-09-14 — verdicts below*

| Spec Requirement | Evidence in Captures | Verdict |
|---|---|---|
| Server verification via `/wp-json/daroon-bookly/v1/verify` (§5.4) | `booking_id`, `status`, `payment_status` in completed event — **consistent with** server lookup | ⚠️ **Cannot confirm** — developer must verify endpoint exists and that completed events only fire on server-confirmed `approved + completed` → ⚪🔴 **checked:** no REST route exists; the equivalent is the admin-ajax action `stms_order_data`, which *does* read the saved order server-side (so the values are server-verified), but does **not** restrict the event to `approved + completed` — it reports the real `status` / `payment_status` instead. Partially real; see §5.2 item 5 |
| `daroon_booking_events` table with GA4 `client_id` (§5.3) | Not visible in dataLayer | ⚠️ **Cannot confirm** — developer must inspect DB schema and verify GA4 client_id is stored via `gtag('get',...)` → 🔴 **checked: not implemented.** No custom table, no `gtag('get', …)`, no `_ga` cookie read anywhere in the plugin. Genuinely missing; see §5.2 item 6 |
| `MutationObserver` on native `.bookly-step-active` (§5.2) | Duplicate payment step_view suggests observer fires on non-stepper DOM mutations | ⚠️ **Needs inspection** — observer target/callback may need tightening → ⚪ **checked: N/A.** No MutationObserver exists; step detection is AJAX-action based, which is strictly more reliable (it survives markup changes and correctly handles Bookly skipping the service step). Do not implement |
| `bookly_booking_pending` for pending bookings (§4.2) | Not observed in either capture | ⚠️ **Not implemented** — optional but recommended for reconciliation → 🔴 **confirmed not implemented**; small, safe addition — see §5.2 item 7 |

---

## 3. Developer Action Plan (Staging → Production)

### Phase 0: Baseline (do first — before any code changes)
- **Run the baseline SQL** (spec §9.1) for the reference period to establish the current GA4-vs-DB gap: 🟡 *ops task, read-only, safe to run — outside this repo*
  ```sql
  SELECT COUNT(*) AS completed_sessions
  FROM {prefix}ba_customer_appointments ca
  JOIN {prefix}ba_appointments      a  ON a.id  = ca.appointment_id
  JOIN {prefix}ba_payments          p  ON p.id  = ca.payment_id
  WHERE ca.created >= '2026-07-09' AND ca.created < '2026-08-06'
    AND ca.status = 'approved'
    AND p.status  = 'completed';
  ```
- Record the current GA4 event counts for the same period (from the Events report). 🟡
- This gap is the improvement target for the 14-day parallel run (Phase 5). 🟡

### Phase 1: Fix Staging Data Layer Bugs (observed in captures)

| # | Fix | Spec Reference | Verdict (2026-09-14) |
|---|---|---|---|
| 1 | **Dedupe `bookly_step_view`:** compare current step index against last-pushed value; push only on change | §5.2 | ✅ **Duplicate task — already shipped** in `d2a711b` (`lastStep` guard + `window.STMSTracker` double-load guard). No work |
| 2 | **`booking_start` before first step view:** ensure push fires on `.bookly-form` detection before the `MutationObserver` processes the first step | §5.2 | ⚪ **Can be implemented, not advisable** — would duplicate `step_view / init / 0`. Use `init` as the funnel entry instead |
| 3 | **Remove or rename `client_id`** from all GA4-bound events. It's the Bookly customer ID — either drop it or rename to `customer_id` and register as custom dimension | §6.3 | 🔴 **Real — implement (rename).** Low risk, ~5 lines across JS + PHP + readme. Must land **before** the Phase 3 GTM build — ✅ **DONE (1.2.0)**, renamed to `customer_id` |
| 4 | **Fix PII in `order_id`:** use Bookly payment ID or SHA-256-hash the `created\|email` composite before pushing to GA4; keep raw key in DB only | §5.3, Google PII policy | 🔴 **Real — implement first.** One line in `order_payload()`; use the already-resolved numeric order id — ✅ **DONE (1.2.0)**, now the Bookly order id; the composite moved to `order_key` in the events table |
| 5 | Keep the corrected `session_value` math and `subtotal`/`coupon_discount` fields (already good) | §4.3 | ✅ **Duplicate task** — verified present in `session_value()`, `details_subtotal()`, `payment_coupon_discount()`, `cart_coupon_discount()`. No work |

### Phase 2: Verify & Complete Server Architecture

| # | Task | Spec Reference | Verdict (2026-09-14) |
|---|---|---|---|
| 6 | Confirm `/wp-json/daroon-bookly/v1/verify` endpoint exists and returns only `approved + completed` bookings | §5.4 | ⚪🔴 **Endpoint does not exist and does not need to** — `stms_order_data` (admin-ajax) is the server-side equivalent and is already authoritative. The *gating* half is real but optional: prefer filtering in GTM on `status`/`payment_status` over dropping the push, so pending/failed stay visible |
| 7 | Confirm `wp_daroon_booking_events` table exists with `UNIQUE(booking_id)`, stores GA4 `client_id` (via `gtag('get',...)` or `_ga` cookie fallback), and `event_sent` flag | §5.3 | 🔴 **Genuinely missing.** Implementable but it is a new subsystem (table + activation hook + writer endpoint + dedupe + retention/GDPR). Only worth it if the §9.4 channel-attribution join is actually going to be built — otherwise skip — ✅ **DONE (1.2.0)**: `{prefix}daroon_booking_events`, `UNIQUE(booking_id)`, `ga_client_id` from `gtag('get', …)` with the `_ga` cookie as fallback, `event_sent`, plus `order_key` for the reconciliation join |
| 8 | Confirm the `MutationObserver` targets `.bookly-step-active` (native stepper class), not `#step-*` injected IDs | §5.2 | ⚪ **N/A — do not implement.** No observer exists; the AJAX-action approach supersedes it |
| 9 | Implement `bookly_booking_pending` for bookings that reach the done step but are not yet `approved + completed` in the DB (optional, feeds reconciliation gap report) | §4.2, §9.3 | 🔴 **Missing — safe to implement.** `pushBookingCompleted()` already holds `status` + `payment_status`; branch the event name on them. ~10 lines — ✅ **DONE (1.2.0)**, with a free (fully-couponed) order counted as confirmed rather than pending |

### Phase 3: GTM Container Rebuild

| # | Task | Spec Reference | Verdict (2026-09-14) |
|---|---|---|---|
| 10 | Archive 11 legacy step tags + 15 visibility/click triggers (incl. orphaned `Appointment` trigger) | §6.1 | 🟡 GTM console — outside this repo. Safe |
| 11 | Add Data Layer Variables for all event parameters (per spec §6.3 table) | §6.3 | 🟡 GTM — remember the extra fields this plugin sends beyond spec: `flow_id`, `subtotal`, `coupon_discount`, `user_id` |
| 12 | Add Custom Event triggers for `booking_start`, `bookly_step_view`, `bookly_payment_started`, `bookly_booking_completed` (+ `bookly_booking_pending` if implemented) | §6.3 | 🟡 GTM. Safe |
| 13 | Add GA4 event tags (Measurement ID `G-NT8THMV82G`) mapped to triggers per §6.3 | §6.3 | 🟡 GTM. Safe |
| 14 | Verify no `client_id` parameter passes through any GTM tag | §6.3 | 🟡 GTM — **depends on Phase 1 #3**; do the rename first or this check will have to be redone |

### Phase 4: GA4 Admin Setup (do at launch — no backfill)

| # | Task | Spec Reference | Verdict (2026-09-14) |
|---|---|---|---|
| 15 | Mark `bookly_booking_completed` as a Key Event (Conversion) | §7 | 🟡 GA4 admin. Safe |
| 16 | Register custom dimensions: `therapist`, `service`, `payment_method`, `coupon`, `order_id`, optionally `step`, `customer_id` (if renamed from `client_id`) | §7 | 🟡 GA4 admin — register `customer_id`, not `client_id`, once Phase 1 #3 lands |
| 17 | Confirm `value`/`currency` = `order_total`/`USD` on `bookly_booking_completed` for GA4 revenue reporting | §7 | 🟡 GA4/GTM — ⚠️ **note:** the plugin pushes `order_total`, not `value`. The GA4 tag must map `order_total` → `value` explicitly, or revenue stays empty |

### Phase 5: QA & Rollout

| # | Task | Spec Reference | Verdict (2026-09-14) |
|---|---|---|---|
| 18 | Run the 14-point QA test matrix on staging (esp. #3: exactly-once steps, #6: two-session bundle → 2 events with shared `order_id`, #7: `DAROON23` free booking, #11: double-click dedupe, #12: close tab during PayPal) | §8 | 🟡 QA — re-capture on a build that actually contains `d2a711b` before re-testing #3. #6 is satisfied for free by the order-id fix. #12: note that a PayPal Standard return is a new page load, so `flow_id` legitimately changes (documented in `readme.md`) |
| 19 | Publish plugin + rebuilt GTM container to production | §11 Phase 3 | 🟡 Release. Safe |
| 20 | 14-day parallel run with daily reconciliation: GA4 `bookly_booking_completed` count vs. Bookly DB (target ±2–3%) | §9.2 | 🟡 Ops. Safe |
| 21 | Build channel/source report via `client_id` join in Looker Studio / BigQuery (spec §9.4) | §9.4 | 🟡 **Blocked by Phase 2 #7** — there is no GA4 `client_id` store today, so this cannot be built as written |
| 22 | Deprecate old `step-*` event names; update dashboards; hand off | §11 Phase 7 | 🟡 Ops. Safe |

---

## 4. Summary of Findings

### What's working well
- `session_value` math is now correct (`order_total / sessions_in_order`) ✅ *verified in source*
- `flow_id` provides consistent session stitching across all events ✅ *verified*
- `subtotal` and `coupon_discount` added beyond spec requirements ✅ *verified*
- No `client_id` leaking onto mid-funnel step views anymore ⚠️ *correction: not a code change — a logged-in visitor still carries it on every step view*
- JS errors eliminated in the new capture ✅
- All four core events fire with the expected parameter set ✅ *verified*

### What must be fixed before production
1. **Dedupe `bookly_step_view`** — payment step fires twice in both versions ✅ **already fixed in `d2a711b`** — re-capture before re-raising
2. **PII in `order_id`** — raw email sent to GA4; use payment ID or hash 🔴 **real — fix first** → ✅ **fixed in 1.2.0** (numeric order id)
3. **`client_id` misnaming** — Bookly customer ID pushed to GA4 under GA4's `client_id` name; remove or rename 🔴 **real — rename to `customer_id`** → ✅ **fixed in 1.2.0**
4. **`booking_start` ordering** — should fire before first step view ⚪ **design choice, leave as is**
5. **Server verification** — must be confirmed (not assumed) via endpoint + DB inspection ✅ **confirmed: server-side read is real** (`stms_order_data`); optional status gating remains
6. **Baseline SQL** — must be run before rollout to establish the gap target 🟡 **ops, unchanged**

---

## 5. Code verification against the plugin (added 2026-09-14)

Checked against `simyatech-tagmanager-suite` at HEAD `d2a711b` — `assets/js/datalayer.js` (550 lines)
and `includes/class-stms-{plugin,assets,ajax,page-meta,language,bookly-data}.php`.

### 5.1 The one structural mismatch: there is no MutationObserver

Three findings (§2.2 root cause, §2.6 row 3, Phase 2 #8) assume a DOM-mutation tracker. This
plugin does not have one. Steps are detected from Bookly's own admin-ajax traffic:

```js
$(document).ajaxSuccess(function (event, xhr, settings) { … });
var STEP_BY_ACTION = {
    bookly_render_service: 'init',  bookly_render_time:    'time',
    bookly_render_cart:    'cart',  bookly_render_details: 'details',
    bookly_render_payment: 'payment', bookly_render_complete: 'done'
};
```

and the dedupe the audit asks for is already there:

```js
if (step === lastStep) { return; }   // added in d2a711b, 2026-09-11 02:41 +0330
if (step === 'init') { if (initSent) { return; } initSent = true; }
```

plus a load guard (`if (window.STMSTracker) { return; }`) that makes a second copy of the asset a
no-op. **Timing matters here:** `d2a711b` is timestamped 11 hours *before* the `new-funnel.txt`
capture at 13:57, and that capture already shows this commit's other outputs (`session_value: 0.8`,
`subtotal`, `coupon_discount`) — so the fix was live. Two `payment` views separated by ~6
`uniqueEventId`s is what a payment → details → payment re-entry looks like, and the spec rule is
*"never twice for the same step **in a row**"*, which that satisfies. The raw captures were not in
`audit/`, so this could not be confirmed from the data — **re-capture, and if payment really does
repeat with no intervening step, that is a new bug with a different cause.**

### 5.2 Item-by-item

| # | Audit item | Missing or duplicate? | Can it be implemented? | Should it be? |
|---|---|---|---|---|
| 1 | Dedupe `bookly_step_view` (§2.2, P1 #1) | **Duplicate** — shipped in `d2a711b` | n/a | **No work.** Re-verify on a current build |
| 2 | `booking_start` before first step view (§2.3, P1 #2) | **Duplicate in substance** — `bookly_step_view / init / 0` already marks form boot | Yes — move the push out of the `button.bookly-hour` click handler, ~3 lines | **No.** It would emit two events for one moment and destroy the "picked a slot" signal, which is the more useful funnel entry |
| 3 | `client_id` misnaming (§2.4, P1 #3, P3 #14, P4 #16) | **Missing — real**, and broader: `withIdentity()` stamps it on *every* push (step views included) and adds `user_id` when logged in | Yes — rename to `customer_id` in `datalayer.js`, `class-stms-ajax.php`, `class-stms-bookly-data.php`, `readme.md` | **Yes.** Do it **before** the GTM rebuild. It is a breaking rename for any existing GTM variable — ✅ **done in 1.2.0** |
| 4 | PII email in `order_id` (§2.5, P1 #4) | **Missing — real, confirmed:** `'order_id' => trim( $first['created_at'] ) . '\|' . $first['customer_email']` | Yes — one line; `resolve_order_id()` already returns the numeric Bookly order id | **Yes — highest priority.** Live GA4 policy exposure. Prefer the order id over the payment id: it also groups multi-session bundles (QA #6) — ✅ **done in 1.2.0** |
| 5 | `/wp-json/…/verify` + approved-only gating (§2.6, P2 #6) | **Half duplicate, half missing** — the server-side read exists (`stms_order_data` → `order_payload()`); the `approved + completed` gate does not | Yes — either filter in `order_payload()` or condition the GA4 tag in GTM | **Yes, but in GTM.** Gate the *tag*, not the *push* — dropping the push server-side would hide pending/failed bookings from reconciliation |
| 6 | `daroon_booking_events` table + GA4 `client_id` (§2.6, P2 #7, P5 #21) | **Missing — real.** No table, no `gtag('get', …)`, no `_ga` fallback anywhere | Yes, but it is a new subsystem: table + activation hook + write endpoint + `UNIQUE(booking_id)` + `event_sent` + retention/GDPR | **Only if §9.4 channel attribution is actually being built.** It is the single largest item in this plan; skip it otherwise — ✅ **done in 1.2.0**, so P5 #21 is unblocked |
| 7 | `bookly_booking_pending` (§2.6, P2 #9) | **Missing — real** | Yes — `pushBookingCompleted()` already receives `status` and `payment_status`; branch the event name, ~10 lines | **Yes, cheap and useful** for the §9.3 gap report. Keep `flow_id` and the payload identical so the two events reconcile — ✅ **done in 1.2.0** |
| 8 | Baseline SQL, GTM rebuild, GA4 admin, QA, rollout (P0, P3, P4, P5) | **Out of scope for this repo** — no plugin code involved | n/a | **Safe to proceed**, with two catches: map `order_total` → `value` in the GA4 tag (P4 #17), and P5 #21 is blocked by item 6 |

### 5.3 Not in the audit — found while checking the code

| Finding | Where | Recommendation |
|---|---|---|
| Staging console leak: `const url = window.location.href; if (url.includes("staging")) { console.log(…) }` prints every payload — including `client_id` — on any URL containing "staging", and duplicates the `cfg.debug` branch three lines below it | `datalayer.js`, `push()` | Remove it and use the existing `?stms_debug=1` / `stms_debug` filter. It also introduces ES6 (`const`, `String.includes`) into an otherwise ES5 file, which breaks the older-browser floor the rest of the file keeps |
| `client_id` is an `int` when known and `''` when not, so GA4 receives two types for one parameter | `class-stms-bookly-data.php`, `order_payload()` | Cast to string, or omit the key entirely when unknown (the JS side already omits it) |
| A request with an invalid nonce still succeeds when it carries a `form_id` or `order_token` | `class-stms-ajax.php`, `check_nonce()` | Deliberate and documented (nonces die on cached pages), and both tokens are unguessable — but it does mean anyone holding a form token can read that cart. Worth an explicit note in the readme rather than a code change |

---

## 6. What was implemented (2026-09-14, plugin 1.2.0)

Decision-table rows **1, 2, 3 and 4** — plus row 12, which is the same expression as row 2.
Rows 5, 11 and 13 were left open on purpose; rows 6–10 are the "do nothing" set.

### 6.1 Row 1 — the email is out of `order_id`

`class-stms-bookly-data.php`, `order_payload()`:

```php
// before
'order_id' => trim( (string) $first['created_at'] ) . '|' . (string) $first['customer_email'],
// after
'order_id' => (string) $order_id,   // Bookly's own numeric order id
```

The `c.email AS customer_email` select and the `Customer` join went with it, so the address
no longer reaches the AJAX response either — it is not in the dataLayer *and* not in the
browser's network tab. The `created_at|email` key that the reconciliation query needs is
rebuilt server-side by the new `STMS_Bookly_Data::order_key()` and written to the events
table, never sent anywhere.

Using the **order id** rather than the payment id also means every session of a multi-session
order already shares one `order_id`, which is what QA test #6 checks.

### 6.2 Row 2 (+12) — `client_id` → `customer_id`

Renamed in `withIdentity()` (`datalayer.js`), in the `stms_customer` response
(`class-stms-ajax.php`) and in `order_payload()`. `user_id` is untouched — that one was
always correct. The value is now consistently a string (row 12).

**This is a breaking change for GTM:** any Data Layer Variable still reading `client_id` will
go empty. Do the Phase 3 rebuild against `customer_id`, and register the custom dimension in
Phase 4 #16 under that name.

### 6.3 Row 3 — `bookly_booking_pending`

`pushBookingCompleted()` became `pushBookingResult()`, and the server now returns a
`confirmed` flag that decides the event name:

```php
private static function is_confirmed( $status, $payment, $order_total )
{
    if ( ! in_array( $status, array( 'approved', 'done' ), true ) ) { return false; }
    if ( $payment === null || $order_total <= 0 ) { return true; }   // nothing payable
    return (string) $payment['status'] === 'completed';
}
```

The payload, the `flow_id` and the `booking_id` are identical across the two events, so a
booking that is pending at the done step and completes later reconciles against itself.

⚠️ **One trap worth knowing about, which the audit's `approved + completed` rule would have
walked into:** a coupon that takes the whole price off (QA test #7, `DAROON23`) leaves Bookly
with nothing to charge and frequently **no payment row at all**. Gating strictly on
`payment_status === 'completed'` would have filed every free booking as pending, forever.
Nothing payable therefore counts as confirmed.

### 6.4 Row 4 — the events table and the real GA4 client id

New `includes/class-stms-events-store.php` + a `stms_record_event` AJAX action.

* Table `{prefix}daroon_booking_events` — the name the spec's SQL expects, overridable with
  the `stms_events_table` filter. Created on activation, and on the first load after an
  update for a plugin upgraded in place.
* Columns: `booking_id` (**UNIQUE**), `order_id`, `order_key`, `flow_id`, `customer_id`,
  `ga_client_id`, `event_name`, `status`, `payment_status`, `order_total`, `currency`,
  `event_sent`, `created_at`, `updated_at`.
* The GA4 client id comes from `gtag('get', <measurement id>, 'client_id', …)` when
  `stms_ga_measurement_id` is set, and from the `_ga` cookie otherwise — both hold the same
  value. A 1-second timeout means a silent gtag never costs the recording, and a value that
  is not `digits.digits` is dropped rather than stored.
* The write is an upsert on `booking_id`, so a reload, a retry or a PayPal round-trip updates
  one row instead of adding another. An existing `ga_client_id` is never overwritten by an
  empty one, so a gateway return that cannot read the cookie cannot erase what the original
  page stored.
* The browser is trusted for exactly three values only it can know — the GA4 client id, the
  flow id, and which event it pushed. The booking is re-read from Bookly on the server, so a
  caller cannot record an order it holds neither the session nor the token for.

**This unblocks Phase 5 #21** (the channel/source join), which had no client id to join on.

### 6.5 Files touched

| File | Change |
| --- | --- |
| `assets/js/datalayer.js` | identity rename; `pushBookingResult()` + pending event; `gaClientId()` / `cookieClientId()` / `recordEvent()` |
| `includes/class-stms-bookly-data.php` | numeric `order_id`; email out of the query; `is_confirmed()`; `order_key()` |
| `includes/class-stms-events-store.php` | **new** — table install + upsert |
| `includes/class-stms-ajax.php` | `customer_id` in the response; `stms_record_event` endpoint |
| `includes/class-stms-assets.php` | `measurementId` in the JS config |
| `includes/class-stms-plugin.php` | boots the store |
| `simyatech-tagmanager-suite.php` | 1.1.0 → **1.2.0**; activation hook |
| `readme.md` | documents all of the above |

### 6.6 Still open

| Row | Item | Why it was left |
| --- | --- | --- |
| 5 | `approved + completed` gating | Belongs in the GTM tag, not in the plugin — gating the push would hide pending bookings from the very reconciliation report they feed. The `confirmed` flag and the separate `bookly_booking_pending` event (row 3) give GTM everything it needs to gate on |
| 11 | Staging `console.log` in `push()` | Not in rows 1–4. Still recommended: it prints every payload on any URL containing "staging" and duplicates the `cfg.debug` branch below it |
| 13 | Nonce fallback | A readme note, not a code change |
| 14–17 | Baseline SQL, GTM, GA4 admin, QA | Outside this repo |
