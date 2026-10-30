<?php
namespace Azula\SmsStudio;

final class M6Tracking
{
    public const COOKIE='azula_sms_journey_v1';
    public const WINDOW=604800;
    public function __construct(private M6Store $s){}
    public static function enabled():bool{if(M7Settings::modern())return M7Settings::enabled();return M2Scheduler::staging()&&defined('AZULA_SMS_STUDIO_TRACKING_ENABLED')&&constant('AZULA_SMS_STUDIO_TRACKING_ENABLED')===true;}
    public static function consent():bool{return SmsConsent::permitted();}
    public function cookie(array $value):string{$json=json_encode($value,JSON_THROW_ON_ERROR);$b=rtrim(strtr(base64_encode($json),'+/','-_'),'=');return $b.'.'.$this->s->crypto->digest(['sms_cookie'=>$b]);}
    public function readCookie(string $raw):?array
    {
        if(strlen($raw)>512||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$raw,$m)||!hash_equals($this->s->crypto->digest(['sms_cookie'=>$m[1]]),$m[2]))return null;$json=base64_decode(strtr($m[1],'-_','+/'),true);$p=is_string($json)?json_decode($json,true):null;
        if(!is_array($p)||!preg_match('/^[a-f0-9]{32}$/D',$p['visitor']??'')||!preg_match('/^[a-f0-9]{32}$/D',$p['receipt']??'')||!is_int($p['until']??null)||$p['until']<time()||$p['until']>time()+self::WINDOW+60)return null;return $p;
    }
    public function activeLink(string $token):?array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))return null;$row=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s',$token);if(!$row)return null;$row['active']=false;try{$c=$this->s->row('SELECT * FROM '.$this->s->table('campaigns').' WHERE id=%d',$row['campaign_id']);if(!$c)return null;
        $accepted=$this->s->row('SELECT * FROM '.$this->s->table('attempts')." WHERE campaign_id=%d AND state='accepted' LIMIT 1",$row['campaign_id']);$row['active']=$accepted&&(str_contains($this->s->campaigns->m2->message($c),M6Links::publicUrl($token))||str_contains($this->s->campaigns->m2->message($c),rtrim(home_url('/'),'/').'/?azula_sms_link='.$token));return $row;}catch(\Throwable $e){if(!M7Settings::modern())throw $e;M7Settings::error('legacy_activity_lookup_failed');return $row;}
    }
    public function redirect(string $token,string $oldCookie='',string $method='GET',bool $scanner=false,bool $short=false,?array $resolvedLink=null,string $handoff=''):array
    {
        $resolved=$short?($resolvedLink??(new M7Links($this->s))->resolve($token)):null;$link=$resolved?$resolved['link']:$this->activeLink($token);if(!$link)throw new ControlledTestException('Tracking link not found.');$target=$resolved?$resolved['target']:$this->s->crypto->open($link['destination'])['target'];if(!M6Links::allowed($target))throw new ControlledTestException('Tracking destination is unavailable.');$out=['target'=>$target,'cookie'=>null,'click_id'=>null];
        if(($resolved['preview']??false)===true){$out['target']=SmsConsent::clean($target);return $out;} // Templates never carry SMS analytics.
        if(!self::enabled()||!self::consent()){$original=$this->s->crypto->open($link['destination'])['original']??$target;$out['target']=SmsConsent::clean($original);return $out;}
        if($method!=='GET'||$scanner)$out['target']=SmsConsent::clean($this->s->crypto->open($link['destination'])['original']??$target);
        if(M7Settings::modern()){try{(new M7Collector($this->s))->record($link,$short?'recipient':'legacy',$method,$scanner);}catch(\Throwable $e){M7Settings::error('request_write_failed');}}
        if(!self::enabled()||!self::consent()||!$link['active']||$method!=='GET'||$scanner)return $out;
        try{$previous=$this->readCookie($oldCookie);$visitor=$previous['visitor']??bin2hex(random_bytes(16));$receipt=bin2hex(random_bytes(16));
            if(SmsNotice::modern()&&$handoff!==''){
                $visitor=substr($this->s->crypto->digest(['notice_visitor'=>$handoff]),0,32);$receipt=substr($this->s->crypto->digest(['notice_receipt'=>$handoff]),0,32);
                $existing=$this->s->row('SELECT * FROM '.$this->s->table('clicks').' WHERE receipt_token=%s',$receipt);
                if($existing){if((int)$existing['campaign_id']!==(int)$link['campaign_id']||(int)$existing['link_id']!==(int)$link['id']||!hash_equals($existing['visitor_hash'],$this->s->crypto->digest(['visitor'=>$visitor])))throw new ControlledTestException('Consent journey mismatch.');$out['cookie']=$this->cookie(['visitor'=>$visitor,'receipt'=>$receipt,'until'=>(int)$existing['clicked_at']+self::WINDOW,'sms_owned'=>true]);$out['click_id']=(int)$existing['id'];return $out;}
            }
            $click=$this->s->insert('clicks',['campaign_id'=>$link['campaign_id'],'link_id'=>$link['id'],'visitor_hash'=>$this->s->crypto->digest(['visitor'=>$visitor]),'receipt_token'=>$receipt,'clicked_at'=>time(),'observed_at'=>0,'stage_session_hash'=>null]+(M7Settings::modern()?['recipient_link_id'=>(int)($link['recipient_mapping']['id']??0),'request_recorded'=>1]:[]));$out['cookie']=$this->cookie(['visitor'=>$visitor,'receipt'=>$receipt,'until'=>time()+self::WINDOW]+(SmsNotice::modern()?['sms_owned'=>true]:[]));$out['click_id']=$click;}catch(\Throwable $e){if(!M7Settings::modern())throw $e;M7Settings::error('click_write_failed');}return $out;
    }
    public function current(string $cookie):?array
    {
        if(!self::enabled()||!self::consent())return null;$p=$this->readCookie($cookie);if(!$p)return null;$click=$this->s->row('SELECT * FROM '.$this->s->table('clicks').' WHERE receipt_token=%s',$p['receipt']);if(!$click||!hash_equals($click['visitor_hash'],$this->s->crypto->digest(['visitor'=>$p['visitor']]))||time()-(int)$click['clicked_at']>self::WINDOW)return null;if(M7Settings::modern()&&!empty($click['recipient_link_id'])){$m=$this->s->row('SELECT state,expires_at FROM '.$this->s->table('recipient_links').' WHERE id=%d',$click['recipient_link_id']);if(!$m||$m['state']==='revoked'||(int)$m['expires_at']<time())return null;}return $click;
    }
    public function event(string $cookie,string $kind,string $dedupe):bool
    {
        if(M8Tokens::modern())return (new M8Events($this->s))->record($cookie,$kind,$dedupe);
        if(!in_array($kind,['visit','product_view','add_to_cart','checkout_start'],true))return false;$click=$this->current($cookie);if(!$click)return false;$meta=$this->s->meta((int)$click['campaign_id']);if(!$meta)return false;$state=M6AttributionContracts::state($this->s->crypto,$meta['public_id']);if($state&&!$state['matches'])return false;
        if($state&&$state['matches'])$this->s->update('clicks',['stage_session_hash'=>$state['session_hash']],['id'=>$click['id']]);$key=$this->s->crypto->digest(['click'=>(int)$click['id'],'kind'=>$kind,'dedupe'=>$dedupe]);if($this->s->row('SELECT * FROM '.$this->s->table('journey').' WHERE event_key=%s',$key))return false;
        $this->s->query('INSERT INTO '.$this->s->table('journey').' (campaign_id,click_id,visitor_hash,kind,event_key,occurred_at) VALUES (%d,%d,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE event_key=event_key',$click['campaign_id'],$click['id'],$click['visitor_hash'],$kind,$key,time());if(!$click['observed_at'])$this->s->update('clicks',['observed_at'=>time()],['id'=>$click['id']]);return true;
    }
    private function associateOwned($order,array $click,array $state):bool
    {
        // Server Woo checkout hook + authenticated, permitted first-party journey.
        // No Stage A/B state mutation, customer-identity claim or multi-touch inference.
        if(!M6AttributionContracts::available()||M6AttributionContracts::exclusions()===null||!is_object($order)||!method_exists($order,'get_id')||!method_exists($order,'get_date_created'))return false;
        $id=(int)$order->get_id();$date=$order->get_date_created();$created=$date?$date->getTimestamp():0;
        if($id<1||$created>time()+300||$created<(int)$click['clicked_at']||$created-(int)$click['clicked_at']>self::WINDOW||$this->s->row('SELECT id FROM '.$this->s->table('conversions').' WHERE order_id=%d',$id))return false;
        $this->s->update('clicks',['stage_session_hash'=>$state['session_hash']],['id'=>$click['id']]);
        $this->s->query('INSERT INTO '.$this->s->table('conversions').' (order_id,campaign_id,click_id,stage_session_hash,order_created,associated_at,verified_at,status,currency,revenue_minor,purchase_eligible,reason) VALUES (%d,%d,%d,%s,%d,%d,0,%s,%s,0,0,%s) ON DUPLICATE KEY UPDATE order_id=order_id',$id,$click['campaign_id'],$click['id'],$state['session_hash'],$created,time(),'unverified','', 'awaiting_order_reconciliation');return true;
    }
    public function associate($order,string $cookie):bool
    {
        $click=$this->current($cookie);if(!$click)return false;if(SmsNotice::modern()){ $owned=SmsJourneyState::state($this->s,$click,$cookie);if(!empty($owned['sms_owned']))return $this->associateOwned($order,$click,$owned); }$snapshot=M6AttributionContracts::snapshot($order,$this->s->crypto);$meta=$this->s->meta((int)$click['campaign_id']);if(!$snapshot||!$meta||$snapshot['public_campaign']!==$meta['public_id'])return false;
        $state=M6AttributionContracts::state($this->s->crypto,$meta['public_id']);if(!$click['stage_session_hash']&&$state&&$state['matches']&&hash_equals($state['session_hash'],$snapshot['session_hash'])){$this->s->update('clicks',['stage_session_hash'=>$state['session_hash'],'observed_at'=>time()],['id'=>$click['id']]);$click['stage_session_hash']=$state['session_hash'];}
        if(!$click['stage_session_hash']||!hash_equals($click['stage_session_hash'],$snapshot['session_hash']))return false;$date=$order->get_date_created();$created=$date?$date->getTimestamp():0;if($created<(int)$click['clicked_at']||$created-(int)$click['clicked_at']>self::WINDOW||$snapshot['snapshot_at']<$created-300||$snapshot['snapshot_at']>$created+300)return false;
        $id=(int)$order->get_id();if($id<1)return false;$existing=$this->s->row('SELECT * FROM '.$this->s->table('conversions').' WHERE order_id=%d',$id);if($existing)return false;
        $this->s->query('INSERT INTO '.$this->s->table('conversions').' (order_id,campaign_id,click_id,stage_session_hash,order_created,associated_at,verified_at,status,currency,revenue_minor,purchase_eligible,reason) VALUES (%d,%d,%d,%s,%d,%d,0,%s,%s,0,0,%s) ON DUPLICATE KEY UPDATE order_id=order_id',$id,$click['campaign_id'],$click['id'],$snapshot['session_hash'],$created,time(),'unverified','', 'awaiting_order_reconciliation');return true;
    }
}
