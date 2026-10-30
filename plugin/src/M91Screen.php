<?php
namespace Azula\SmsStudio;
final class M91Screen
{
    public static function apply(string $html):string
    {
        $advanced='';$html=preg_replace_callback('~<section class="m9-execution">.*?</section>~s',function($m)use(&$advanced){$advanced=$m[0];return '';},$html);
        foreach(['~<p id="flow-action-status".*?</p>~s','~<button id="flow-primary".*?</button>~s'] as $pattern)$html=preg_replace_callback($pattern,function($m)use(&$advanced){$advanced.=$m[0];return '';},$html);
        $simple=<<<'HTML'
<section id="m91-send"><span class="azs-kicker">FINAL CAMPAIGN</span><h3>Review once. Confirm when ready.</h3><label id="m91-schedule-budget" hidden>Maximum authorized provider units per recipient<input id="m91-schedule-ceiling" inputmode="decimal" placeholder="Enter an explicit safety ceiling"></label><p id="m91-schedule-notice" role="status"></p><dl id="m91-summary"></dl><p id="m91-status" role="status">Save your campaign review in Step 4.</p><p><a href="#" id="m91-settings">Settings &amp; Health</a></p><div class="azs-actions"><button id="m91-primary" class="azs-btn primary">Confirm &amp; Send SMS</button><button id="m91-recipients" class="azs-btn secondary" disabled>View Final Recipients</button><button id="m91-pause" class="azs-btn secondary" disabled>Pause</button><button id="m91-resume" class="azs-btn secondary" disabled>Resume</button><button id="m91-cancel" class="azs-btn secondary" disabled>Cancel unsent recipients</button></div><p>Accepted is not delivered. Submitted SMS cannot be recalled. Unknown outcomes are never retried automatically.</p><dialog id="m91-confirm"><h2>Confirm real SMS submission</h2><pre id="m91-confirm-summary"></pre><p>Real SMS messages will be submitted to SMS Misr. This approval covers only the exact frozen recipients and messages shown.</p><div class="azs-actions"><button id="m91-back" class="azs-btn secondary">Back</button><button id="m91-confirm-send" class="azs-btn primary">Confirm &amp; Send SMS</button></div></dialog><dialog id="m91-audience"><h2>Exact frozen sending list</h2><p>Protected customer information. Export only for authorized campaign review.</p><div class="azs-table-wrap"><table><thead><tr><th>Name</th><th>Original phone</th><th>Canonical</th><th>Outbound</th><th>Exact SMS</th><th>Encoding / segments</th><th>Eligibility</th></tr></thead><tbody id="m91-rows"></tbody></table></div><div class="azs-actions"><button id="m91-prev" class="azs-btn secondary">Previous</button><button id="m91-next" class="azs-btn secondary">Next</button><button id="m91-export" class="azs-btn secondary">Download final CSV</button><button id="m91-close" class="azs-btn secondary">Close</button></div></dialog></section>
HTML;
        $html=str_replace('<p id="flow-result"',$simple.'<p id="flow-result"',$html);
        $html=str_replace('<div data-view="settings" hidden>','<div data-view="settings" hidden><details id="m91-advanced" class="azs-panel"><summary>Advanced Testing · dry-run, Provider Test and legacy manual sending</summary>'.$advanced.'</details>',$html);
        $html=str_replace('Start with at most three recipients','Start with exactly one recipient',$html);
        $html=str_replace('value="3"></label><label>Seconds','value="1"></label><label>Seconds',$html);
        $html=str_replace('<label>Completed pilot run ID','<label>Conservative maximum provider units per recipient<input id="m91-policy-units" inputmode="decimal" placeholder="Required before Live authorization"></label><label>Completed pilot run ID',$html);
        return str_replace('v0.9.0','v0.9.1',$html);
    }
}
