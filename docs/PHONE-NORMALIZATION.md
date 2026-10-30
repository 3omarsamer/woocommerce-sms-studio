# Egyptian phone normalization

A dedicated qualification layer converts accepted Egyptian mobile formats into canonical provider-ready numbers **without editing WooCommerce's original customer phone field**.

Supported patterns include common local `010/011/012/015` prefixes and equivalent `+20`, `0020` and `20` international representations. Ten-digit significant numbers with recognized mobile prefixes can be normalized conservatively.

Preparation rejects missing, incomplete, ambiguous and malformed values rather than guessing. Recipients are deduplicated after canonicalization, so different textual formats of the same number do not generate duplicate campaign recipients.

The approved design also rejects Arabic-script digits and invisible formatting marks when they cannot be normalized safely. Diagnostics use synthetic phone examples; no real recipient data belongs in the public project.

Frozen snapshots keep the approved audience stable between preview/review and submission. Validation results and exclusion counts help the operator explain why a source segment's raw size differs from eligible unique recipients.
