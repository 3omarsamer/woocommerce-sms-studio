<?php
namespace Azula\SmsStudio;

final class Schema
{
    public const VERSION = 8;
    public const OPTION = 'azula_sms_schema_version';
    public static function columns(): array
    {
        $columns = [
            'campaigns' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\nname varchar(190) NOT NULL,\nstate varchar(32) NOT NULL,\nsegment_id bigint unsigned NOT NULL,\nrevision bigint unsigned NOT NULL DEFAULT 1,\naudience_id bigint unsigned DEFAULT NULL,\napproval_hash char(64) DEFAULT NULL,\ntransmission_intent tinyint unsigned NOT NULL DEFAULT 0,\nstart_at bigint DEFAULT NULL,\ntimezone varchar(64) NOT NULL,\ncreated_at bigint NOT NULL,\nPRIMARY KEY  (id),\nKEY state_start (state,start_at)",
            'revisions' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\nrevision bigint unsigned NOT NULL,\nbinding_json longtext NOT NULL,\ncreated_at bigint NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY campaign_revision (campaign_id,revision)",
            'audiences' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\ngeneration bigint unsigned NOT NULL,\nstate varchar(24) NOT NULL,\nsegment_id bigint unsigned NOT NULL,\nrules_hash char(64) NOT NULL,\nadapter_version varchar(48) NOT NULL,\nkey_version varchar(16) NOT NULL,\nceiling bigint unsigned NOT NULL,\ncursor_id bigint unsigned NOT NULL DEFAULT 0,\nstarted_at bigint NOT NULL,\ncompleted_at bigint DEFAULT NULL,\ncounts_json text NOT NULL,\nmanifest_hash char(64) DEFAULT NULL,\nerror_code varchar(48) DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY campaign_generation (campaign_id,generation),\nKEY state_started (state,started_at),\nKEY state_completed (state,completed_at)",
            'members' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\naudience_id bigint unsigned NOT NULL,\nsource_id bigint unsigned NOT NULL,\nphone_hash char(64) DEFAULT NULL,\ndisposition varchar(32) NOT NULL,\npayload longtext NOT NULL,\npayload_hash char(64) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY audience_source (audience_id,source_id),\nKEY audience_disposition (audience_id,disposition),\nKEY audience_phone (audience_id,phone_hash)",
            'recipients' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nphone_hash char(64) NOT NULL,\nstate varchar(24) NOT NULL,\npayload longtext NOT NULL,\npayload_hash char(64) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY audience_phone (audience_id,phone_hash),\nKEY campaign_state (campaign_id,state)",
            'suppressions' => "phone_hash char(64) NOT NULL,\nactive tinyint unsigned NOT NULL,\nrevision bigint unsigned NOT NULL,\nreason varchar(80) NOT NULL,\nactor_id bigint unsigned NOT NULL,\nupdated_at bigint NOT NULL,\nPRIMARY KEY  (phone_hash),\nKEY active_updated (active,updated_at)",
            'approvals' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nbinding_hash char(64) NOT NULL,\nevidence_json longtext NOT NULL,\nactor_id bigint unsigned NOT NULL,\napproved_at bigint NOT NULL,\nexpires_at bigint NOT NULL,\noperational tinyint unsigned NOT NULL DEFAULT 0,\nPRIMARY KEY  (id),\nUNIQUE KEY binding_hash (binding_hash),\nKEY campaign_approved (campaign_id,approved_at)",
            'audit' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned DEFAULT NULL,\nactor_id bigint unsigned NOT NULL,\nevent_code varchar(48) NOT NULL,\nevidence_json text NOT NULL,\ncreated_at bigint NOT NULL,\nPRIMARY KEY  (id),\nKEY campaign_created (campaign_id,created_at)"
        ];
        $columns['campaigns'] = str_replace('PRIMARY KEY  (id)', "composer_payload longtext DEFAULT NULL,\nreview_hash char(64) DEFAULT NULL,\ndraft_token char(64) DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY draft_token (draft_token)", $columns['campaigns']);
        $columns['audiences'] = str_replace('PRIMARY KEY  (id)', "normalizer_version bigint unsigned NOT NULL DEFAULT 1,\nreview_version bigint unsigned NOT NULL DEFAULT 0,\nretry_count bigint unsigned NOT NULL DEFAULT 0,\nlast_progress_at bigint NOT NULL DEFAULT 0,\nnext_attempt_at bigint NOT NULL DEFAULT 0,\nPRIMARY KEY  (id)", $columns['audiences']);
        $columns['schedules'] = "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\nversion bigint unsigned NOT NULL DEFAULT 1,\nstate varchar(32) NOT NULL,\nstart_at bigint NOT NULL,\nprepare_at bigint NOT NULL,\nnext_check bigint NOT NULL,\nfinal_audience_id bigint unsigned DEFAULT NULL,\nbase_audience_id bigint unsigned DEFAULT NULL,\nrefresh_started bigint NOT NULL DEFAULT 0,\napproval_hash char(64) DEFAULT NULL,\napproval_payload longtext DEFAULT NULL,\nqueue_cursor bigint unsigned NOT NULL DEFAULT 0,\nverify_cursor bigint unsigned NOT NULL DEFAULT 0,\nretry_count bigint unsigned NOT NULL DEFAULT 0,\nlast_progress bigint NOT NULL DEFAULT 0,\nerror_code varchar(48) DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY campaign (campaign_id),\nKEY state_check (state,next_check)";
        $columns['queue'] = "id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nrecipient_id bigint unsigned NOT NULL,\nphone_hash char(64) NOT NULL,\napproval_hash char(64) NOT NULL,\nstate varchar(32) NOT NULL,\ncreated_at bigint NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY campaign_phone (campaign_id,phone_hash),\nKEY approval_cursor (campaign_id,approval_hash,id)";
        $columns['controlled']="id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nphase varchar(32) NOT NULL,\nactor_id bigint unsigned NOT NULL,\nrequested_at bigint NOT NULL,\napproved_at bigint NOT NULL DEFAULT 0,\nexpires_at bigint NOT NULL,\nupdated_at bigint NOT NULL,\nprovider_revision bigint unsigned NOT NULL,\napproval_hash char(64) DEFAULT NULL,\nbinding_hash char(64) DEFAULT NULL,\npayload longtext NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY controlled_campaign (campaign_id)";
        $columns['attempts']="id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\naudience_id bigint unsigned NOT NULL,\nrecipient_id bigint unsigned NOT NULL,\nphone_hash char(64) NOT NULL,\napproval_hash char(64) NOT NULL,\nrequest_hash char(64) NOT NULL,\nstate varchar(32) NOT NULL,\nprovider_code varchar(16) DEFAULT NULL,\nsmsid varchar(190) DEFAULT NULL,\ncost varchar(32) DEFAULT NULL,\nretry_class varchar(48) NOT NULL,\nhttp_status bigint NOT NULL DEFAULT 0,\nclaimed_at bigint NOT NULL,\nfinished_at bigint NOT NULL DEFAULT 0,\nreceipt longtext NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY controlled_approval (approval_hash),\nKEY campaign_attempt (campaign_id,id),\nKEY campaign_phone (campaign_id,phone_hash)";
        $columns['attempts']=str_replace('claimed_at bigint',"plan_id bigint unsigned NOT NULL DEFAULT 0,\nrecipient_count bigint unsigned NOT NULL DEFAULT 1,\nenvironment tinyint unsigned NOT NULL DEFAULT 2,\nclaimed_at bigint",$columns['attempts']);
        $columns['clicks']=str_replace('PRIMARY KEY  (id)',"recipient_link_id bigint unsigned NOT NULL DEFAULT 0,\nrequest_recorded tinyint unsigned NOT NULL DEFAULT 0,\nPRIMARY KEY  (id)",M6Schema::columns()['clicks']);
        $columns['journey']=str_replace('PRIMARY KEY  (id)',"recipient_id bigint unsigned NOT NULL DEFAULT 0,\naudience_id bigint unsigned NOT NULL DEFAULT 0,\nvalue_minor bigint DEFAULT NULL,\nvalue_decimal varchar(32) DEFAULT NULL,\ncurrency varchar(8) NOT NULL DEFAULT '',\nvalue_basis varchar(64) NOT NULL DEFAULT 'unavailable',\nevent_source varchar(24) NOT NULL DEFAULT 'legacy',\nsession_hash char(64) DEFAULT NULL,\nKEY campaign_recipient_kind (campaign_id,recipient_id,kind,occurred_at),\nKEY event_period (occurred_at,campaign_id,kind),\nKEY event_campaign_kind (campaign_id,kind,occurred_at,id),\nPRIMARY KEY  (id)",M6Schema::columns()['journey']);
        $columns['attempts']=str_replace('PRIMARY KEY  (id)',"KEY campaign_submission (campaign_id,state,claimed_at),\nKEY attempt_period (claimed_at,campaign_id),\nPRIMARY KEY  (id)",$columns['attempts']);
        $columns['clicks']=str_replace('PRIMARY KEY  (id)',"KEY click_period (clicked_at,campaign_id),\nPRIMARY KEY  (id)",$columns['clicks']);
        $columns['conversions']=str_replace('PRIMARY KEY  (id)',"KEY conversion_period (order_created,campaign_id),\nPRIMARY KEY  (id)",M6Schema::columns()['conversions']);
        $columns['journey']=str_replace('PRIMARY KEY  (id)',"checkout_fingerprint char(64) DEFAULT NULL,\nPRIMARY KEY  (id)",$columns['journey']);
        $columns['conversions']=str_replace('PRIMARY KEY  (id)',"checkout_fingerprint char(64) DEFAULT NULL,\nPRIMARY KEY  (id)",$columns['conversions']);
        $columns['members']=str_replace('PRIMARY KEY  (id)',"KEY source_identity (source_id,phone_hash,audience_id),\nPRIMARY KEY  (id)",$columns['members']);
        $columns['send_items']=str_replace('PRIMARY KEY  (id)',"approved_payload_hash char(64) DEFAULT NULL,\napproved_message_hash char(64) DEFAULT NULL,\nPRIMARY KEY  (id)",M6Schema::columns()['send_items']);
        $columns['clicks']=str_replace('PRIMARY KEY  (id)',"KEY observation_period (observed_at,campaign_id,recipient_link_id),\nPRIMARY KEY  (id)",$columns['clicks']);
        return $columns+M6Schema::columns()+M7Schema::columns()+M9Schema::columns();
    }
    public static function migrate(Database $database): void
    {
        if (!current_user_can('activate_plugins')) throw new \RuntimeException('Schema changes require plugin administration.');
        if ((int) get_option(self::OPTION, 0) > self::VERSION) throw new \RuntimeException('SMS schema downgrade is not supported.');
        $db = $database->db;
        $lock = 'azula_sms_schema_' . substr(hash('sha256', $db->prefix), 0, 32);
        if ((string) $db->get_var($db->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') throw new \RuntimeException('SMS schema upgrade already running.');
        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            foreach (self::columns() as $name => $ddl) {
                $table = $database->table($name);
                $existing = $db->get_row($db->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
                $database->check(true);
                if ($existing && strcasecmp($existing->Engine, 'InnoDB') !== 0) throw new \RuntimeException('Existing SMS table is not transactional; no engine conversion attempted.');
                dbDelta("CREATE TABLE $table (\n$ddl\n) ENGINE=InnoDB " . $db->get_charset_collate() . ';');
                $database->check(true);
            }
            self::verify($database);
            update_option(self::OPTION, self::VERSION, false);
            if ((int) get_option(self::OPTION, 0) !== self::VERSION) throw new \RuntimeException('SMS schema checkpoint failed.');
        } finally { $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    public static function verify(Database $database): void
    {
        $db = $database->db;
        foreach (self::columns() as $name => $ddl) {
            $table = $database->table($name);
            $meta = $db->get_row($db->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
            $database->check(true);
            if (!$meta || strcasecmp($meta->Engine, 'InnoDB') !== 0) throw new \RuntimeException('SMS transactional schema unavailable.');
            $columnRows = $db->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A); $database->check($columnRows);
            $columns = array_column($columnRows, 'Field');
            preg_match_all('/^([a-z_]+) (?:bigint|tinyint|varchar|char|text|longtext)/m', $ddl, $matches);
            if (array_diff($matches[1], $columns)) throw new \RuntimeException('SMS schema columns incomplete.');
            $types = array_column($columnRows, 'Type', 'Field');
            preg_match_all('/^([a-z_]+) ((?:bigint|tinyint)(?: unsigned)?|(?:varchar|char)\(\d+\)|longtext|text)/m', $ddl, $definitions, PREG_SET_ORDER);
            foreach ($definitions as $definition) {
                $actualType = preg_replace('/\b(bigint|tinyint)\(\d+\)/', '$1', strtolower($types[$definition[1]]));
                if ($actualType !== $definition[2]) throw new \RuntimeException('SMS schema column type mismatch.');
            }
            $indexes = $db->get_results("SHOW INDEX FROM `$table`", ARRAY_A); $database->check($indexes);
            $actual = [];
            foreach ($indexes as $index) $actual[$index['Key_name']][(int) $index['Seq_in_index']] = [$index['Column_name'], (int) $index['Non_unique'], $index['Sub_part']];
            preg_match_all('/^(PRIMARY KEY|UNIQUE KEY [a-z_]+|KEY [a-z_]+)\s*\(([^)]+)\)/m', $ddl, $keys, PREG_SET_ORDER);
            foreach ($keys as $key) {
                $indexName = $key[1] === 'PRIMARY KEY' ? 'PRIMARY' : substr($key[1], strrpos($key[1], ' ') + 1);
                $unique = str_starts_with($key[1], 'KEY ') ? 1 : 0;
                $expected = array_map(static fn($col) => [$col, $unique, null], explode(',', $key[2]));
                $found = $actual[$indexName] ?? []; ksort($found);
                if (array_values($found) !== $expected) throw new \RuntimeException('SMS schema indexes incomplete.');
            }
        }
    }
    /** Metadata only: never reads campaign/customer rows or exposes SQL/error strings. */
    public static function status(Database $database): array
    {
        $stored=(int)get_option(self::OPTION,0);$valid=false;
        try{self::verify($database);$valid=true;}catch(\Throwable $e){}
        $ready=$stored===self::VERSION && $valid;
        return ['stored_version'=>$stored,'required_version'=>self::VERSION,'migration_recorded'=>$stored===self::VERSION,'structure_verified'=>$valid,'ready'=>$ready,
            'state'=>$ready?'ready':($stored>self::VERSION?'newer_schema_blocked':($stored===self::VERSION?'schema_verification_failed':'upgrade_required')),
            'can_upgrade'=>$stored<=self::VERSION && current_user_can('manage_azula_sms_settings') && current_user_can('activate_plugins')];
    }
    public static function ready(): bool { return (int) get_option(self::OPTION, 0) === self::VERSION; }
}
