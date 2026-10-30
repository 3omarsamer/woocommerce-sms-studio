<?php
namespace Azula\SmsStudio;
/** Test-only dependency contract. No production transport implements it. */
interface SmsMisrOfflineTransport { public function exchange(array $request):array; }
