<?php
namespace Azula\SmsStudio;

/** Includes RC7 execution-only cancellation without rewriting historical campaigns. */
final class CampaignLifecycle
{
    public static function cancelled(Database $db,array $campaign):bool
    {
        if(in_array($campaign['state']??'',['canceled','cancelled'],true))return true;
        if(!M9Schema::modern())return false;
        $r=$db->db->get_row($db->db->prepare('SELECT r.id FROM '.$db->table('bulk_runs').' r INNER JOIN '.$db->table('sendplans')." p ON p.id=r.plan_id WHERE p.campaign_id=%d AND r.mode<>'dry' AND r.state='cancelled' AND COALESCE(r.error_code,'')<>'superseded_unapproved' LIMIT 1",$campaign['id']),ARRAY_A);$db->check(true);
        return (bool)$r;
    }
}
