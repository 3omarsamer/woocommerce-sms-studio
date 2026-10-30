<?php
namespace Azula\SmsStudio;

/** SMS-scoped choice; no identifier, destination or campaign stored in this cookie. */
final class SmsConsent
{
    public const COOKIE='azula_sms_analytics_choice';
    public const DAYS=30;
    public static function choice():?bool
    {
        try{$raw=$_COOKIE[self::COOKIE]??'';if(!is_string($raw)||!preg_match('/^v1\.([ad])\.([0-9]{10})\.([a-f0-9]{64})$/D',$raw,$m))return null;
            $until=(int)$m[2];if($until<time()||$until>time()+(self::DAYS+1)*86400||!hash_equals(Crypto::deployment()->digest(['sms_consent_v1'=>$m[1],'until'=>$until,'home'=>home_url('/')]),$m[3]))return null;return $m[1]==='a';
        }catch(\Throwable $e){return null;}
    }
    public static function permitted():bool
    {
        // A prior explicit refusal is never silently overridden by another source.
        $choice=self::choice();if($choice===false)return false;
        try{
            if(function_exists('wp_has_consent')&&wp_has_consent('statistics')!==true)return false;
            if($choice===true)return true; // Authenticated explicit SMS choice; RC18 adds a protected notice.
            // WP Consent API's unset/optout modes can return true without a choice.
            // Only its strict optin mode can establish an independent permission.
            return function_exists('wp_get_consent_type')&&function_exists('wp_has_consent')&&wp_get_consent_type()==='optin'&&wp_has_consent('statistics')===true;
        }catch(\Throwable $e){return false;}
    }
    public static function status():array
    {
        $choice=self::choice();$api=function_exists('wp_has_consent');$optin=false;
        try{$optin=function_exists('wp_get_consent_type')&&wp_get_consent_type()==='optin';}catch(\Throwable $e){}
        return ['permitted'=>self::permitted(),'legacy_choice'=>$choice===true?'accepted':($choice===false?'rejected':'absent_or_invalid'),'framework_present'=>$api,'framework_optin'=>$optin,'scope'=>'Current browser only; no permission is granted by opening a link.'];
    }
    public static function value(bool $allow,?int $until=null):string
    {
        $until??=(intdiv(time(),86400)+self::DAYS)*86400;$choice=$allow?'a':'d';
        return 'v1.'.$choice.'.'.$until.'.'.Crypto::deployment()->digest(['sms_consent_v1'=>$choice,'until'=>$until,'home'=>home_url('/')]);
    }
    public static function proof(string $scope,int $until):string{return Crypto::deployment()->digest(['sms_consent_form_v1'=>$scope,'until'=>$until,'home'=>home_url('/')]);}
    public static function decision(string $scope,array $post,array $server):?bool
    {
        if(($server['REQUEST_METHOD']??'')!=='POST')return null;
        $origin=$server['HTTP_ORIGIN']??'';$home=rtrim(home_url('/'),'/');
        // no-referrer may serialize a form Origin as null; Fetch Metadata must then
        // prove same-origin. A foreign Origin never gains that fallback.
        $sameOrigin=is_string($origin)&&($origin===$home||(in_array($origin,['','null'],true)&&($server['HTTP_SEC_FETCH_SITE']??'')==='same-origin'));
        if(!$sameOrigin||!in_array($post['sms_choice']??null,['allow','deny'],true))throw new ControlledTestException('Consent request rejected. Reload the link and choose again.');
        $until=filter_var($post['until']??null,FILTER_VALIDATE_INT);$proof=$post['proof']??'';
        if(!$until||$until<time()||$until>time()+1200||!is_string($proof)||!hash_equals(self::proof($scope,$until),$proof))throw new ControlledTestException('Consent request expired. Reload the link and choose again.');
        return $post['sms_choice']==='allow';
    }
    public static function remember(bool $allow,bool $notice=false):void
    {
        if($allow&&(!SmsNotice::modern()||!$notice))throw new ControlledTestException('Positive SMS consent requires the protected SMS notice.');$value=self::value($allow);setcookie(self::COOKIE,$value,['expires'=>(intdiv(time(),86400)+self::DAYS)*86400,'path'=>'/','secure'=>true,'httponly'=>false,'samesite'=>'Lax']);$_COOKIE[self::COOKIE]=$value;
        if(!$allow){setcookie(M6Tracking::COOKIE,'',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE[M6Tracking::COOKIE]);}
    }
    public static function headers():void
    {
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        if(function_exists('do_action'))do_action('litespeed_control_set_nocache','Azula SMS privacy response');
        nocache_headers();foreach(['Cache-Control: private, no-store, max-age=0','CDN-Cache-Control: no-store','Cloudflare-CDN-Cache-Control: no-store','X-LiteSpeed-Cache-Control: no-cache','Referrer-Policy: no-referrer','X-Robots-Tag: noindex, nofollow','X-Content-Type-Options: nosniff','X-Frame-Options: DENY','Vary: Cookie'] as $h)header($h);
    }
    public static function clean(string $url):string
    {
        if(!M6Links::allowed($url))throw new ControlledTestException('Link destination unavailable.');
        $parts=explode('#',$url,2);$base=explode('?',$parts[0],2);$keep=[];
        foreach(explode('&',$base[1]??'') as $pair){$key=rawurldecode(explode('=',$pair,2)[0]);if($pair!==''&&!preg_match('/^(?:utm_.*|gclid|dclid|fbclid|msclkid|azula_sms_link)$/i',$key))$keep[]=$pair;}
        return $base[0].($keep?'?'.implode('&',$keep):'').(isset($parts[1])?'#'.$parts[1]:'');
    }
    public static function page(string $scope,string $original,string $notice=''):string
    {
        $until=time()+1200;$proof=self::proof($scope,$until);$escape=fn($s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");header('Content-Type: text/html; charset=UTF-8');
        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="strict-origin"><title>SMS analytics withdrawal</title><main><h1>Withdraw SMS analytics permission</h1><p>This existing privacy address only withdraws permission. It cannot grant consent. Shopping and SMS links remain available.</p><p role="status">'.$escape($notice).'</p><form method="post"><input type="hidden" name="until" value="'.$until.'"><input type="hidden" name="proof" value="'.$proof.'"><button name="sms_choice" value="deny">Withdraw SMS analytics permission</button></form><a rel="noreferrer" href="'.$escape($original).'">Return to the shop</a></main></html>';
    }
    public static function register():void{add_action('template_redirect',[self::class,'manage'],0);SmsNotice::register();}
    public static function footer():void{} // No automatic customer-facing control.
    public static function manage():void
    {
        if(!isset($_GET['azula_sms_privacy'])||!M11Environment::available())return;self::headers();$notice='';
        try{$choice=self::decision('manage',$_POST,$_SERVER);if($choice===true)throw new ControlledTestException('This address only withdraws permission.');if($choice!==null){self::remember(false);$notice='SMS analytics rejected. The SMS journey cookie has been removed.';}echo self::page('manage',home_url('/'),$notice);}catch(\Throwable $e){status_header(400);echo self::page('manage',home_url('/'),'Choice not saved. Reload and try again.');}exit;
    }
}
