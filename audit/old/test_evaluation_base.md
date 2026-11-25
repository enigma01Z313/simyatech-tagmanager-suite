# Bookly Data Layer Test Evaluation & Migration Plan

**Project:** Daroon.me Booking Funnel Analytics (`daroon-bookly-tracking`)
**Reference Spec:** `BOOKLY_TRACKING_SPEC.md`
**Date:** 2026-09-12
**Captures reviewed:** `data layer-test.txt` (Sep 9, flow `1keipjn…`) / `new-funnel.txt` (Sep 11, flow `3cged4…`)

---

## 1. Comparison: Previous Version (`data layer-test.txt`) vs. Current Version (`new-funnel.txt`)

### Previous Version (`data layer-test.txt`)

| Category | Finding |
|---|---|
| **JS Errors** | Two errors captured: `SyntaxError: Identifier 'errorTitle' has already been declared` (from `bookly.min.js` validation re-render) and `TypeError: Cannot set properties of undefined (setting 'innerHTML')` (from an inline `setInterval` script at `mahshid-naseri/:2279` — unrelated to the tracker) |
| **`session_value` Bug** | `session_value: 39.95` while `order_total: 0.8` — the pre-discount price was pushed instead of `order_total / sessions_in_order`. This would overstate GA4 revenue vs. the Bookly DB and break reconciliation (spec §4.3, §9.2) |
| **`client_id` on Step Views** | Bookly customer ID (`"7555"`) leaked onto cart/details/payment/done `bookly_step_view` events after the form submit — clogging funnel data with a non-funnel parameter |
| **Missing Payment Detail** | `bookly_payment_started` lacked `subtotal` and `coupon_discount` — reduced coupon analysis depth |
| **Duplicate Step Views** | `bookly_step_view` for payment fired twice (uniqueEventId 34 and 45) — same inflation problem the old DOM-visibility triggers caused |
| **Event Ordering** | `booking_start` fired after `init` and `time` step views (uniqueEventId 23 vs. 20/21) |

### Current Version (`new-funnel.txt`)

| Category | Finding |
|---|---|
| **Flow Consistency** | Consistent `flow_id` (`3cged4…`) across all events — good session stitching |
| **`session_value` Fix** | `session_value: 0.8` = `order_total / sessions_in_order` — now spec-compliant (§4.3) |
| **Payment Detail** | `bookly_payment_started` now includes `subtotal: 39.95`, `coupon_discount: 39.15` — improved coupon/revenue analysis |
| **Step View Cleanup** | `client_id` no longer leaks onto mid-funnel step views — cleaner funnel data |
| **JS Errors** | Zero JavaScript errors in this capture (single session — not generalizable) |
| **Duplicate Step Views — NOT FIXED** | `bookly_step_view` for payment still fires twice (uniqueEventId 51 and 57, identical payloads). This is the most critical remaining bug — it reproduces the exact step-inflation disease the project was created to cure |

### Side-by-Side: Event Parameters Compared

| Parameter | Old (`data layer-test.txt`) | New (`new-funnel.txt`) | Spec (§4) | Status |
|---|---|---|---|---|
| `session_value` | `39.95` (wrong) | `0.8` (correct) | `order_total / sessions_in_order` | ✅ Fixed |
| `subtotal` | absent | `39.95` | Not in spec (optional) | ✅ Added |
| `coupon_discount` | absent | `39.15` | Not in spec (optional) | ✅ Added |
| `client_id` on step views | present (`"7555"`) | absent | §6.3: not in GA4 events | ✅ Fixed |
| `client_id` on `booking_completed` | `"7555"` (Bookly customer ID) | `"7556"` (Bookly customer ID) | §6.3: GA4 client_id only in DB table | ❌ **Misnamed** — see §2.4 |
| `order_id` | `"2026-09-09 09:40:43\|test2020@gmail.com"` | `"2026-09-11 13:57:12\|test20252@gmail.com"` | §5.3: fallback key | ❌ **PII in GA4** — see §2.5 |
| Duplicate `payment` step_view | Yes (2×) | Yes (2×) | §5.2: "push only when step changes" | ❌ **Not fixed** |
| `booking_start` ordering | After step views | After step views | §5.2: when `.bookly-form` appears (first) | ⚠️ Ordering issue |

---

## 2. Audit against `BOOKLY_TRACKING_SPEC.md`

### 2.1 Spec Event Taxonomy vs. Staging Reality

| Spec Event | Spec Params | Staging Status | Issues |
|---|---|---|---|
| `booking_start` | `entry_page`, `therapist`, `language`, `source` | ✅ All present and correct | Fires after `init`/`time` step views instead of first — minor ordering |
| `bookly_step_view` | `step`, `step_index` (1–5) | ⚠️ `init`/`0` added per project owner's design (acceptable); **payment fires twice** | Dedupe broken — §2.2 below |
| `bookly_payment_started` | `payment_method`, `total`, `currency`, `coupon`, `sessions` | ✅ All present + extra `subtotal`/`coupon_discount` | Clean |
| `bookly_booking_completed` | `booking_id`, `status`, `payment_status`, `order_id`, `sessions_in_order`, `order_total`, `session_value`, `currency`, `service`, `therapist`, `slot_start`, `payment_method`, `coupon` | ⚠️ All present + extra fields; **`client_id` present but misnamed; `order_id` contains PII** | §2.4, §2.5 below |

### 2.2 Critical Bug: Duplicate `bookly_step_view` on Payment Step

Both captures show `bookly_step_view` for `step: "payment"` firing twice with identical payloads (old: uniqueEventId 34→45; new: 51→57). Per spec §5.2: *"Push only when the step value changes (never twice for the same step in a row)."*

**Impact:** Inflates the payment funnel step count. If 10% of sessions trigger the duplicate, GA4 payment-step count will be ~110% of the details-step count — breaking the monotonic funnel property (spec §1.3 criterion #2).

**Root cause (likely):** The `MutationObserver` fires on every DOM mutation inside `.bookly-form`, not just stepper-class changes. The payment step re-renders its gateway block (Stripe/PayPal radios) which triggers the observer again without the active step actually changing.

**Fix:** Guard the observer callback to compare the current step index against the last-pushed value; push only when they differ.

### 2.3 `booking_start` Firing Order

In both captures, `booking_start` fires after the `init` (index 0) and `time` (index 1) step views. Per spec §5.2, `booking_start` should fire when `.bookly-form` is detected — before any step view. This is a minor sequencing issue that affects funnel analysis if `booking_start` is used as the funnel entry point.

**Fix:** Ensure the `booking_start` push in the tracker fires on `.bookly-form` DOM insertion/detection, before the `MutationObserver` processes the first step.

### 2.4 `client_id` Misnaming on `bookly_booking_completed`

The field `client_id` in `bookly_booking_completed` carries the **Bookly customer ID** (e.g., `"7556"`), not the GA4 `client_id`. This creates two problems:

1. **GA4 pollution:** Spec §6.3 says *"Do not pass `client_id` in these events."* The GA4 client_id is a long decimal string (e.g., `"123456789.987654321"`) attached automatically by GA4; it must not be sent as a dataLayer parameter.
2. **Conceptual collision:** If a GA4 custom dimension is ever registered for `client_id`, it would show the Bookly customer ID, not the GA4 client_id — breaking the channel-reconciliation bridge (spec §9.4).

**Fix:** Either remove the field from GA4-bound events entirely (keep it server-side only in `daroon_booking_events`), or rename it to `customer_id` and register it as a custom dimension if Bookly customer ID analysis is desired. The GA4 ↔ DB bridge uses the real GA4 `client_id` stored only in the custom table.

### 2.5 🔴 PII Violation: Raw Email in `order_id`

Both captures push `order_id` containing a raw customer email address:
```
order_id: "2026-09-11 13:57:12|test20252@gmail.com"
```

This violates [Google's PII policy](https://support.google.com/analytics/answer/10374426): *"You will not send any information to Google Analytics that Google could use or recognize as personally-identifiable information."* An email address in a GA4 event parameter is a clear violation that risks account action.

Note: this flaw originates in the spec itself (§5.3 defines the fallback `order_key` as `CONCAT(ca.created, '|', c.email)` — correct for the DB, but the raw value must never reach GA4).

**Fix:** Two options (developer's choice):
- **Option A (recommended):** Use the Bookly payment ID (`p.id`) as `order_id` in GA4 events. It's numeric, unique, and contains no PII. Keep the `created|email` composite in the DB table only.
- **Option B:** SHA-256 hash the composite before pushing to GA4 (`SHA256("2026-09-11 13:57:12|test20252@gmail.com")`). This preserves bundle grouping in GA4 without exposing PII.

### 2.6 Architectural Verification Needed (Cannot Confirm from Captures Alone)

The following spec requirements cannot be verified from dataLayer captures and require source-code / database inspection:

| Spec Requirement | Evidence in Captures | Verdict |
|---|---|---|
| Server verification via `/wp-json/daroon-bookly/v1/verify` (§5.4) | `booking_id`, `status`, `payment_status` in completed event — **consistent with** server lookup | ⚠️ **Cannot confirm** — developer must verify endpoint exists and that completed events only fire on server-confirmed `approved + completed` |
| `daroon_booking_events` table with GA4 `client_id` (§5.3) | Not visible in dataLayer | ⚠️ **Cannot confirm** — developer must inspect DB schema and verify GA4 client_id is stored via `gtag('get',...)` |
| `MutationObserver` on native `.bookly-step-active` (§5.2) | Duplicate payment step_view suggests observer fires on non-stepper DOM mutations | ⚠️ **Needs inspection** — observer target/callback may need tightening |
| `bookly_booking_pending` for pending bookings (§4.2) | Not observed in either capture | ⚠️ **Not implemented** — optional but recommended for reconciliation |

---

## 3. Developer Action Plan (Staging → Production)

### Phase 0: Baseline (do first — before any code changes)
- **Run the baseline SQL** (spec §9.1) for the reference period to establish the current GA4-vs-DB gap:
  ```sql
  SELECT COUNT(*) AS completed_sessions
  FROM {prefix}ba_customer_appointments ca
  JOIN {prefix}ba_appointments      a  ON a.id  = ca.appointment_id
  JOIN {prefix}ba_payments          p  ON p.id  = ca.payment_id
  WHERE ca.created >= '2026-07-09' AND ca.created < '2026-08-06'
    AND ca.status = 'approved'
    AND p.status  = 'completed';
  ```
- Record the current GA4 event counts for the same period (from the Events report).
- This gap is the improvement target for the 14-day parallel run (Phase 5).

### Phase 1: Fix Staging Data Layer Bugs (observed in captures)

| # | Fix | Spec Reference |
|---|---|---|
| 1 | **Dedupe `bookly_step_view`:** compare current step index against last-pushed value; push only on change | §5.2 |
| 2 | **`booking_start` before first step view:** ensure push fires on `.bookly-form` detection before the `MutationObserver` processes the first step | §5.2 |
| 3 | **Remove or rename `client_id`** from all GA4-bound events. It's the Bookly customer ID — either drop it or rename to `customer_id` and register as custom dimension | §6.3 |
| 4 | **Fix PII in `order_id`:** use Bookly payment ID or SHA-256-hash the `created\|email` composite before pushing to GA4; keep raw key in DB only | §5.3, Google PII policy |
| 5 | Keep the corrected `session_value` math and `subtotal`/`coupon_discount` fields (already good) | §4.3 |

### Phase 2: Verify & Complete Server Architecture

| # | Task | Spec Reference |
|---|---|---|
| 6 | Confirm `/wp-json/daroon-bookly/v1/verify` endpoint exists and returns only `approved + completed` bookings | §5.4 |
| 7 | Confirm `wp_daroon_booking_events` table exists with `UNIQUE(booking_id)`, stores GA4 `client_id` (via `gtag('get',...)` or `_ga` cookie fallback), and `event_sent` flag | §5.3 |
| 8 | Confirm the `MutationObserver` targets `.bookly-step-active` (native stepper class), not `#step-*` injected IDs | §5.2 |
| 9 | Implement `bookly_booking_pending` for bookings that reach the done step but are not yet `approved + completed` in the DB (optional, feeds reconciliation gap report) | §4.2, §9.3 |

### Phase 3: GTM Container Rebuild

| # | Task | Spec Reference |
|---|---|---|
| 10 | Archive 11 legacy step tags + 15 visibility/click triggers (incl. orphaned `Appointment` trigger) | §6.1 |
| 11 | Add Data Layer Variables for all event parameters (per spec §6.3 table) | §6.3 |
| 12 | Add Custom Event triggers for `booking_start`, `bookly_step_view`, `bookly_payment_started`, `bookly_booking_completed` (+ `bookly_booking_pending` if implemented) | §6.3 |
| 13 | Add GA4 event tags (Measurement ID `G-NT8THMV82G`) mapped to triggers per §6.3 | §6.3 |
| 14 | Verify no `client_id` parameter passes through any GTM tag | §6.3 |

### Phase 4: GA4 Admin Setup (do at launch — no backfill)

| # | Task | Spec Reference |
|---|---|---|
| 15 | Mark `bookly_booking_completed` as a Key Event (Conversion) | §7 |
| 16 | Register custom dimensions: `therapist`, `service`, `payment_method`, `coupon`, `order_id`, optionally `step`, `customer_id` (if renamed from `client_id`) | §7 |
| 17 | Confirm `value`/`currency` = `order_total`/`USD` on `bookly_booking_completed` for GA4 revenue reporting | §7 |

### Phase 5: QA & Rollout

| # | Task | Spec Reference |
|---|---|---|
| 18 | Run the 14-point QA test matrix on staging (esp. #3: exactly-once steps, #6: two-session bundle → 2 events with shared `order_id`, #7: `DAROON23` free booking, #11: double-click dedupe, #12: close tab during PayPal) | §8 |
| 19 | Publish plugin + rebuilt GTM container to production | §11 Phase 3 |
| 20 | 14-day parallel run with daily reconciliation: GA4 `bookly_booking_completed` count vs. Bookly DB (target ±2–3%) | §9.2 |
| 21 | Build channel/source report via `client_id` join in Looker Studio / BigQuery (spec §9.4) | §9.4 |
| 22 | Deprecate old `step-*` event names; update dashboards; hand off | §11 Phase 7 |

---

## 4. Summary of Findings

### What's working well
- `session_value` math is now correct (`order_total / sessions_in_order`)
- `flow_id` provides consistent session stitching across all events
- `subtotal` and `coupon_discount` added beyond spec requirements
- No `client_id` leaking onto mid-funnel step views anymore
- JS errors eliminated in the new capture
- All four core events fire with the expected parameter set

### What must be fixed before production
1. **Dedupe `bookly_step_view`** — payment step fires twice in both versions
2. **PII in `order_id`** — raw email sent to GA4; use payment ID or hash
3. **`client_id` misnaming** — Bookly customer ID pushed to GA4 under GA4's `client_id` name; remove or rename
4. **`booking_start` ordering** — should fire before first step view
5. **Server verification** — must be confirmed (not assumed) via endpoint + DB inspection
6. **Baseline SQL** — must be run before rollout to establish the gap target
