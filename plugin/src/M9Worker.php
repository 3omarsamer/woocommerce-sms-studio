<?php
namespace Azula\SmsStudio;
final class M9Worker
{
    public const HOOK='azula_sms_bulk_tick';
    public const GROUP='azula-sms-bulk';
    public static function register():void{add_action(self::HOOK,[self::class,'run'],10,1);add_action('action_scheduler_init',[self::class,'ensure'],35);add_action('azula_sms_prepare_watchdog',[self::class,'ensure'],35);}
    public static function source(M6Store $s):AudienceSource{return M9Source::wrap(new CustomerSegmentsAdapterV1($s->database->db),$s);}
    public static function ensure():void
    {
        if(!M9Schema::modern()||!M11Environment::available()||!Schema::ready()||!M2Scheduler::available())return;
        try{$s=M6Worker::store();foreach($s->rows('SELECT * FROM '.$s->table('bulk_runs')." WHERE state IN ('preparing','authorized') AND next_check>0 ORDER BY next_check LIMIT 20") as $r){$args=[(int)$r['id']];if(!as_has_scheduled_action(self::HOOK,$args,self::GROUP))as_schedule_single_action(max(time(),(int)$r['next_check']),self::HOOK,$args,self::GROUP,false);}}catch(\Throwable $e){}
    }
    public static function run(int $id):void
    {
        if(!M9Schema::modern()||!M11Environment::available()||!Schema::ready())return;
        try{$s=M6Worker::store();if(M11Environment::modern())update_option(M11Environment::option('azula_sms_scheduled_worker_seen'),['at'=>time(),'trigger'=>TargetedDiagnostics::trigger()],false);$source=self::source($s);self::execute($s,$source,$id,TargetedDiagnostics::modern()?TargetedDiagnostics::trigger():'direct_service');}catch(\Throwable $e){
            if(ScheduledCampaign::modern()&&isset($s)&&!isset($source))try{
                $row=$s->row('SELECT p.campaign_id FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans').' p ON p.id=r.plan_id WHERE r.id=%d',$id);
                if($row)$s->campaigns->m2->lock(-(int)$row['campaign_id'],fn()=>$s->database->transaction(function()use($s,$id,$row){
                    $r=$s->row('SELECT * FROM '.$s->table('bulk_runs').' WHERE id=%d',$id);
                    if(!$r||!in_array($r['state'],['preparing','authorized'],true)||(int)$r['next_check']>time())return;
                    $s->update('bulk_runs',['state'=>'blocked','next_check'=>0,'error_code'=>'worker_source_initialization_unavailable','last_progress'=>time()],['id'=>$id]);
                    $s->audit((int)$row['campaign_id'],'bulk_worker_blocked',['run'=>$id,'reason'=>'worker_source_initialization_unavailable']);
                }));
            }catch(\Throwable $ignored){} // Unavailable storage cannot safely be repaired from a worker.
        }
        // Successor is allowed while this action is still running; database ownership is authoritative.
        try{$r=$s->row('SELECT * FROM '.$s->table('bulk_runs').' WHERE id=%d',$id);if($r&&in_array($r['state'],['preparing','authorized'],true)&&$r['next_check']>0)as_schedule_single_action(max(time()+1,(int)$r['next_check']),self::HOOK,[$id],self::GROUP,false);}catch(\Throwable $e){}
    }
    public static function execute(M6Store $s,AudienceSource $source,int $id,string $trigger='direct_service'):void
    {
        $service=new M9Bulk($s,$source);try{if(TargetedDiagnostics::modern()){$observed=$s->row('SELECT p.campaign_id FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans').' p ON p.id=r.plan_id WHERE r.id=%d',$id);if($observed)$s->audit((int)$observed['campaign_id'],'bulk_worker_observed',['run'=>$id,'trigger'=>in_array($trigger,['wp_cron','wp_cli','ajax_request','admin_request','hook_unknown_trigger'],true)?$trigger:'direct_service']);}$service->recoverUnknown($id);$service->tick($id);update_option(M11Environment::option('azula_sms_m9_worker_seen'),time(),false);}catch(\Throwable $e){
            $r=$s->row('SELECT * FROM '.$s->table('bulk_runs').' WHERE id=%d',$id);if(!$r||(TargetedDiagnostics::modern()&&$r['state']==='complete'))return;$p=$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$r['plan_id']);$unknown=$s->row('SELECT id FROM '.$s->table('attempts')." WHERE plan_id=%d AND state='unknown' LIMIT 1",$p['id']);$tries=(int)$p['retry_count']+1;$reason=TargetedDiagnostics::modern()?TargetedDiagnostics::reason($e):'validation_or_storage_blocked';
            $retry=!$unknown&&!($e instanceof ControlledTestException)&&in_array($r['state'],['preparing','authorized'],true)&&$tries<=3&&time()+[1=>15,2=>45,3=>120][$tries]<(int)$p['expires_at'];
            $s->update('sendplans',['retry_count'=>$tries,'updated_at'=>time()],['id'=>$p['id']]);$s->update('bulk_runs',['state'=>$retry?$r['state']:'blocked','next_check'=>$retry?time()+[1=>15,2=>45,3=>120][$tries]:0,'error_code'=>$unknown?'unknown_outcome_no_retry':($retry?'preclaim_retry_pending':$reason),'last_progress'=>time()],['id'=>$id]);$s->audit((int)$p['campaign_id'],$retry?'bulk_preclaim_retry':'bulk_worker_blocked',['run'=>$id,'retry'=>$tries]+(TargetedDiagnostics::modern()?['reason'=>$unknown?'unknown_outcome_no_retry':$reason,'previous_state'=>$r['state']]:[]));
        }
    }
}
