# Publication security review

## Construction and exclusions

The export uses an explicit allowlist: RC18 entrypoint/uninstall, PHP under src, JS/CSS under assets, public documents, example configuration, offline synthetic smoke tests and six separately reviewed redacted PNGs. It does not recursively copy the workspace. No original Git history or .git directory is included.

Excluded in full: `work/` (SSH helpers, hosting evidence, keys/known-host material, logs, HTTP captures, LIVE state, customer-related artifacts and local databases); `.tools/` (runtimes, profiles, databases and dependencies); original `dist/` and nested ZIPs; all original operational `docs/`, installed plugin documentation and README; original test fixtures/results/reference plugins and browser evidence; all original operational screenshots except the six separately supplied redacted portfolio illustrations; all database exports; all hidden repository/config state. These categories are intentionally not listed with private internal filenames or values. Only SMS Studio implementation is exported, not other Azula plugins.

## Changes to implementation

Only `plugin/src/PhoneDiagnostics.php` has publication-specific content changes: old phone samples are replaced with synthetic format-only fixtures and corresponding expectations. No validation rule, environment guard, sending permission, encryption scheme, schema or approval logic changes. `source-provenance.json` lists original and exported hashes; other implementation files remain byte-for-byte identical.

Public brand hostnames in runtime allowlists and the official SMS Misr API endpoints are deliberately retained: they are functional integration boundaries, not account credentials. Removing them would weaken the original security boundary. Source-hash pins are public compatibility fingerprints, not encryption keys. Constants and option names describe the configuration contract, not their secret values. Generic crypto API names are not secrets.

## Scan and limits

All code/documentation files are UTF-8 text. Six reviewed PNGs are the only binary assets; their ancillary metadata is removed. No nested archives, database files, symlinks or private Git objects are imported. The existing public repository history is preserved. Its complete file set and hashes are compared against the staged directory after packaging. Scanning checks known private infrastructure/account/token markers without printing their values, credential-assignment patterns, private-key headers, deployment key values, phone patterns and secret-like strings. All surfaced candidates require classification; scanner absence is not mathematical proof that no secret exists.

`scan.json` records findings/classifications. The only allowed phone examples are explicitly synthetic test/self-test fixtures. Their numeric syntax cannot prove a globally unassigned carrier number, so they must never be submitted. The public test is offline. No runtime provider password, sender token, real recipient link, private analytics or customer dataset is bundled.

The owner must still approve any actual license grant and any subsequently added screenshot. The six included images have been reviewed for this authorized publication; see screenshots/README.md for AI-assisted redaction limits. This is a scoped source/publication review, not an independent full vulnerability audit or a production-readiness certification.

## GitHub publication review

The existing professional README and public documentation were retained and extended in place. Pending-source text was replaced with file links; unsupported exact LIVE cron provenance was qualified. No LICENSE was added. Reports and hashes were refreshed for the committed tree. Local Git push lacked a credential, so the authenticated GitHub connector publishes Git objects with the original public HEAD as parent; no development Git history is uploaded.
