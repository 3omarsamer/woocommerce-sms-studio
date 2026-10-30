# Azula SMS Studio

**Consent-aware, first-party SMS journey tracking and behavioral retargeting for WooCommerce.**

Built for **Azula Sportswear**, an Egypt-focused e-commerce store, this custom WordPress platform connects customer segmentation, SMS campaign operations, branded tracking links, behavioral analytics, WooCommerce purchases and controlled background execution.

> **Project status:** RC18 portfolio case study. The reviewed RC18 implementation, six redacted portfolio images, synthetic smoke tests, examples and publication reports are included in this repository. Documentation does not imply that every production verification has passed.

## Why we built it

SMS Misr handles the SMS provider infrastructure, but our business needed marketing capabilities beyond simply sending campaigns from an external dashboard. We wanted campaign management, segmented recipients, tracking, revenue attribution and retargeting **inside our own WooCommerce environment**.

**Our workflow:** Customer Segments → audience preparation and Egyptian phone normalization → immutable campaign review → SMS Misr submission → branded recipient links → consent-aware shopping journeys → campaign analytics → behavioral audiences.

## The engineering highlights

### Branded first-party links and attribution

- Recipient-specific branded URLs redirect directly to the intended store destination.
- Permitted journeys can record SMS clicks, add-to-cart, checkout and qualifying WooCommerce purchases.
- Seven-day SMS attribution window, separate from the 24-hour potential-abandonment waiting period.
- Campaign/global reporting for attempted, provider-accepted, rejected and unknown outcomes, clicks, commerce events and attributed revenue.
- **Provider acceptance does not prove handset delivery.** Missing tracking events are **UNKNOWN**, not proof a person took no action.

### Behavioral retargeting

SMS Studio builds its own snapshot/dynamic audiences. It supports positive clicked audiences, buyer-verified purchaser audiences and **potential** cart/checkout recovery, with independent WooCommerce purchase checks before recovery targeting. A recorded link belongs to an intended recipient, not necessarily the person physically operating the browser. Consent loss and cross-device journeys limit inference.

**Example:** A customer clicks an SMS, adds leggings to cart and begins checkout. After an inactivity period the system can evaluate a recovery audience, but suppresses reliably matched buyers and withholds uncertain identity/order evidence. This is deliberately safer than assuming that an unrecorded purchase did not happen.

### Customer Segments and phone data quality

- Read-only integration with the separate Azula Customer Segments plugin.
- Egyptian number normalization across supported local, +20, 0020 and 20-prefixed formats.
- Rejection of missing/invalid/ambiguous numbers; deduplication by normalized recipient.
- Original customer phone values are not rewritten.
- Frozen approved audiences protect against unexpected segment drift between review and submission.

### SMS Misr and campaign controls

- SMS Misr provider integration, sender configuration, balance checks and parsed submission outcomes.
- Message composition, Unicode/encoding and estimated SMS-unit calculations.
- Approval gates, recipient limits, immutable send authorization and guarded retry handling.
- Consent-aware tracking with compact accept/decline controls and reversible permissions.

### Background scheduling on Hostinger

WordPress **Action Scheduler** coordinates approved send jobs. A **Hostinger server-side cron trigger, with an approximately five-minute target cadence,** can wake processing regardless of whether the administrator is logged in or the laptop is switched on. The available source/evidence does not independently establish the current dedicated LIVE trigger interval; see [background scheduling](docs/BACKGROUND-SCHEDULING.md). Africa/Cairo schedule selections are stored in UTC. Worker checks, locks and durable attempt claims help limit duplicate sends. A background runner is independent of the local browser, but execution still depends on hosting and runner health.

## Architecture

```mermaid
flowchart LR
 A[Azula Customer Segments] --> B[Phone validation and deduplication]
 B --> C[Frozen recipient snapshot]
 C --> D[Composer and explicit approval]
 D --> E[Action Scheduler / guarded worker]
 E --> F[SMS Misr API]
 C --> G[Branded tracking links]
 G --> H[Direct WooCommerce destination]
 H --> I{Valid SMS tracking consent?}
 I -->|yes| J[First-party journey]
 J --> K[WooCommerce events and purchase verification]
 K --> L[Analytics and retargeting]
```

## Technical stack

WordPress, WooCommerce, PHP, JavaScript, WooCommerce Action Scheduler, SMS Misr HTTP API, first-party attribution, Hostinger cron scheduling, and a separate custom Customer Segments plugin.

The export is based on **1.0.0-rc.18 (RC18)** and is application-specific rather than a general-purpose one-click plugin. It depends on other private Azula plugins and explicit production safeguards.

## Development and validation

The work progressed from a dispatch-disabled audience and normalization foundation through message composition, provider integration, tracking, consent, customer journey attribution, WooCommerce recovery verification and production safety gates.

RC18's local review reported **445 PHP/SQL checks and 63 browser cases**. The publication package separately reported **64 assertions, 117 PHP and 12 JS syntax checks**. These are reported test results, **not an independent certification of LIVE delivery or all customer journeys**.

## Screenshots and source publication

The real implementation is in [`plugin/`](plugin/). The six redacted UI screenshots below are portfolio illustrations, not independent proof of delivery, production readiness or current account state. See [screenshot provenance and redaction notes](screenshots/README.md).

| View | Screenshot |
|---|---|
| Campaign Analytics | ![Campaign analytics](screenshots/campaign-analytics.png) |
| SMS Misr Integration | ![SMS Misr connection](screenshots/sms-misr-integration.png) |
| Campaign Management | ![Campaign management](screenshots/campaign-management.png) |
| Audience Review | ![Audience review](screenshots/audience-review.png) |
| SMS Composer | ![SMS composer](screenshots/sms-composer.png) |
| Audience Retargeting | ![Audience retargeting](screenshots/audience-retargeting.png) |

## Source and documentation

- [`plugin/src/`](plugin/src/) and [`plugin/assets/`](plugin/assets/): RC18 PHP, JavaScript and CSS.
- [Architecture](docs/ARCHITECTURE.md), [tracking and retargeting](docs/TRACKING-AND-RETARGETING.md), [normalization](docs/PHONE-NORMALIZATION.md).
- [Development journey](docs/DEVELOPMENT-JOURNEY.md), [background scheduling](docs/BACKGROUND-SCHEDULING.md), [planned V2](docs/V2-ROADMAP.md).
- [Security boundaries](docs/SECURITY.md), [validation scope](docs/VALIDATION.md), [sanitization report](reports/SECURITY-SANITIZATION.md).
- [`tests/public-smoke.php`](tests/public-smoke.php) and [`examples/`](examples/): offline synthetic checks and non-operational configuration guidance.

Run `php tests/public-smoke.php` with PHP 8.1+ and Sodium. No credentials, WordPress installation, database or provider connection is required for that smoke test. This is an application-specific source reference, not a one-click general-purpose plugin. Private integration dependencies are not bundled; do not weaken its hostname, source or approval guards to run a demo.

## V2 — Automated Customer Lifecycle Marketing

**Planned, target development: November 2026 — not RC18 functionality.**

- 30-day no-purchase win-back messages.
- Collection-based product recommendations.
- Personalized post-purchase follow-ups.
- New-order confirmations and shipment tracking messages.
- Behavior-triggered workflows with suppression, consent, throttling and frequency controls.

## Security and rights

The separate source publication was reviewed to exclude customer data, credentials, internal infrastructure artifacts, LIVE logs and private Git history. Any public screenshots must be separately redacted. **No standalone license has been granted yet**, pending ownership and compatibility review. WordPress/GPL obligations may still apply.

**Built for a real WooCommerce business problem: connecting SMS delivery to measurable, privacy-aware commerce workflows.**
