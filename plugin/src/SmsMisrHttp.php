<?php
namespace Azula\SmsStudio;

/** Balance POST: public endpoint audit returned GET 405 / Allow POST on 2026-10-17. */
final class SmsMisrHttp
{
    public const BALANCE_RESPONSE_CONTRACT='balance-number-v1';
    private static function fetch(array $credentials):array
    {
        if(!M11Environment::available()||array_keys($credentials)!==['username','password'])throw new \DomainException('Balance request refused.');
        foreach($credentials as $v)if(!is_string($v)||$v===''||strlen($v)>1024||preg_match('/[\x00\r\n]/',$v))throw new \DomainException('Balance request refused.');
        try{
            // Fixed Balance route only. No method fallback, credential query, redirect or SMS transport.
            $r=wp_remote_post(SmsMisrAdapter::BALANCE_URL,['timeout'=>10,'redirection'=>0,'sslverify'=>true,'blocking'=>true,'limit_response_size'=>16384,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/x-www-form-urlencoded'],'body'=>http_build_query($credentials,'','&',PHP_QUERY_RFC1738),'data_format'=>'body']);
            if(is_wp_error($r))return ['http'=>0,'body'=>''];
            $body=wp_remote_retrieve_body($r);$status=(int)wp_remote_retrieve_response_code($r);
            return ['http'=>$status>=100&&$status<=599?$status:0,'body'=>is_string($body)&&strlen($body)<=16384?$body:''];
        }catch(\Throwable $e){return ['http'=>0,'body'=>''];}
    }
    public static function diagnostic(array $credentials):array
    {
        $r=self::fetch($credentials);$safe=BalanceDiagnosticSanitizer::response($r['body'],array_values($credentials));
        return ['request_method'=>'POST','http_status'=>$r['http'],'response_format'=>$safe['format'],'sanitized_body'=>$safe['body'],'balance_replacement'=>5000,'sending_enabled'=>false];
    }
    public static function balance(array $credentials):array
    {
        if(!M11Environment::available()||array_keys($credentials)!==['username','password'])throw new \DomainException('Balance request refused.');
        $r=self::fetch($credentials);return self::parseBalance($r['http'],$r['body']);
    }
    /** Owner-verified POST response: one case-sensitive Balance JSON number, not an SMS code. */
    public static function parseBalance(int $http,string $body):array
    {
        $result=['connection_status'=>'unverified','status'=>'network_unavailable','code'=>null,'balance'=>null,'balance_candidate'=>null,'balance_verified'=>false,'http_status'=>$http,'response_contract'=>self::BALANCE_RESPONSE_CONTRACT];
        if($http===0)return $result;
        if($http!==200){$result['status']=$http===405?'balance_method_not_allowed':($http===429?'http_rate_limited':($http>=500?'provider_unavailable':'http_error'));return $result;}
        $result['status']='invalid_balance_response';
        // Match the entire confirmed shape. Reject duplicate/extra fields and alternate casing.
        // Keep the original decimal token as text: no float conversion, rounding or fake 5000.
        if(strlen($body)>16384||!preg_match('/\A[ \t\r\n]*\{[ \t\r\n]*"Balance"[ \t\r\n]*:[ \t\r\n]*((?:0|[1-9][0-9]*)(?:\.[0-9]+)?)[ \t\r\n]*\}[ \t\r\n]*\z/',$body,$m))return $result;
        $result['balance']=$result['balance_candidate']=$m[1];$result['balance_verified']=true;
        $result['connection_status']='verified';$result['status']='balance_verified';return $result;
    }
}
