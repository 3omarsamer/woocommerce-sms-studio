<?php
namespace Azula\SmsStudio;

/** Small parameterized repository shared by M6 services; never writes outside SMS tables. */
final class M6Store
{
    public M3Repository $campaigns;
    public function __construct(public Database $database,public Crypto $crypto){$this->campaigns=new M3Repository($database,$crypto);}
    public function table(string $name):string{return $this->database->table($name);}
    public function query(string $sql,...$args){$db=$this->database->db;return $this->database->check($db->query($args?$db->prepare($sql,...$args):$sql));}
    public function rows(string $sql,...$args):array{$db=$this->database->db;return $this->database->check($db->get_results($args?$db->prepare($sql,...$args):$sql,ARRAY_A));}
    public function row(string $sql,...$args):?array{$db=$this->database->db;$r=$this->database->check($db->get_row($args?$db->prepare($sql,...$args):$sql,ARRAY_A));return $r?:null;}
    public function insert(string $name,array $row):int{$db=$this->database->db;$this->database->check($db->insert($this->table($name),$row));return (int)$db->insert_id;}
    public function update(string $name,array $values,array $where):void{$this->database->check($this->database->db->update($this->table($name),$values,$where));}
    public function audit(int $campaign,string $event,array $safe=[]):void{$this->campaigns->m2->store()->audit($campaign,$event,$safe);}
    public function meta(int $campaign,bool $create=false):?array
    {
        $row=$this->row('SELECT * FROM '.$this->table('campaign_meta').' WHERE campaign_id=%d',$campaign);
        if(!$row&&$create){$this->insert('campaign_meta',['campaign_id'=>$campaign,'public_id'=>bin2hex(random_bytes(16)),'purpose'=>'campaign','trashed_at'=>0,'updated_at'=>time()]);$row=$this->meta($campaign);}
        return $row;
    }
    public function campaign(int $id,bool $lock=false):array
    {
        $c=$this->row('SELECT * FROM '.$this->table('campaigns').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id);
        if(!$c)throw new ControlledTestException('Campaign not found.');
        if(!empty($this->meta($id)['trashed_at']))throw new ControlledTestException('Restore this campaign before editing. Restoration does not approve sending.');
        return $c;
    }
    public function latestPlan(int $campaign,bool $lock=false):?array{return $this->row('SELECT * FROM '.$this->table('sendplans').' WHERE campaign_id=%d ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''),$campaign);}
    public function invalidate(int $campaign,string $reason):void
    {
        $p=$this->latestPlan($campaign,true);if(!$p||in_array($p['state'],['submitted','unknown','canceled','invalidated'],true))return;
        $this->update('sendplans',['state'=>'invalidated','next_check'=>0,'updated_at'=>time()],['id'=>$p['id']]);
        $this->query('UPDATE '.$this->table('send_items')." SET state='canceled' WHERE plan_id=%d AND state='pending'",$p['id']);
        $this->audit($campaign,'sending_approval_invalidated',['result'=>$reason]);
    }
}
