<?php
namespace Azula\SmsStudio;

/** SMS-only consent UI. The short-lived encrypted handoff is not an analytics record. */
final class SmsNotice
{
    public const CONTEXT='azula_sms_notice_context';
    public const FLAG='azula_sms_notice';
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.18','>=');}
    private static function cookie(string $name,string $value,int $until,bool $httpOnly):void
    {
        setcookie($name,$value,['expires'=>$until,'path'=>'/','secure'=>true,'httponly'=>$httpOnly,'samesite'=>'Lax']);
        if($until>time())$_COOKIE[$name]=$value;else unset($_COOKIE[$name]);
    }
    public static function arrive(string $token,bool $short,string $method,bool $scanner):void
    {
        if(!self::modern()||$method!=='GET'||$scanner||!M6Tracking::enabled())return;
        // Used only to display this notice and resume the requested link after a choice.
        // Never put the token into a destination URL, HTML, localStorage or a log.
        $until=time()+1200;
        self::cookie(self::CONTEXT,Crypto::deployment()->seal(['token'=>$token,'short'=>$short,'until'=>$until]),$until,true);
        self::cookie(self::FLAG,'1',SmsConsent::choice()===null?$until:time()+SmsConsent::DAYS*86400,false);
    }
    private static function context():?array
    {
        try{$raw=$_COOKIE[self::CONTEXT]??'';if(!is_string($raw)||strlen($raw)>2048)return null;$v=Crypto::deployment()->open($raw);
            if(!is_int($v['until']??null)||$v['until']<time()||$v['until']>time()+1200||!is_bool($v['short']??null)||!is_string($v['token']??null)||!M8Tokens::valid($v['token']))return null;return $v;
        }catch(\Throwable $e){return null;}
    }
    private static function scope():string{return 'sms_notice:'.hash('sha256',(string)($_COOKIE[self::CONTEXT]??'').'|'.(string)($_COOKIE[SmsConsent::COOKIE]??''));}
    private static function origin(array $server):void
    {
        if(($server['REQUEST_METHOD']??'')!=='POST'||rtrim((string)($server['HTTP_ORIGIN']??''),'/')!==rtrim(home_url('/'),'/'))throw new ControlledTestException('Reload the shop and try again.');
        if(isset($server['HTTP_SEC_FETCH_SITE'])&&$server['HTTP_SEC_FETCH_SITE']!=='same-origin')throw new ControlledTestException('Cross-site consent request blocked.');
    }
    public static function handle(array $p,array $server):array
    {
        if(!self::modern()||!M11Environment::available()||!Schema::ready())throw new ControlledTestException('SMS preferences are unavailable.');
        self::origin($server);$context=self::context();$known=SmsConsent::choice();$op=$p['op']??'status';
        if(($_COOKIE[self::FLAG]??'')!=='1'||(!$context&&$known===null)){if($op==='choice')throw new ControlledTestException('The SMS arrival expired. Open the SMS link again before choosing.');return ['visible'=>false];}
        $resumeMessage='';
        if($op==='choice'){
            $choice=SmsConsent::decision(self::scope(),$p,$server);if($choice===null)throw new ControlledTestException('Choose Accept or Decline.');
            SmsConsent::remember($choice,true);self::cookie(self::FLAG,'1',time()+SmsConsent::DAYS*86400,false);
            // A repeat request must not issue another click. The existing journey wins.
            if($choice&&$context&&SmsConsent::permitted()){
                try{$s=M7Settings::store();$t=new M6Tracking($s);$old=is_string($_COOKIE[M6Tracking::COOKIE]??null)?$_COOKIE[M6Tracking::COOKIE]:'';
                    if(!$t->current($old)){$out=$t->redirect($context['token'],$old,'GET',false,$context['short'],null,(string)($_COOKIE[self::CONTEXT]??''));if($out['cookie']!==null)self::cookie(M6Tracking::COOKIE,$out['cookie'],time()+M6Tracking::WINDOW,true);}
                }catch(\Throwable $e){$resumeMessage='Your preference is saved. This SMS visit could not start measurement; shopping remains available.';self::cookie(self::CONTEXT,'',1,true);}
            }
            // A rejection discards the recipient handoff as well as the optional journey.
            if(!$choice)self::cookie(self::CONTEXT,'',1,true);
        }elseif($op!=='status')throw new ControlledTestException('Unknown preference action.');
        $until=time()+1200;$choice=SmsConsent::choice();
        return ['visible'=>true,'choice'=>$choice===null?'unknown':($choice?'accepted':'declined'),'permitted'=>SmsConsent::permitted(),'until'=>$until,'proof'=>SmsConsent::proof(self::scope(),$until),'message'=>($choice===true&&!SmsConsent::permitted())?'Another existing consent setting blocks analytics. Your SMS preference is saved; no analytics will be collected.':$resumeMessage];
    }
    public static function ajax():void
    {
        SmsConsent::headers();try{wp_send_json_success(self::handle(wp_unslash($_POST),$_SERVER));}catch(\Throwable $e){wp_send_json_error(['message'=>'SMS preference was not saved. Reload the page and try again.'],400);}
    }
    public static function register():void
    {
        if(!self::modern())return;add_action('wp_enqueue_scripts',[self::class,'assets'],31);add_action('wp_ajax_azula_sms_consent',[self::class,'ajax']);add_action('wp_ajax_nopriv_azula_sms_consent',[self::class,'ajax']);
    }
    public static function assets():void
    {
        if(is_admin()||!M11Environment::available())return;
        // Identical cacheable bootstrap for all visitors. No personalized cached markup.
        wp_enqueue_style('azula-sms-notice',plugin_dir_url(AZULA_SMS_STUDIO_FILE).'assets/sms-notice.css',[],AZULA_SMS_STUDIO_VERSION);
        wp_enqueue_script('azula-sms-notice',plugin_dir_url(AZULA_SMS_STUDIO_FILE).'assets/sms-notice.js',[],AZULA_SMS_STUDIO_VERSION,true);
        wp_add_inline_script('azula-sms-notice','window.AzulaSmsNotice='.wp_json_encode(['url'=>admin_url('admin-ajax.php')]).';','before');
    }
}
