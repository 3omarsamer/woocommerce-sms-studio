<?php
namespace Azula\SmsStudio;

/** Explicit private administrator download; no server file/cache/log is created. */
final class AudienceCsv
{
    public static function cell(string $value,bool $phone=false): string
    {
        $value=AudienceReview::visible($value);
        // Text marker protects phone formatting and formula prefixes, including whitespace/fullwidth variants.
        if($value!=='' && ($phone || preg_match('/^[\s\p{Z}\p{Cf}]*[=+\-@＝＋－＠]/u',$value)))$value="'".$value;
        return $value;
    }
    public static function line(array $cells): string
    {
        $stream=fopen('php://memory','r+');
        try{fputcsv($stream,$cells,',','"','',"\r\n");rewind($stream);return stream_get_contents($stream);}finally{fclose($stream);}
    }
    public static function chunks(array $result): \Generator
    {
        if(!$result['complete'])throw new \DomainException('Incomplete audience cannot be exported.');
        yield "\xEF\xBB\xBF".self::line(['Campaign','Audience','Generation','Customer name','Phone (text)','Classification','Reason','Retained recipient name','Retained recipient phone (text)']);
        foreach($result['rows'] as $row)yield self::line([(string)$result['campaign'],(string)$result['audience'],(string)$result['generation'],self::cell($row['name']),self::cell($row['phone'],true),$row['classification'],self::cell($row['reason']),self::cell($row['retained_name']),self::cell($row['retained_phone'],true)]);
    }
    public static function download(): void
    {
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try{
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \RuntimeException('POST required.');
            $p=wp_unslash($_POST);if(!is_string($p['nonce']??null))throw new \RuntimeException('Nonce required.');M2Api::authorize($p['nonce']);
            foreach(['campaign','audience'] as $key)if(!is_string($p[$key]??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$p[$key]))throw new \InvalidArgumentException('Invalid export scope.');
            $result=M2Scheduler::repository()->review((int)$p['campaign'],(int)$p['audience'],$p,true);
        }catch(\Throwable $e){$wpdb->suppress_errors($previous);wp_die('CSV unavailable. Select a completed current audience; check access, filters, retained evidence and review size limits. No file was created.','',['response'=>400]);return;}
        $wpdb->suppress_errors($previous);
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="azula-campaign-'.$result['campaign'].'-audience-'.$result['audience'].'-'.$result['category'].'.csv"');
        foreach(self::chunks($result) as $chunk)echo $chunk;
        exit;
    }
}
