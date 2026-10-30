<?php
namespace Azula\SmsStudio;
final class M7Front
{
    public static function register():void{add_action('parse_request',[self::class,'route'],0);add_action('azula_sms_tracking_retention',[self::class,'prune']);add_action('action_scheduler_init',[self::class,'ensure'],35);}
    public static function route():void
    {
        $configured=M7Settings::config()['path'];if(M7Settings::conflict($configured))return;$path=wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);$prefix='/'.$configured.'/';if(!is_string($path)||!str_starts_with($path,$prefix))return;
        SmsConsent::headers();
        try{if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true))throw new \RuntimeException('method');$token=substr($path,strlen($prefix));if(!M8Tokens::valid($token))throw new \RuntimeException('token');M7Settings::domain();$target=M7Collector::probe($token);if($target!==null){update_option('azula_sms_tracking_request_seen',time(),false);wp_redirect($target,302,'Azula SMS Studio diagnostic');exit;}
            $purpose=strtolower((string)($_SERVER['HTTP_SEC_PURPOSE']??$_SERVER['HTTP_PURPOSE']??''));$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');$scanner=str_contains($purpose,'prefetch')||preg_match('/bot|crawler|spider|preview|facebookexternalhit|whatsapp|proofpoint|barracuda|mimecast|urlscan|google-safety/i',$ua)===1;
            $s=M7Settings::store();$resolved=(new M7Links($s))->resolve($token);$r=(new M6Tracking($s))->redirect($token,is_string($_COOKIE[M6Tracking::COOKIE]??null)?$_COOKIE[M6Tracking::COOKIE]:'',$_SERVER['REQUEST_METHOD']??'GET',$scanner,true,$resolved);if($r['cookie']!==null)setcookie(M6Tracking::COOKIE,$r['cookie'],['expires'=>time()+M6Tracking::WINDOW,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);SmsNotice::arrive($token,true,$_SERVER['REQUEST_METHOD']??'GET',(bool)$scanner);wp_redirect($r['target'],302,'Azula SMS Studio');exit;
        }catch(\Throwable $e){status_header(404);echo 'This SMS link is unavailable.';exit;}
    }
    public static function ensure(bool $successor=false):void{try{M7Settings::domain();if(Schema::ready()&&M2Scheduler::available()&&($successor||!as_has_scheduled_action('azula_sms_tracking_retention',[],M6Worker::GROUP)))as_schedule_single_action(time()+3600,'azula_sms_tracking_retention',[],M6Worker::GROUP,false);}catch(\Throwable $e){}}
    public static function prune():void
    {
        try{$s=M7Settings::store();foreach(['link_requests'=>'bucket','journey'=>'occurred_at'] as $table=>$time){$days=$table==='link_requests'?30:90;$t=$s->table($table);$s->query("DELETE FROM $t WHERE id IN (SELECT id FROM (SELECT id FROM $t WHERE $time<%d ORDER BY id LIMIT 500) old_rows)",time()-$days*86400);}}catch(\Throwable $e){M7Settings::error('retention_failed');}self::ensure(true);
    }
}
