<?php
namespace Azula\SmsStudio;

final class M5Activity
{
    public function __construct(private Database $database,private Crypto $crypto){}
    public function rows(int $campaign,int $after=0):array
    {
        $db=$this->database->db;$t=$this->database->table('audit');
        $rows=$this->database->check($db->get_results($db->prepare("SELECT * FROM $t WHERE campaign_id=%d AND id>%d ORDER BY id ASC LIMIT 26",$campaign,$after),ARRAY_A));$more=count($rows)>25;$rows=array_slice($rows,0,25);$events=[];
        foreach($rows as $r){$p=json_decode($r['evidence_json'],true);$safe=[];
            foreach(['revision','audience_id','generation','version','plan_id','source_campaign','recipient_count','environment','attempt_id','http_status','start_utc','run','retry','accepted','rejected','skipped','dry_ready'] as $key)if(isset($p[$key])&&is_numeric($p[$key]))$safe[$key]=(int)$p[$key];
            foreach(['provider_code','smsid','cost','result','retry_class','code','reason','previous_state','trigger'] as $key)if(isset($p[$key])&&is_scalar($p[$key])&&preg_match('/^[A-Za-z0-9._:-]{1,190}$/D',(string)$p[$key]))$safe[$key]=(string)$p[$key];
            $events[]=['id'=>(int)$r['id'],'at'=>(int)$r['created_at'],'actor'=>(int)$r['actor_id'],'event'=>preg_match('/^[a-z0-9_]{1,48}$/D',$r['event_code'])?$r['event_code']:'recorded_event','details'=>$safe];
        }
        $attempts=$this->database->check($db->get_results($db->prepare('SELECT * FROM '.$this->database->table('attempts').' WHERE campaign_id=%d ORDER BY id DESC LIMIT 25',$campaign),ARRAY_A));$history=[];
        foreach($attempts as $a)$history[]=['id'=>(int)$a['id'],'state'=>$a['state'],'claimed'=>(int)$a['claimed_at'],'finished'=>(int)$a['finished_at'],'http_status'=>(int)$a['http_status'],'code'=>$a['provider_code'],'smsid'=>$a['smsid'],'cost'=>$a['cost'],'retry_class'=>$a['retry_class'],'environment'=>(int)($a['environment']??2),'recipients'=>(int)($a['recipient_count']??1),'plan_id'=>(int)($a['plan_id']??0),'delivered'=>false];
        $approvals=$this->database->check($db->get_results($db->prepare('SELECT * FROM '.$this->database->table('approvals').' WHERE campaign_id=%d ORDER BY id DESC LIMIT 25',$campaign),ARRAY_A));$approvalHistory=[];
        foreach($approvals as $a)$approvalHistory[]=['id'=>(int)$a['id'],'audience_id'=>(int)$a['audience_id'],'actor'=>(int)$a['actor_id'],'at'=>(int)$a['approved_at'],'expires'=>(int)$a['expires_at'],'scope'=>(int)$a['operational']===3?'Exact frozen campaign execution authorized':((int)$a['operational']===2?'Exact normal campaign package (separate confirmation required)':((int)$a['operational']===1?'One controlled Test request only':'Held queue preparation only'))];
        return ['events'=>$events,'more'=>$more,'next'=>$rows?(int)end($rows)['id']:$after,'attempts'=>$history,'approvals'=>$approvalHistory,'delivery_reporting'=>'Unavailable: no documented delivery API. Acceptance is not delivery.'];
    }
}
