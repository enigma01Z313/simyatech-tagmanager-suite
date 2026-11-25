# Bookly Data Layer — Production Launch Handoff

**Project:** Daroon.me Booking Funnel Analytics (`daroon-bookly-tracking`)
**Date:** 2026-09-25
**Status:** Staging capture `u7ghlnqg…` reviewed — 4 of 6 pre-production blockers resolved
**Decision:** Ship to production after completing the pre-launch items below; remaining items are handled via the post-launch safety net (fix-forward).

**Rationale:** GA4 data never backfills — every delayed week is data lost permanently. The tracker is passive (proven: a mid-funnel JS error did not break `booking_completed`), so remaining bugs affect measurement quality only, never bookings or payments. The one account-risk issue (PII in `order_id`) is already fixed.

---

## ✅ Already Confirmed Fixed (no action — regression-test only)

| Item | Evidence |
|---|---|
| Dedupe of consecutive `bookly_step_view` on payment | payment (42) → details (46) → payment (48) = real back-navigation, not double-fire |
| PII removed from `order_id` | `order_id: "44966"` (numeric) |
| `client_id` renamed to `customer_id` | All post-submit events carry `customer_id` |
| Revenue math | `subtotal 39.95 − coupon_discount 39.15 = order_total 0.8 = session_value` ✓ |
| `booking_completed` fires exactly once | uniqueEventId 69, single push |
| `flow_id` session stitching | Consistent across all 10 events |

---

## 🔴 PRE-LAUNCH — Must Complete Before Publishing

### 1. Add `transaction_id = order_id` to the `bookly_booking_completed` GA4 tag *(highest priority)*

- **Where:** GTM → GA4 event tag for `bookly_booking_completed`.
- **What:** Map the dataLayer `order_id` into the GA4 `transaction_id` parameter.
- **Why:** GA4 auto-deduplicates events by `transaction_id`. This is the revenue safety net — even if double-click dedupe (QA #11) or any future bug double-fires the completed event, **revenue and conversions cannot double-count**.
- **Acceptance:** In GTM Preview, submit a booking and click submit twice rapidly → GA4 DebugView shows the event; BigQuery/GA4 reports count it once per `transaction_id`.
- **DataLayer side done:** the `bookly_booking_completed` push now carries `transaction_id` (= `order_id`) under GA4's own parameter name, so the GTM step is a straight `{{DLV - transaction_id}}` mapping with no rename. **Still to do in GTM: the mapping itself** — a dataLayer key is inert until the tag sends it.

### 2. Confirm revenue mapping on `bookly_booking_completed`

- **What:** GA4 event parameter `value` = dataLayer `order_total`, `currency` = dataLayer `currency` (`USD`).
- **Why:** DataLayer alone does not prove the GTM tag maps revenue; unmapped `value` = silently wrong revenue reporting.
- **Acceptance:** GTM Preview shows `value: 0.8, currency: "USD"` on the fired tag; GA4 DebugView shows the same.
- **DataLayer side done:** the push now carries `value` (= `order_total`) alongside the existing `currency`, both under GA4's own parameter names. **Still to do in GTM: map them on the tag.**

### 3. Normalize the `therapist` parameter format ✅

- **Problem:** `booking_start` sends slug (`vida-yousefi-asl`), `booking_completed` sends display name (`Vida Yousefi Asl`). Same GA4 custom dimension → every therapist splits into two report rows.
- **Fix:** Use one format in both events (slug recommended — stable, no casing/whitespace issues).
- **Acceptance:** Both events emit the identical `therapist` string for the same booking.

### 4. Fix `booking_start` firing order *(if quick — else defer)* ✅

- **Problem:** Fires after `init` (id 19) and `time` (id 20) step views; spec §5.2 requires it on `.bookly-form` detection, before the first step view.
- **Impact:** Low — only matters if `booking_start` is used as entry step of a strictly-ordered GA4 funnel.
- **Acceptance:** Console order = `booking_start` → `init` → `time` → …

---

## 🟡 LAUNCH DAY — GA4 Admin (Phase 4)

| # | Task |
|---|---|
| 5 | **Do NOT mark `bookly_booking_completed` as a Key Event yet.** Wait ~1 week of reconciled data, then mark it. Keeps conversion metrics clean during validation. |
| 6 | Register custom dimensions: `therapist`, `service`, `payment_method`, `coupon`, `order_id`, `customer_id`, optionally `step`. |
| 7 | Add a GA4 annotation for the go-live date (allows clean exclusion of any bad-data windows). |

---

## 🟢 POST-LAUNCH — Safety Net (starts day 1)

### 8. Baseline SQL — run NOW (DB-side, independent of launch)

```sql
SELECT COUNT(*) AS completed_sessions
FROM {prefix}ba_customer_appointments ca
JOIN {prefix}ba_appointments a ON a.id  = ca.appointment_id
JOIN {prefix}ba_payments    p ON p.id  = ca.payment_id
WHERE ca.created >= '2026-07-09' AND ca.created < '2026-08-06'
  AND ca.status = 'approved'
  AND p.status  = 'completed';
```

### 9. 14-day parallel run (daily)

- Compare GA4 `bookly_step_view` step counts and `bookly_booking_completed` count vs. Bookly DB (`approved` + `completed`). **Target: ±2–3%.**
- Watch the **payment/details step ratio**: payment > 105% of details ⇒ dedupe guard failing under re-render → fix then.
- This parallel run is the real QA suite — it catches duplicates, drops, and misfires with production traffic within days.

### 10. Server verification — confirm week 1

- `/wp-json/daroon-bookly/v1/verify` exists and returns only `approved + completed` bookings.
- `wp_daroon_booking_events` table exists: `UNIQUE(booking_id)`, stores real GA4 `client_id` (via `gtag('get',…)` or `_ga` cookie fallback), `event_sent` flag.
- **Why:** this is the reconciliation source of truth (spec §9); console captures cannot prove it.

---

## ⚪ BACKLOG — Post-Launch Iteration

| # | Item | Notes |
|---|---|---|
| 11 | Stress-test dedupe guard | On payment step: toggle Stripe/PayPal radios, apply/remove coupon, trigger validation errors → expect zero extra `payment` step views. Monitor via item 9 ratio in the meantime. |
| 12 | Multi-session bundle (QA #6) | 2-session purchase → 2 `booking_completed` events sharing one `order_id`; verify `session_value = order_total / sessions_in_order` (only tested at sessions = 1 so far). |
| 13 | 100%-off coupon booking (QA #7) | Free booking path untested. |
| 14 | Legacy GTM cleanup (Phase 3) | Console `gtm.uniqueEventId` gaps (21, 24–41, 43–47, 49–64, 67–68) suggest other dataLayer pushes mid-funnel — filter console to bookly events / inspect container; archive the 11 legacy step tags + 15 triggers if still live. |
| 15 | `bookly_booking_pending` event | Optional; feeds reconciliation gap report (spec §4.2, §9.3). |
| 16 | Therapist-page JS bug (separate ticket, **not the tracker**) | `TypeError: Cannot set properties of undefined (setting 'innerHTML')` — inline `setInterval` at `vida-yousefi-asl/:2284`, also seen at `mahshid-naseri/:2279`. Template-level bug on therapist pages. |

---

## Go / No-Go Checklist

- [ ] `transaction_id` mapped on `bookly_booking_completed` (item 1)
- [ ] `value` / `currency` verified in GTM Preview + DebugView (item 2)
- [ ] `therapist` format normalized (item 3)
- [ ] `booking_start` ordering fixed or explicitly deferred (item 4)
- [ ] Baseline SQL recorded (item 8)
- [ ] Parallel-run report scheduled (item 9)

**When boxes 1–3 and 8 are checked → publish.**
