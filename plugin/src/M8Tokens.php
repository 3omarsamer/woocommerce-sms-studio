<?php
namespace Azula\SmsStudio;
final class M8Tokens
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.8.0','>=');}
    public static function pattern():string{return self::modern()?'(?:[a-f0-9]{32}|[A-Za-z0-9_-]{22}'.(M91Campaign::modern()?'|[A-Za-z0-9_-]{16}':'').(RC1Status::modern()?'|[A-Za-z0-9_-]{10}':'').')':'[a-f0-9]{32}';}
    public static function valid(string $token):bool
    {
        if(RC1Status::modern()&&preg_match('/^[A-Za-z0-9_-]{10}$/D',$token))return true;if(preg_match('/^[a-f0-9]{32}$/D',$token))return true;if(M91Campaign::modern()&&preg_match('/^[A-Za-z0-9_-]{16}$/D',$token))return true;if(!self::modern()||!preg_match('/^[A-Za-z0-9_-]{22}$/D',$token))return false;
        $raw=base64_decode(strtr($token,'-_','+/').'==',true);return is_string($raw)&&strlen($raw)===16&&hash_equals(rtrim(strtr(base64_encode($raw),'+/','-_'),'='),$token);
    }
    public static function allocate(M6Store $s,?callable $random=null):string
    {
        for($i=0;$i<8;$i++){$length=RC1Status::modern()?8:(M91Campaign::modern()?12:16);$bytes=$random?$random():random_bytes($length);if(!is_string($bytes)||strlen($bytes)!==$length)throw new ControlledTestException('Invalid token entropy.');$token=rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');if(RC1Status::modern())$token=substr($token,0,10);
            if(!$s->row('SELECT id FROM '.$s->table('links').' WHERE public_token=%s',$token)&&!$s->row('SELECT id FROM '.$s->table('recipient_links').' WHERE public_token=%s',$token))return $token;}
        throw new ControlledTestException('Token allocation busy; retry with new randomness.');
    }
}
