<?php
namespace Azula\SmsStudio;
final class M8Events
{
    public function __construct(private M6Store $s){}
    public function record(string $cookie,string $kind,string $event,array $value=[]):bool
    {
        if(!in_array($kind,['visit','product_view','add_to_cart','checkout_start'],true)||strlen($event)>160||$event==='')return false;$t=new M6Tracking($this->s);$click=$t->current($cookie);if(!$click)return false;$meta=$this->s->meta((int)$click['campaign_id']);if(!$meta)return false;$state=SmsJourneyState::state($this->s,$click,$cookie);if($state&&!$state['matches'])return false;
        $mapping=!empty($click['recipient_link_id'])?$this->s->row('SELECT * FROM '.$this->s->table('recipient_links').' WHERE id=%d AND campaign_id=%d',$click['recipient_link_id'],$click['campaign_id']):null;$recipient=(int)($mapping['recipient_id']??0);$audience=(int)($mapping['audience_id']??0);$session=$state&&$state['matches']?$state['session_hash']:null;
        if(M91Campaign::modern()&&$kind==='checkout_start'){$fingerprint=M9Checkout::cart();$event='checkout:'.$click['id'].':'.($fingerprint??'unavailable').':'.intdiv(time(),600);}
        $key=$this->s->crypto->digest(['campaign'=>(int)$click['campaign_id'],'recipient'=>$recipient?:'click:'.$click['id'],'kind'=>$kind,'event'=>$event]);$row=$this->s->row('SELECT id FROM '.$this->s->table('journey').' WHERE event_key=%s',$key);if($row)return false;
        $minor=isset($value['minor'])?(string)(int)$value['minor']:'NULL';
        $this->s->query('INSERT INTO '.$this->s->table('journey').' (campaign_id,click_id,visitor_hash,kind,event_key,occurred_at,recipient_id,audience_id,currency,value_decimal,value_basis,event_source,session_hash,value_minor) VALUES (%d,%d,%s,%s,%s,%d,%d,%d,%s,%s,%s,%s,%s,'. $minor .') ON DUPLICATE KEY UPDATE event_key=event_key',$click['campaign_id'],$click['id'],$click['visitor_hash'],$kind,$key,time(),$recipient,$audience,$value['currency']??'',$value['decimal']??'',$value['basis']??'not_recorded',$kind==='add_to_cart'?'woo_add_action':'visible_page',$session??'');
        if(M9Schema::modern()&&$kind==='checkout_start'){ $fingerprint=M9Checkout::cart();if($fingerprint)$this->s->query('UPDATE '.$this->s->table('journey').' SET checkout_fingerprint=%s WHERE event_key=%s AND checkout_fingerprint IS NULL',$fingerprint,$key); }
        if($session)$this->s->update('clicks',['stage_session_hash'=>$session],['id'=>$click['id']]);if(!$click['observed_at'])$this->s->update('clicks',['observed_at'=>time()],['id'=>$click['id']]);return true;
    }
}
