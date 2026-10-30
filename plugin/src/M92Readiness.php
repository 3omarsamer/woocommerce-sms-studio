<?php
namespace Azula\SmsStudio;
final class M92Readiness
{
    public static function tracking(string $message):array
    {
        preg_match_all('~https://[^\s<>]+~u',$message,$matches);$links=[];foreach($matches[0] as $url)if(M7Links::token($url)!==null)$links[]=$url;
        return ['branded_links'=>array_values(array_unique($links)),'collection_enabled'=>M6Tracking::enabled(),'consent_required'=>true,'notice'=>'Branded redirects work independently of optional click collection. Collection also requires visitor statistics consent.'];
    }
    private static function micro($v):int
    {
        $v=M8Costs::numeric($v);if($v===null)throw new ControlledTestException('Verified numeric provider-unit evidence is unavailable.');$p=explode('.',$v);return (int)$p[0]*1000000+(int)str_pad($p[1]??'',6,'0');
    }
    /** Reserve remaining verified balance, not guessed billing or a price estimate. */
    public static function ceiling(M6Store $s,array $health,int $count):string
    {
        if($count<1||$count>10000)throw new ControlledTestException('A completed, nonempty, reconciled audience is required.');$free=self::micro($health['balance']);
        $free-=BalanceReservations::needed($s,$health,time());
        if($free<$count)throw new ControlledTestException('No unreserved verified provider balance remains. Check Balance and pending campaigns.');$unit=min(1000000000000,intdiv($free,$count));return intdiv($unit,1000000).'.'.str_pad((string)($unit%1000000),6,'0',STR_PAD_LEFT);
    }
    public static function begin(M6Store $s,AudienceSource $source,array $p):array
    {
        M6SendPolicy::authorize($p);$policy=M9Policy::status();if(!$policy['enabled'])throw new ControlledTestException('Controlled Live is OFF. Enable it explicitly in Settings & Health.');if($policy['emergency'])throw new ControlledTestException('Emergency pause is ON. No SMS can be authorized.');
        $settings=new SmsMisrSettings($s->crypto,$s->database->db);$h=$settings->status();if(!$h['configured'])throw new ControlledTestException('SMS Misr credentials or Sender ID token are missing. Complete the provider connection first.');if($h['environment']!==1)throw new ControlledTestException('Provider settings are in Test (2). The normal real-SMS workflow requires explicitly selecting Live (1).');
        $h=AuthorizationBalance::verified($settings);if(!$h['balance_verified']||$h['connection_status']!=='verified')throw new ControlledTestException('The SMS Misr Balance connection is not verified. Use Check Balance; it never sends SMS.');if(time()-(int)$h['last_balance_check']>900)throw new ControlledTestException('The verified Balance is older than fifteen minutes. Use Check Balance before approving.');if(M8Costs::numeric($h['balance'])===null||self::micro($h['balance'])<1)throw new ControlledTestException('SMS Misr returned no positive verified balance. No SMS was authorized.');
        $sender=(new SenderIdentity($s))->status();if(!$sender['verified']||$sender['label']!=='AzulaSports')throw new ControlledTestException('Verify approved sender AzulaSports. '.$sender['approval_explanation']);
        if(($p['timing']??'now')==='later'&&!$policy['automatic'])throw new ControlledTestException('Scheduled Live is OFF. Confirm actual receipt of the identified one-recipient pilot and enable scheduled Live in Settings & Health.');
        $runner=M92Runner::status();if(!$runner['verified_recently'])throw new ControlledTestException($runner['reason']);
        $campaign=M9Policy::integer($p['campaign']??'',1,PHP_INT_MAX);$c=$s->campaign($campaign);$count=(int)$s->row('SELECT COUNT(*) n FROM '.$s->table('recipients').' WHERE audience_id=%d',(int)$c['audience_id'])['n'];if($count>(int)$policy['limit'])throw new ControlledTestException('This campaign exceeds the explicitly configured recipient limit of '.$policy['limit'].'.');
        if(ScheduledCampaign::modern()){
            $requested=$p['schedule_max_units']??null;$unit=M8Costs::numeric($requested===null||$requested===''?$policy['budget_units']:$requested);$limit=M8Costs::numeric($policy['budget_units']);
            if($unit===null||BalanceReservations::micro($unit)<1||BalanceReservations::micro($unit)>1000000000000)throw new ControlledTestException('Enter a positive maximum provider units per recipient for this campaign. No price is inferred from the account balance.');
            if($limit!==null&&BalanceReservations::micro($unit)>BalanceReservations::micro($limit))throw new ControlledTestException('The campaign spending ceiling exceeds the owner-configured per-recipient limit.');
            return (new M9Bulk($s,$source))->prepare(array_replace($p,['approve_once'=>(($p['timing']??'now')==='later'?'1':'0'),'execution'=>'live','max_units'=>$unit,'budget_ack'=>'OPERATOR BUDGET','ceiling_basis'=>'Explicit operator safety ceiling']));
        }
        $unit=M8Costs::numeric($policy['budget_units']);if(M10::modern()&&$unit===null){$existing=$s->latestPlan($campaign);if($existing&&in_array($existing['state'],['m9_preparing','m9_ready'],true)){$saved=$s->row('SELECT payload FROM '.$s->table('bulk_runs').' WHERE plan_id=%d',$existing['id']);if($saved)$unit=M8Costs::numeric($s->crypto->open($saved['payload'])['max_units']??null);}}if($unit===null)$unit=self::ceiling($s,$h,$count);
        return (new M9Bulk($s,$source))->prepare(array_replace($p,['execution'=>'live','max_units'=>$unit,'budget_ack'=>'OPERATOR BUDGET','ceiling_basis'=>M8Costs::numeric($policy['budget_units'])===null?'Unreserved verified balance divided by recipients':'Owner-configured safety ceiling']));
    }
    public static function values(M6Store $s):array
    {
        return $s->rows('SELECT kind,value_basis,currency,COUNT(*) event_count,SUM(CASE WHEN value_minor IS NULL THEN 1 ELSE 0 END) missing_value FROM '.$s->table('journey')." WHERE kind IN ('add_to_cart','checkout_start') GROUP BY kind,value_basis,currency ORDER BY kind,value_basis,currency LIMIT 101");
    }
}
