<?php
namespace Azula\SmsStudio;

final class M6SendPolicy
{
    public const FRESH=600;
    public const BATCH=100;
    public const MAX_TEST=10000;
    public const LIVE_OPTION='azula_sms_manual_live_policy';
    public static function liveStatus():array
    {
        // This option is non-autoloaded. Re-read it rather than trusting an earlier
        // request-local/object-cache authorization result at the HTTP boundary.
        if(function_exists('wp_cache_delete')){wp_cache_delete(M11Environment::option(self::LIVE_OPTION),'options');wp_cache_delete('notoptions','options');}
        $enabled=false;$source='default_off';$raw=get_option(M11Environment::option(self::LIVE_OPTION),null);
        if(is_string($raw)){try{$v=Crypto::deployment()->open($raw);$enabled=($v['enabled']??null)===true;$source='administrator_setting';}catch(\Throwable $e){$source='invalid_setting';}}
        elseif(M2Scheduler::staging()&&$raw===null&&defined('AZULA_SMS_STUDIO_LIVE_TEST_ENABLED')){$enabled=constant('AZULA_SMS_STUDIO_LIVE_TEST_ENABLED')===true;$source='legacy_constant';}
        $staging=M11Environment::available();return ['enabled'=>$staging&&$enabled,'configured'=>$enabled,'settings_available'=>M11Environment::available(),'staging'=>M2Scheduler::staging(),'source'=>$source];
    }
    public static function saveLive(array $p,$db):array
    {
        self::authorize($p);if(!M11Environment::available())throw new ControlledTestException('Manual Live settings are available only on an explicitly enabled Azula environment.');
        if(!is_string($p['enabled']??null)||!in_array($p['enabled'],['0','1'],true))throw new ControlledTestException('Choose ON or OFF.');
        $lock='azula_sms_provider_'.substr(hash('sha256',$db->prefix),0,32);if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new ControlledTestException('A provider submission or settings change is in progress. Retry after it finishes.');
        try{$sealed=Crypto::deployment()->seal(['enabled'=>$p['enabled']==='1','actor'=>get_current_user_id(),'saved_at'=>time()]);update_option(M11Environment::option(self::LIVE_OPTION),$sealed,false);if(get_option(M11Environment::option(self::LIVE_OPTION))!==$sealed)throw new ControlledTestException('Manual Live setting could not be saved.');return self::liveStatus();}finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function enabled(int $environment):bool
    {
        if(!M11Environment::dispatchReady())return false;
        if(M11Environment::production()&&$environment!==1)return false;if($environment===1)return self::liveStatus()['enabled'];
        $key=$environment===2?'AZULA_SMS_STUDIO_TEST_SENDING_ENABLED':($environment===1?'AZULA_SMS_STUDIO_LIVE_TEST_ENABLED':'');
        return $key!==''&&defined($key)&&constant($key)===true;
    }
    public static function authorize(array $p):void
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new ControlledTestException('Sending actions require an explicit administrator POST.');
        foreach(['manage_azula_sms_settings','approve_azula_sms_campaigns','dispatch_azula_sms_campaigns'] as $cap)if(!current_user_can($cap))throw new ControlledTestException('Provider, approval and dispatch permissions are required.');
    }
    public static function scope(int $environment,int $count,int $selected,bool $automatic):void
    {
        if(!self::enabled($environment))throw new ControlledTestException('The owner has not enabled this sending environment on STAGING. Installation does not enable sending.');
        if($count<1||($environment===2&&$count>self::MAX_TEST))throw new ControlledTestException('A complete audience of 1–10,000 unique recipients is required for this bounded Test workflow.');
        if($environment===1&&($count!==1||$selected<1||$automatic))throw new ControlledTestException('Bulk and automatic Live sending are blocked. The initial Live request requires exactly one explicitly selected validated recipient.');
    }
}
