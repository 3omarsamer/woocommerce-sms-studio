# Hostinger server-side cron and autonomous scheduling

The project uses WooCommerce Action Scheduler and a guarded background execution path for send authorizations.

## Why server-side cron?
Site-visitor-triggered WP-Cron alone may be irregular for scheduled sends. A Hostinger server-side cron job, configured to trigger processing **approximately every five minutes**, helps wake the WordPress scheduling system independently of the administrator's browser or laptop.

### Execution model
Approved campaign → Cairo-local schedule converted/stored in UTC → Action Scheduler due job → cron-triggered worker opportunity → eligibility/approval/idempotency checks → SMS Misr API submission → persisted outcome and audit history.

The laptop can be switched off because job execution happens at the server. However, cron health, WordPress runtime health, queue load and provider/network latency can affect submission and actual delivery timing. Five-minute cadence does not guarantee second-precise delivery.

### Operational safeguards
Single-execution claims, locks/attempt records, recipient limits, budget constraints, approval snapshot binding and protections for unknown provider outcomes. Scheduled Live requires a separate permission gate after a controlled pilot. Avoid automatic duplicate attempts on ambiguous provider responses.

This public document intentionally contains no real Hostinger login, cron URL, SSH account, server IP, tokens or private paths.
