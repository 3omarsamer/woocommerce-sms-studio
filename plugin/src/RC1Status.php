<?php
namespace Azula\SmsStudio;

/** Read-only presentation; never grants execution authority. */
final class RC1Status
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.1','>=');}
    public static function label(array $run,array $binding,array $counts,?int $now=null):string
    {
        if(!empty($counts['unknown'])||!empty($counts['submitting']))return 'Uncertain';
        return match($run['state']){
            'preparing'=>'Preparing', 'ready'=>'Ready', 'paused'=>'Paused', 'cancelled'=>'Cancelled', 'blocked'=>(M10::modern()&& !empty($counts['accepted'])?'Stopped after provider acceptance':(M10::modern()?'Blocked before further submission':'Failed')),
            'authorized'=>((int)$binding['start']>($now??time())?(ScheduledCampaign::frozen($binding)?'Scheduled / Approved':'Scheduled'):(($run['mode']??'')==='dry'?'Simulating':'Sending')),
            'complete'=>!empty($counts['rejected'])?(!empty($counts['accepted'])?'Completed with failures':'Failed'):(!empty($counts['accepted'])?'Submitted':'Completed'),
            default=>'Draft'
        };
    }
    public static function campaign(M6Store $s,int $campaign):?array
    {
        $r=$s->row('SELECT r.* FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans').' p ON p.id=r.plan_id WHERE p.campaign_id=%d ORDER BY r.id DESC LIMIT 1',$campaign);if(!$r)return null;
        $b=$s->crypto->open($r['payload']);$counts=[];foreach($s->rows('SELECT state,COUNT(*) n FROM '.$s->table('send_items').' WHERE plan_id=%d GROUP BY state',$r['plan_id']) as $v)$counts[$v['state']]=(int)$v['n'];
        $label=self::label($r,$b,$counts);if($r['mode']==='dry')$label='Dry run · '.$label;elseif($r['mode']==='test')$label='Provider Test · '.$label;
        return ['can_reschedule'=>(ScheduledCampaign::frozen($b)||AuthorizationBalance::modern())&&in_array($r['state'],['ready','authorized','paused'],true)&&!$s->row('SELECT id FROM '.$s->table('attempts').' WHERE campaign_id=%d LIMIT 1',$campaign),'label'=>$label,'mode'=>$r['mode'],'state'=>$r['state'],'start'=>(int)$b['start'],'scheduled'=>$r['state']==='authorized'&&(int)$b['start']>time(),'notice'=>'Submitted means provider accepted; handset delivery is unverified. Completed means processing finished.'];
    }
}
