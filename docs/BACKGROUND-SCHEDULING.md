# Background scheduling

## Mechanism verified in source

`CairoTime` uses the IANA `Africa/Cairo` timezone, validates local choices including DST ambiguity and converts execution times to UTC. No fixed Egyptian UTC offset should be used. Saving a future time does not authorize a provider request. `ScheduledCampaign`, `M91Campaign` and `M92Readiness` bind timing, audience and approval; freshness, balance and production readiness are rechecked at execution.

WooCommerce Action Scheduler provides persisted jobs. `M2Scheduler` prepares bounded audiences and provides probe/watchdog hooks; `M9Worker` schedules due `azula_sms_bulk_tick` actions in the SMS bulk group. `M92Runner` checks harmless probe completion. A completed probe confirms recent worker execution, **not which external scheduler triggered it**. The code explicitly reports unattended verification as false absent operational evidence.

An external Hostinger Cron Job can trigger WordPress approximately every five minutes, without an administrator browser remaining open. Visitor-only WP-Cron can delay execution on a quiet store. A five-minute trigger means minute-level lateness is expected, not precise-second delivery.

Sanitized illustrative hPanel schedule and command (not a claim of the current LIVE configuration):

```cron
*/5 * * * * curl --fail --silent --show-error --max-time 60 'https://shop.example.invalid/wp-cron.php' >/dev/null
```

Replace only inside a reviewed deployment procedure. This command contains no credentials or private path. Do not publish real hosting commands. Do not add another trigger blindly; inventory existing jobs and verify the scheduler with browsers closed.

## Evidence boundary

Historical project requests describe Hostinger Cron Jobs and successful scheduled STAGING SMS tests. The available RC18 operations evidence explicitly does **not** establish a dedicated five-minute LIVE trigger: a listing showed a STAGING job and recent Action Scheduler/probe activity. Therefore this public documentation does not claim the exact LIVE interval or provenance was verified. No fresh hosting access was used for publication.

## Safety and logging

`M9Bulk` uses database locks, exact recipient/message digests, provider balance reservations and durable attempts. The worker distinguishes safe pre-claim retry from unknown provider outcomes, which are not automatically resubmitted. Late/stale readiness or purchase/source changes cannot silently authorize more recipients. Scheduling permissions are separate from manual controlled Live permission.

SMS-owned activity records include preparation, worker observation, bounded retry and blocked reasons. Trigger categories distinguish cron/CLI/admin/AJAX evidence but do not prove the Hostinger origin. Never publish raw activity logs or encrypted records. A production pilot requires separate owner authorization; provider acceptance is not phone receipt.
