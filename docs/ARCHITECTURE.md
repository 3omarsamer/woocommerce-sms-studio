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
