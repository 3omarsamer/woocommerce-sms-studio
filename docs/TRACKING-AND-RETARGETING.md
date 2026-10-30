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
