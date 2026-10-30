<?php
namespace Azula\SmsStudio;

/** Sender tokens are credentials, not a verified handset-facing brand name. */
final class SenderIdentity
{
    public const OPTION='azula_sms_sender_identity';
    public function __construct(private M6Store $s){}
    public function status(?array $config=null):array
    {
        $config??=(new SmsMisrSettings($this->s->crypto,$this->s->database->db))->config();$sender=(string)$config['sender'];$label=preg_match('/^[A-Za-z][A-Za-z0-9]{0,10}$/D',$sender)?$sender:'';$verified=$label==='AZULASPORTS';
        $raw=get_option(M11Environment::option(self::OPTION),null);
        $evidence=['mapping_present'=>is_string($raw),'mapping_readable'=>false,'account_binding_matches'=>false,'sender_binding_matches'=>false,'saved_label_valid'=>false];
        if(is_string($raw)){try{$saved=$this->s->crypto->open($raw);
            $evidence['mapping_readable']=true;
            $evidence['account_binding_matches']=hash_equals($this->s->crypto->digest(['account'=>$config['username'],'sender'=>$sender]),$saved['account_hash']??'');
            $evidence['sender_binding_matches']=hash_equals($this->s->crypto->digest(['sender'=>$sender]),$saved['sender_hash']??'');
            $evidence['saved_label_valid']=preg_match('/^[A-Za-z][A-Za-z0-9]{0,10}$/D',$saved['label']??'')===1;
            if($evidence['account_binding_matches']&&$evidence['sender_binding_matches']&&$evidence['saved_label_valid']){$label=$saved['label'];$verified=true;}
        }catch(\Throwable $e){}}
        $problem='';
        if(!$verified){
            $problem=!$evidence['mapping_present']?'No sender-name verification is saved for this environment.':(!$evidence['mapping_readable']?'The saved sender verification could not be authenticated; preserve the key and settings for investigation.':(!$evidence['sender_binding_matches']?'The configured Sender ID changed after sender verification.':(!$evidence['account_binding_matches']?'The configured provider account changed after sender verification.':'The saved sender label is invalid.')));
        }elseif($label!=='AzulaSports')$problem='The verified saved name does not exactly match the required AzulaSports name.';
        $evidence['approved_name_matches']=$verified&&$label==='AzulaSports';
        $approvalExplanation=$problem===''?'Approved AzulaSports mapping matches the current provider account and Sender ID.':($evidence['mapping_present']&&!$evidence['mapping_readable']?$problem.' Do not overwrite the retained mapping to troubleshoot this error.':$problem.' Open Settings & Health, verify the provider-approved name and explicitly save AzulaSports for the current environment settings. No SMS was authorized.');

        return ['verification'=>$evidence,'approval_explanation'=>$approvalExplanation,'label'=>$label?:'Sender name unverified','verified'=>$verified,'signature'=>$this->s->crypto->digest(['account'=>$config['username'],'sender'=>$sender,'label'=>$label,'verified'=>$verified]),'requires_mapping'=>$label==='', 'explanation'=>$verified?'Uses the configured Sender ID; handset-facing mapping must match the approved SMS Misr account.':'Verify the approved sender name in SMS Misr settings before sending. A Sender ID token does not establish its visible name.'];
    }
    public function save(string $label):array
    {
        $label=trim($label);if(!preg_match('/^[A-Za-z][A-Za-z0-9]{0,10}$/D',$label))throw new ControlledTestException('Use the approved alphanumeric sender name: one to eleven characters, beginning with a letter.');$db=$this->s->database->db;$lock='azula_sms_provider_'.substr(hash('sha256',$db->prefix),0,32);if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new ControlledTestException('Provider settings are busy.');
        try{$c=(new SmsMisrSettings($this->s->crypto,$db))->config();if($c['sender']==='')throw new ControlledTestException('Save the actual approved Sender ID configuration first.');$sealed=$this->s->crypto->seal(['label'=>$label,'account_hash'=>$this->s->crypto->digest(['account'=>$c['username'],'sender'=>$c['sender']]),'sender_hash'=>$this->s->crypto->digest(['sender'=>$c['sender']]),'actor'=>get_current_user_id(),'verified_at'=>time()]);update_option(M11Environment::option(self::OPTION),$sealed,false);if(get_option(M11Environment::option(self::OPTION))!==$sealed)throw new ControlledTestException('Sender verification could not be saved.');return $this->status($c);}finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function requireVerified(array $config):array{$identity=$this->status($config);if(!$identity['verified'])throw new ControlledTestException($identity['explanation']);return $identity;}
}
