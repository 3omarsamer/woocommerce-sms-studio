<?php
namespace Azula\SmsStudio;
/** Read-only correlation evidence. Equal recipient or amount alone never proves completion. */
final class M9Checkout
{
    private static function fingerprint(array $lines,$total,string $currency,Crypto $crypto):?string
    {
        if(!$lines||M8Costs::numeric($total)===null||!preg_match('/^[A-Z]{3}$/D',$currency))return null;sort($lines,SORT_STRING);$parts=explode('.',(string)$total);$total=(ltrim($parts[0],'0')?:'0').'.'.str_pad($parts[1]??'',6,'0');return $crypto->digest(['checkout_lines'=>$lines,'payable'=>(string)$total,'currency'=>$currency]);
    }
    public static function cart():?string
    {
        try{if(!function_exists('WC')||!WC()||!(WC()->cart??null)||!method_exists(WC()->cart,'get_cart'))return null;$cart=WC()->cart;$lines=[];foreach($cart->get_cart() as $row){$qty=(string)($row['quantity']??'');if(!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$qty))return null;$lines[]=(int)$row['product_id'].':'.(int)($row['variation_id']??0).':'.$qty;}return self::fingerprint($lines,$cart->get_total('edit'),get_woocommerce_currency(),Crypto::deployment());}catch(\Throwable $e){return null;}
    }
    public static function bindOrder(M6Store $s,$order):void
    {
        try{if(!method_exists($order,'get_items'))return;$lines=[];foreach($order->get_items('line_item') as $item)$lines[]=(int)$item->get_product_id().':'.(int)$item->get_variation_id().':'.(string)$item->get_quantity();$hash=self::fingerprint($lines,$order->get_total(),$order->get_currency(),$s->crypto);if($hash)$s->query('UPDATE '.$s->table('conversions').' SET checkout_fingerprint=%s WHERE order_id=%d AND checkout_fingerprint IS NULL',$hash,$order->get_id());}catch(\Throwable $e){}
    }
    public static function classify(array $event,array $orders,?int $now=null):array
    {
        $now??=time();$uncertain=false;foreach($orders as $o){if(M91Campaign::modern()&&(int)($o['order_created']??0)<(int)$event['occurred_at'])continue;$qualified=(int)$o['purchase_eligible']===1||$o['reason']==='fully_refunded';$exact=$qualified&&!empty($event['checkout_fingerprint'])&&!empty($o['checkout_fingerprint'])&&hash_equals($event['checkout_fingerprint'],$o['checkout_fingerprint'])&&!empty($event['session_hash'])&&hash_equals($event['session_hash'],$o['stage_session_hash'])&&(int)$o['order_created']>=(int)$event['occurred_at'];
            if($exact)return ['state'=>'completed','reason'=>$o['reason']==='fully_refunded'?'Correlated order was fully refunded; not abandoned.':'Same cart fingerprint, attribution session and subsequent qualifying order.'];$uncertain=true;}
        if($uncertain)return ['state'=>'uncertain','reason'=>'Purchase evidence exists but cannot reliably close this checkout. Same recipient or equal amount is insufficient.'];
        if(!M6Tracking::enabled()||!M6AttributionContracts::available()||M6AttributionContracts::exclusions()===null||empty($event['checkout_fingerprint'])||empty($event['session_hash'])||$event['value_minor']===null||$event['currency']!=='EGP'||$event['occurred_at']<$event['clicked_at']||$event['occurred_at']>$event['clicked_at']+M6Tracking::WINDOW)return ['state'=>'uncertain','reason'=>'Missing reliable cart, session, monetary or collection evidence.'];
        if($now<(M11Environment::modern()?(int)$event['occurred_at']+86400:max((int)$event['occurred_at']+86400,(int)$event['clicked_at']+M6Tracking::WINDOW+86400)))return ['state'=>'observing','reason'=>M11Environment::modern()?'Waiting for 24 hours without newer qualifying shopping activity.':'Still within inactivity or attribution observation window.'];
        return ['state'=>'potential','reason'=>M11Environment::modern()?'24-hour inactivity reached; identity, current purchase and suppression checks must still pass.':'No qualifying purchase observed after the complete observation window. Potential unfinished checkout, not confirmed abandonment.'];
    }
    public static function checkoutPage():bool
    {
        if((function_exists('wp_doing_ajax')&&wp_doing_ajax())||(defined('REST_REQUEST')&&REST_REQUEST))return false;
        if(function_exists('wc_get_page_id')&&function_exists('is_page')){$id=(int)wc_get_page_id('checkout');if($id<1||!is_page($id))return false;}
        return function_exists('is_checkout')&&is_checkout()&&(!function_exists('is_cart')||!is_cart())&&(!function_exists('is_order_received_page')||!is_order_received_page())&&(!function_exists('is_wc_endpoint_url')||!is_wc_endpoint_url('order-pay'));
    }
}
