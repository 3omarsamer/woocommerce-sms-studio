# Architecture — Azula SMS Studio RC18

## Modules and responsibilities
Azula Customer Segments is an independent read-only audience source. SMS Studio handles audience capture, identity/phone qualification, normalization and deduplication, immutable snapshots, drafts, approvals, SMS Misr transport, branded links, consent-aware journeys, campaign/global reports, and SMS-owned retargeting.

### Sending flow
1. Select segment and prepare a bounded recipient snapshot.
2. Validate Egyptian mobile formats, reject malformed inputs and deduplicate canonical recipients. Source phone records remain unchanged.
3. Compose message, estimate Unicode/encoding and provider units, validate sender/recipient counts and approve immutable campaign payload.
4. Send Now or approve a scheduled job.
5. Guarded Action Scheduler worker checks approval, environment, permissions, balance, recipient limit, dispatch state and idempotency.
6. Submit to SMS Misr, store provider response; distinguish accepted, rejected and unknown. Acceptance is not handset delivery.

### Tracking flow
Branded per-recipient token → HTTP redirect to target → SMS-specific consent decision → first-party journey cookie if permitted → WooCommerce activity and attributed purchase events → analytics and campaign-scoped audiences. Redirecting is separate from analytics collection. A token represents its intended recipient, not proof of the actual browser visitor.

### Production safety
Audit-only observations must not modify unrelated plugins. Sending requires explicit operator authorization; unknown responses must not trigger blind re-sends. Production tracking and scheduled permission have separate gates. Follow the original project runbook for production setup; do not use this document as an activation guide.

### Dependencies
WordPress, WooCommerce, Action Scheduler, PHP/Sodium, SMS Misr, separately deployed Customer Segments and other Azula integration contracts. The stand-alone public export is application-specific.

## RC18 implementation detail

## Boundaries

The `Azula\SmsStudio` namespace is autoloaded by `plugin/azula-sms-studio.php`; `Plugin.php` registers lifecycle and runtime hooks. The RC18 schema is version 8. SMS-owned data is persisted independently of WooCommerce and Customer Segments. `Database`, `Schema`, `M6Schema`, `M7Schema` and `M9Schema` own storage and upgrades. `uninstall.php` deliberately does not erase historical data.

`CustomerSegmentsAdapterV1` implements `AudienceSource` and `FrozenIdentitySource`. `SourceCompatibility` validates exact audited versions and source hashes. Catalog counts use the source plugin's `dashboard_sql`, not different SMS rules. Keyset capture and `CustomerHistory::decorate` supply identity-checked phone candidates. Customer Segments is never written by this integration. Its current email-bearing lookup coverage excludes customers with no email; **email subscription is not an additional SMS eligibility rule**.

## Functional map

| Layer | Main files in `plugin/src/` |
|---|---|
| Audience | `AudiencePreparationService`, `M2Repository`, `M2Scheduler`, `AudienceReview`, `AudienceCsv` |
| Approval / schedule | `CampaignLifecycle`, `ScheduledCampaign`, `M91Campaign`, `M92Readiness`, `CairoTime` |
| Sending | `M9Bulk`, `M9Worker`, `M6SendService`, `M6SendPolicy`, `M92Policy` |
| Provider / funds | `SmsMisrAdapter`, `SmsMisrHttp`, `SmsMisrSettings`, `SenderIdentity`, `AuthorizationBalance`, `BalanceReservations`, `ExactPoints` |
| Journey / consent | `M7Links`, `M7Front`, `M8Tokens`, `M8Events`, `RC1Journey`, `SmsJourneyState`, `SmsConsent`, `SmsNotice` |
| Attribution / reporting | `M6Tracking`, `M6Revenue`, `M6Analytics`, `M8Analytics`, `M6AttributionContracts` |
| Retargeting | `M9Retargeting`, `RC18Retargeting`, `RC2Audiences`, `M9Source`, `M11Purchases` |
| Security / operations | `Crypto`, `Security`, `M11Environment`, `RC1LinkGuard`, `TargetedDiagnostics`, `AdminRequestError` |

## Approval and execution

A source definition, campaign revision, current audience, rendered individual messages, sender, language, timing and spending ceiling form the reviewed package. Approval does not permit silently replacing recipients. Eligibility and suppression are rechecked near execution; stale or inconsistent evidence blocks rather than broadens a send. Future scheduling and actual authorization are separate concepts.

The worker uses durable attempt state before network submission. A timeout is not proof that the provider did not accept a message. Unknown outcomes therefore remain protected from automatic resubmission. Database uniqueness and locking supplement scheduler deduplication; a job queue alone does not provide exactly-once external delivery.

## Deliberate application specificity

Brand-hostname guards and audited integration pins remain intact in this export. Hash pinning offers a narrow, reviewable compatibility surface but makes upgrades intentionally fail closed. Porting this application to another store requires a separate compatibility/security review. No public example enables production or dispatch.
