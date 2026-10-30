<?php
namespace Azula\SmsStudio;

/** Read-only readiness preview. A held queue never becomes a submission source in M4. */
final class HeldQueueProviderBridge
{
    public function __construct(private Database $database,private Crypto $crypto){}
    public function preview(int $campaign,AudienceSource $source):array
    {
        $r=new M3Repository($this->database,$this->crypto);$c=$r->m2->store()->campaign($campaign);$s=$r->get($campaign);
        if(!$s||!in_array($s['state'],['queue_preparing','scheduled_approved','due_verifying','ready_disabled'],true)||!$s['approval_hash']||$c['transmission_intent'])throw new \DomainException('Approved held queue required.');
        $a=$r->m2->store()->audience((int)$s['final_audience_id']);if(!$r->valid($c,$a,$s,time(),$source))throw new \DomainException('Held queue binding expired or changed.');
        $db=$this->database->db;$rows=$this->database->check($db->get_results($db->prepare('SELECT * FROM '.$this->database->table('queue').' WHERE campaign_id=%d AND approval_hash=%s AND id>0 ORDER BY id ASC LIMIT 100',$campaign,$s['approval_hash']),ARRAY_A));
        $eligible=0;$suppressed=0;$hashes=[];
        foreach($rows as $row){if($row['state']!=='held_disabled')continue;
            if(isset($hashes[$row['phone_hash']]))throw new \DomainException('Duplicate held destination.');$hashes[$row['phone_hash']]=true;
            if($r->m2->store()->suppressed($row['phone_hash'])){$suppressed++;continue;}
            $recipient=$db->get_row($db->prepare('SELECT * FROM '.$this->database->table('recipients').' WHERE id=%d',$row['recipient_id']),ARRAY_A);$this->database->check(true);
            if(!$recipient||(int)$recipient['campaign_id']!==$campaign||(int)$recipient['audience_id']!==(int)$a['id']||!hash_equals($row['phone_hash'],$recipient['phone_hash']))throw new \DomainException('Held recipient reference changed.');
            $p=$this->crypto->open($recipient['payload']);if(!hash_equals($recipient['payload_hash'],$this->crypto->digest($p))||(new EgyptMobileNormalizer())->normalize($p['phone']??'')['phone']!==($p['phone']??'')||!hash_equals($row['phone_hash'],$this->crypto->correlate($p['phone'])))throw new \DomainException('Held destination evidence changed.');$eligible++;
        }
        return ['campaign'=>$campaign,'dry_run'=>true,'inspected'=>count($rows),'eligible_in_preview'=>$eligible,'newly_suppressed_in_preview'=>$suppressed,'sending_enabled'=>false,'submitted'=>0,'delivered'=>0,'limit'=>100,'note'=>'Read-only bounded preview, not a sending authorization or whole-campaign total.'];
    }
    public function submit(int $campaign):void { DispatchGate::assertAvailable(); }
}
