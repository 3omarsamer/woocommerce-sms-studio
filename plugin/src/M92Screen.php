<?php
namespace Azula\SmsStudio;
final class M92Screen
{
    public static function apply(string $html):string
    {
        $legacy='';$html=preg_replace_callback('~<section id="flow-live-settings"[^>]*>.*?</section>~s',function($m)use(&$legacy){$legacy=$m[0];return '';},$html);
        $summary='<summary>Advanced Testing · dry-run, Provider Test and legacy manual sending</summary>';$html=str_replace($summary,$summary.$legacy,$html);
        $advanced='';foreach(['m9-policy-pilot','m9-policy-interval','m9-policy-ack','m91-policy-units'] as $id)$html=preg_replace_callback('~<label>[^<]*<input id="'.$id.'"[^>]*></label>~s',function($m)use(&$advanced){$advanced.=$m[0];return '';},$html);
        $html=preg_replace_callback('~<button id="m9-health"[^>]*>.*?</button>~s',function($m)use(&$advanced){$advanced.=$m[0];return '';},$html);
        $html=str_replace('<label><input type="checkbox" id="m9-policy-receipt">','<p id="m92-pilot-evidence" role="status">Checking saved one-recipient pilot evidence…</p><label><input type="checkbox" id="m9-policy-receipt">',$html);
        $html=str_replace('I confirmed actual receipt of the limited pilot','I personally received the SMS from the pilot shown above',$html);
        $html=str_replace('id="m9-policy-save" class="azs-btn secondary">Save execution policy','id="m9-policy-save" class="azs-btn primary">Save sending permissions',$html);
        $html=str_replace('<pre id="m9-health-status"','<p id="m92-settings-status" role="status"></p><p id="m92-runner-status" role="status"></p><details class="azs-panel" id="m92-technical"><summary>Advanced Diagnostics · optional execution overrides</summary>'.$advanced.'<p>The request interval defaults to 15 seconds. A provider-unit ceiling is optional; without it the engine reserves verified uncommitted balance internally. This reservation is not an estimated or confirmed charge.</p><pre id="m92-value-evidence"></pre></details><pre id="m9-health-status"',$html);
        $html=str_replace('Bulk execution activation','Sending permissions',$html);
        $html=str_replace('Independent of manual one-recipient Live. Installation leaves this OFF. Start with exactly one recipient; larger or automatic Live requires a completed pilot and explicit receipt confirmation.','Enable sending explicitly. Start with one recipient. The plugin identifies your saved accepted pilot; confirm actual receipt before enabling scheduling or raising the limit. Saving these permissions never sends SMS.',$html);
        return str_replace('v0.9.1',RC1Status::modern()?'v1.0.0 RC1':'v0.9.2',$html);
    }
}
