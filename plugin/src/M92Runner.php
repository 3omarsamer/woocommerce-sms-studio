<?php
namespace Azula\SmsStudio;
/** Automatically test the existing scheduler with a harmless SMS-owned probe, never a provider request. */
final class M92Runner
{
    public static function register():void
    {
        add_action('action_scheduler_init',[self::class,'ensure'],40);add_action('init',[self::class,'ensure'],90);add_action('init',[M9Worker::class,'ensure'],91);add_action('azula_sms_prepare_watchdog',[self::class,'ensure'],40);add_action('azula_sms_prepare_probe',[self::class,'completed'],20,1);
    }
    public static function ensure():void
    {
        if(!M92Policy::modern()||!M11Environment::available()||!Schema::ready()||!M2Scheduler::available())return;
        try{$p=get_option(M11Environment::option('azula_sms_probe'),[]);if((int)($p['requested']??0)<time()-180&&(!M10::modern()||(int)($p['completed']??0)>0||(int)($p['requested']??0)<time()-7200))M2Scheduler::requestProbe();}catch(\Throwable $e){update_option(M11Environment::option('azula_sms_runner_probe_error'),'probe_queue_failed',false);}
    }
    public static function completed(string $token):void
    {
        if(!M11Environment::available())return;$p=get_option(M11Environment::option('azula_sms_probe'),[]);if(!empty($p['token'])&&hash_equals($p['token'],$token)&&(int)($p['completed']??0)>=(int)($p['requested']??PHP_INT_MAX)&&(int)($p['completed']??0)<=time())update_option(M11Environment::option('azula_sms_runner_verified'),(int)$p['completed'],false);
    }
    public static function status():array
    {
        $p=get_option(M11Environment::option('azula_sms_probe'),[]);$completed=(int)get_option(M11Environment::option('azula_sms_runner_verified'),0);if(!empty($p['token'])&&(int)($p['completed']??0)>=(int)($p['requested']??PHP_INT_MAX))$completed=max($completed,(int)($p['completed']??0));
        $available=M2Scheduler::available();$verified=$available&&$completed>time()-300&&$completed<=time();
        return ['available'=>$available,'verified_recently'=>$verified,'last_completed'=>$completed,'requested'=>(int)($p['requested']??0),'manual_check_proves_unattended'=>false,'unattended_verified'=>false,'reason'=>!$available?'Action Scheduler is unavailable.':($verified?'A real SMS-owned background probe completed recently. Unattended Hostinger cron still requires the closed-browser acceptance test.':($completed?'Background scheduler verification is stale. New provider authorizations are blocked until its automatic probe completes.':'Background scheduler has not completed its automatic probe. No provider submission is authorized. Check Advanced Diagnostics if it stays pending.'))];
    }
}
