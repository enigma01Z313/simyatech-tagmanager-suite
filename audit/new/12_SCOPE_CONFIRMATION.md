# Scope Confirmation — Developer

**Decision:** Implement Phase 1 only. Defer refunds, offline bookings, and CMP. Keep `init` removed.

**Goal:** measure traffic share by channel / source-medium, and conversion rate through the booking funnel to `purchase`. Anything that does not change that number is out of scope for now.

---

## Why we are skipping these

**CMP** — Consent Management Platform (cookie banner / Consent Mode v2) is a privacy-compliance feature, not a measurement feature. It does not improve source/medium attribution or funnel CR. Revisit only before EU/UK ads or if legally required.

**Refund** — affects net revenue after the purchase, not channel share or booking CR. Reconcile refunds in Bookly for now; the tracker helper can stay dormant.

**Offline booking** — cannot be attributed to a web channel without the original `ga_client_id`. Inventing one would corrupt the data. It does not change online funnel CR.

---

## Why `init` stays removed

`init` is Bookly's form boot / service-screen render, not a user action. Keeping it would add a step that exists only on the appointment page, while therapist pages start at `time`. The two entry points would no longer be comparable by channel.

The common top of funnel is:

1. `view_booking_form` — the form/screen appeared
   - therapist page: time screen
   - appointment page: service screen
2. `booking_start` — the first real choice
   - therapist page: time-slot click
   - appointment page: service selected + Next

`booking_source` still separates `therapist_page` from `appointment_page`, so nothing needed for the goal is lost.

---

## Why no PHP change is needed for `purchase`

The existing `stms_order_data` endpoint already returns only confirmed orders (`status=approved`, `payment_status=completed`) and does not send a separate `confirmed` field. The updated tracker derives `confirmed` from those two values. No server-side change is required for purchase tracking.

`booking_pending` can stay in GTM but remains dormant until the endpoint is extended to return pending orders.

That is the full scope change for this round. The existing tracker/GTM documentation covers the event contract; refunds, offline bookings, CMP, and `init` are not part of this task.
