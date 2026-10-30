<?php
namespace Azula\SmsStudio;

/** Fixed administrator-facing reasons; never echo an arbitrary exception or configuration value. */
final class AdminRequestError extends \RuntimeException
{
    private const MESSAGES = [
        'sms_nonce_invalid'=>'Administrator session verification failed. Reload this SMS Studio page and retry. Do not repeat a previously confirmed submission.',
        'sms_permissions_required'=>'Required SMS Studio or WooCommerce administrator permissions are missing. Ask an administrator to review your role; no action was authorized.',
        'sms_environment_unsupported'=>'SMS Studio is unavailable in this WordPress environment. Verify that LIVE is configured as production; no setting was changed.',
        'sms_production_home_mismatch'=>'Production access requires WordPress Home to be exactly https://azulasportswear.com/. Request an administrator review; do not change site URLs automatically.',
        'sms_production_optin_required'=>'Production access requires AZULA_SMS_STUDIO_PRODUCTION_ENABLED to be boolean true. Review configuration and obtain approval before changing it. Sending permissions remain separate.',
        'sms_key_missing'=>'AZULA_SMS_STUDIO_DATA_KEY is not configured. Review the dedicated LIVE key privately; never paste, replace or rotate an existing key to troubleshoot this error.',
        'sms_key_invalid'=>'The SMS data key format is invalid. Preserve the existing key and encrypted data and review configuration privately.',
        'sms_crypto_runtime_missing'=>'Sodium encryption is unavailable in PHP. Ask the host to review the extension; encryption cannot be downgraded.',
        'sms_key_authentication_failed'=>'The current key could not authenticate retained encrypted SMS evidence. A different key or damaged record can cause this. Preserve the key and records for investigation.',
        'sms_source_incompatible'=>'Customer Segments does not match an audited read-only API/source contract. Verify its installed version and source files without bypassing compatibility checks.',
        'sms_source_read_unavailable'=>'The Customer Segments read service is unavailable. Review source database access privately; no customer rows are included in this error.',
        'sms_schema_invalid'=>'SMS-owned schema verification failed. Inspect schema metadata before approving any repair; do not reset tables.',
        'sms_storage_unavailable'=>'An SMS-owned storage operation could not complete. Review schema and database permissions privately; no database details are exposed.',
        'sms_request_blocked'=>'Request blocked. Check permissions, campaign revision, source compatibility, SMS key, schema and audience freshness. If a submission was confirmed, review activity before taking further action.',
    ];
    public readonly string $publicCode;
    public function __construct(string $code)
    {
        $this->publicCode=isset(self::MESSAGES[$code])?$code:'sms_request_blocked';
        parent::__construct(self::MESSAGES[$this->publicCode]);
    }
    public static function response(\Throwable $e):array
    {
        if($e instanceof self)return ['code'=>$e->publicCode,'message'=>$e->getMessage()];
        if($e instanceof ControlledTestException)return ['message'=>$e->getMessage()];
        if($e instanceof \LengthException)return ['message'=>'Audience review exceeds its safe size limit. No rows were silently omitted.'];
        $code=$e instanceof \RuntimeException?match($e->getMessage()){
            'SMS data key is not configured. Audience storage is blocked.'=>'sms_key_missing',
            'A dedicated base64 32-byte SMS data key is required.'=>'sms_key_invalid',
            'Sodium encryption is required.'=>'sms_crypto_runtime_missing',
            'Encrypted record could not be authenticated.'=>'sms_key_authentication_failed',
            'Customer Segments version is not an audited read-only contract.',
            'An audited Customer Segments API v1 and WooCommerce are required.',
            'Read-only source interface unavailable.',
            'Customer Segments source differs from its audited read-only contract.'=>'sms_source_incompatible',
            'Customer Segments read service unavailable.'=>'sms_source_read_unavailable',
            'SMS transactional schema unavailable.', 'SMS schema columns incomplete.',
            'SMS schema column type mismatch.', 'SMS schema indexes incomplete.'=>'sms_schema_invalid',
            'SMS storage operation failed.'=>'sms_storage_unavailable',
            default=>'sms_request_blocked',
        }:'sms_request_blocked';
        return self::response(new self($code));
    }
}
