<?php
namespace Azula\SmsStudio;

/** Tracking permission is independent of all sending permission. */
final class M7Settings
{
    public const OPTION='azula_sms_tracking_policy';
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.7.0','>=');}
    public static function domain():string
    {
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));$env=wp_get_environment_type();
        if($env==='staging'&&$host==='preview.azulasportswear.com')return 'https://preview.azulasportswear.com';
        if($env==='production'&&$host==='azulasportswear.com')return 'https://azulasportswear.com';
        throw new ControlledTestException('Tracking requires the exact configured STAGING or production Azula domain.');
    }
    public static function config():array
    {
        if(function_exists('wp_cache_delete')){wp_cache_delete(M11Environment::option(self::OPTION),'options');wp_cache_delete('notoptions','options');}
        $raw=get_option(M11Environment::option(self::OPTION),null);$v=['enabled'=>false,'path'=>'s'];
        if($raw===null){$v['enabled']=M2Scheduler::staging()&&defined('AZULA_SMS_STUDIO_TRACKING_ENABLED')&&AZULA_SMS_STUDIO_TRACKING_ENABLED===true;return $v;}
        try{$p=Crypto::deployment()->open(is_string($raw)?$raw:'');if(!is_bool($p['enabled']??null)||!preg_match('/^[a-z][a-z0-9-]{0,23}$/D',$p['path']??''))return $v;return ['enabled'=>$p['enabled'],'path'=>$p['path']];}catch(\Throwable $e){return $v;}
    }
    public static function enabled():bool{try{self::domain();return self::config()['enabled']&&Schema::ready();}catch(\Throwable $e){return false;}}
    public static function conflict(string $path):bool
    {
        if(function_exists('get_page_by_path')&&get_page_by_path($path))return true;
        $rules=get_option('rewrite_rules',[]);if(is_array($rules))foreach($rules as $pattern=>$target){$prefix=ltrim((string)$pattern,'^');if(str_starts_with($prefix,$path.'/')||str_starts_with($prefix,$path.'/?'))return true;}
        return false;
    }
    public static function store():M6Store{self::domain();if(!Schema::ready())throw new ControlledTestException('Tracking schema upgrade required.');global $wpdb;return new M6Store(new Database($wpdb),Crypto::deployment());}
    public static function save(M6Store $s,array $p):array
    {
        SmsMisrSettings::authorize($p['nonce']??'');self::domain();if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!in_array($p['enabled']??null,['0','1'],true)||!is_string($p['path']??null)||!preg_match('/^[a-z][a-z0-9-]{0,23}$/D',$p['path'])||in_array($p['path'],['wp-admin','wp-json','shop','product','cart','checkout','my-account'],true))throw new ControlledTestException('Choose ON/OFF and a safe short-link path.');
        $lock='azula_sms_tracking_'.substr(hash('sha256',$s->database->db->prefix),0,32);$db=$s->database->db;if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new ControlledTestException('Tracking settings are busy.');
        try{$old=self::config();if($old['path']!==$p['path']){if($s->row('SELECT id FROM '.$s->table('recipient_links').' LIMIT 1'))throw new ControlledTestException('The short path is locked after links are created. Preserve issued URLs.');if(self::conflict($p['path']))throw new ControlledTestException('That path belongs to an existing WordPress page.');}
            $v=$s->crypto->seal(['enabled'=>$p['enabled']==='1','path'=>$p['path'],'actor'=>get_current_user_id(),'saved_at'=>time()]);update_option(M11Environment::option(self::OPTION),$v,false);if(get_option(M11Environment::option(self::OPTION))!==$v)throw new ControlledTestException('Tracking setting was not saved.');return self::status();
        }finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function status():array
    {
        $v=self::config();try{$domain=self::domain();$reason=$v['enabled']?(Schema::ready()?'Collection gate ON; use the diagnostic to test storage.':'Schema upgrade required.'):'Collection OFF. Enable Tracking collection in Settings & Health. No events or journey cookies are collected.';}catch(\Throwable $e){$domain=null;$reason=$e->getMessage();}
        return ['enabled'=>self::enabled(),'path'=>$v['path'],'domain'=>$domain,'reason'=>$reason,'route_conflict'=>self::conflict($v['path']),'schema_ready'=>Schema::ready(),'consent'=>M6Tracking::consent(),'consent_policy'=>SmsConsent::status()];
    }
    public static function error(string $code):void
    {
        try{$rows=get_option('azula_sms_tracking_errors',[]);if(!is_array($rows))$rows=[];if($rows&&($rows[0]['code']??'')===$code&&time()-($rows[0]['at']??0)<60)return;array_unshift($rows,['code'=>$code,'at'=>time()]);update_option('azula_sms_tracking_errors',array_slice($rows,0,20),false);}catch(\Throwable $e){}
    }
}
