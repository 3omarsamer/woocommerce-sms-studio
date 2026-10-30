# Egyptian phone normalization

A dedicated qualification layer converts accepted Egyptian mobile formats into canonical provider-ready numbers **without editing WooCommerce's original customer phone field**.

Supported patterns include common local `010/011/012/015` prefixes and equivalent `+20`, `0020` and `20` international representations. Ten-digit significant numbers with recognized mobile prefixes can be normalized conservatively.

Preparation rejects missing, incomplete, ambiguous and malformed values rather than guessing. Recipients are deduplicated after canonicalization, so different textual formats of the same number do not generate duplicate campaign recipients.

The approved design also rejects Arabic-script digits and invisible formatting marks when they cannot be normalized safely. Diagnostics use synthetic phone examples; no real recipient data belongs in the public project.

Frozen snapshots keep the approved audience stable between preview/review and submission. Validation results and exclusion counts help the operator explain why a source segment's raw size differs from eligible unique recipients.

## RC18 implementation detail

`EgyptMobileNormalizer` produces canonical `20`-prefixed Egyptian mobile numbers, validates supported operator prefixes (10, 11, 12, 15) and never writes customer records. Source identity checks precede recipient use; normalization is not proof of customer ownership or consent.

Supported forms include local trunk, +20, 20, 0020 and a ten-digit national form. One leading ASCII apostrophe at byte zero is treated as a text-formatting marker; embedded, repeated, trailing or displaced apostrophes remain invalid. Exactly one country-code trunk marker is removed only where the complete result validates.

## Unicode handling is intentionally narrow

- Strip U+061C, U+200E, U+200F directional marks without changing logical digits.
- Permit up to four balanced whole-value wrappers: U+202A/U+202C, U+202B/U+202C and U+2066, U+2067 or U+2068 paired with U+2069.
- Reject partial, mismatched or remaining bidi scopes, U+202D/U+202E overrides, line controls and malformed UTF-8.
- Permit horizontal separators explicitly listed in `HORIZONTAL`: tab, space, NBSP, U+1680, U+2000–200A, U+202F, U+205F, U+3000, plus ordinary parentheses, dot and hyphen formatting.
- Do not transliterate arbitrary Unicode digits, remove every control character, infer missing digits or guess country codes.

Normalize before deduplication. Multiple customer accounts sharing a normalized number produce one send recipient; secondary rows are duplicate exclusions. Conflicting identities require neutral personalization. Suppression applies independently and is rechecked at submission.

Phone strings in the public smoke tests and diagnostic self-test are **synthetic formatting fixtures only**, never approved destinations. No test contacts a carrier or provider.
