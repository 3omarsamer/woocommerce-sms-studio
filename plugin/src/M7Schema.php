<?php
namespace Azula\SmsStudio;
final class M7Schema
{
    public static function columns():array{return [
        'recipient_links'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nlink_id bigint unsigned NOT NULL,\ncampaign_id bigint unsigned NOT NULL,\nrevision bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nrecipient_id bigint unsigned NOT NULL,\npublic_token char(32) NOT NULL,\ndomain varchar(80) NOT NULL,\nroute varchar(24) NOT NULL,\ncreated_at bigint NOT NULL,\nexpires_at bigint NOT NULL,\nstate varchar(16) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY recipient_token (public_token),\nUNIQUE KEY recipient_mapping (link_id,revision,audience_id,recipient_id),\nKEY campaign_recipient (campaign_id,recipient_id)",
        'link_requests'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\nlink_id bigint unsigned NOT NULL,\nrecipient_link_id bigint unsigned NOT NULL DEFAULT 0,\nevent_key char(64) NOT NULL,\nsource varchar(12) NOT NULL,\nclassification varchar(24) NOT NULL,\nbucket bigint NOT NULL,\nhits bigint unsigned NOT NULL DEFAULT 1,\nPRIMARY KEY  (id),\nUNIQUE KEY request_bucket (event_key),\nKEY campaign_request (campaign_id,bucket),\nKEY request_retention (bucket)"];
    }
}
