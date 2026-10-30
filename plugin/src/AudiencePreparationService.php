<?php
namespace Azula\SmsStudio;

final class AudiencePreparationService
{
    public const COUNTS = ['captured' => 0, 'prepared' => 0, 'missing_phone' => 0, 'identity_unverified' => 0, 'invalid_phone' => 0, 'duplicate' => 0, 'suppressed' => 0];
    public function __construct(private AudienceSource $source, private AudienceStore $store, private Crypto $crypto) {}
    public function begin(int $campaignId, int $now): array
    {
        $campaign = $this->store->campaign($campaignId);
        if(in_array($campaign['state']??'',['canceled','cancelled','trashed'],true))throw new \DomainException('Cancelled campaigns cannot prepare a new audience. Duplicate the campaign instead.');
        (new ApprovalPolicy())->assertRefreshable((bool) $campaign['transmission_intent']);
        $segment = $this->source->segment((int) $campaign['segment_id']);
        return $this->store->begin($campaignId, $segment, $this->source->ceiling($segment), $now);
    }
    public function step(int $id, int $now, bool $allowRetry = false): array
    {
        $requestStarted = hrtime(true);
        try {
            return $this->store->atomic($id, function (array $a) use ($id, $now, $requestStarted): array {
                if ($a['state'] !== 'preparing') throw new \DomainException('Capture is not writable.');
                if ($now < (int) $a['started_at'] || $now - (int) $a['started_at'] > ApprovalPolicy::CAPTURE_SECONDS) throw new \DomainException('Capture expired. Restart preparation.');
                $segment = $this->source->segment((int) $a['segment_id']);
                if (!hash_equals($a['rules_hash'], $segment['fingerprint'])) throw new \DomainException('Segment rules changed. Restart preparation.');
                $page = $this->source->scan($segment, (int) $a['cursor_id'], (int) $a['ceiling']);
                $counts = json_decode($a['counts_json'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($page['members'] as $m) $this->member($a, $m, $counts);
                if ($counts['captured'] !== array_sum(array_diff_key($counts, ['captured' => true]))) throw new \LogicException('Audience counts do not reconcile.');
                $this->store->checkpoint($id, (int) $page['next_cursor'], $counts);
                if ($page['complete']) {
                    $current = $this->source->segment((int) $a['segment_id']);
                    if (!hash_equals($a['rules_hash'], $current['fingerprint'])) throw new \DomainException('Segment rules changed.');
                    $hash = hash_init('sha256');
                    hash_update($hash, json_encode([$id, $a['generation'], $a['rules_hash'], $a['adapter_version'], $a['key_version'], $a['started_at'], $now, $counts], JSON_THROW_ON_ERROR));
                    foreach ($this->store->manifestRows($id) as $row) hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
                    $finished = $now + (int) ((hrtime(true) - $requestStarted) / 1e9);
                    if ($finished - (int) $a['started_at'] > ApprovalPolicy::CAPTURE_SECONDS) throw new \DomainException('Capture expired while finalizing.');
                    hash_update($hash, (string) $finished);
                    $this->store->complete($id, hash_final($hash), $finished);
                }
                return $this->store->audience($id);
            });
        } catch (\Throwable $e) {
            if (!$allowRetry) $this->store->fail($id);
            // No exception from a source query is copied into a log or response.
            throw new \RuntimeException('Audience preparation blocked. Refresh after checking source compatibility and capture age.', 0, $e);
        }
    }
    private function member(array $a, array $m, array &$counts): void
    {
        $id = (int) $m['customer_id'];
        $verified = empty($m['identity_warning']);
        $raw = (string) ($m['phone'] ?? '');
        $payload = ['first_name' => $verified ? trim((string) ($m['first_name'] ?? '')) : '', 'phone' => '', 'greeting' => 'Hello'];
        // Bounded encrypted evidence; never retain unverified identity or phone details.
        $payload['review_version'] = 1;
        if(M9Schema::modern()&&isset($m['sms_source_segment'])&&(int)$m['sms_source_segment']>0&&(int)$m['sms_source_segment']<M9Source::OFFSET)$payload['sms_source_segment']=(int)$m['sms_source_segment'];
        $payload['original_phone'] = $verified && strlen($raw) <= 4096 && preg_match('//u', $raw) === 1 ? $raw : '';
        $payload['source_value_withheld'] = $verified && $raw !== '' && $payload['original_phone'] === '';
        $payload['validation_reason'] = $verified ? (new PhoneDiagnostics())->inspect($raw)['reason'] : 'identity_unverified';
        $hash = null;
        if (!empty($m['identity_warning'])) $reason = 'identity_unverified';
        elseif (trim((string) ($m['phone'] ?? '')) === '') $reason = 'missing_phone';
        else {
            $n = (new EgyptMobileNormalizer())->normalize((string) $m['phone']);
            if (!$n['phone']) $reason = 'invalid_phone';
            else {
                $payload['phone'] = $n['phone'];$payload['canonical_e164'] = '+' . $n['phone'];$payload['normalization_ruleset'] = 4;
                $payload['greeting'] = $payload['first_name'] !== '' ? $payload['first_name'] : 'Hello';
                $hash = $this->crypto->correlate($n['phone']);
                if ($this->store->suppressed($hash)) $reason = 'suppressed';
                else {
                    $old = $this->store->recipient((int) $a['id'], $hash);
                    if ($old) {
                        $reason = 'duplicate';
                        $previous = $this->crypto->open($old['payload']);
                        if ($previous['first_name'] !== $payload['first_name']) {
                            $previous['first_name'] = ''; $previous['greeting'] = 'Hello';
                            $this->store->saveRecipient($a, $hash, $this->crypto->seal($previous), $this->crypto->digest($previous));
                        }
                    } else {
                        $reason = 'prepared';
                        $this->store->saveRecipient($a, $hash, $this->crypto->seal($payload), $this->crypto->digest($payload));
                    }
                }
            }
        }
        $payload['exclusion_detail'] = match($reason) {
            'identity_unverified' => 'Identity verification failed; personal details withheld',
            'missing_phone' => 'No billing phone supplied by the verified source',
            'invalid_phone' => $payload['validation_reason'],
            'suppressed' => 'Active independent SMS suppression / opt-out',
            'duplicate' => 'Same normalized number already has one prepared recipient',
            default => 'Identity checked; normalized unique phone',
        };
        $this->store->saveMember((int) $a['id'], $id, $hash, $reason, $this->crypto->seal($payload), $this->crypto->digest($payload));
        $counts['captured']++; $counts[$reason]++;
    }
}
