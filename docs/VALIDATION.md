# Validation scope

The publication build checks every exported PHP file with `php -l`, every JavaScript file with `node --check`, local Markdown file links, file extensions and SHA-256 source provenance. It runs offline public smoke checks against the exported source, then separately reruns relevant existing RC18 consent/retargeting and synthetic production-authorization regressions in the original workspace. Private fixture dependencies and raw old result files are not redistributed.

Historical RC18 local gate: 445 PHP/SQL and 63 browser cases reported passing. Those browser suites were not re-executed for this documentation-only export; there are no production functional changes. One diagnostic self-test's historical phone samples are replaced consistently with synthetic samples. Exact current validation results appear in `reports/validation.json`.

No requests to LIVE, STAGING or SMS Misr are needed or made by this publication process. Passing local tests does not prove handset delivery, unattended LIVE cron provenance, customer identity on a forwarded link or every edge-cache configuration. The package is not a new production release or a claim of full production readiness.
