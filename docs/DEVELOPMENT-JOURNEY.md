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
RC18 contractor report: 445 PHP/SQL checks and 63 browser cases passed locally; provider behavior mocked for local tests. Publication package report: 64 assertions plus 117 PHP and 12 JS syntax checks. Earlier project notes report LIVE test observations including accepted SMS, consented clicks, cart and checkout analytics. These are historical owner/project observations, not a new verification by this publication task; scheduled delivery and the full production chain are not certified here.

## Lessons
Test *real business outcomes*, not just unit-level event callbacks. Establish explicit consent and identity boundaries. Differentiate provider accepted from delivered. Keep source customer data unchanged. Preserve stable production safety gates and make ambiguity visible to operators.

## RC18 implementation detail

This reconstruction uses local implementation reports, changelogs, test records and source. It summarizes engineering work, not a claim that each intermediate release was production-ready. Dates and operational details not established by evidence are omitted.

| Stage | Evidence-backed progression |
|---|---|
| M0 / M1 | Audited existing Azula plugins; independent schema and read-only source adapter; approval foundation, phone normalization, suppression, deduplication and frozen capture. Full composer/dispatch deliberately deferred. |
| M1.1–M1.3 | Protected STAGING phone diagnostics isolated failures; accepted exactly one leading ASCII apostrophe only where the remaining mobile was valid. No customer records changed. |
| M2 / M2.1 / 0.2.2 | Guided composer, automatic bounded audience preparation, progress, searchable/paginated review, category exports and original segment names/IDs/counts. Narrow bidi-formatting handling replaced overly broad rejection. |
| M3 | Cairo/UTC scheduling, background preparation, approval foundations, pause/resume/cancellation; migration and saved-campaign UI state bugs required focused corrections. |
| M4 | Encrypted provider settings, SMS Misr request construction, response codes and balance diagnostics while dispatch stayed disabled. Conflicting Balance GET documentation versus observed POST behavior and uppercase Balance response field required evidence-driven parsing. |
| M5 / M6 | Controlled submission, explicit review, durable attempts, first-party tracking and campaign reporting; acceptance remained distinct from delivery. |
| M7 / M8 | Branded links, cache boundaries and journey analytics. Short-link evolution retained old formats rather than invalidating issued links. |
| M9 / RC1–RC10 | Scheduling/rescheduling, approval refresh, reservations, execution lifecycle and retargeting management stabilization. Unknown outcomes protected from resend; empty audiences gained evidence-based explanations. |
| RC11–RC14 | Production initialization/access diagnostics, environment-aware UI, production sender mapping/persistence and worker readiness alignment. Missing encryption key and generic request errors were separated from legitimate policy requirements. |
| RC15–RC17 | Redirect availability separated from collection, consent default-denial, clearer readiness and expanded local workflow/retention testing. Consent and cache behavior still required real deployment evidence. |
| RC18 | SMS-arrival compact consent notice, signed choices and withdrawal, evidence-aware retargeting, reliable purchaser membership and independent purchase exclusion; send-date and observation-date scopes separated. |

## Decisions and lessons

Phone diagnostics showed why formatting corrections should preserve complete validation rather than stripping arbitrary characters. A source adapter had to preserve business segment rules while adding independent SMS eligibility. Original counts were never presented as validated SMS recipients.

Production access is intentionally isolated from staging; working staging evidence does not silently enable production. Balance freshness, reservation accounting, cancelled/abandoned packages and unknown outcomes are separate lifecycle concerns. Precise durable state is more useful than a generic readiness message.

Retargeting required a semantic correction: absence of a click/cart is not proof of inactivity. RC18 withholds uncertain negative audiences and verifies purchasers independently through WooCommerce identity. Consent-aware attribution necessarily measures less than all commerce; reporting must admit that limitation.

## Validation status

The RC18 consolidated report records 445 PHP/SQL and 63 browser checks, using mocks and isolated synthetic WordPress/WooCommerce environments. That historical report describes WordPress 7.1.3, WooCommerce 11.2.1 and MariaDB 10.11.14. These are recorded test-environment versions, not a new certification against all current versions.

Project history reports real STAGING SMS receipt and scheduled execution. Later operational records establish a matching RC18 LIVE source comparison and pre-send verification work. This export does not assert a completed RC18 production handset receipt or complete LIVE click-to-purchase chain. Current publication checks are separately reported, with no production requests or sends.
