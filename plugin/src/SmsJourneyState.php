<?php
namespace Azula\SmsStudio;

/** Consented SMS-owned journey; never changes Stage A/B sessions, cookies or metadata. */
final class SmsJourneyState
{
    public static function state(M6Store $s,array $click,string $cookie):?array
    {
        if(SmsNotice::modern()){
            $p=(new M6Tracking($s))->readCookie($cookie);
            if(($p['sms_owned']??false)===true&&hash_equals((string)$click['receipt_token'],(string)$p['receipt'])){
                // Respect a newer explicit acquisition from the existing pinned source.
                // A direct landing captured before acceptance does not erase the SMS handoff.
                $meta=$s->meta((int)$click['campaign_id']);$stage=$meta?M6AttributionContracts::state($s->crypto,$meta['public_id']):null;
                if($stage&&!$stage['matches']&&function_exists('WC')&&WC()&&WC()->session){$raw=WC()->session->get(\Azula_Attribution_Stage_A::WC_SESSION_KEY);$source=strtolower(trim((string)($raw['source_raw']??'')));if((int)($raw['captured_at']??0)>(int)$click['clicked_at']&&!in_array($source,['','(direct)','direct'],true))return ['matches'=>false,'session_hash'=>$stage['session_hash']];}
                return ['matches'=>true,'session_hash'=>$s->crypto->digest(['sms_owned_session'=>$p['receipt']]),'sms_owned'=>true];
            }
        }
        $meta=$s->meta((int)$click['campaign_id']);return $meta?M6AttributionContracts::state($s->crypto,$meta['public_id']):null;
    }
}
