# Development journey — from audience foundation to RC18

## Business requirements
Azula Sportswear already operated WooCommerce and a separate customer segmentation plugin. Existing SMS Misr capabilities covered transport, but not the first-party tracking, purchase attribution, campaign reporting and behavioral retargeting needed by the store.

## Milestone progression
- **M1:** Independent plugin foundation, read-only Customer Segments adapter, conservative Egyptian phone normalization and deduplication, encrypted configuration, audit tables. Dispatch was intentionally disabled during foundation testing.
- **Audience diagnostics:** Investigated unexpectedly high invalid phone counts on a live-data-derived STAGING copy. Designed masked diagnostics and synthetic normalizer checks rather than exposing or overwriting original customer phones.
- **Composer/provider:** Campaign drafts, sender and language, encoding and unit estimates, SMS Misr integration, explicit review/approval and throttled/guarded send paths.
- **Tracking/analytics:** Branded first-party link redirects, campaign attribution, WooCommerce add-to-cart/checkout/order events, reports and seven-day journey limits.
- **Retargeting:** Snapshot/dynamic SMS-owned audiences, WooCommerce purchase verification and unknown-evidence exclusions instead of claiming that unobserved activity did not happen.
- **RC17:** Production-readiness and consent limitations identified. Passing local tests had not yet proved positive tracking consent in real LIVE browsers.
- **RC18:** SMS-specific consent banner, cautious audience definitions and purchaser verification in one consolidated review release.

## Validation
RC18 contractor report: 445 PHP/SQL checks and 63 browser cases passed locally; provider behavior mocked for local tests. Publication package report: 64 assertions plus 117 PHP and 12 JS syntax checks. Separate LIVE test observations included accepted test SMS, consented link click, cart and checkout analytics; scheduled delivery and full retargeting were still being checked when documentation was prepared.

## Lessons
Test *real business outcomes*, not just unit-level event callbacks. Establish explicit consent and identity boundaries. Differentiate provider accepted from delivered. Keep source customer data unchanged. Preserve stable production safety gates and make ambiguity visible to operators.
