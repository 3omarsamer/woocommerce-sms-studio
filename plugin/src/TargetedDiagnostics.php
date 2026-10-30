<?php
namespace Azula\SmsStudio;
final class TargetedDiagnostics
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.5','>=');}
    public static function trigger():string
    {
        if(defined('WP_CLI')&&WP_CLI)return 'wp_cli';
        if(function_exists('wp_doing_cron')&&wp_doing_cron())return 'wp_cron';
        if(function_exists('wp_doing_ajax')&&wp_doing_ajax())return 'ajax_request';
        if(function_exists('is_admin')&&is_admin())return 'admin_request';return 'hook_unknown_trigger';
    }
    public static function reason(\Throwable $e):string
    {
        if(!($e instanceof ControlledTestException))return 'storage_or_runtime_error';
        $s=$e->getMessage();foreach(['Frozen source identity lookup failed'=>'frozen_identity_lookup_failed','Current Customer Segments membership could not be verified'=>'retargeting_source_unavailable','Frozen source evidence missing'=>'frozen_source_evidence_missing','Frozen customer identity is unverified'=>'frozen_identity_unverified','Frozen customer phone is invalid'=>'frozen_phone_invalid','Customer phone changed before approval'=>'source_phone_changed_before_approval','Initial approval freshness expired'=>'initial_approval_freshness_expired','Approved destination URL changed'=>'approved_destination_changed','Frozen source identity unavailable'=>'frozen_identity_unavailable','Read-only frozen identity verification'=>'frozen_identity_verification_unavailable','Rescheduling requires the original frozen'=>'reschedule_binding_changed','Original frozen authorization evidence'=>'original_authorization_missing','Production dispatch requires'=>'production_readiness_missing','Execution approval expired'=>'approval_expired_or_environment','fresh complete audience'=>'audience_freshness_or_source_rules','Campaign, audience, sender or provider'=>'package_binding_changed','Controlled Live authorization changed'=>'live_policy_changed_or_paused','Approving administrator'=>'actor_permissions_changed','Execution plan was cancelled'=>'plan_invalidated','Scheduled launch was missed'=>'scheduled_launch_missed','Scheduled time was missed'=>'scheduled_launch_missed','Authorized Balance refresh failed'=>'balance_refresh_failed','Verified balance is below'=>'budget_balance_insufficient','unknown provider outcome'=>'unknown_outcome_no_retry','Current approval is unavailable'=>'approval_missing','No final authorization'=>'authorization_missing','Exact approved recipient message changed'=>'recipient_message_changed','Execution binding is invalid'=>'execution_binding_invalid','Save the exact final campaign review'=>'review_package_invalid'] as $part=>$code)if(str_contains($s,$part))return $code;
        return 'controlled_validation_blocked'; // Never store arbitrary exception text.
    }
    public static function actions():array
    {
        $out=['records'=>[],'hostinger_origin_proven'=>false,'notice'=>'Action Scheduler records and observed PHP context do not authenticate the external caller. WP-Cron does not prove Hostinger cron; AJAX does not prove unattended execution.'];
        if(!function_exists('as_get_scheduled_actions'))return $out+['error'=>'Action Scheduler records unavailable'];
        try{foreach(['complete','failed','in-progress'] as $state){$ids=as_get_scheduled_actions(['hook'=>M9Worker::HOOK,'group'=>M9Worker::GROUP,'status'=>$state,'per_page'=>10,'orderby'=>'date','order'=>'DESC'],'ids');foreach($ids as $id){$v=['action_id'=>(int)$id,'status'=>$state,'runner'=>'unknown','started_at'=>null];
            if(class_exists('ActionScheduler_Logger')&&method_exists('ActionScheduler_Logger','instance')){$logger=\ActionScheduler_Logger::instance();if(method_exists($logger,'get_logs'))foreach(array_slice($logger->get_logs((int)$id),-20) as $log){if(!method_exists($log,'get_message'))continue;$m=$log->get_message();foreach(['WP Cron'=>'wp_cron','WP-Cron'=>'wp_cron','Async Request'=>'async_request','WP CLI'=>'wp_cli','WP-CLI'=>'wp_cli','Admin'=>'admin_request'] as $term=>$runner)if(stripos($m,'started')!==false&&stripos($m,$term)!==false){$v['runner']=$runner;if(method_exists($log,'get_date')){$date=$log->get_date();if($date instanceof \DateTimeInterface)$v['started_at']=$date->getTimestamp();}}}}
            $out['records'][]=$v;}}}catch(\Throwable $e){$out['error']='Action Scheduler record inspection unavailable; no raw logs were displayed.';}
        return $out;
    }
}
