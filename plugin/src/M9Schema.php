<?php
namespace Azula\SmsStudio;
final class M9Schema
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.9.0','>=');}
    public static function columns():array{return [
        'bulk_runs'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nplan_id bigint unsigned NOT NULL,\nmode varchar(16) NOT NULL,\nstate varchar(32) NOT NULL,\npayload longtext NOT NULL,\nbinding_hash char(64) NOT NULL,\nnext_check bigint NOT NULL,\nlast_progress bigint NOT NULL,\nerror_code varchar(64) NOT NULL DEFAULT '',\nPRIMARY KEY  (id),\nUNIQUE KEY bulk_plan (plan_id),\nKEY bulk_due (state,next_check)",
        'target_segments'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nname varchar(190) NOT NULL,\nmode varchar(16) NOT NULL,\ndefinition longtext NOT NULL,\nfingerprint char(64) NOT NULL,\nactor_id bigint unsigned NOT NULL,\ncreated_at bigint NOT NULL,\nPRIMARY KEY  (id)",
        'target_members'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nsegment_id bigint unsigned NOT NULL,\nsource_id bigint unsigned NOT NULL,\npayload longtext NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY target_identity (segment_id,source_id),\nKEY target_cursor (segment_id,source_id)"
    ];}
}
