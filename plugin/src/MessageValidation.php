<?php
namespace Azula\SmsStudio;
/** Explain existing validation; grants no new encoding/provider capability. */
final class MessageValidation
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.4','>=');}
    public static function problem(string $text,?int $language=null):string
    {
        $m=MessageMetrics::calculate($text);
        if(trim($text)==='')return 'Enter the final SMS in Step 3.';
        if(preg_match('/[\x{10000}-\x{10FFFF}]/u',$text))return 'Emoji/non-BMP characters are not verified for SMS Misr and block final review. Your draft is preserved unchanged. Explicitly edit Step 3 to supported BMP text (including Arabic), then save and review again. No SMS was authorized.';
        if($m['unsupported'])return 'Unsupported control characters block final review. Explicitly correct the Step 3 draft; its content has not been altered.';
        if($m['segments']>10)return 'The final SMS exceeds the ten estimated segment limit. Shorten it explicitly in Step 3.';
        if($language===1&&$m['encoding']!=='GSM-7')return 'This message needs Arabic (2) or Unicode (3), not English (1). Choose the matching language before final review.';
        return '';
    }
}
