<?php
namespace Azula\SmsStudio;

/** Reconciles only SMS-owned associations. Woo orders/refunds are read through public APIs. */
final class M6Revenue
{
    public function __construct(private M6Store $s){}
    public static function minor($value):?int
    {
        if(is_float($value)){if(!is_finite($value))return null;$value=function_exists('wc_format_decimal')?wc_format_decimal($value,2):sprintf('%.2f',$value);}
        if(!is_string($value)&&!is_int($value))return null;if(!preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D',(string)$value,$m))return null;return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public function order(int $id):void
    {
        $row=$this->s->row('SELECT * FROM '.$this->s->table('conversions').' WHERE order_id=%d',$id);if(!$row)return;$order=function_exists('wc_get_order')?wc_get_order($id):null;$reason='order_unavailable';$currency='';$status='unavailable';$net=0;$eligible=0;$exclusions=M6AttributionContracts::exclusions();
        if($order){$status=(string)$order->get_status();$currency=(string)$order->get_currency();$total=self::minor($order->get_total());$refund=self::minor($order->get_total_refunded());
            if($exclusions===null)$reason='analytics_contract_unavailable';elseif(in_array('wc-'.preg_replace('/^wc-/','',$status),$exclusions,true))$reason='excluded_order_status';elseif($currency!=='EGP')$reason='currency_not_egp';elseif($total===null||$refund===null||$refund>$total)$reason='invalid_or_ambiguous_amount';elseif($refund>0&&$refund===$total)$reason='fully_refunded';else{$net=$total-$refund;$eligible=1;$reason=$refund>0?'eligible_after_partial_refund':'eligible';}}
        $this->s->update('conversions',['verified_at'=>time(),'status'=>$status,'currency'=>$currency,'revenue_minor'=>$net,'purchase_eligible'=>$eligible,'reason'=>$reason],['id'=>$row['id']]);
    }
    public function batch():int
    {
        $rows=$this->s->rows('SELECT order_id FROM '.$this->s->table('conversions').' WHERE verified_at<%d ORDER BY verified_at ASC,id ASC LIMIT 100',time()-300);foreach($rows as $r)$this->order((int)$r['order_id']);return count($rows);
    }
}
