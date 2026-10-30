<?php
namespace Azula\SmsStudio;
/** Reverify a captured source identity without recalculating segment membership. */
interface FrozenIdentitySource { public function identity(int $sourceId):?array; }
