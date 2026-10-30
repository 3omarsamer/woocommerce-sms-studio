<?php
namespace Azula\SmsStudio;

/** Non-destructive preflight only. Never authorizes spending or invokes SMS transport. */
final class AuthorizationBalance
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.10','>=');}
    public static function verified(SmsMisrSettings $settings):array
    {
        $h=$settings->status();
        if(!self::modern())return $h;
        $fresh=static fn(array $v):bool=>$v['balance_verified']&&$v['connection_status']==='verified'&&(int)$v['last_balance_check']<=time()&&(int)$v['last_balance_check']>=time()-900;
        if($fresh($h))return $h;
        try{$h=$settings->probe();}catch(\Throwable $e){throw new ControlledTestException('Automatic Balance verification could not complete. No SMS was authorized. Wait briefly and retry this confirmation; if it persists, review SMS Misr connection health.');}
        if(!$fresh($h))throw new ControlledTestException('Automatic Balance verification failed. No SMS was authorized. Retry here when the provider is available, or review SMS Misr connection health.');
        return $h;
    }
}
