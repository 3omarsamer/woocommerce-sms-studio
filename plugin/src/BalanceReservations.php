<?php
namespace Azula\SmsStudio;

/** Read-only accounting. Drafts are proposals; only final approval reserves funds. */
final class BalanceReservations
{
    public static function micro($value):int
    {
        $value=M8Costs::numeric($value);if($value===null)throw new ControlledTestException('Verified numeric provider-unit evidence is unavailable.');
        $p=explode('.',$value);return (int)$p[0]*1000000+(int)str_pad($p[1]??'',6,'0');
    }
    public static function needed(M6Store $s,array $health,int $now,int $candidatePlan=0):int
    {
        if(empty($health['balance_verified'])||($health['connection_status']??'')!=='verified'||$now<(int)$health['last_balance_check']||$now-(int)$health['last_balance_check']>900)throw new ControlledTestException('Refresh a verified Balance within fifteen minutes.');
        $needed=0;$add=static function(int $amount)use(&$needed){if($amount<0||$needed>PHP_INT_MAX-$amount)throw new ControlledTestException('Outstanding campaign budgets exceed safe accounting bounds.');$needed+=$amount;};
        $plans=$s->rows('SELECT r.payload,r.binding_hash,p.id plan FROM '.$s->table('bulk_runs').' r INNER JOIN '.$s->table('sendplans')." p ON p.id=r.plan_id WHERE r.mode<>'dry' AND p.expires_at>=%d AND ((r.state IN ('authorized','paused') AND p.state IN ('m9_authorized','m9_paused') AND p.released_at>0 AND p.approved_at>0) OR (p.id=%d AND r.state IN ('ready','authorized','paused'))) ORDER BY r.id LIMIT 1001",$now,$candidatePlan);
        if(count($plans)>1000)throw new ControlledTestException('Outstanding campaign reservation limit reached. Review pending campaigns.');
        foreach($plans as $plan){$b=$s->crypto->open($plan['payload']);if(!hash_equals($plan['binding_hash'],$s->crypto->digest($b)))throw new ControlledTestException('Execution budget integrity check failed.');if((int)$b['expires']<$now)continue;
            $n=(int)$s->row('SELECT COUNT(*) n FROM '.$s->table('send_items')." WHERE plan_id=%d AND state='pending'",$plan['plan'])['n'];$unit=self::micro($b['max_units']);if($n<0||($n&&$unit>intdiv(PHP_INT_MAX,$n)))throw new ControlledTestException('Execution budget exceeds safe accounting bounds.');$add($unit*$n);
        }
        // An old/expired/cancelled plan or fresh Balance cannot erase an uncertain HTTP claim.
        $attempts=$s->rows('SELECT state,cost FROM '.$s->table('attempts')." WHERE state IN ('unknown','submitting','claimed') OR (state IN ('accepted','rejected') AND (claimed_at>=%d OR finished_at>=%d)) ORDER BY id LIMIT 10001",$health['last_balance_check'],$health['last_balance_check']);
        if(count($attempts)>10000)throw new ControlledTestException('Outstanding provider evidence exceeds safe review bounds.');
        foreach($attempts as $a){if(in_array($a['state'],['unknown','submitting','claimed'],true))throw new ControlledTestException('An unresolved provider outcome requires reconciliation before further bulk activation.');$cost=M8Costs::numeric($a['cost']);if($cost!==null)$add(self::micro($cost));elseif($a['state']==='accepted')throw new ControlledTestException('A recent accepted SMS has no confirmed Cost. Check Balance before approving another campaign.');}
        return $needed;
    }
}
