# User attribution tracking — design (NOT implemented)

**Status:** proposal only. Nothing in this document is built, migrated or deployed. It exists so the Acquisition tab can say precisely
what is missing and what it would take.

## Why the Acquisition tab cannot show these today

| Wanted | Why it is not possible now |
|---|---|
| Registrations by UTM source / medium / campaign | The sign-up request carries no UTM and no `visitor_id`; UTM lives only in `analytics_events` rows of the page that had it in its URL. |
| Referral codes | No code is stored with an account (no column, no table). |
| «Direct» registrations | Absence of data is not evidence of a direct visit. Today an empty source means *unknown*. |
| Conversion rate | Visits are anonymous and consent-gated; no visit is linked to the account it became. |

What exists: `users.how_did_you_hear_about_us` (optional free text, self-reported) and `analytics_events` (consent-gated visits).

## Principles

1. **Consent first.** Capture only after the visitor accepted analytics cookies (the existing `funnelTrackingAllowed()` contract). No consent ⇒ nothing stored, the account's source stays *unknown*.
2. **First-touch, captured once.** Store the first attribution seen for a visitor and never overwrite it; the sign-up reads it, it is not recomputed.
3. **No backfill, no guessing.** Accounts created before capture existed keep *unknown*. Nothing is inferred from IPs, referrers of later visits, or email domains.
4. **Minimal and aggregate-friendly.** Store campaign tags, not browsing history. Reports stay aggregates.
5. **Deduplicated and bounded.** One row per account; bounded string lengths; no free-form blobs.

## Proposed data

A new table (one row per account, written at sign-up):

```
user_attributions
  id
  user_id            unique, FK users (cascade on delete)
  source             nullable string(120)   utm_source, lower-cased/trimmed
  medium             nullable string(120)
  campaign           nullable string(160)
  referral_code      nullable string(64)    validated against referral_codes (below)
  landing_path       nullable string(255)   path only, query string stripped
  referrer_host      nullable string(120)   host only
  visitor_id         nullable string(64)    ties to analytics_events when consent was given
  consented          boolean                capture happened under analytics consent
  captured_at        timestamp              first-touch time (not sign-up time)
  created_at
  index (source, created_at), index (campaign, created_at), index (referral_code)
```

Optional, only if referral codes become a product feature:

```
referral_codes   id, code (unique), owner_user_id nullable, label, created_by, active, created_at
```

## Capture flow

1. Frontend (already has `visitor_id` and consent state): on first page view with `utm_*` or `?ref=` **and** consent, keep the first-touch values in
   `localStorage` (`emc_first_touch`, with a captured-at timestamp; never overwritten).
2. `POST /auth/register` (and QuickJoin / other account-creating paths) sends `first_touch` only when it exists. The server validates lengths and
   the referral code, ignores anything malformed, and writes **one** `user_attributions` row inside the same transaction as the account.
3. Without consent the field is simply absent: no row, source stays *unknown*.
4. Emit `signup_completed` (declared in `funnelEvents.ts` but not sent today) with the `visitor_id`, so visit→account conversion becomes measurable
   **for consenting visitors only**.

## What becomes possible, and how to label it

* Registrations by source/medium/campaign — over accounts that have a row; the rest remain *unknown* (state the coverage %).
* Referral performance — accounts per validated code.
* Conversion rate — `signup_completed` ÷ distinct consenting visitors per campaign, **labelled as consent-based** (a lower-bound sample, not the whole audience).
* «Direct» — still not claimed; at most «no campaign tags at first touch (consenting visitors)».

## Privacy and retention

* Document in the privacy notice; aggregates only in the admin UI (`users.acquisition.view`).
* `visitor_id` is a random UUID from the browser, no IP, no fingerprinting.
* Deleting an account deletes its attribution row (cascade). Rows follow the analytics retention policy.
* No attribution without consent; withdrawing consent stops further capture (existing rows are unaffected unless the account is deleted).

## Rollout (when approved)

1. Migration + model + validation + tests (no UI change).
2. Frontend capture + sign-up payload behind a feature flag; verify consent gating end to end.
3. Switch the Acquisition tab's «unavailable» cards on, with coverage shown next to every figure.
4. Review after N weeks: coverage %, consent rate, bias of the consenting sample.

## Out of scope / not proposed

Cross-device identity, fingerprinting, IP geolocation, retro-attribution of existing accounts, third-party ad pixels.
