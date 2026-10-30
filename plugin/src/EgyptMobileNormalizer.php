<?php
namespace Azula\SmsStudio;

final class EgyptMobileNormalizer
{
    public const VERSION = 3;
    public const HORIZONTAL = '[\x{0009}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]';
    /** Formatting only, preserving logical digit order. Null means unsafe/ambiguous formatting. */
    public static function formatting(string $value): ?string
    {
        if(strlen($value)>100 || preg_match('//u',$value)!==1 || preg_match('/[\r\n\x00\x{0085}\x{2028}\x{2029}\x{202D}\x{202E}]/u',$value))return null;
        $value=preg_replace('/[\x{061C}\x{200E}\x{200F}]/u','',$value);
        $trim=static fn($s)=>preg_replace('/^'.self::HORIZONTAL.'+|'.self::HORIZONTAL.'+$/u','',$s);
        $value=$trim($value);
        $pairs=["\u{202A}"=>"\u{202C}","\u{202B}"=>"\u{202C}","\u{2066}"=>"\u{2069}","\u{2067}"=>"\u{2069}","\u{2068}"=>"\u{2069}"];
        // At most four nested whole-value wrappers; partial-number scopes are never stripped.
        for($depth=0;$depth<4;$depth++){
            $matched=false;
            foreach($pairs as $open=>$close)if(str_starts_with($value,$open)){
                if(!str_ends_with($value,$close))return null;
                $value=$trim(substr($value,strlen($open),-strlen($close)));$matched=true;break;
            }
            if(!$matched)break;
        }
        if(preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',$value))return null;
        return $value;
    }
    public function normalize(string $value): array
    {
        // Permit one text-formatting marker at byte zero only. All remaining
        // characters and the full Egyptian mobile pattern still pass the existing rules.
        if (strlen($value) > 100) return ['phone' => null, 'reason' => 'malformed'];
        if (str_starts_with($value, "'")) $value = substr($value, 1);
        $value=self::formatting($value);
        if ($value===null || preg_match('/[^0-9+().\-\x{0009}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', $value)) return ['phone' => null, 'reason' => 'malformed'];
        $value = preg_replace('/(?:'.self::HORIZONTAL.'|[().\-])+/u', '', $value);
        if (!$value) return ['phone' => null, 'reason' => 'missing_phone'];
        if (str_starts_with($value, '+')) {
            if (!str_starts_with($value, '+20')) return ['phone' => null, 'reason' => 'invalid_phone'];
            $value = substr($value, 1);
        }
        if (str_contains($value, '+')) return ['phone' => null, 'reason' => 'malformed'];
        if (str_starts_with($value, '0020')) $value = substr($value, 2);
        // Remove exactly one country-code trunk marker only if the full result is valid.
        if (preg_match('/^2001[0125][0-9]{8}$/D', $value)) $value = '20' . substr($value, 3);
        if (preg_match('/^01[0125][0-9]{8}$/D', $value)) $value = '2' . $value;
        elseif (preg_match('/^1[0125][0-9]{8}$/D', $value)) $value = '20' . $value;
        if (!preg_match('/^201[0125][0-9]{8}$/D', $value)) return ['phone' => null, 'reason' => 'invalid_phone'];
        return ['phone' => $value, 'reason' => null];
    }
}
