<?php
namespace Azula\SmsStudio;
/** Read-only explanations from already stored evidence; no inference or backfill. */
final class RC2Evidence
{
    public static function describe(?array $event,array $classification,array $orders):array
    {
        if(!$event||!in_array($classification['state'],['uncertain','observing'],true))return [];$out=[];
        if(!M6Tracking::enabled())$out[]='Tracking collection is currently disabled.';
        if(!M6AttributionContracts::available())$out[]='The Stage A/B attribution contract is unavailable.';
        if(M6AttributionContracts::exclusions()===null)$out[]='The purchase exclusion/finance contract is unavailable.';
        if(empty($event['session_hash']))$out[]='Missing stored attribution-session binding.';
        if($event['kind']==='checkout_start'&&empty($event['checkout_fingerprint']))$out[]='Missing stored checkout/cart fingerprint; a checkout observation alone cannot establish recovery eligibility.';
        if($event['value_minor']===null)$out[]='The latest stored monetary value is unavailable.';
        if($event['currency']!=='EGP')$out[]='The stored monetary currency is not verified EGP.';
        if((int)$event['occurred_at']<(int)$event['clicked_at']||(int)$event['occurred_at']>(int)$event['clicked_at']+M6Tracking::WINDOW)$out[]='The activity lies outside its verified SMS click window.';
        if($classification['state']==='observing')$out[]='Earliest observation review: '.CairoTime::label((M11Environment::modern()?(int)$event['occurred_at']+86400:max((int)$event['occurred_at']+86400,(int)$event['clicked_at']+M6Tracking::WINDOW+86400))).'. Other eligibility checks must still pass; this is not sending authorization.';
        if(!M11Environment::modern()&&TargetedDiagnostics::modern()&&$classification['state']==='observing'){$out[]='Calculation: eligibility review = max(latest shopping activity + 24h, SMS click + 7-day attribution window + 24h purchase-observation safety). The date-range filter only selects evidence; it does not shorten either deadline. Identity, suppression and purchase checks still apply.';$out[]='Shopping inactivity deadline: '.CairoTime::label((int)$event['occurred_at']+86400).'; purchase-observation deadline: '.CairoTime::label((int)$event['clicked_at']+M6Tracking::WINDOW+86400).'. This is potential unfinished checkout, not proven abandonment.';}
        if(M11Environment::modern())$out[]='Recovery uses 24-hour shopping inactivity with current cross-channel purchase checks. The separate seven-day SMS attribution window is unchanged.';return $out;
    }
}
