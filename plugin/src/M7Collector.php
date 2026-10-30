<?php
namespace Azula\SmsStudio;
final class M7Collector
{
    public function __construct(private M6Store $s){}
    public function record(array $link,string $source,string $method,bool $scanner):void
    {
        if(!M7Settings::enabled()||!M6Tracking::consent())return;$classification=$method==='HEAD'?'head':($method!=='GET'?'unsupported_method':($scanner?'automated_preview':(empty($link['active'])?'unreleased_preview':'unclassified_get')));$minute=intdiv(time(),60)*60;$mapping=(int)($link['recipient_mapping']['id']??0);$key=hash('sha256',implode(':',[$source,$link['id'],$mapping,$classification,$minute]));
        $this->s->query('INSERT INTO '.$this->s->table('link_requests').' (campaign_id,link_id,recipient_link_id,event_key,source,classification,bucket,hits) VALUES (%d,%d,%d,%s,%s,%s,%d,1) ON DUPLICATE KEY UPDATE hits=hits+1',$link['campaign_id'],$link['id'],$mapping,$key,$source,$classification,$minute);
    }
    public function diagnostic():array
    {
        $status=M7Settings::status();$write=false;$resolver=false;$key=hash('sha256',random_bytes(32));try{$this->s->database->transaction(function()use($key){$this->s->insert('link_requests',['campaign_id'=>0,'link_id'=>0,'recipient_link_id'=>0,'event_key'=>$key,'source'=>'diagnostic','classification'=>'probe','bucket'=>time(),'hits'=>1]);if(!$this->s->row('SELECT id FROM '.$this->s->table('link_requests').' WHERE event_key=%s',$key))throw new \RuntimeException('write failed');$this->s->query('DELETE FROM '.$this->s->table('link_requests').' WHERE event_key=%s',$key);});$write=true;}catch(\Throwable $e){M7Settings::error('diagnostic_write_failed');}
        $token=bin2hex(random_bytes(16));$sealed=$this->s->crypto->seal(['hash'=>hash('sha256',$token),'until'=>time()+300,'target'=>M7Settings::domain().'/']);update_option('azula_sms_route_probe',$sealed,false);$resolver=self::probe($token)!==null;
        return ['status'=>$status,'route_registered'=>function_exists('has_action')?has_action('parse_request',[M7Front::class,'route'])!==false:!empty($GLOBALS['test_hooks']['parse_request']),'database_write'=>$write,'local_resolver'=>$resolver,'test_url'=>M7Links::url($token),'probe_expires'=>time()+300,'last_request_seen'=>(int)get_option('azula_sms_tracking_request_seen',0),'errors'=>get_option('azula_sms_tracking_errors',[]),'edge'=>'WordPress route registration and local resolution do not verify Cloudflare/Hostinger reachability. Open the five-minute diagnostic link from the allowed network, then refresh diagnostics. No SMS or provider call occurs.'];
    }
    public static function probe(string $token):?string
    {
        try{$raw=get_option('azula_sms_route_probe',null);if(!is_string($raw))return null;$v=Crypto::deployment()->open($raw);if(($v['until']??0)<time()||!hash_equals($v['hash']??'',hash('sha256',$token))||($v['target']??'')!==M7Settings::domain().'/')return null;return $v['target'];}catch(\Throwable $e){return null;}
    }
}
