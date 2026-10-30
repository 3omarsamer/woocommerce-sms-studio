<?php
namespace Azula\SmsStudio;

/** Converts a phone in request memory to a small allowlisted, masked record. Never returns raw input. */
final class PhoneDiagnostics
{
    public function __construct(private EgyptMobileNormalizer $normalizer = new EgyptMobileNormalizer()) {}
    public function inspect(string $phone, bool $identityWarning = false): array
    {
        if ($identityWarning) return ['masked_original' => '[withheld: identity unverified]', 'ascii_digits' => null, 'decimal_digits' => null, 'prefix' => 'Withheld', 'flags' => [], 'normalization' => 'Not eligible for inspection', 'masked_normalized' => '—', 'normalizer_reason' => 'not_applied_to_identity', 'reason' => 'identity_unverified', 'classification' => 'identity_unverified'];
        $bytes = strlen($phone);
        $utf8 = preg_match('//u', $phone) === 1;
        if ($bytes > 4096) return ['masked_original' => '[fully masked: oversized value]', 'ascii_digits' => null, 'decimal_digits' => null, 'prefix' => 'Not assessed', 'flags' => ['oversized_input'], 'normalization' => 'Rejected', 'masked_normalized' => '—', 'normalizer_reason' => 'malformed', 'reason' => $identityWarning ? 'identity_unverified' : 'input_over_100_bytes', 'classification' => $identityWarning ? 'identity_unverified' : 'invalid_phone'];
        $ascii = preg_match_all('/[0-9]/', $phone);
        $decimal = $utf8 ? preg_match_all('/\p{Nd}/u', $phone) : null;
        $flags = [];
        if (str_starts_with($phone, "'")) $flags[] = 'leading_ascii_apostrophe';
        if (substr_count($phone, "'") > 1 || (str_contains($phone, "'") && !str_starts_with($phone, "'"))) $flags[] = 'embedded_or_repeated_apostrophe';
        $checks = [
            'arabic_indic_digits' => '/[\x{0660}-\x{0669}]/u',
            'eastern_arabic_digits' => '/[\x{06F0}-\x{06F9}]/u',
            'direction_mark' => '/[\x{200E}\x{200F}\x{061C}]/u',
            'bidi_control' => '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
            'zero_width_character' => '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u',
            'nonbreaking_space' => '/[\x{00A0}\x{202F}]/u',
            'formatting_separators' => '/[ ().\-]/',
            'line_break_or_nul' => '/[\r\n\x00]/',
            'html_entity' => '/&(?:#[0-9]+|#x[a-f0-9]+|[a-z]+);/i',
            'scientific_notation' => '/e[+-]?[0-9]+/i',
            'extension_marker' => '/(?:ext(?:ension)?\.?|[x#])\s*[0-9]+$/i',
        ];
        foreach ($checks as $flag => $pattern) if (($utf8 || !str_ends_with($pattern, '/u')) && preg_match($pattern, $phone) === 1) $flags[] = $flag;
        if (!$utf8) $flags[] = 'invalid_utf8';
        if ($decimal !== null && $decimal > $ascii) $flags[] = 'non_ascii_decimal_digits';
        if ($bytes > 100) $flags[] = 'over_100_bytes';
        $result = $this->normalizer->normalize($phone);
        // Match M1 member classification exactly. Identity failures must not leak even a suffix.
        $classification = $identityWarning ? 'identity_unverified' : (trim($phone) === '' ? 'missing_phone' : ($result['phone'] ? 'valid_phone' : 'invalid_phone'));
        $reason = $this->reason($phone, $result, $flags, $utf8, $ascii);
        return [
            'masked_original' => $identityWarning ? '[withheld: identity unverified]' : $this->mask($phone, $utf8),
            'ascii_digits' => $identityWarning ? null : $ascii,
            'decimal_digits' => $identityWarning ? null : $decimal,
            'prefix' => $identityWarning ? 'Withheld' : $this->prefix($phone, $utf8, $ascii),
            'flags' => $identityWarning ? [] : $flags,
            'normalization' => $identityWarning ? 'Not eligible for inspection' : ($result['phone'] ? 'Accepted' : 'Rejected'),
            'masked_normalized' => !$identityWarning && $result['phone'] ? $this->mask($result['phone'], true) : '—',
            'normalizer_reason' => $identityWarning ? 'not_applied_to_identity' : ($result['reason'] ?? 'accepted'),
            'reason' => $identityWarning ? 'identity_unverified' : $reason,
            'classification' => $classification,
        ];
    }
    private function mask(string $phone, bool $utf8): string
    {
        if (!$utf8) return '[fully masked: invalid encoding]';
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // Never transliterate unsupported digits or reveal short/mixed numeric strings.
        if (preg_match('/[^0-9+\s().\-]/u', $phone) || strlen($digits) < 8 || strlen($digits) > 15) return '[fully masked]';
        return str_repeat('*', strlen($digits) - 2) . substr($digits, -2);
    }
    private function prefix(string $phone, bool $utf8, int $ascii): string
    {
        if (!$utf8 || $ascii < 8) return 'Unrecognized';
        if (str_starts_with($phone, "'")) $phone = substr($phone, 1);
        $phone=EgyptMobileNormalizer::formatting($phone);
        if($phone===null)return 'Unrecognized';
        $s = preg_replace('/[\s().\-]+/u', '', trim($phone));
        foreach (['+20' => 'Egypt international (+20)', '0020' => 'Egypt international (0020)', '20' => 'Egypt international (20)', '0' => 'Egypt local', '' => 'Egypt national without trunk'] as $prefix => $label) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(1[0125])[0-9]+$/D', $s, $m)) return $label . ' / mobile 0' . $m[1];
        }
        if ((str_starts_with($s, '+') && !str_starts_with($s, '+20')) || (str_starts_with($s, '00') && !str_starts_with($s, '0020'))) return 'Non-Egypt international prefix';
        return 'Unrecognized';
    }
    private function reason(string $phone, array $result, array $flags, bool $utf8, int $ascii): string
    {
        if (trim($phone) === '') return 'missing_phone';
        if ($result['phone']) return 'accepted';
        if (!$utf8) return 'invalid_utf8';
        if (in_array('embedded_or_repeated_apostrophe', $flags, true)) return 'embedded_or_repeated_apostrophe_not_permitted';
        if (in_array('over_100_bytes', $flags, true)) return 'input_over_100_bytes';
        if(preg_match('/[\x{202D}\x{202E}]/u',$phone))return 'bidi_override_not_permitted';
        if(EgyptMobileNormalizer::formatting(str_starts_with($phone,"'")?substr($phone,1):$phone)===null && (in_array('bidi_control',$flags,true)))return 'unbalanced_or_partial_bidi_wrapper';
        if(preg_match('/[\x{0085}\x{2028}\x{2029}]/u',$phone))return 'line_separator_not_permitted';
        $logical=EgyptMobileNormalizer::formatting(str_starts_with($phone,"'")?substr($phone,1):$phone);
        if($logical!==null){$phone=$logical;$flags=array_values(array_diff($flags,['direction_mark','bidi_control']));}
        foreach (['non_ascii_decimal_digits' => 'non_ascii_digits_not_supported', 'direction_mark' => 'direction_mark_not_permitted', 'bidi_control' => 'bidi_control_not_permitted', 'zero_width_character' => 'zero_width_character_not_permitted', 'html_entity' => 'html_entity_not_permitted', 'scientific_notation' => 'scientific_notation_not_permitted', 'extension_marker' => 'extensions_not_supported', 'line_break_or_nul' => 'line_break_or_nul_not_permitted'] as $flag => $reason) if (in_array($flag, $flags, true)) return $reason;
        if ($result['reason'] === 'malformed') return 'unsupported_character_or_plus_placement';
        if (str_starts_with($phone, "'")) $phone = substr($phone, 1);
        $s = preg_replace('/[\s().\-]+/u', '', trim($phone));
        if ($s === '') return 'formatting_only_no_digits';
        if ((str_starts_with($s, '+') && !str_starts_with($s, '+20')) || (str_starts_with($s, '00') && !str_starts_with($s, '0020'))) return 'unsupported_country_prefix';
        if ($ascii === 10 && str_starts_with($s, '0')) return 'incomplete_local_10_digits';
        if (str_starts_with($s, '+200') || str_starts_with($s, '00200')) return 'redundant_local_trunk_after_country';
        return 'invalid_length_or_mobile_prefix';
    }
    public function selfTest(): array
    {
        $cases = [
            'Local mobile' => '01100000000', 'International plus' => '+201100000000',
            'International digits' => '201100000000', 'International 00' => '00201100000000',
            'National without zero' => '1100000000', 'Spaces' => '011 0000 0000',
            'Hyphens' => '011-0000-0000',
            'One leading ASCII apostrophe' => "'01100000000",
        ];
        $out = [];
        foreach ($cases as $label => $phone) {
            $r = $this->normalizer->normalize($phone);
            $out[] = ['case' => $label, 'status' => $r['phone'] === '201100000000' ? 'PASS' : 'FAIL', 'detail' => $this->inspect($phone)];
        }
        $r = $this->normalizer->normalize('0110000000');
        $out[] = ['case' => 'Incomplete local must reject', 'status' => $r['phone'] === null ? 'PASS' : 'FAIL', 'detail' => $this->inspect('0110000000')];
        $r = $this->normalizer->normalize("''01100000000");
        $out[] = ['case' => 'Repeated apostrophe must reject', 'status' => $r['phone'] === null ? 'PASS' : 'FAIL', 'detail' => $this->inspect("''01100000000")];
        return ['normalizer_version' => EgyptMobileNormalizer::VERSION, 'normalizer_sha256' => hash_file('sha256', __DIR__ . '/EgyptMobileNormalizer.php'), 'cases' => $out];
    }
}
