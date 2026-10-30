<?php
namespace Azula\SmsStudio;

/** First-party collection only. No GA4, Meta, provider or foreign data mutation. */
final class M6Front
{
    private static function tracking():M6Tracking{return new M6Tracking(M7Settings::modern()?M7Settings::store():M6Worker::store());}
    private static function cookie():string{return is_string($_COOKIE[M6Tracking::COOKIE]??null)?$_COOKIE[M6Tracking::COOKIE]:'';}
    private static function usable():bool{try{if(M7Settings::modern()){M7Settings::domain();return Schema::ready();}return M2Scheduler::staging()&&Schema::ready();}catch(\Throwable $e){return false;}}
    public static function register():void
    {
        add_action('template_redirect',[self::class,'redirect'],1);add_action('wp_enqueue_scripts',[self::class,'assets'],30);add_action('wp_ajax_azula_sms_observe',[self::class,'observe']);add_action('wp_ajax_nopriv_azula_sms_observe',[self::class,'observe']);
        add_action('woocommerce_add_to_cart',[self::class,'cart'],20,6);add_action('woocommerce_checkout_order_created',[self::class,'order'],99999);add_action('woocommerce_store_api_checkout_order_processed',[self::class,'order'],99999);add_action('woocommerce_order_status_changed',[self::class,'reconcile'],30,1);add_action('woocommerce_order_refunded',[self::class,'reconcile'],30,1);add_action('azula_sms_reconcile_orders',[self::class,'batch']);add_action('action_scheduler_init',[self::class,'ensure'],30);
    }
    public static function redirect():void
    {
        if(!self::usable()||!isset($_GET['azula_sms_link']))return;SmsConsent::headers();
        try{$token=is_string($_GET['azula_sms_link'])?$_GET['azula_sms_link']:'';$purpose=strtolower((string)($_SERVER['HTTP_SEC_PURPOSE']??$_SERVER['HTTP_PURPOSE']??''));$agent=(string)($_SERVER['HTTP_USER_AGENT']??'');$scanner=str_contains($purpose,'prefetch')||preg_match('/bot|crawler|spider|preview|facebookexternalhit|whatsapp|proofpoint|barracuda|mimecast|urlscan|google-safety/i',$agent)===1;
            $method=(string)($_SERVER['REQUEST_METHOD']??'GET');if(!in_array($method,['GET','HEAD'],true))throw new \RuntimeException('Method blocked');$link=self::tracking()->activeLink($token);if(!$link)throw new \RuntimeException('Link unavailable');$r=self::tracking()->redirect($token,self::cookie(),$method,$scanner);if($r['cookie']!==null)setcookie(M6Tracking::COOKIE,$r['cookie'],['expires'=>time()+M6Tracking::WINDOW,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            // Target was loaded from an immutable allowlisted row; never accept a destination parameter.
            SmsNotice::arrive($token,false,$method,(bool)$scanner);wp_redirect($r['target'],302,'Azula SMS Studio');exit;
        }catch(\Throwable $e){status_header(404);echo 'This SMS link is unavailable.';exit;}
    }
    public static function assets():void
    {
        if(!self::usable()||!M6Tracking::enabled()||is_admin())return;
        try{$kind='visit';$subject=0;if(function_exists('is_product')&&is_product()){$kind='product_view';$subject=(int)get_queried_object_id();}elseif(M9Schema::modern()?M9Checkout::checkoutPage():(function_exists('is_checkout')&&is_checkout()&&!is_order_received_page()&&!is_wc_endpoint_url('order-pay')))$kind='checkout_start';
            $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);$scope=['kind'=>$kind,'subject'=>$subject,'path'=>substr($path,0,1000),'bucket'=>intdiv(time(),600)];$proof=Crypto::deployment()->digest(['sms_page'=>$scope]);$url=plugin_dir_url(AZULA_SMS_STUDIO_FILE).'assets/journey.js';wp_enqueue_script('azula-sms-journey',$url,[],AZULA_SMS_STUDIO_VERSION,true);wp_add_inline_script('azula-sms-journey','window.AzulaSmsJourney='.wp_json_encode(['url'=>admin_url('admin-ajax.php'),'scope'=>$scope,'proof'=>$proof,'m8'=>M8Tokens::modern()]).';','before');
        }catch(\Throwable $e){}
    }
    /** Renew a genuinely server-signed cached-page scope only for an active journey. */
    public static function renewObservation(array $p,string $cookie):?array
    {
        $scope=['kind'=>$p['kind']??'','subject'=>(int)($p['subject']??0),'path'=>$p['path']??'','bucket'=>(int)($p['bucket']??0)];
        if(!is_string($scope['path'])||strlen($scope['path'])>1000||!in_array($scope['kind'],['visit','product_view','checkout_start'],true)||!is_string($p['proof']??null)||!hash_equals(Crypto::deployment()->digest(['sms_page'=>$scope]),$p['proof'])||!self::tracking()->current($cookie))return null;
        $scope['bucket']=intdiv(time(),600);return ['scope'=>$scope,'proof'=>Crypto::deployment()->digest(['sms_page'=>$scope])];
    }
    public static function observe():void
    {
        nocache_headers();header('Cache-Control: private, no-store');$accepted=false;
        try{if(!self::usable()||($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \RuntimeException('Unavailable');$home=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);$origin=(string)($_SERVER['HTTP_ORIGIN']??'');if(strtolower((string)wp_parse_url($origin,PHP_URL_HOST))!==strtolower($home)||wp_parse_url($origin,PHP_URL_SCHEME)!=='https')throw new \RuntimeException('Origin blocked');
            $p=wp_unslash($_POST);if(M7Settings::modern()&&abs(intdiv(time(),600)-(int)($p['bucket']??0))>1){$refresh=self::renewObservation($p,self::cookie());if(!$refresh)throw new \RuntimeException('Invalid cached scope');wp_send_json_success(['observed'=>false,'refresh'=>$refresh]);return;}
            $kind=is_string($p['kind']??null)?$p['kind']:'';$path=is_string($p['path']??null)?$p['path']:'';$subject=(int)($p['subject']??0);$bucket=(int)($p['bucket']??0);if(!in_array($kind,['visit','product_view','checkout_start'],true)||strlen($path)>1000||abs(intdiv(time(),600)-$bucket)>1)throw new \RuntimeException('Invalid scope');$scope=['kind'=>$kind,'subject'=>$subject,'path'=>$path,'bucket'=>$bucket];if(!is_string($p['proof']??null)||!hash_equals(Crypto::deployment()->digest(['sms_page'=>$scope]),$p['proof']))throw new \RuntimeException('Invalid proof');$tracking=self::tracking();$cookie=self::cookie();if(M8Tokens::modern()){$event=is_string($p['event_id']??null)?$p['event_id']:'';if(!preg_match('/^[a-f0-9]{32}$/D',$event))throw new \RuntimeException('Event identifier required');$collector=new M8Events(M7Settings::store());$accepted=$collector->record($cookie,'visit',$event);if($kind!=='visit')$accepted=$collector->record($cookie,$kind,$event,$kind==='checkout_start'?M8Values::checkout():[])||$accepted;}else{$accepted=$tracking->event($cookie,'visit','observed_visit');if($kind!=='visit')$accepted=$tracking->event($cookie,$kind,(string)$subject)||$accepted;}
        }catch(\Throwable $e){}wp_send_json_success(['observed'=>$accepted,'identity'=>'anonymous browser observation; not verified human engagement']);
    }
    public static function cart($key,$productId,$quantity=1,$variationId=0,$variation=[],$data=[]):void
    {
        if(!self::usable()||!M6Tracking::enabled())return;if(M8Tokens::modern()){try{static $request=null,$seen=[];$request??=bin2hex(random_bytes(16));$ordinal=M9Schema::modern()&&function_exists('did_action')?':action:'.did_action('woocommerce_add_to_cart'):'';$identity=hash('sha256',(string)$key.':'.(int)$productId.':'.(int)$variationId.':'.(string)$quantity.$ordinal);if(isset($seen[$identity]))return;$seen[$identity]=true;(new M8Events(M7Settings::store()))->record(self::cookie(),'add_to_cart',$request.':'.$identity,M8Values::cart((int)$productId,(int)$variationId,$quantity));}catch(\Throwable $e){}return;}try{self::tracking()->event(self::cookie(),'add_to_cart',hash('sha256',(string)$key.':'.(int)$productId.':'.(int)$quantity.':'.intdiv(time(),10)));}catch(\Throwable $e){}
    }
    public static function order($order):void
    {
        if(!self::usable()||!M6Tracking::enabled())return;try{if(!is_object($order)&&function_exists('wc_get_order'))$order=wc_get_order((int)$order);if(!$order)return;$s=(M7Settings::modern()?M7Settings::store():M6Worker::store());(new M6Tracking($s))->associate($order,self::cookie());if(M9Schema::modern())M9Checkout::bindOrder($s,$order);(new M6Revenue($s))->order((int)$order->get_id());self::ensure();}catch(\Throwable $e){} // Never interrupt checkout or write the order.
    }
    public static function reconcile(int $id):void{if(!self::usable())return;try{(new M6Revenue((M7Settings::modern()?M7Settings::store():M6Worker::store())))->order($id);}catch(\Throwable $e){}}
    public static function batch():void{if(!self::usable())return;try{(new M6Revenue((M7Settings::modern()?M7Settings::store():M6Worker::store())))->batch();}catch(\Throwable $e){}self::ensure(true);}
    public static function ensure(bool $successor=false):void
    {
        if(!self::usable()||!M2Scheduler::available())return;try{$s=(M7Settings::modern()?M7Settings::store():M6Worker::store());if($s->row('SELECT id FROM '.$s->table('conversions').' LIMIT 1')&&($successor||!as_has_scheduled_action('azula_sms_reconcile_orders',[],M6Worker::GROUP)))as_schedule_single_action(time()+300,'azula_sms_reconcile_orders',[],M6Worker::GROUP,false);}catch(\Throwable $e){}
    }
}
