<?php
namespace Azula\SmsStudio;

/** Local estimation only; no provider adapter or implicit message rewriting. */
final class MessageMetrics
{
    public static function calculate(string $text, int $recipients = 0, ?int $rateMinor = null): array
    {
        if (strlen($text) > 20000 || preg_match('//u', $text) !== 1) throw new \InvalidArgumentException('Message is too long or has invalid encoding.');
        $basic = '@£$¥èéùìòÇ' . "\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ" . "ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extension = '^{}\\[~]|€' . chr(12);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $gsm = true; $units = 0; $unicode = 0; $unsupported = false;
        foreach ($chars as $char) {
            if (str_contains($basic, $char)) $units++;
            elseif (str_contains($extension, $char)) $units += 2;
            else $gsm = false;
            $unicode += strlen($char) === 4 ? 2 : 1;
            if (strlen($char) === 4 || preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $char)) $unsupported = true;
        }
        if (!$gsm) $units = $unicode;
        $single = $gsm ? 160 : 70; $multi = $gsm ? 153 : 67;
        $segments = $units === 0 ? 0 : 1;
        if($units>$single){$used=0;foreach($chars as $char){$cost=$gsm?(str_contains($extension,$char)?2:1):(strlen($char)===4?2:1);if($used+$cost>$multi){$segments++;$used=0;}$used+=$cost;}}
        if ($recipients < 0 || $recipients > 10000000 || ($rateMinor !== null && ($rateMinor < 0 || $rateMinor > 1000000))) throw new \InvalidArgumentException('Invalid estimate parameters.');
        return ['characters' => count($chars), 'units' => $units, 'encoding' => $gsm ? 'GSM-7' : 'Unicode', 'segments' => $segments, 'recipients' => $recipients,
            'cost_minor' => $rateMinor === null ? null : $segments * $recipients * $rateMinor, 'currency' => 'EGP',
            'valid' => trim($text) !== '' && !$unsupported && $segments <= 10, 'unsupported' => $unsupported, 'estimate_only' => true];
    }
}
