<?php
namespace Azula\SmsStudio;

/** Explicit evidence labels; absence never proves inactivity or a person's identity. */
final class RC18Retargeting
{
    public static function negative(array $d):bool{return !empty($d['not_cart'])||!empty($d['not_purchased'])||!empty($d['abandoned'])||!empty($d['cart'])||!empty($d['checkout']);}
    public static function decorate(array $r,array $orders,int $start,int $end):array
    {
        $ids=[];$lastPurchase=0;foreach($orders as $o)if((int)$o['order_created']>=$start&&(int)$o['order_created']<$end&&((int)$o['purchase_eligible']===1||$o['reason']==='fully_refunded')){$ids[]=(int)$o['order_id'];$lastPurchase=max($lastPurchase,(int)$o['order_created']);}
        $r['purchase_order_ids']=array_values(array_unique($ids));$r['matched_flags']['purchased']=(bool)$ids;
        if(!$r['last_activity']&&$ids){$r['last_activity']=$lastPurchase;$r['stage']='purchase';}
        $r['activity_status']=$r['last_activity']>0?'observed':'unknown';
        $r['downstream_activity']='unknown';if(!empty($r['first_submitted']))$r['recovery_since']=!empty($r['recovery_since'])?min((int)$r['recovery_since'],(int)$r['first_submitted']):(int)$r['first_submitted'];
        if(!$r['last_activity']){$r['stage']='unknown';$r['eligible']=false;$r['reason']='UNKNOWN: no permitted activity is available in the observation dates. This does not prove no click, cart or purchase.';}
        return $r;
    }
    public static function purchaser(array $member,array $ids):?string
    {
        if(!$ids||!function_exists('wc_get_order'))return 'purchase_unverified';
        try{foreach($ids as $id){$o=wc_get_order($id);if(!$o||!M11Purchases::sameCustomer($member,$o))continue;if(in_array($o->get_status(),['processing','completed','refunded'],true))return null;}return 'purchase_unverified';}catch(\Throwable $e){return 'purchase_unverified';}
    }
}
