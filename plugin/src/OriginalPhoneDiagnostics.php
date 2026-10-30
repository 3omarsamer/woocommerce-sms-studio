<?php
namespace Azula\SmsStudio;

/** Request-memory-only explanation. Candidate assessment never changes eligibility. */
final class OriginalPhoneDiagnostics
{
    public static function inspect(string $phone, bool $identityWarning): ?array
    {
        $row = (new PhoneDiagnostics())->inspect($phone, $identityWarning);
        if ($row['classification'] !== 'invalid_phone') return null;
        $row['original'] = $phone;
        $row['potential'] = 'Undetermined';
        $row['assessment'] = 'Input needs review; no digits are guessed and mobile ownership is not verified.';
        if (strlen($phone) <= 4096 && preg_match('//u', $phone) === 1) {
            // Explanation only: a separate copy, never passed back to audience preparation.
            $candidate = strtr($phone, array_combine(
                preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
                str_split('01234567890123456789')
            ));
            if ((new EgyptMobileNormalizer())->normalize($candidate)['phone'] !== null) {
                $row['potential'] = 'Yes — Egyptian mobile pattern after diagnostic-only formatting cleanup';
                $row['assessment'] = 'Potential formatting issue after diagnostic-only digit mapping; no digit mapping is applied to eligibility. Unsafe controls still reject.';
            } elseif (preg_match('/^[0-9+\s().\-]+$/u', $phone)) {
                $row['potential'] = 'No — original does not match the approved Egyptian mobile pattern';
                $row['assessment'] = 'Likely invalid length, country or mobile prefix; this does not prove the customer input is invalid for another country.';
            }
        }
        return $row;
    }
}
