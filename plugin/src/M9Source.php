<?php
namespace Azula\SmsStudio;
/** SMS-owned saved audiences; the established Customer Segments interface remains read-only. */
final class M9Source implements AudienceSource, FrozenIdentitySource
{
    public const OFFSET=1000000000;
    private array $membership=[];
    public function __construct(private AudienceSource $base,private M6Store $s){}
    public static function wrap(AudienceSource $base,M6Store $s):AudienceSource{return M9Schema::modern()?new self($base,$s):$base;}
    public function catalog(int $after=0,int $limit=100):array
    {
        $rows=$after<self::OFFSET?$this->base->catalog($after,$limit):[];if(count($rows)===$limit)return $rows;$cursor=max(0,$after-self::OFFSET);foreach($this->s->rows('SELECT * FROM '.$this->s->table('target_segments').' WHERE id>%d AND mode IN (\'dynamic\',\'snapshot\') ORDER BY id LIMIT %d',$cursor,$limit-count($rows)) as $r){try{$count=count($this->members($r,true));}catch(\Throwable $unavailable){$count=null;}$rows[]=['id'=>self::OFFSET+(int)$r['id'],'name'=>$r['name'].' · SMS '.$r['mode'],'customer_count'=>$count];}return $rows;
    }
    private function saved(int $id,bool $history=false):array{$r=$this->s->row('SELECT * FROM '.$this->s->table('target_segments').' WHERE id=%d',$id-self::OFFSET);if(!$r)throw new ControlledTestException('SMS saved audience unavailable.');if(!hash_equals($r['fingerprint'],hash('sha256',$r['definition'])))throw new ControlledTestException('Saved SMS audience definition integrity failed.');if(!$history&&!in_array($r['mode'],['dynamic','snapshot'],true))throw new ControlledTestException('This SMS audience was removed from selection. Historical data is retained.');return $r;}
    public function segment(int $id):array
    {
        if($id<self::OFFSET)return $this->base->segment($id);$r=$this->saved($id,true);return ['archived'=>!in_array($r['mode'],['dynamic','snapshot'],true),'id'=>$id,'name'=>$r['name'],'rules'=>$r['definition'],'adapter_version'=>'sms-retarget-v1/cs-readonly','fingerprint'=>hash('sha256',$id.'|'.$r['fingerprint'].'|'.str_replace('removed_','',$r['mode']))];
    }
    private function members(array $r,bool $refresh=false,bool $verifySource=true):array
    {
        if(!hash_equals($r['fingerprint'],hash('sha256',$r['definition'])))throw new ControlledTestException('Saved SMS audience definition integrity failed.');if(!in_array($r['mode'],['dynamic','snapshot'],true))throw new ControlledTestException('Removed SMS audiences cannot supply new recipients.');if(RC2Audiences::modern()){$key=$r['id'].':'.($verifySource?'verified':'projection');if(!$refresh&&array_key_exists($key,$this->membership))return $this->membership[$key];return $this->membership[$key]=(new RC2Audiences($this->s,$this->base))->membership($r,$verifySource);}if(!$refresh&&array_key_exists((int)$r['id'],$this->membership))return $this->membership[(int)$r['id']];
        $projection=new M9Retargeting($this->s);$d=json_decode($r['definition'],true,512,JSON_THROW_ON_ERROR);$negative=!empty($d['not_purchased'])||!empty($d['abandoned']);$current=$r['mode']==='dynamic'||$negative?$projection->all($d):[];$map=[];foreach($current as $v)if($v['eligible']&&$v['source_id'])$map[$v['source_id']]=['source_id'=>$v['source_id'],'source_segment'=>$v['source_segment']];
        if($r['mode']==='dynamic')return $this->membership[(int)$r['id']]=$map;
        $fixed=[];foreach($this->s->rows('SELECT * FROM '.$this->s->table('target_members').' WHERE segment_id=%d ORDER BY source_id LIMIT 10001',$r['id']) as $row){$v=$this->s->crypto->open($row['payload']);if(!$negative||isset($map[$row['source_id']]))$fixed[$row['source_id']]=$v;}return $this->membership[(int)$r['id']]=$fixed;
    }
    public function identity(int $sourceId):?array
    {
        if(!$this->base instanceof FrozenIdentitySource)throw new ControlledTestException('Read-only identity revalidation is unavailable. No scheduled recipient may be submitted.');
        return $this->base->identity($sourceId);
    }
    public function ceiling(array $segment):int
    {
        if($segment['id']<self::OFFSET)return $this->base->ceiling($segment);$rows=$this->members($this->saved((int)$segment['id']),true);return $rows?max(array_keys($rows)):0;
    }
    public function scheduledExclusion(array $segment,int $sourceId):string
    {
        $review=(new RC2Audiences($this->s,$this->base))->evaluate($this->saved((int)$segment['id']),true,$sourceId);
        foreach($review['rows'] as $row)if((int)$row['source_id']===$sourceId)return $row['exclusion_code']??'retargeting_eligibility_unverified';
        return 'activity_unavailable';
    }
    public function currentMember(array $segment,int $sourceId):?array
    {
        if($segment['id']<self::OFFSET)throw new \LogicException('Use the original source scan for external segments.');$refs=M11Environment::modern()?(new RC2Audiences($this->s,$this->base))->membership($this->saved((int)$segment['id']),true,$sourceId):$this->members($this->saved((int)$segment['id']),false,!RC2Audiences::modern());$ref=$refs[$sourceId]??null;if(!$ref)return null;$base=$this->base->segment((int)$ref['source_segment']);$page=$this->base->scan($base,$sourceId-1,$sourceId);foreach($page['members'] as $member)if((int)$member['customer_id']===$sourceId){$member['sms_source_segment']=(int)$ref['source_segment'];return $member;}return null;
    }
    public function scan(array $segment,int $after,int $ceiling):array
    {
        if($segment['id']<self::OFFSET)return $this->base->scan($segment,$after,$ceiling);$current=$this->segment((int)$segment['id']);if(!hash_equals($segment['fingerprint'],$current['fingerprint']))throw new ControlledTestException('Saved SMS audience definition changed.');$refs=$this->members($this->saved((int)$segment['id']),$after===0);ksort($refs,SORT_NUMERIC);$ids=array_values(array_filter(array_keys($refs),fn($id)=>$id>$after&&$id<=$ceiling));$page=array_slice($ids,0,100);$out=[];$cursor=$after;
        foreach($page as $id){$ref=$refs[$id];$source=$this->base->segment((int)$ref['source_segment']);$scan=$this->base->scan($source,(int)$id-1,(int)$id);foreach($scan['members'] as $member)if((int)$member['customer_id']===(int)$id){$member['sms_source_segment']=(int)$ref['source_segment'];$out[]=$member;}$cursor=(int)$id;}
        // The source contract requires cursor to equal the last returned ID. Withheld source records
        // are represented without identity/phone, so every capture exclusion remains accounted for.
        $by=[];foreach($out as $v)$by[(int)$v['customer_id']]=$v;$out=[];foreach($page as $id)$out[]=$by[$id]??['customer_id'=>(int)$id,'phone'=>'','first_name'=>'','identity_warning'=>true];
        return ['members'=>$out,'next_cursor'=>$cursor,'complete'=>count($ids)<=100,'fingerprint'=>$segment['fingerprint']];
    }
}
