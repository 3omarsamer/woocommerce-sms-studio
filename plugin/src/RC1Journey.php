<?php
namespace Azula\SmsStudio;

/** A recipient association does not join sessions or establish a person's identity. */
final class RC1Journey
{
    public static function summarize(array $events,array $orders,?int $now=null):array
    {
        $now??=time();usort($events,static fn($a,$b)=>[(int)$a['occurred_at'],(int)$a['id']]<=>[(int)$b['occurred_at'],(int)$b['id']]);
        $sessions=[];$last=null;foreach($events as $e){$key=!empty($e['session_hash'])?$e['session_hash']:'unverified:'.$e['click_id'];$sessions[$key][]=$e;$last=$e;}
        $kinds=[];$uncertain=false;$purchased=false;$purchase_minor=null;$purchase_at=0;$stage='click';$classification=['state'=>'none','reason'=>'No shopping activity observed.'];if(!$last){if($orders){$uncertain=true;foreach($orders as $o)if((int)$o['purchase_eligible']===1||$o['reason']==='fully_refunded'){$stage='purchase';$purchased=true;}$classification=['state'=>'uncertain','reason'=>'Purchase evidence exists without retained shopping-session activity. Recovery withheld.'];}return compact('kinds','uncertain','purchased','stage','classification','purchase_minor');}
        $key=!empty($last['session_hash'])?$last['session_hash']:'unverified:'.$last['click_id'];$shopping=[];
        foreach($sessions[$key] as $e){$kinds[$e['kind']]=$e;if(in_array($e['kind'],['add_to_cart','checkout_start'],true))$shopping[]=$e;}
        // Visits do not regress a known shopping stage. A later cart starts fresh activity.
        $latest=$shopping?end($shopping):$last;$stage=['checkout_start'=>'checkout','add_to_cart'=>'cart','product_view'=>'product'][$latest['kind']]??'click';
        $relevant=[];foreach($orders as $o){if((int)$o['order_created']<(int)$latest['occurred_at'])continue;if(!empty($latest['session_hash'])&&!empty($o['stage_session_hash'])&&hash_equals($latest['session_hash'],$o['stage_session_hash'])){$relevant[]=$o;if((int)$o['purchase_eligible']===1||$o['reason']==='fully_refunded'){
            $sameCart=$latest['kind']!=='checkout_start'||(!empty($latest['checkout_fingerprint'])&&!empty($o['checkout_fingerprint'])&&hash_equals($latest['checkout_fingerprint'],$o['checkout_fingerprint']));
            if($sameCart){$purchased=true;$stage='purchase';if((int)$o['order_created']>=$purchase_at){$purchase_at=(int)$o['order_created'];$purchase_minor=$o['currency']??'EGP';$purchase_minor=$purchase_minor==='EGP'?($o['revenue_minor']??null):null;}}else $uncertain=true;
        }}else $uncertain=true;}
        // Different browsers/sessions cannot prove progression. Withhold recovery if another unresolved journey exists.
        foreach($sessions as $other=>$group){if($other===$key)continue;foreach($group as $e)if(in_array($e['kind'],['add_to_cart','checkout_start'],true)){$closed=false;foreach($orders as $o)if(!empty($e['session_hash'])&&$e['session_hash']===($o['stage_session_hash']??null)&&(int)$o['order_created']>=(int)$e['occurred_at']&&((int)$o['purchase_eligible']===1||$o['reason']==='fully_refunded'))$closed=true;if(!$closed)$uncertain=true;}}
        if($purchased)$classification=['state'=>'completed','reason'=>'Confirmed attributed purchase in this session; completed journey is excluded from recovery.'];
        elseif($stage==='checkout')$classification=M9Checkout::classify($latest,$relevant,$now);
        elseif($stage==='cart'){
            $reliable=!empty($latest['session_hash'])&&$latest['value_minor']!==null&&$latest['currency']==='EGP'&&M6Tracking::enabled()&&M6AttributionContracts::available()&&M6AttributionContracts::exclusions()!==null&&(int)$latest['occurred_at']>=(int)$latest['clicked_at']&&(int)$latest['occurred_at']<=(int)$latest['clicked_at']+M6Tracking::WINDOW;
            $classification=!$reliable?['state'=>'uncertain','reason'=>'Insufficient reliable session, value or collection evidence.']:($now<(M11Environment::modern()?(int)$latest['occurred_at']+86400:max((int)$latest['occurred_at']+86400,(int)$latest['clicked_at']+M6Tracking::WINDOW+86400))?['state'=>'observing','reason'=>M11Environment::modern()?'Waiting for 24 hours without newer qualifying shopping activity.':'Still within inactivity or attribution observation window.']:['state'=>'potential','reason'=>'Potential abandoned cart; no reliably associated later checkout or purchase.']);
            unset($kinds['checkout_start']);
        }
        if(!empty($latest['newer_shopping_outside']))$uncertain=true;
        if($uncertain)$classification=['state'=>'uncertain','reason'=>'Separate, unverified or newer out-of-range shopping evidence cannot be merged. Recovery withheld.'];
        return compact('kinds','uncertain','purchased','stage','classification','purchase_minor');
    }
}
