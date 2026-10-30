<?php
namespace Azula\SmsStudio;
/** Presentation orchestration only: all claims and HTTP remain in M9Bulk. */
final class M91Campaign
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.9.1','>=');}
    public static function begin(M6Store $s,AudienceSource $source,array $p):array
    {
        if(M92Policy::modern())return M92Readiness::begin($s,$source,$p);
        $policy=M9Policy::status();
        if(!$policy['enabled']||$policy['emergency'])throw new ControlledTestException('Live sending is disabled or paused. Open Settings & Health → Controlled Live Safety.');
        $unit=M8Costs::numeric($policy['budget_units']??null);
        if($unit===null||(float)$unit<=0)throw new ControlledTestException('Set the conservative provider-unit ceiling once in Settings & Health. Estimated units are not verified charges.');
        if(($p['timing']??'now')==='later'&&!$policy['automatic'])throw new ControlledTestException('Automatic Live scheduling is disabled. Complete the one-recipient pilot and explicitly enable scheduled Live in Settings & Health.');
        return (new M9Bulk($s,$source))->prepare(array_replace($p,['execution'=>'live','max_units'=>$unit,'budget_ack'=>'OPERATOR BUDGET']));
    }
    /** Bounded checkpoint, never releases or submits a message. */
    public static function review(M6Store $s,AudienceSource $source,int $run):array
    {
        $bulk=new M9Bulk($s,$source);$status=$bulk->status($run);
        if($status['state']==='preparing')$status=$bulk->prepareBatch($run);
        return $status['state']==='ready'?$bulk->dialog($run):['summary'=>$status,'ticket'=>null];
    }
    public static function csv(M6Store $s,AudienceSource $source,int $run):\Generator
    {
        $bulk=new M9Bulk($s,$source);$after=0;
        yield "\xEF\xBB\xBF";yield self::line(['Customer name','Original phone','Canonical phone','Outbound destination','Exact SMS','Tracking links','Encoding','SMS segments','Eligibility'],false);
        do{$page=$bulk->preview($run,$after);foreach($page['rows'] as $row){preg_match_all('~https://[^\s]+/s/[A-Za-z0-9_-]+~',$row['message'],$links);yield self::line([$row['name'],$row['original'],$row['canonical'],$row['outbound'],$row['message'],implode(' ',$links[0]),$row['metrics']['encoding'],$row['metrics']['segments'],$row['eligible']?'Eligible':'Withheld']);}$after=$page['after'];}while($page['more']);
    }
    public static function scheduler():array
    {
        $out=['pending_jobs'=>null,'failed_jobs'=>null,'next_action'=>null,'scope'=>'SMS-owned Action Scheduler jobs only; account cron is not inferred.'];
        if(!function_exists('as_get_scheduled_actions'))return $out;
        try{foreach(['pending','failed'] as $state){$ids=as_get_scheduled_actions(['hook'=>M9Worker::HOOK,'group'=>M9Worker::GROUP,'status'=>$state,'per_page'=>1001],'ids');$out[$state.'_jobs']=count($ids)>1000?'1000+':count($ids);}$next=as_get_scheduled_actions(['hook'=>M9Worker::HOOK,'group'=>M9Worker::GROUP,'status'=>'pending','per_page'=>1,'orderby'=>'date','order'=>'ASC']);if($next){$action=reset($next);$date=$action->get_schedule()->get_date();$out['next_action']=$date?$date->getTimestamp():null;}}catch(\Throwable $e){$out['error']='scheduler_query_unavailable';}return $out;
    }
    private static function line(array $values,bool $phones=true):string{$f=fopen('php://temp','r+');$safe=[];foreach($values as $i=>$v)$safe[]=AudienceCsv::cell((string)$v,($phones&&in_array($i,[1,2,3],true)));fputcsv($f,$safe,',','"','');rewind($f);$v=stream_get_contents($f);fclose($f);return $v;}
}
