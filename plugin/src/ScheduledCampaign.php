<?php
namespace Azula\SmsStudio;
/** Explicit frozen-campaign authorization. Never creates campaigns or calls a provider. */
final class ScheduledCampaign
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.9','>=');}
    public static function frozen(array $b):bool{return ($b['frozen_schedule_v1']??false)===true;}
    public static function identityHash(M6Store $s,array $member):string
    {
        if(!empty($member['identity_warning'])||empty($member['customer_id']))throw new ControlledTestException('Frozen customer identity is unverified.');
        $keys=[];foreach(['customer_id','user_id','email','first_name','last_name'] as $key)$keys[$key]=(string)($member[$key]??'');
        $phone=(new EgyptMobileNormalizer())->normalize((string)($member['phone']??''))['phone'];if(!$phone)throw new ControlledTestException('Frozen customer phone is invalid.');
        return $s->crypto->digest(['frozen_identity'=>$keys,'phone'=>$phone]);
    }
    public static function member(AudienceSource $source,int $id):?array
    {
        if(!$source instanceof FrozenIdentitySource)throw new ControlledTestException('Read-only frozen identity verification is unavailable.');
        try{return $source->identity($id);}catch(\Throwable $e){throw new ControlledTestException('Frozen source identity lookup failed. No eligibility was inferred.');}
    }
    public static function metadata(M6Store $s,AudienceSource $source,array $c):array
    {
        $seg=$source->segment((int)$c['segment_id']);$type='Normal customer segment';$negative=false;
        if((int)$seg['id']>=M9Source::OFFSET){$row=$s->row('SELECT * FROM '.$s->table('target_segments').' WHERE id=%d',(int)$seg['id']-M9Source::OFFSET);if(!$row||!in_array($row['mode'],['snapshot','dynamic'],true))throw new ControlledTestException('Saved retargeting audience is unavailable.');$type=$row['mode']==='dynamic'?'Dynamic retargeting':'Snapshot retargeting';$d=json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);$negative=!empty($d['not_purchased'])||!empty($d['abandoned']);}
        return ['frozen_schedule_v1'=>true,'campaign_type'=>$type,'segment_id'=>(int)$seg['id'],'segment_name'=>$seg['name'],'campaign_name'=>$c['name'],'negative_recheck'=>$negative,'destinations_hash'=>self::destinationsHash($s,(int)$c['id']),'frozen_identities'=>[]];
    }
    public static function destinations(M6Store $s,int $id):array
    {
        $out=[];foreach($s->rows('SELECT * FROM '.$s->table('links').' WHERE campaign_id=%d ORDER BY id LIMIT 21',$id) as $r){$v=$s->crypto->open($r['destination']);if(!M6Links::allowed($v['target']??''))throw new ControlledTestException('Approved destination URL is unavailable.');$out[]=['id'=>(int)$r['id'],'token'=>$r['public_token'],'target'=>$v['target'],'original'=>$v['original']];}if(count($out)>20)throw new ControlledTestException('Too many approved destination URLs.');return $out;
    }
    public static function destinationsHash(M6Store $s,int $id):string{return $s->crypto->digest(['destinations'=>self::destinations($s,$id)]);}
    public static function reason(M6Store $s,AudienceSource $source,array $item,array $p,array $b):?string
    {
        if($s->campaigns->m2->store()->suppressed($item['phone_hash']))return 'sms_suppressed';
        $m=$s->row('SELECT * FROM '.$s->table('members')." WHERE audience_id=%d AND phone_hash=%s AND disposition='prepared'",$p['audience_id'],$item['phone_hash']);if(!$m)return 'frozen_source_evidence_missing';
        $member=self::member($source,(int)$m['source_id']);if(!$member||!empty($member['identity_warning']))return 'identity_unverified';
        try{$hash=self::identityHash($s,$member);}catch(ControlledTestException $e){return 'identity_or_phone_invalid';}
        if(!isset($b['frozen_identities'][(string)$item['recipient_id']])||!hash_equals($b['frozen_identities'][(string)$item['recipient_id']],$hash))return 'identity_or_phone_changed';
        if($b['campaign_type']!=='Normal customer segment'){
            if(!$source instanceof M9Source)return 'retargeting_verification_unavailable';
            $current=$source->currentMember($source->segment((int)$b['segment_id']),(int)$m['source_id']);
            if(!$current||!empty($current['identity_warning']))return $source->scheduledExclusion($source->segment((int)$b['segment_id']),(int)$m['source_id']);
            if(!hash_equals($hash,self::identityHash($s,$current)))return 'retargeting_identity_changed';
        }
        return null;
    }
    /** Revoking old authorization is atomic with new package creation; caller owns campaign lock. */
    public static function retire(M6Store $s,array $run,array $plan):void
    {
        $s->update('bulk_runs',['state'=>'cancelled','next_check'=>0,'error_code'=>'superseded_unapproved','last_progress'=>time()],['id'=>$run['id']]);
        $s->update('sendplans',['state'=>'m9_cancelled','next_check'=>0,'updated_at'=>time()],['id'=>$plan['id']]);
        $s->query('UPDATE '.$s->table('send_items')." SET state='cancelled' WHERE plan_id=%d AND state='pending'",$plan['id']);
        $s->audit((int)$plan['campaign_id'],'schedule_authorization_replaced',['run'=>(int)$run['id'],'previous_approved'=>(bool)$plan['approved_at']]);
    }
}
