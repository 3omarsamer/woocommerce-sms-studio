<?php
namespace Azula\SmsStudio;
final class M9Api
{
    public static function register():void{M9Worker::register();if(M92Policy::modern())M92Runner::register();add_action('admin_post_azula_sms_retarget_export',[self::class,'export']);add_action('admin_post_azula_sms_final_export',[self::class,'finalExport']);}
    public static function handle(array $p):array
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new ControlledTestException('Administrator POST required.');$s=M6Worker::store();Schema::verify($s->database);return self::execute($s,M9Worker::source($s),$p);
    }
    public static function execute(M6Store $s,AudienceSource $source,array $p):array
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new ControlledTestException('Administrator POST required.');$op=$p['op']??'';$target=new M9Retargeting($s);if(RC2Audiences::modern()&&in_array($op,['m9_target_save','m9_target_report','m9_target_list','m9_target_detail','m9_target_rename','m9_target_remove'],true))return (new RC2Audiences($s,$source))->execute($p);
        if(in_array($op,['m9_target_report','m9_target_save'],true)){if(!current_user_can('view_azula_sms_reports'))throw new ControlledTestException('SMS report permission required.');return $op==='m9_target_save'?$target->save($p):$target->report($p);}
        M6SendPolicy::authorize($p);$bulk=new M9Bulk($s,$source);
        if($op==='m9_owner_readiness')return ['production'=>['is_production'=>M11Environment::production(),'environment_available'=>M11Environment::available(),'dispatch_ready'=>M11Environment::dispatchReady(),'reason'=>M11Environment::dispatchProblem()],'policy'=>M9Policy::status(),'pilot'=>M10::modern()?M10::pilot($s,M92Policy::review($s)):M92Policy::review($s),'runner'=>M92Runner::status(),'tracking'=>['collection_enabled'=>M6Tracking::enabled()],'value_evidence'=>M92Readiness::values($s)];
        if($op==='m9_health')return (M10::modern()?['m10_jobs'=>M10::jobs()]:[])+['policy'=>M9Policy::status(),'action_scheduler'=>M91Campaign::scheduler(),'runner'=>M2Scheduler::health(),'last_worker'=>(int)get_option(M11Environment::option('azula_sms_m9_worker_seen'),0),'execution_health'=>$s->row('SELECT MIN(CASE WHEN next_check>0 THEN next_check ELSE NULL END) next_execution,SUM(CASE WHEN state IN (\'preparing\',\'authorized\') THEN 1 ELSE 0 END) pending_runs,SUM(CASE WHEN state=\'blocked\' THEN 1 ELSE 0 END) failed_runs FROM '.$s->table('bulk_runs')),'runs'=>$s->rows('SELECT r.id,p.campaign_id,r.mode,r.state,r.last_progress,r.error_code FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans').' p ON p.id=r.plan_id ORDER BY r.id DESC LIMIT 100')];
        if(M10::modern()&&$op==='m9_campaign_diagnostics')return M10::inspect($s,$source,M9Policy::integer($p['campaign']??'',1,PHP_INT_MAX),M9Policy::integer($p['language']??'1',1,3),is_string($p['timing']??null)?$p['timing']:'later');
        if($op==='m9_policy_save')return ['policy'=>M9Policy::save($s,$p)];
        if($op==='m9_campaign_state'){$campaign=M9Policy::integer($p['campaign']??'',1,PHP_INT_MAX);$binding='';if(M10::modern()){$current=$s->campaign($campaign);$binding=' AND p.revision='.(int)$current['revision'].' AND p.audience_id='.(int)$current['audience_id'];}$row=$s->row('SELECT r.id FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans').' p ON p.id=r.plan_id WHERE p.campaign_id=%d'.$binding.' ORDER BY r.id DESC LIMIT 1',$campaign);return ['run'=>$row?$bulk->status((int)$row['id']):null];}
        if($op==='m9_campaign_begin')return M91Campaign::begin($s,$source,$p);
        if($op==='m9_campaign_review')return M91Campaign::review($s,$source,M9Policy::integer($p['run']??'',1,PHP_INT_MAX));
        if($op==='m9_prepare')return $bulk->prepare($p);
        $id=M9Policy::integer($p['run']??'',1,PHP_INT_MAX);
        if($op==='m9_status')return $bulk->status($id);
        if($op==='m9_preview')return $bulk->preview($id,M9Policy::integer($p['after']??'0',0,PHP_INT_MAX));
        if($op==='m9_dialog')return $bulk->dialog($id);
        if($op==='m9_renew')return $bulk->renew($id);
        if($op==='m9_confirm')return $bulk->confirm($id,is_string($p['ticket']??null)?$p['ticket']:'',is_string($p['ack']??null)?$p['ack']:'');
        if($op==='m9_control'){$result=$bulk->control($id,$p['control']??'');M9Worker::ensure();return $result;}
        throw new ControlledTestException('Unknown M9 action.');
    }
    public static function finalExport():void
    {
        try{$p=wp_unslash($_POST);M6SendPolicy::authorize($p);if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!M11Environment::available())throw new \RuntimeException('Denied');$s=M6Worker::store();Schema::verify($s->database);$csv=implode('',iterator_to_array(M91Campaign::csv($s,M9Worker::source($s),M9Policy::integer($p['run']??'',1,PHP_INT_MAX)),false));}catch(\Throwable $e){wp_die('Final audience export unavailable. Revalidate the campaign and permissions.','',['response'=>400]);return;}
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="azula-sms-final-recipients.csv"');echo $csv;exit;
    }
    public static function export():void
    {
        try{$p=wp_unslash($_POST);M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!current_user_can('view_azula_sms_reports'))throw new \RuntimeException('Denied');$s=M6Worker::store();Schema::verify($s->database);$target=new M9Retargeting($s);$rows=RC2Audiences::modern()?(new RC2Audiences($s,M9Worker::source($s)))->reviewRows($p):$target->filtered($target->all($target->definition($p)),$p);}catch(\Throwable $e){wp_die('Retargeting export unavailable. Check scope, permissions and bounded review limits.','',['response'=>400]);return;}
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="azula-sms-retargeting.csv"');foreach(M9Retargeting::csv($rows) as $chunk)echo $chunk;exit;
    }
}
