# Tracking, attribution and retargeting

## First-party SMS measurement
A branded link directly opens its product/collection destination. Its signed, consent-dependent journey state can attribute eligible clicks, add-to-carts, checkout starts, WooCommerce purchases and revenue to an SMS campaign for a bounded seven-day window.

**Consent:** The SMS-specific notice offers Accept/Decline and withdrawal; no cookie or recipient journey should be inferred from a preview, an unconsented arrival or merely a successful redirect. Declining means optional journey analytics are not collected, not that the SMS wasn't received.

**Event interpretation:** Provider acceptance is not handset delivery. Missing add-to-cart or purchase events are UNKNOWN, not verified absence of action. A link may be shared; link ownership is not proof of who clicked.

## Behavioral audiences
- Positive clicked audience: observed permitted link activity.
- Purchased: qualifying orders, with WooCommerce buyer validation.
- Potential abandoned cart/checkout: qualifying observed activity, followed by inactivity threshold and independent buyer verification.
- Clicked with no observed cart: not automatically treated as proof of inactivity.

**Purchase exclusion:** Before recovery targeting, check independent WooCommerce orders when identity can be reliably matched. Ambiguous matches, unavailable data and unresolved states should withhold recovery membership, not force a nonbuyer classification.

**Time:** A roughly 24-hour inactivity evaluation is separate from seven-day journey attribution. The date of a campaign send and the date of later observed shopping activity are distinct filters.

The goal is **cautious retention marketing**, not perfect individual identification.

## Tracking And Attribution — implementation detail

## Direct redirect, conditional measurement

`M7Links`, `M7Front` and `M6Tracking` distinguish operational link lookup/redirect from optional collection. Valid links open the intended allowed destination directly. Preview links are operational previews, not evidence of a recipient click or a completed SMS. Revoked, invalid or inactive links can be unavailable intentionally.

`M8Tokens` allocates collision-checked ten-character URL-safe tokens for the current generation and accepts supported legacy lengths. Tokens are capabilities, not identities or authentication. `RC1LinkGuard` applies bounded shared-database rate counters without trusting arbitrary forwarded IP headers. Abuse protections reduce, not eliminate, guessing risk; proxy peer aggregation can affect legitimate visitors.

The SMS-arrival notice uses an encrypted, short-lived operational handoff, keeping the recipient token out of the destination query string and shared markup. It does not record a click before permission. `SmsConsent` validates a deployment-bound signed choice and same-origin, expiring proof. Explicit refusal takes precedence; an absent framework never silently grants permission. Existing framework denial also prevents optional SMS analytics. A positive SMS choice does not grant permission to GA4, Meta or other plugins.

Accept can resume the permitted journey with a Secure HttpOnly journey cookie. Decline and withdrawal prevent new optional collection and clear journey/context state as implemented; previously lawful history is not automatically erased. The choice cookie lasts 30 days, subject to validity and browser policies. The visitor always retains access to the destination.

## What the model proves

`M8Events` observes permitted visits, product views, carts and checkout. `M6Tracking` constrains order association to seven days and compatible Stage A/B attribution evidence. A newer explicit competing acquisition source prevents continued SMS ownership as required by the code. This is not a claim of multi-touch, view-through or Meta seven-day click equivalence.

`M6Revenue` reads WooCommerce orders/refunds, stores one SMS-owned association per order, applies audited eligibility exclusions, requires EGP and validates monetary amounts. Partial refunds reduce net eligible revenue; fully refunded orders do not contribute positive eligible revenue. Campaign/global totals use these owned associations rather than adding independent dashboards together. Provider accepted/rejected/unknown outcomes are separate from shopping metrics; acceptance is not delivery.

## Accuracy limits

Cookies can be deleted, consent declined, scripts blocked, devices switched or links forwarded. An associated recipient is the link's intended recipient, not proof of the physical actor. Missing observations remain UNKNOWN. Cross-device later purchases need reliable WooCommerce identity evidence; no phone-only guess should create purchaser identity. Seven-day reporting attribution and the 24-hour recovery observation threshold answer different questions.

## Cache boundary

Consent/route responses use private/no-store, CDN no-store, LiteSpeed no-cache, `Vary: Cookie` and no-referrer policies through `SmsConsent::headers` and route handlers. The destination can use neutral cacheable markup with a protected consent bootstrap. Operators must still verify edge behavior and cookie isolation; source headers cannot certify a particular Cloudflare or LiteSpeed deployment. Server/CDN access logs remain a separate operational privacy responsibility.

## Retargeting — implementation detail

SMS Studio manages its own saved audiences through `RC2Audiences` and exposes them through `M9Source`. It does not add behavioral rules to, or mutate, the separate Customer Segments plugin. Snapshot membership and dynamic reevaluation are different: a snapshot preserves captured membership, while current safety and purchase exclusions still apply. Dynamic sources reevaluate supported predicates. Approved recipient lists remain frozen.

| Audience | Evidence / limitation |
|---|---|
| Clicked | Positive permitted click observation associated with an intended recipient; not proof of physical actor |
| Clicked, no observed cart | Absence of downstream cart remains UNKNOWN; insufficient negative evidence is withheld |
| Cart, no purchase | Potential recovery after observation/inactivity conditions, reliable identity and independent purchase exclusion |
| Checkout, no purchase | Potential recovery after the existing 24-hour condition, checkout evidence and purchase exclusion |
| Purchased | Qualifying associated order plus reliable actual buyer match |
| No activity / refused tracking | No inferred inactivity or automatic negative targeting from absent optional analytics |

`RC18Retargeting::decorate` labels unknown evidence; `purchaser` validates actual orders. `M11Purchases::check` independently reads WooCommerce orders across marketing channels before negative membership. Logged-in matches use customer IDs. Guest matching requires email and first/last names; email or phone alone is insufficient. Conflicting, unavailable, bounded-query-overflow or uncertain order evidence withholds the record. Refunded buyers are not classified as unfinished checkouts merely because their revenue is zero.

Campaign-send date filters use recipient submission dates; observation filters use activity dates. A purchase after SMS submission but before the recorded click can exclude recovery. The seven-day attribution window remains unchanged; it is not an instruction to wait seven days before evaluating potential checkout recovery.

Saved-audience management includes names, membership type, eligible counts and explanations. Deleting an unused SMS-owned audience must preserve historical references and must not compromise active/scheduled campaigns. No-management action deletes Customer Segments records or campaign history.

The central tradeoff is deliberate under-targeting rather than claiming certainty from missing data. Positive purchaser membership is stronger than inferred non-purchase, but even independent order matching cannot prove that an unidentified purchase never happened.
