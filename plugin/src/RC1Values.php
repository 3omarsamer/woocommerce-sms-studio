<?php
namespace Azula\SmsStudio;

/** Read-only latest-observation values. Purchase revenue remains the order projection. */
final class RC1Values
{
    public function __construct(private M6Store $s){}
    public function decorate(array $report,string $filter):array
    {
        $s=$this->s;$from=(int)$report['range']['start_utc'];$to=(int)$report['range']['end_utc'];$j=$s->table('journey');$k=$s->table('clicks');$map=$s->table('recipient_links');$c=$s->table('campaigns');$meta=$s->table('campaign_meta');
        $rows=$s->rows("SELECT e.*,COALESCE(NULLIF(e.recipient_id,0),mp.recipient_id) tracked_recipient FROM $j e INNER JOIN $c c ON c.id=e.campaign_id LEFT JOIN $meta m ON m.campaign_id=c.id LEFT JOIN $k ck ON ck.id=e.click_id LEFT JOIN $map mp ON mp.id=ck.recipient_link_id AND mp.campaign_id=e.campaign_id WHERE $filter AND e.kind IN ('add_to_cart','checkout_start') AND e.occurred_at>=%d AND e.occurred_at<%d ORDER BY e.occurred_at,e.id LIMIT 50001",$from,$to);
        $latest=[];foreach($rows as $e){$id=(int)($e['tracked_recipient']??0);$key=$e['campaign_id'].':'.($id?:'unmapped:'.$e['click_id']).':'.$e['kind'];$latest[$key]=$e;}
        $blank=static fn()=>['known_minor'=>0,'missing_values'=>0,'value_records'=>0];$all=['add_to_cart'=>$blank(),'checkout_start'=>$blank()];$by=[];
        if(count($rows)>50000){foreach($all as &$a){$a['known_minor']=null;$a['missing_values']=1;}unset($a);}else foreach($latest as $e){$kind=$e['kind'];$id=(int)$e['campaign_id'];$a=$by[$id][$kind]??$blank();$valid=(int)($e['tracked_recipient']??0)>0&&$e['currency']==='EGP'&&$e['value_minor']!==null&&preg_match('/^[0-9]+$/D',(string)$e['value_minor']);
            if(!$valid){$a['missing_values']++;$all[$kind]['missing_values']++;}else{foreach([&$a,&$all[$kind]] as &$v){$n=(string)$e['value_minor'];if(strlen($n)>strlen((string)PHP_INT_MAX)||(strlen($n)===strlen((string)PHP_INT_MAX)&&strcmp($n,(string)PHP_INT_MAX)>0)||$v['known_minor']===null||$v['known_minor']>PHP_INT_MAX-(int)$n)$v['known_minor']=null;else $v['known_minor']+=(int)$n;$v['value_records']++;}unset($v);}$by[$id][$kind]=$a;}
        foreach($all as $kind=>$a){$a['value_minor']=$a['value_records']?$a['known_minor']:($a['missing_values']?null:0);$report['m8']['events'][$kind]=array_replace($report['m8']['events'][$kind],$a);}
        foreach($report['rows'] as &$row)foreach($all as $kind=>$unused){$a=$by[(int)$row['id']][$kind]??$blank();if(count($rows)>50000){$a['known_minor']=null;$a['missing_values']=1;}$a['value_minor']=$a['value_records']?$a['known_minor']:($a['missing_values']?null:0);$row['m8'][$kind]=array_replace($row['m8'][$kind],$a);}unset($row);
        $report['m8']['definitions']['cart']='Sum of latest known EGP added-item observation per campaign-recipient in this date range. Not full cart totals or revenue. Repeated observations are not summed. Missing records are disclosed separately.';
        $report['m8']['definitions']['checkout']='Sum of latest known EGP checkout observation per campaign-recipient in this date range. Not revenue; missing amounts are disclosed, never guessed or backfilled.';
        $items=$s->table('send_items');$attempts=$s->table('attempts');$conv=$s->table('conversions');
        $cohort=$s->rows("SELECT DISTINCT i.campaign_id,i.recipient_id FROM $items i INNER JOIN $attempts a ON a.id=i.attempt_id INNER JOIN $c c ON c.id=i.campaign_id LEFT JOIN $meta m ON m.campaign_id=c.id WHERE $filter AND a.state='accepted' AND i.state='accepted' AND a.claimed_at>=%d AND a.claimed_at<%d ORDER BY i.campaign_id,i.recipient_id LIMIT 10001",$from,$to);
        $clicked=$s->rows("SELECT DISTINCT mp.campaign_id,mp.recipient_id FROM $k ck INNER JOIN $map mp ON mp.id=ck.recipient_link_id AND mp.campaign_id=ck.campaign_id INNER JOIN $c c ON c.id=ck.campaign_id LEFT JOIN $meta m ON m.campaign_id=c.id WHERE $filter AND ck.observed_at>=%d AND ck.observed_at<%d LIMIT 10001",$from,$to);
        $bought=$s->rows("SELECT DISTINCT v.campaign_id,mp.recipient_id FROM $conv v INNER JOIN $k ck ON ck.id=v.click_id INNER JOIN $map mp ON mp.id=ck.recipient_link_id AND mp.campaign_id=v.campaign_id INNER JOIN $c c ON c.id=v.campaign_id LEFT JOIN $meta m ON m.campaign_id=c.id WHERE $filter AND v.purchase_eligible=1 AND v.order_created>=%d AND v.order_created<%d LIMIT 10001",$from,$to);
        $keys=static fn($rows)=>array_fill_keys(array_map(static fn($v)=>$v['campaign_id'].':'.$v['recipient_id'],$rows),true);$accepted=$keys($cohort);$n=count($accepted);$limited=count($cohort)>10000||count($clicked)>10000||count($bought)>10000;
        $report['rc1']=['accepted_unique'=>$limited?null:$n,'click_rate'=>$limited||!$n?null:round(count(array_intersect_key($accepted,$keys($clicked)))*100/$n,2),'purchase_rate'=>$limited||!$n?null:round(count(array_intersect_key($accepted,$keys($bought)))*100/$n,2),'delivery_rate'=>null,'notice'=>'Date-scoped accepted campaign-recipient cohort; observations and purchases must also lie in the selected dates. Not verified unique people. Delivery API contract unavailable.'];return $report;
    }
}
