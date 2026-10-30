<?php
namespace Azula\SmsStudio;

final class M6AnalyticsCsv
{
    public static function chunks(array $report):\Generator
    {
        if(M8Tokens::modern()){yield from M8AnalyticsCsv::chunks($report);return;}
        yield "\xEF\xBB\xBF".AudienceCsv::line(['Campaign','Segment','First accepted Egypt time','Accepted request destinations','Rejected request destinations','Unknown outcome destinations','Unique observed browser clicks','Raw link requests (unverified human)','Eligible purchases','EGP order gross after refunds','Provider Cost units (not EGP)','Missing Cost records','Purchases / accepted destinations (%)','ROI / ROAS']);
        $finance=($report['coverage']['finance_contract']??false)&&($report['coverage']['attribution_contract']??false)&&(int)($report['coverage']['finance_pending']??0)===0;
        foreach($report['rows'] as $r){$minor=(int)$r['revenue_minor'];$money=intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);$cells=[$r['name'],$r['segment'],$r['sending_egypt']??'Not verified',(string)$r['accepted'],(string)$r['rejected'],(string)$r['unknown_count'],(string)$r['unique_clicks'],(string)$r['link_requests'],$finance?(string)$r['purchases']:'Unavailable',$finance?$money:'Unavailable',$r['provider_cost']['reported']??'Unavailable',(string)($r['provider_cost']['missing']??'Unavailable'),!$finance||$r['conversion_rate']===null?'Unavailable':number_format((float)$r['conversion_rate'],2,'.',''),'Unavailable — monetary cost not verified'];yield AudienceCsv::line(array_map(fn($v)=>AudienceCsv::cell((string)$v),$cells));}
    }
    public static function download():void
    {
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try{if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \RuntimeException('POST required');$p=wp_unslash($_POST);M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(!current_user_can('view_azula_sms_reports')||!Schema::ready())throw new \RuntimeException('Not available');$s=M6Worker::store();try{$source=new CustomerSegmentsAdapterV1($wpdb);}catch(\Throwable $e){$source=null;}$report=(new M6Analytics($s,$source))->report($p,true);}catch(\Throwable $e){$wpdb->suppress_errors($previous);wp_die('Analytics CSV unavailable. Check permissions, report dates, schema and the 10,000-row export limit. No file was created.','',['response'=>400]);return;}
        $wpdb->suppress_errors($previous);nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="azula-sms-analytics-'.$report['range']['from'].'-'.$report['range']['to'].'.csv"');foreach(self::chunks($report) as $chunk)echo $chunk;exit;
    }
}
