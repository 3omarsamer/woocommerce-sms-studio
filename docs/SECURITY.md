# Security and operational boundaries

This is a reviewed portfolio export, not an independent penetration-test certification. It contains working submission code. Never activate it against customer data merely to explore the source.

`Crypto` uses Sodium secretbox with random nonces and a dedicated 32-byte base64 deployment key. Phone correlation and package digests use keyed hashing. Encrypting a database value does not make that value appropriate for a public repository. Losing or rotating a key without migration can invalidate protected history and hashes. Production and staging keys, options, permissions and verification evidence must remain separate.

`Security`, `M6SendPolicy` and API handlers enforce capabilities, nonces, request methods and explicit approval. `M11Environment` requires the exact production environment/hostname and an explicit access constant; dispatch also requires tracking attestation, configured tracking, conflict-free route and recent runner. Missing access or keys should fail closed. Do not set attestation solely to make a readiness screen green.

Credentials are configured at runtime and encrypted; provider request URLs/payloads, raw diagnostics, cookie jars and private keys are not public fixtures. SMS uses form-encoded POST. Balance POST reflects observed provider behavior despite conflicting published GET documentation; parser accepts the verified case-sensitive numeric `Balance` field including zero. No OTP, SMPP or undocumented delivery API is introduced.

The consent choice is signed and deployment bound, with same-origin expiring proof for positive consent. Explicit denial and framework denial are preserved. Direct redirects and optional analytics are separate. SMS consent does not make the whole store compliant or control independent Meta/GA4/Stage A behavior. Edge logs, retention policies and other trackers need an owner review.

CSV export applies authorization, identity restrictions and formula-injection mitigation. Identity-unverified phones are not exposed in normal audience exports. Temporary original-phone diagnostics are administrator/STAGING restricted. A public code reader can see these interfaces, but receives no private data or access.

Short links use collision checking and bounded abuse counters; they are not user authentication. Keep HTTPS, route no-store policies and cookie isolation intact. Cache/proxy behavior requires deployment-specific verification.

## Reporting vulnerabilities

Do not attach customer records, tokens, provider payloads or credentials to public issues. Contact the repository owner privately through an available GitHub private vulnerability reporting channel, if enabled. Repository publication and security-channel configuration remain owner actions.
