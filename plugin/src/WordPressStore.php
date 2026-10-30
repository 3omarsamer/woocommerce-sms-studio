<?php
namespace Azula\SmsStudio;

final class WordPressStore implements AudienceStore
{
    private $db;
    public function __construct(private Database $database) { $this->db = $database->db; }
    private function t(string $name): string { return $this->database->table($name); }
    private function row(string $sql): array
    {
        $row = $this->db->get_row($sql, ARRAY_A); $this->database->check(true);
        if (!$row) throw new \RuntimeException('SMS record unavailable.');
        return $row;
    }
    public function campaign(int $id): array { return $this->row($this->db->prepare('SELECT * FROM ' . $this->t('campaigns') . ' WHERE id=%d', $id)); }
    public function audience(int $id): array { return $this->row($this->db->prepare('SELECT * FROM ' . $this->t('audiences') . ' WHERE id=%d', $id)); }
    public function createCampaign(string $name, int $segment, ?int $start, string $timezone, ?string $draftToken = null): int
    {
        if (!$name || strlen($name) > 190 || $segment < 1) throw new \InvalidArgumentException('Invalid campaign draft.');
        new \DateTimeZone($timezone);
        return $this->database->transaction(function () use ($name, $segment, $start, $timezone, $draftToken): int {
            $now = time();
            $this->database->check($this->db->insert($this->t('campaigns'), ['name' => $name, 'segment_id' => $segment, 'state' => $start === null ? 'draft' : 'planned', 'start_at' => $start, 'timezone' => $timezone, 'created_at' => $now, 'draft_token'=>$draftToken]));
            $id = (int) $this->db->insert_id;
            $this->database->check($this->db->insert($this->t('revisions'), ['campaign_id' => $id, 'revision' => 1, 'binding_json' => json_encode(['segment_id' => $segment, 'start' => $start, 'timezone' => $timezone, 'dispatch_available' => false], JSON_THROW_ON_ERROR), 'created_at' => $now]));
            $this->audit($id, 'draft_created', []);
            return $id;
        });
    }
    public function begin(int $campaign, array $segment, int $ceiling, int $now): array
    {
        return $this->database->transaction(function () use ($campaign, $segment, $ceiling, $now): array {
            $c = $this->row($this->db->prepare('SELECT * FROM ' . $this->t('campaigns') . ' WHERE id=%d FOR UPDATE', $campaign));
            (new ApprovalPolicy())->assertRefreshable((bool) $c['transmission_intent']);
            if ((int) $c['segment_id'] !== (int) $segment['id']) throw new \RuntimeException('Campaign segment changed.');
            if ($c['audience_id']) {
                $old = $this->audience((int) $c['audience_id']);
                if ($old['state'] === 'preparing') throw new \DomainException('Finish or expire the current preparation before refreshing.');
            }
            $generation = 1 + (int) $this->db->get_var($this->db->prepare('SELECT COALESCE(MAX(generation),0) FROM ' . $this->t('audiences') . ' WHERE campaign_id=%d', $campaign)); $this->database->check(true);
            $this->database->check($this->db->insert($this->t('audiences'), ['campaign_id' => $campaign, 'generation' => $generation, 'state' => 'preparing', 'segment_id' => $segment['id'], 'rules_hash' => $segment['fingerprint'], 'adapter_version' => $segment['adapter_version'] ?? CustomerSegmentsAdapterV1::VERSION, 'key_version' => '1', 'ceiling' => $ceiling, 'started_at' => $now, 'counts_json' => json_encode(AudiencePreparationService::COUNTS, JSON_THROW_ON_ERROR)]));
            $id = (int) $this->db->insert_id;
            $revision = (int) $c['revision'] + 1;
            $this->database->check($this->db->update($this->t('campaigns'), ['audience_id' => $id, 'revision' => $revision, 'state' => 'preparing', 'approval_hash' => null], ['id' => $campaign]));
            $this->database->check($this->db->insert($this->t('revisions'), ['campaign_id' => $campaign, 'revision' => $revision, 'binding_json' => json_encode(['audience_id' => $id, 'rules_hash' => $segment['fingerprint'], 'start' => $c['start_at'], 'timezone' => $c['timezone'], 'dispatch_available' => false], JSON_THROW_ON_ERROR), 'created_at' => $now]));
            $this->audit($campaign, 'audience_started', ['audience_id' => $id, 'generation' => $generation]);
            return $this->audience($id);
        });
    }
    public function atomic(int $id, callable $callback): array
    {
        return $this->database->transaction(function () use ($id, $callback): array {
            // Consistent lock order: campaign first, then audience.
            $a = $this->audience($id);
            $c = $this->row($this->db->prepare('SELECT * FROM ' . $this->t('campaigns') . ' WHERE id=%d FOR UPDATE', $a['campaign_id']));
            $a = $this->row($this->db->prepare('SELECT * FROM ' . $this->t('audiences') . ' WHERE id=%d FOR UPDATE', $id));
            $schedule=$this->db->get_row($this->db->prepare('SELECT * FROM '.$this->t('schedules').' WHERE campaign_id=%d',(int)$c['id']),ARRAY_A);$this->database->check(true);
            if($schedule && in_array($schedule['state'],['paused','canceled'],true))throw new \DomainException('Scheduled campaign preparation is paused.');
            if ((int) $c['audience_id'] !== $id || $c['approval_hash'] !== null || $c['transmission_intent']) throw new \DomainException('Audience is frozen or superseded.');
            return $callback($a);
        });
    }
    public function suppressed(string $phoneHash): bool
    {
        $value = $this->db->get_var($this->db->prepare('SELECT active FROM ' . $this->t('suppressions') . ' WHERE phone_hash=%s', $phoneHash)); $this->database->check(true);
        return (int) $value === 1;
    }
    public function suppress(string $hash, bool $active, string $reason): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || !in_array($reason, ['explicit_sms_optout', 'owner_suppression', 'owner_revocation'], true)) throw new \InvalidArgumentException('Invalid SMS suppression.');
        $this->database->transaction(function () use ($hash, $active, $reason): void {
            $sql = 'INSERT INTO ' . $this->t('suppressions') . ' (phone_hash,active,revision,reason,actor_id,updated_at) VALUES (%s,%d,1,%s,%d,%d) ON DUPLICATE KEY UPDATE active=VALUES(active),revision=revision+1,reason=VALUES(reason),actor_id=VALUES(actor_id),updated_at=VALUES(updated_at)';
            $this->database->check($this->db->query($this->db->prepare($sql, $hash, (int) $active, $reason, get_current_user_id(), time())));
            $this->audit(null, $active ? 'sms_suppressed' : 'sms_suppression_revoked', ['phone_hash' => $hash]);
        });
    }
    public function recipient(int $audience, string $phoneHash): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . $this->t('recipients') . ' WHERE audience_id=%d AND phone_hash=%s', $audience, $phoneHash), ARRAY_A); $this->database->check(true);
        return $row ?: null;
    }
    public function saveRecipient(array $audience, string $hash, string $payload, string $digest): void
    {
        $sql = 'INSERT INTO ' . $this->t('recipients') . ' (campaign_id,audience_id,phone_hash,state,payload,payload_hash) VALUES (%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload),payload_hash=VALUES(payload_hash)';
        $this->database->check($this->db->query($this->db->prepare($sql, $audience['campaign_id'], $audience['id'], $hash, 'prepared', $payload, $digest)));
    }
    public function saveMember(int $audience, int $source, ?string $phoneHash, string $disposition, string $payload, string $digest): void
    {
        $this->database->check($this->db->insert($this->t('members'), ['audience_id' => $audience, 'source_id' => $source, 'phone_hash' => $phoneHash, 'disposition' => $disposition, 'payload' => $payload, 'payload_hash' => $digest]));
    }
    public function checkpoint(int $id, int $cursor, array $counts): void
    {
        $this->database->check($this->db->update($this->t('audiences'), ['cursor_id' => $cursor, 'counts_json' => json_encode($counts, JSON_THROW_ON_ERROR)], ['id' => $id, 'state' => 'preparing']));
    }
    public function manifestRows(int $audience): iterable
    {
        foreach (['members' => 'source_id', 'recipients' => 'phone_hash'] as $table => $order) {
            $after = 0;
            do {
                $rows = $this->db->get_results($this->db->prepare('SELECT id,' . $order . ',payload_hash' . ($table === 'members' ? ',disposition' : '') . ' FROM ' . $this->t($table) . ' WHERE audience_id=%d AND id>%d ORDER BY id ASC LIMIT 100', $audience, $after), ARRAY_A); $this->database->check($rows);
                foreach ($rows as $row) { $after = (int) $row['id']; yield ['table' => $table, 'data' => $row]; }
            } while (count($rows) === 100);
        }
    }
    public function complete(int $id, string $manifest, int $now): void
    {
        $a = $this->audience($id);
        $this->database->check($this->db->update($this->t('audiences'), ['state' => 'complete', 'manifest_hash' => $manifest, 'completed_at' => $now], ['id' => $id, 'state' => 'preparing']));
        $this->database->check($this->db->update($this->t('campaigns'), ['state' => 'review_ready'], ['id' => $a['campaign_id'], 'audience_id' => $id]));
        $this->audit((int) $a['campaign_id'], 'audience_complete', ['audience_id' => $id, 'manifest' => $manifest]);
    }
    public function fail(int $id): void
    {
        $this->database->transaction(function () use ($id): void {
            $a = $this->audience($id);
            $this->database->check($this->db->update($this->t('audiences'), ['state' => 'blocked', 'error_code' => 'capture_blocked'], ['id' => $id, 'state' => 'preparing']));
            $this->database->check($this->db->query($this->db->prepare('UPDATE ' . $this->t('campaigns') . ' SET state=%s WHERE id=%d AND audience_id=%d AND state=%s', 'blocked', $a['campaign_id'], $id, 'preparing')));
        });
    }
    public function differences(int $old, int $new): array
    {
        $a = $this->audience($old); $b = $this->audience($new);
        if ($a['campaign_id'] != $b['campaign_id'] || $a['state'] !== 'complete' || $b['state'] !== 'complete') throw new \DomainException('Only completed generations of the same campaign can be compared.');
        $r = $this->t('recipients'); $m = $this->t('members');
        $added = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $r n LEFT JOIN $r o ON o.audience_id=%d AND o.phone_hash=n.phone_hash WHERE n.audience_id=%d AND o.id IS NULL", $old, $new)); $this->database->check(true);
        $removed = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $r o LEFT JOIN $r n ON n.audience_id=%d AND n.phone_hash=o.phone_hash WHERE o.audience_id=%d AND n.id IS NULL", $new, $old)); $this->database->check(true);
        $changed = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $m o INNER JOIN $m n ON n.audience_id=%d AND n.source_id=o.source_id WHERE o.audience_id=%d AND o.disposition<>n.disposition", $new, $old)); $this->database->check(true);
        return ['added_phones' => $added, 'removed_phones' => $removed, 'changed_dispositions' => $changed, 'newly_suppressed' => $this->newSuppressions($old, $new)];
    }
    private function newSuppressions(int $old, int $new): int
    {
        $m = $this->t('members');
        $count = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $m o INNER JOIN $m n ON n.audience_id=%d AND n.source_id=o.source_id WHERE o.audience_id=%d AND o.disposition<>%s AND n.disposition=%s", $new, $old, 'suppressed', 'suppressed')); $this->database->check(true); return $count;
    }
    public function audit(?int $campaign, string $event, array $evidence): void
    {
        $this->database->check($this->db->insert($this->t('audit'), ['campaign_id' => $campaign, 'actor_id' => get_current_user_id(), 'event_code' => $event, 'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => time()]));
    }
    /** Foundation only: evidence is append-only and never creates dispatch authorization. */
    public function appendPolicyEvidence(int $campaign, int $audience, array $record): void
    {
        if (($record['operational'] ?? null) !== false) throw new \DomainException('M1 evidence cannot be operational.');
        $this->database->transaction(function () use ($campaign, $audience, $record): void {
            $c = $this->row($this->db->prepare('SELECT * FROM ' . $this->t('campaigns') . ' WHERE id=%d FOR UPDATE', $campaign));
            $a = $this->row($this->db->prepare('SELECT * FROM ' . $this->t('audiences') . ' WHERE id=%d FOR UPDATE', $audience));
            if ((int) $a['campaign_id'] !== $campaign || (int) $c['audience_id'] !== $audience) throw new \DomainException('Evidence belongs to a different audience.');
            $verified = (new ApprovalPolicy())->record($record['binding'], ApprovalPolicy::audienceEvidence($a), (int) $record['actor'], (int) $record['approved_at']);
            if ($verified !== $record) throw new \DomainException('Policy evidence differs from its bound capture.');
            $this->database->check($this->db->insert($this->t('approvals'), ['campaign_id' => $campaign, 'audience_id' => $audience, 'binding_hash' => $record['binding_hash'], 'evidence_json' => json_encode($record, JSON_THROW_ON_ERROR), 'actor_id' => $record['actor'], 'approved_at' => $record['approved_at'], 'expires_at' => $record['expires_at'], 'operational' => 0]));
        });
    }
    /** Explicit admin maintenance only. Preserve manifests/counts and all suppression records. */
    public function purgeExpired(int $now): int
    {
        $a = $this->t('audiences');
        $ids = $this->db->get_col($this->db->prepare("SELECT id FROM $a WHERE (state=%s AND started_at<%d) OR (state=%s AND completed_at<%d) ORDER BY id ASC LIMIT 100", 'blocked', $now - 7 * 86400, 'complete', $now - 90 * 86400)); $this->database->check($ids);
        $purged=0;foreach ($ids as $id) $this->database->transaction(function () use ($id,&$purged): void {
            $audience = $this->audience((int) $id);
            $this->row($this->db->prepare('SELECT * FROM ' . $this->t('campaigns') . ' WHERE id=%d FOR UPDATE', $audience['campaign_id']));
            $this->row($this->db->prepare('SELECT * FROM ' . $this->t('audiences') . ' WHERE id=%d FOR UPDATE', $id));
            if(M9Schema::modern()){$active=$this->row($this->db->prepare('SELECT id FROM '.$this->t('sendplans')." WHERE audience_id=%d AND expires_at>=%d AND ((state IN ('m9_authorized','m9_paused') AND released_at>0 AND approved_at>0) OR (state IN ('m9_preparing','m9_ready') AND updated_at>=%d)) LIMIT 1",$id,time(),time()-ApprovalPolicy::FRESH_SECONDS));if($active)return;}

            foreach (['members', 'recipients', 'queue'] as $table) $this->database->check($this->db->query($this->db->prepare('DELETE FROM ' . $this->t($table) . ' WHERE audience_id=%d', $id)));
            $this->database->check($this->db->update($this->t('schedules'),['state'=>'needs_review','approval_hash'=>null,'approval_payload'=>null,'next_check'=>0,'error_code'=>'capture_purged'],['campaign_id'=>$audience['campaign_id'],'final_audience_id'=>$id]));
            $this->database->check($this->db->update($a = $this->t('audiences'), ['state' => 'purged'], ['id' => $id]));
            $this->database->check($this->db->update($this->t('campaigns'), ['state' => 'blocked', 'approval_hash' => null], ['id' => $audience['campaign_id'], 'audience_id' => $id]));
            $this->audit((int) $audience['campaign_id'], 'expired_capture_purged', ['audience_id' => (int) $id]);$purged++;
        });
        return $purged;
    }
}
