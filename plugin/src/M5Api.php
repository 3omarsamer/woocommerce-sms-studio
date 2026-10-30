<?php
namespace Azula\SmsStudio;
final class M5Api
{
    public static function handle(array $p):array
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');
        if(defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.6.0','>=')&&!in_array($p['op']??'',['m5_activity','m5_status'],true))throw new ControlledTestException('The standalone M5 Test workflow is retired. Use the normal campaign builder; historical attempts are preserved.');
        if(!Schema::ready())throw new ControlledTestException('Complete the SMS schema upgrade before M5 activity or controlled testing.');
        global $wpdb;$database=new Database($wpdb);Schema::verify($database);$crypto=Crypto::deployment();$id=$p['campaign']??null;
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new ControlledTestException('Open a saved campaign first.');
        if($p['op']==='m5_activity')return (new M5Activity($database,$crypto))->rows((int)$id,is_string($p['after']??null)&&ctype_digit($p['after'])?(int)$p['after']:0);
        $service=new ControlledTestService($database,$crypto,new CustomerSegmentsAdapterV1($wpdb));
        return match($p['op']){'m5_status'=>$service->summary((int)$id),'m5_prepare'=>$service->prepare($p),'m5_approve'=>$service->approve($p),'m5_confirm'=>$service->confirm($p),default=>throw new ControlledTestException('Unsupported controlled Test action.')};
    }
}
