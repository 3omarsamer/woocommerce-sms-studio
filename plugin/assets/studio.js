/* Same-origin administration only. Controlled Test confirmation is manual; never persisted in browser storage. */
(() => {
 'use strict';
 const basic = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
 const extended = '^{}\\[~]|€\f';
 function metrics(text, recipients = 0, rate = null) {
  const chars = [...text]; let gsm = true, units = 0, unicode = 0, unsupported = false;
  for (const c of chars) { if (basic.includes(c)) units++; else if (extended.includes(c)) units += 2; else gsm = false; unicode += c.length; if (c.length === 2 || /[\x00-\x08\x0B\x0E-\x1F\x7F]/.test(c)) unsupported = true; }
  if (!gsm) units = unicode;
  let segments = units ? 1 : 0;
  if(units>(gsm?160:70)){let used=0;const limit=gsm?153:67;for(const c of chars){const cost=gsm?(extended.includes(c)?2:1):c.length;if(used+cost>limit){segments++;used=0;}used+=cost;}}
  return { characters: chars.length, units, encoding: gsm ? 'GSM-7' : 'Unicode', segments, recipients, cost_minor: rate === null ? null : segments * recipients * rate, valid: !!text.trim() && !unsupported && segments <= 10, unsupported };
 }
 function validationError(text,language=null){const m=metrics(text);if(!text.trim())return 'Enter the final SMS in Step 3.';if(/[\u{10000}-\u{10FFFF}]/u.test(text))return 'Emoji/non-BMP characters are not verified for SMS Misr and block final review. Your draft is preserved unchanged. Explicitly edit Step 3 to supported BMP text (including Arabic), then save and review again. No SMS was authorized.';if(m.unsupported)return 'Unsupported control characters block final review. Explicitly correct the Step 3 draft; its content has not been altered.';if(m.segments>10)return 'The final SMS exceeds the ten estimated segment limit. Shorten it explicitly in Step 3.';if(Number(language)===1&&m.encoding!=='GSM-7')return 'This message needs Arabic (2) or Unicode (3), not English (1). Choose the matching language before final review.';return '';}
 function schedulingEligibility({campaign,blocked=false,busy=false,local='',offset='',confirmedLocal='',checking=false,error=''}){
  if(blocked)return 'schema_required';
  if(busy)return 'campaign_busy';
  if(!campaign || !Number.isSafeInteger(Number(campaign.id)) || Number(campaign.id)<1 || !Number.isSafeInteger(Number(campaign.revision)) || Number(campaign.revision)<1)return 'campaign_required';
  if(!local)return 'egypt_time_required';
  if(checking)return 'egypt_time_checking';
  if(error)return error;
  if(!offset || confirmedLocal!==local)return 'egypt_occurrence_required';
  return 'ready_to_plan';
 }
 function formatPoints(value){const text=String(value),parts=text.split('.');if(!/^[0-9]+$/.test(parts[0])||parts.length>2||(parts.length===2&&!/^[0-9]+$/.test(parts[1]))||/[\r\n]/.test(text))return 'Unavailable';return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,',')+(parts.length===2?'.'+parts[1]:'');}
 if (typeof module !== 'undefined' && module.exports) module.exports = { metrics, schedulingEligibility, formatPoints, validationError };
 if (typeof document === 'undefined') return;
 const root = document.getElementById('azula-studio'); if (!root) return;
 const $ = s => root.querySelector(s), all = s => [...root.querySelectorAll(s)], config = window.AzulaStudio;
 const draftToken = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');
 const state = { campaign: null, audience: null, history: null, historical: {}, rate: null, dirty: false, tab: 'ready', cursor: 0, next: 0, step: 0, segments: [], selection: 0, detailsRequest: 0, busy: false };
 state.draftToken = draftToken();
 state.category='ready';state.page=1;
 const labels = { captured: 'Captured customers', prepared: 'Unique recipients', missing_phone: 'Missing phone', invalid_phone: 'Invalid phone', duplicate: 'Duplicates', suppressed: 'SMS suppressed', identity_unverified: 'Identity unverified' };
 const human = v => ({ ready: 'Ready', prepared: 'Ready', invalid_phone: 'Invalid phone', missing_phone: 'Missing phone', duplicate: 'Duplicate', suppressed: 'SMS opt-out', identity_unverified: 'Identity unverified', preparing: 'Validating', review_ready: 'Audience ready', pending_review: 'Saved for review', blocked: 'Needs attention', complete: 'Complete' }[v] || v);
 const put = (sel, value) => { $(sel).textContent = String(value); };
 const date = t => new Date(t * 1000).toLocaleString('en-GB', {timeZone:'Africa/Cairo'}) + ' · Egypt';
 function notice(text = '') { put('#azs-notice', text); $('#azs-notice').hidden = !text; }
 async function api(op, data = {}) {
  const body = new URLSearchParams({ action: 'azula_sms_m2', nonce: config.nonce, op, ...data });
  const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
  let result; try { result = await response.json(); } catch { throw new Error('The server did not return a valid response. Check the connection and preparation health.'); }
  if (!response.ok || !result.success) { if(result.data?.schema)renderSchema(result.data); throw new Error(result.data?.message || 'Request unavailable. Your campaign was not sent.'); }
  return result.data;
 }
 async function act(fn) { try { await fn(); } catch (e) { notice(e.message); } }
 function renderSchema(result){
  if(!result.schema && result.needs_upgrade===undefined)return;
  const s=result.schema;if(s)state.schemaStatus=s;state.needsUpgrade=result.needs_upgrade===true || !!(s&&!s.ready);$('#schema-setup').hidden=!state.needsUpgrade;
  if(s)put('#schema-status',`Stored SMS schema: ${s.stored_version} · Required: ${s.required_version} · Tables, columns and indexes: ${s.structure_verified?'verified':'not verified'} · ${s.state}`);
  else put('#schema-status','An SMS database upgrade is required. Recheck schema to inspect the stored checkpoint and table structure.');
  $('#schema-upgrade').disabled=state.schemaBusy||!s?.can_upgrade||s.ready;
 }
 $('#schema-check').addEventListener('click',()=>act(async()=>{const result=await api('schema_status');renderSchema(result);updateHealth(result.health);if(result.schema?.ready)location.reload();}));
 $('#schema-upgrade').addEventListener('click',()=>act(async()=>{
  if(state.schemaBusy)return;state.schemaBusy=true;$('#schema-upgrade').disabled=true;$('#schema-check').disabled=true;put('#schema-result','Upgrading and verifying SMS-owned tables. Existing data will be retained…');
  try{const result=await api('upgrade');renderSchema(result);if(!result.schema?.ready)throw new Error('Schema verification did not pass. Campaign operations remain blocked.');put('#schema-result','SMS schema verified. Reloading saved segments and campaigns…');location.reload();}
  catch(e){put('#schema-result',e.message);throw e;}
  finally{state.schemaBusy=false;$('#schema-check').disabled=false;renderSchema({schema:state.schemaStatus,needs_upgrade:state.needsUpgrade});}
 }));
 function changeUrl(id) { const url = new URL(location.href); if (id) url.searchParams.set('campaign', id); else url.searchParams.delete('campaign'); history.replaceState(null, '', url); }
 function workspace(view){
  if(!['campaigns','builder','audience','activity','analytics','provider','settings',...(config.m9?['retargeting']:[])].includes(view))view='campaigns';
  state.view=view;all('[data-view]').forEach(p=>p.hidden=p.dataset.view!==view);all('[data-nav]').forEach(b=>{b.classList.toggle('active',b.dataset.nav===(view==='builder'?'campaigns':view));b.setAttribute('aria-current',b.dataset.nav===(view==='builder'?'campaigns':view)?'page':'false');});
  const titles={campaigns:config.m61?'Campaigns.':'Your next campaign starts here.',builder:'Create a campaign.',audience:'Every recipient, accounted for.',activity:'Campaign Activity & History',analytics:'Every click. A clearer picture.',provider:'Your SMS Misr account.',settings:'A healthy workspace.',retargeting:'Your next audience starts here.'};$('.azs-pagehead h1').textContent=titles[view];$('#new-campaign').hidden=view!=='campaigns';
  const names={campaigns:'Campaigns',builder:'Campaign builder',audience:'Audience Review',activity:'Campaign Activity & History',analytics:'Analytics',provider:'SMS Misr',settings:'Settings & Health',retargeting:'Audience Retargeting'};$('.azs-top>span').textContent='Workspace / '+names[view];
  const url=new URL(location.href);url.searchParams.set('view',view);history.replaceState(null,'',url);
  if(view==='activity'&&config.m5)act(()=>activity());
  root.dispatchEvent(new CustomEvent('azula:view',{detail:view}));
 }
 function showStep(index) { state.step = index; if(config.m61&&index===1)state.reviewOverride=null;workspace(config.m61?'builder':index===1?'audience':'campaigns');all('[data-panel]').forEach(p => p.hidden = Number(p.dataset.panel) !== index); all('[data-step]').forEach(b => { const active = Number(b.dataset.step) === index; b.classList.toggle('current', active); if (active) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current'); }); render(); if (index === 1 && state.audience) act(() => details(1)); }
 function updateHealth(health) {
  if (!health) return;
  state.health=health;
  const disabled = health.wp_cron_disabled ? ' WP Cron is disabled.' : '';
  put('#scheduler-health', !health.available ? 'Action Scheduler is unavailable. Preparation is waiting.' : health.verified_recently ? 'Runner observed executing recently.' + disabled : (health.probe_requested ? 'Probe queued; execution not yet verified.' : 'Background execution has not been verified.') + disabled + ' A working host runner is required.');
  put('#schedule-health',health.schedule_worker ? `Scheduling worker last observed ${date(health.schedule_worker)}. Sending remains disabled.` : 'Scheduling worker not yet observed. An external host runner is required when WP Cron is disabled.');
 }
 function load(result, restoreMessage = false) {
  const previouslyPreparing=state.audience?.state==='preparing';
  updateHealth(result.health);
  if (result.rate_minor !== undefined) state.rate = result.rate_minor;
  renderSchema(result);
  if (result.needs_upgrade) { notice('Complete the SMS database setup above. Saved segments are browse-only until verification passes.'); return; }
  if (result.campaign) {
   if(state.campaign?.id!==result.campaign.id){state.attempts=null;state.events=[];}
   if(state.campaign?.id!==result.campaign.id||state.campaign?.revision!==result.campaign.revision||state.audience?.id!==result.audience?.id){state.confirmToken=null;state.controlled=null;}
   if (state.campaign && result.campaign.id === state.campaign.id && result.campaign.revision < state.campaign.revision) return;
   state.campaign = result.campaign; state.audience = result.audience; state.schedule=result.schedule||null; changeUrl(state.campaign.id);
   if(restoreMessage){
    timeRequest++;const s=state.schedule;$('#schedule-local').value=s?s.egypt_time.slice(0,16).replace(' ','T'):'';
    state.confirmedLocal=$('#schedule-local').value;state.scheduleTimeChecking=false;state.scheduleTimeError='';
    const option=document.createElement('option');option.value=s?s.egypt_time.slice(17,23):'';option.dataset.utc=s?String(s.start_utc):'';option.textContent=s?s.egypt_time:'Choose a date and time first';$('#schedule-offset').replaceChildren(option);$('#schedule-offset').disabled=!s;
   }
   if (restoreMessage && !state.dirty){$('#sms-message').value = result.message || '';if(config.m6)$('#m6-track').checked=(/[?&]azula_sms_link=(?:[a-f0-9]{32}|[A-Za-z0-9_-]{22}|[A-Za-z0-9_-]{10})(?![A-Za-z0-9_-])/.test(result.message||'')||/https:\/\/(?:preview\.)?azulasportswear\.com\/[a-z][a-z0-9-]{0,23}\/(?:[a-f0-9]{32}|[A-Za-z0-9_-]{22}|[A-Za-z0-9_-]{10})(?![A-Za-z0-9_-])/.test(result.message||''));}
   put('#campaign-ref', `Campaign #${state.campaign.id} · v${state.campaign.revision}`);
   if(!state.segments.some(s=>Number(s.id)===state.campaign.segment_id)){const option=document.createElement('option');option.value=state.campaign.segment_id;option.textContent=`Saved segment #${state.campaign.segment_id}`;$('#segment-select').append(option);state.segments.push({id:state.campaign.segment_id,name:option.textContent});}
   $('#segment-select').value = String(state.campaign.segment_id);
  }
  render();
  if(previouslyPreparing && state.audience?.state==='complete' && state.step===1)act(()=>details(1));
  if(previouslyPreparing && state.audience?.state==='complete' && state.view==='activity' && config.m5)act(()=>activity());
 }
 function reviewContext(){return state.reviewOverride||state;}
 function render() {
  renderSchedule();
  $('#new-campaign').disabled=!!(state.busy||state.scheduleBusy);
  const a = state.audience, counts = a?.counts || {}, complete = a?.state === 'complete', text = $('#sms-message').value;
  put('#ov-captured', a ? counts.captured : '—'); put('#ov-ready', a ? counts.prepared : '—'); put('#ov-excluded', a ? counts.captured - counts.prepared : '—'); put('#ov-state', state.dirty ? 'Unsaved changes' : (state.schedule ? scheduleLabel(state.schedule.state) : state.campaign ? (state.campaign.display_status||human(state.campaign.state)) : 'Draft'));
  put('#draft-status', a ? `${complete ? 'Completed capture' : 'Partial capture'} · ${counts.captured} records` : 'Your campaign, at your pace.');
  const ra=reviewContext().audience,rc=ra?.counts||{},rd=ra?.state==='complete';
  const prep = $('#preparation'); prep.classList.toggle('busy', ra?.state === 'preparing');
  prep.textContent = !ra ? 'Select a segment to begin fresh validation.' : ra.state === 'preparing' ? `Validating in the background · ${rc.captured} customers captured so far. These are partial counts, not the final audience. Last progress: ${date(ra.last_progress || ra.started)}.${ra.retries ? ` Retry ${ra.retries}/3 pending.` : ''}` : rd ? `Validation complete · ${rc.captured} captured records · ${rc.prepared} unique recipients. ${ra.stale ? 'This audience is stale: prepare a fresh generation before saving for approval.' : 'Captured ' + date(ra.completed) + '.'}` : 'Preparation blocked or expired. Review health, then explicitly prepare a fresh audience. Partial results cannot be approved.';
  if(ra?.state==='preparing' && Date.now()/1000-(ra.last_progress||ra.started)>60){prep.classList.remove('busy');prep.textContent=`Preparation appears stalled · ${rc.captured} records captured so far. No recent batch progress. Check the runner below; composing can continue. The original 30-minute capture deadline still applies.`;}
  const focusedCategory=$('#audience-counts').contains(document.activeElement)?document.activeElement.dataset.category:null;
  $('#audience-counts').replaceChildren();
  for (const [key, label] of Object.entries(labels)) { const card = document.createElement('button'), number = document.createElement('strong'), title = document.createElement('span'); card.type='button';card.dataset.category=key==='captured'?'all':key==='prepared'?'ready':key;card.disabled=!ra;number.textContent = ra ? rc[key] || 0 : '—'; title.textContent = label; card.append(number, title);card.addEventListener('click',()=>filterReview(card.dataset.category,true)); $('#audience-counts').append(card); }
  if(focusedCategory)$('#audience-counts').querySelector(`[data-category="${focusedCategory}"]`)?.focus({preventScroll:true});
  put('#ready-total', rc.prepared || 0); put('#excluded-total', (rc.captured || 0) - (rc.prepared || 0));
  const m = metrics(text, counts.prepared || 0, state.rate);
  put('#char-count', m.characters); put('#unit-count', m.units); put('#encoding', m.encoding); put('#segment-count', m.segments); put('#recipient-estimate', a ? m.recipients : '—'); put('#estimate-kind', complete ? '· captured' : '· provisional');
  put('#cost-estimate', m.cost_minor === null ? 'Not configured' : `EGP ${(m.cost_minor / 100).toFixed(2)}`); put('#message-preview', text || 'Your message will appear here.');
  put('#message-warning', config.hotfix&&validationError(text)?validationError(text):m.unsupported ? 'Unsupported characters detected (including non-BMP emoji). Remove them before review. Provider support is unverified.' : m.segments > 10 ? 'This review build allows up to 10 estimated SMS segments.' : 'Standard SMS estimates only. Final provider encoding and billing are unverified; no text is silently rewritten.');
  const selected=state.segments.find(item=>Number(item.id)===state.campaign?.segment_id);
  const summary = !state.campaign ? 'Select a segment and compose your message to review this campaign.' : `Campaign #${state.campaign.id} · Revision ${state.campaign.revision}\nSegment: ${selected?.name||'Saved segment'} (#${state.campaign.segment_id}) · Original customers: ${selected?.customer_count??'Unavailable'}\n${complete ? 'Completed audience' : 'Partial audience'}: ${counts.captured || 0} captured · ${counts.prepared || 0} unique recipients\n${m.encoding} · ${m.segments} SMS segments per recipient\n${m.cost_minor === null ? 'Pricing not configured' : 'Estimated cost: EGP ' + (m.cost_minor / 100).toFixed(2)}\nStandard encoding estimate; provider Unicode billing remains unverified.\n\n${text || 'Message is empty.'}`;
  put('#review-summary', summary); put('#final-count', !complete ? 'Fresh validation must complete first. You can keep composing while it runs.' : `${counts.prepared} unique recipients in this captured audience.\n${a.stale ? 'Stale audience — refresh required.' : 'Saving for review does not authorize sending.'}\n${m.segments} estimated segments per recipient.`);
  $('#save-review').disabled = !(complete && !a.stale && a.evidence_available && counts.prepared > 0 && m.valid && state.campaign && !state.busy && !state.savingReview);
  $('#download-csv').disabled=!(rd&&reviewContext().campaign&&!state.exporting&&!state.reviewLoading&&(!config.m61||!!state.loadedSpec));
  root.dispatchEvent(new CustomEvent('azula:state'));
 }
 function renderHistory(h) { state.history = h; const box = $('#historical'); box.hidden = !h; if (h) box.textContent = `HISTORICAL ${h.complete ? 'COMPLETED AUDIENCE' : 'PARTIAL CAPTURE'} · ${date(h.at)}\n${h.counts.captured} captured · ${h.counts.prepared} prepared unique recipients. Reference only — fresh validation is required and has not inherited these recipients.`; }
 async function select(fresh = false) {
  if(config.m61&&window.AzulaStudioFlow)return window.AzulaStudioFlow.select(fresh);
  if(state.needsUpgrade){notice('This segment is browse-only until the SMS database upgrade is verified. Use Complete SMS database upgrade above.');return;}
  const segment = $('#segment-select').value; if (!segment || state.busy) return;
  const request = ++state.selection; state.busy = true; $('#segment-select').disabled = true; notice('');
  state.audience=null;state.detailsRequest++;put('#recipient-rows','Waiting for fresh captured records.');render();
  renderHistory(state.historical[segment] || null);
  try {
   // Start both requests immediately. Historical data never delays fresh preparation.
   const historical = api('history', { segment }).then(r => { if (request === state.selection) renderHistory(r.history); }).catch(() => { if (request === state.selection) renderHistory(null); });
   const result = await api('select', { segment, campaign: state.campaign?.id || 0, fresh: fresh ? '1' : '0', draft_token: state.draftToken });
   if (request === state.selection) { load(result); notice('Fresh audience validation started. You can compose while it continues.'); }
   await historical;
  } finally { state.busy = false; $('#segment-select').disabled = false; render(); }
 }
 async function saveMessage() {
  if(state.messageSave){await state.messageSave;if(state.dirty)return saveMessage();return;}
  const pending=writeMessage();state.messageSave=pending;
  try{await pending;}finally{if(state.messageSave===pending)state.messageSave=null;}
 }
 async function writeMessage() {
  if(config.m61&&window.AzulaStudioFlow)await window.AzulaStudioFlow.ensureSetup();
  if (!state.campaign) throw new Error('Choose a segment first. Composition can continue while its audience is preparing.');
  if (!state.dirty) return;
  const sent=$('#sms-message').value, id=state.campaign.id;
  const result = await api(config.m6?'m6_message':'message', { campaign: id, revision: state.campaign.revision, message: sent,tracking:$('#m6-track')?.checked?'1':'0' });
  if(state.campaign?.id!==id)return;
  state.dirty = $('#sms-message').value!==sent;if(config.m6&&!state.dirty)$('#sms-message').value=result.message||''; load(result); put('#draft-status', state.dirty?'Newer unsaved changes remain':'Draft saved · not approved for sending');
 }
 function reviewSpec(page=1){return {category:state.category,search:$('#audience-search').value,sort:$('#audience-sort').value,direction:$('#audience-direction').value,reason:$('#audience-reason').value,page:String(Math.max(1,page))};}
 function filterReview(category,clear=false){
  state.category=category;state.tab=category==='ready'?'ready':category==='all'?'all':'excluded';$('#audience-filter').value=category;
  $('#audience-reason').value='';
  if(clear){$('#audience-search').value='';$('#audience-sort').value='captured';$('#audience-direction').value='asc';}
  all('[data-tab]').forEach(t=>{const active=t.dataset.tab===state.tab;t.classList.toggle('active',active);t.setAttribute('aria-selected',active?'true':'false');});if(config.m61&&state.view==='audience'){render();act(()=>details(1));}else showStep(1);
 }
 async function details(page=1) {
  if (!reviewContext().audience) return;
  const token = ++state.detailsRequest, audience = reviewContext().audience.id, tab = state.tab, spec=reviewSpec(page);
  state.reviewLoading=true;state.loadedSpec=null;put('#recipient-rows','Loading selected audience filter…');$('#next-page').disabled=true;render();
  try{const result = await api('details', { campaign: reviewContext().campaign.id, audience, tab, ...spec });
  if (token !== state.detailsRequest || reviewContext().audience?.id !== audience || state.tab !== tab || JSON.stringify({...spec,page:'1'})!==JSON.stringify(reviewSpec(1))) return;
  if(config.m61&&(result.category!==spec.category||Number(result.audience)!==audience||Number(result.campaign)!==reviewContext().campaign.id))throw new Error('Audience response did not match the requested filter or snapshot. No rows were displayed.');
  if(config.m61&&(!Array.isArray(result.rows)||result.rows.some(row=>{const c=row.classification;if(!['prepared','invalid_phone','missing_phone','duplicate','suppressed','identity_unverified'].includes(c))return true;return spec.category==='ready'?c!=='prepared':spec.category==='excluded'?c==='prepared':spec.category!=='all'&&c!==spec.category;})))throw new Error('Audience rows did not match the selected status. No unrelated rows were displayed.');
  state.page=result.page||1;state.next=state.page+1;$('#next-page').disabled=!result.more;
  const reasons=$('#audience-reason');reasons.replaceChildren();const any=document.createElement('option');any.value='';any.textContent='All reasons';reasons.append(any);for(const value of new Set([...(result.reason_options||[]),...(spec.reason?[spec.reason]:[])])){const option=document.createElement('option');option.value=value;option.textContent=value;reasons.append(option);}reasons.value=spec.reason;
  const body = $('#recipient-rows'); body.replaceChildren();
  for (const row of result.rows) { const tr = document.createElement('tr'); const classification=row.classification||row.reason;let explanation=row.detail||row.reason;if(row.retained_phone)explanation+=` · Retained recipient: ${row.retained_name} (${row.retained_phone})`;for (const value of [row.name, row.phone, human(classification), row.withheld ? 'Oversized or invalid-encoding source value withheld' : explanation]) { const td = document.createElement('td'); td.textContent = String(value); tr.append(td); } body.append(tr); }
  if (!result.rows.length) { const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 4; td.textContent = result.complete ? 'No records in this tab.' : 'No records captured for this tab yet. Validation is still in progress.'; tr.append(td); body.append(tr); }
  put('#page-label', `Page ${state.page} of ${result.pages||1} · ${result.rows.length} rows · ${result.complete ? 'completed capture' : 'partial capture'}`);
  put('#filter-summary',`${result.total??result.rows.length} matching records · ${human(state.category)} · generation ${result.generation||reviewContext().audience.generation}. CSV includes all matching pages; identity-unverified values stay withheld.`);
  state.loadedSpec=`${reviewContext().campaign.id}/${audience}/${JSON.stringify(reviewSpec(1))}`;state.reviewLoading=false;render();
  }catch(e){if(token===state.detailsRequest){state.reviewLoading=false;state.loadedSpec=null;put('#recipient-rows','Selected audience filter could not be loaded. Retry; no previous rows are being shown.');put('#filter-summary','Filter unavailable — export disabled.');$('#download-csv').disabled=true;render();}throw e;}
 }
 async function remainingSegments(items) {
  while (items.length === 100) {
   const after = Number(items.at(-1).id);
   const result = await api('segments', { after });
   items = result.segments;
   if (!Array.isArray(items) || items.some(item => Number(item.id) <= after)) throw new Error('Saved segment listing did not progress. Reload the selector to retry.');
   Object.assign(state.historical, result.historical || {}); segments(items);
  }
 }
 const scheduleLabel = value => ({planned:'Scheduled · awaiting final capture',final_preparing:'Final audience validating',awaiting_approval:'Final approval required',queue_preparing:'Preparing held queue',scheduled_approved:'Approved · scheduled',due_verifying:'Checking final suppressions',ready_disabled:'Due · sending disabled',paused:'Paused · approval revoked',canceled:'Schedule canceled',missed:'Scheduled deadline missed',needs_review:'Review required'}[value]||human(value));
 function renderSchedule(){
  const s=state.schedule,c=state.campaign,a=state.audience,locked=!!(state.busy||state.scheduleBusy);
  const reason=schedulingEligibility({campaign:c,blocked:state.needsUpgrade,busy:locked,local:$('#schedule-local').value,offset:$('#schedule-offset').value,confirmedLocal:state.confirmedLocal,checking:state.scheduleTimeChecking,error:state.scheduleTimeError});
  const segment=state.segments.find(item=>Number(item.id)===Number(c?.segment_id));
  const context=c?`Campaign #${c.id} · Revision ${c.revision}\nSegment: ${segment?.name||'Saved segment'} (#${c.segment_id})\nCaptured: ${a?.counts.captured??0} · Ready unique recipients: ${a?.counts.prepared??0}\n${c.review_hash?'Saved review package present; explicit final approval is still required.':'Review package not yet saved; scheduling is not approval.'}`:'';
  const option=$('#schedule-offset').selectedOptions[0],selectedUtc=Number(option?.dataset.utc||0);
  put('#timing-review',selectedUtc?`Verified Egypt execution: ${date(selectedUtc)}\nAutomatic final validation: ${date(Math.max(Date.now()/1000,selectedUtc-3600))}\nFinal approval deadline: ${date(selectedUtc)}\nExecution stored as UTC. Exact persisted times appear in Campaign Status & Activity after saving.`:'Choose a date and verify its Egypt occurrence. No scheduling or sending approval exists yet.');
  const approval=s?.approved?'Approved for held queue only':s?.state==='awaiting_approval'?'Final approval required':c?.review_hash?'Review saved; queue not approved':'Not approved';
  const worker=Math.max(state.health?.schedule_worker||0,state.health?.last_worker||0);
  put('#activity-details',c?`${c.name} · Campaign #${c.id} · Revision ${c.revision}\nSegment: ${segment?.name||'Saved segment'} (#${c.segment_id}) · Original customers: ${segment?.customer_count??'Unavailable'}\nValidated SMS recipients: ${a?.counts.prepared??0}${a?.state!=='complete'?' (partial capture)':''}\nSchedule: ${s?.egypt_time||'Not scheduled'}\nFinal validation: ${s&&s.final_audience_id===a?.id?(a?.state==='complete'?'Complete':'Preparing')+' · '+(a?.counts.captured||0)+' captured':s?'Waiting for '+s.prepare_egypt:'Not scheduled'}\nApproval: ${approval}\nLast background worker: ${worker?date(worker):'Not observed'}`:'Open a campaign to view its activity.');
  put('#activity-held',s?.queue.held_disabled||0);put('#activity-submitted',(state.attempts||[]).filter(a=>a.state==='accepted').length);put('#activity-delivered',config.m5?'Not verified':0);put('#activity-failed',state.attempts?(state.attempts.filter(a=>a.state==='rejected').length):'Not available');
  $('#provider-queue-preview').disabled=!state.provider?.configured||!s?.approved||state.providerBusy;
  put('#schedule-state',s?scheduleLabel(s.state):'Not scheduled');
  put('#schedule-summary',s?`${context}\n${s.egypt_time}\nFinal preparation: ${s.prepare_egypt}\n${scheduleLabel(s.state)}\nHeld unique entries: ${s.queue.held_disabled||0} · Newly suppressed: ${s.queue.suppressed||0}\nSubmitted: 0 · Delivered: 0 · Sending disabled\nLast progress: ${s.last_progress?date(s.last_progress):'Not observed'} · Retries: ${s.retry_count||0}\n${s.error_code ? 'Attention: '+s.error_code : 'Queue approval does not authorize SMS sending.'}`:c?`${context}\nNo schedule yet. Choose an Egypt date and time in Step 5 to plan this existing campaign.`:'Select a segment and save a message to plan a campaign.');
  const reasons={schema_required:'Complete SMS database verification before scheduling.',campaign_busy:'Campaign operation in progress. Please wait.',campaign_required:'Open an existing campaign or select a segment first.',egypt_time_required:'Campaign loaded. Choose an Egypt date and time to enable scheduling.',egypt_time_checking:'Checking the selected Egypt minute against Africa/Cairo rules…',egypt_occurrence_required:'Select a verified Egypt time occurrence. Repeated DST minutes require an explicit choice.',egypt_time_invalid:'This Egypt minute does not exist. Choose another date and time.',egypt_time_check_failed:'Egypt time verification failed. Re-enter the date/time to retry.',ready_to_plan:'This campaign can be scheduled. Scheduling invalidates the old review binding and does not approve sending.'};
  put('#schedule-eligibility',reasons[reason]||'Verify the Egypt date and time before scheduling.');
  Object.assign($('#schedule-summary').dataset,{campaignId:String(c?.id||''),revision:String(c?.revision||''),scheduleExists:String(!!s),reviewSaved:String(!!c?.review_hash),eligibility:reason});
  $('#schedule-save').disabled=reason!=='ready_to_plan';$('#schedule-local').disabled=locked||!c||state.needsUpgrade===true;
  $('#schedule-pause').disabled=locked||!s||['paused','canceled','missed'].includes(s.state);
  $('#schedule-resume').disabled=locked||!s||s.state!=='paused';
  $('#schedule-cancel').disabled=locked||!s||['canceled','missed'].includes(s.state);
  $('#schedule-refresh').disabled=locked||!s||['paused','canceled'].includes(s.state)||s.start_utc<=Date.now()/1000||s.start_utc-Date.now()/1000>3600;
  $('#schedule-review').disabled=locked||!a||!s||s.final_audience_id!==a.id;
  $('#schedule-approve').disabled=locked||!s||s.state!=='awaiting_approval'||!a||a.id!==s.final_audience_id||a.state!=='complete'||a.stale||!c.review_hash||state.dirty;
  $('#schedule-approve').textContent=`Approve queue preparation only${a&&s&&a.id===s.final_audience_id?' · '+a.counts.prepared+' recipients':''}`;
 }
 let timeRequest=0;
 function resetScheduleTime(){clearTimeout(timeTimer);timeRequest++;state.confirmedLocal='';state.scheduleTimeChecking=false;state.scheduleTimeError='';$('#schedule-local').value='';$('#schedule-offset').replaceChildren();$('#schedule-offset').disabled=true;}
 async function resolveScheduleTime(){
  const request=++timeRequest,local=$('#schedule-local').value,campaign=state.campaign?.id;state.confirmedLocal='';state.scheduleTimeError='';state.scheduleTimeChecking=!!local;$('#schedule-offset').replaceChildren();$('#schedule-offset').disabled=true;renderSchedule();
  if(!local){state.scheduleTimeChecking=false;renderSchedule();return;}
  try{
  const result=await api('schedule_choices',{local});if(request!==timeRequest||campaign!==state.campaign?.id)return;
  const choices=result.choices;
  if(choices.length!==1){const option=document.createElement('option');option.value='';option.textContent=choices.length?'Choose the DST occurrence…':'Nonexistent Egypt time — choose another minute';$('#schedule-offset').append(option);}
  for(const choice of choices){const option=document.createElement('option');option.value=choice.offset;option.dataset.utc=String(choice.utc);option.textContent=choice.label;$('#schedule-offset').append(option);}
  $('#schedule-offset').disabled=!choices.length;
  state.confirmedLocal=local;state.scheduleTimeError=choices.length?'':'egypt_time_invalid';
  put('#schedule-time-help',choices.length===2?'This Egypt minute occurs twice. Select the intended occurrence explicitly.':choices.length?'Verified using Africa/Cairo timezone rules. Execution is stored in UTC.':'This local minute is skipped by Egyptian daylight saving time.');
  }catch(e){if(request===timeRequest){state.scheduleTimeError='egypt_time_check_failed';put('#schedule-time-help','Could not verify this Egypt time. Re-enter it to retry.');}throw e;}
  finally{if(request===timeRequest){state.scheduleTimeChecking=false;renderSchedule();}}
 }
 let timeTimer;
 $('#schedule-local').addEventListener('input',()=>{clearTimeout(timeTimer);timeRequest++;state.confirmedLocal='';state.scheduleTimeError='';state.scheduleTimeChecking=!!$('#schedule-local').value;$('#schedule-offset').replaceChildren();$('#schedule-offset').disabled=true;renderSchedule();timeTimer=setTimeout(()=>act(resolveScheduleTime),200);});
 $('#schedule-local').addEventListener('change',()=>{clearTimeout(timeTimer);act(resolveScheduleTime);});
 $('#schedule-offset').addEventListener('change',renderSchedule);
 async function scheduleAction(op){
  if(state.scheduleBusy||!state.campaign)return;
  state.scheduleBusy=true;render();
  try{
   if(op==='schedule')await saveMessage();
   if(state.dirty)throw new Error('Save and review your current message before changing scheduling or approval.');
   const data={campaign:state.campaign.id,revision:state.campaign.revision,version:state.schedule?.version||0};
   if(op==='schedule'){data.local=$('#schedule-local').value;data.offset=$('#schedule-offset').value;}
   if(op==='schedule_approve')data.confirm='prepare_queue_only';
   const result=await api(op,data);load(result);
   if(op==='schedule'&&!state.dirty&&state.audience?.state==='complete'&&!state.audience.stale&&state.audience.evidence_available&&state.audience.counts.prepared>0&&metrics($('#sms-message').value).valid){try{load(await api('review',{campaign:state.campaign.id,revision:state.campaign.revision}));}catch{notice('Schedule saved. Current review package could not be saved; fresh final validation and review remain required.');}}
   put('#schedule-action-result',op==='schedule_approve'?'Explicit approval recorded for held queue preparation only. Sending remains disabled.':op==='schedule'?'Campaign and schedule saved. Fresh final validation and explicit approval are still required.':'Schedule updated. Review its status and Egypt time above.');
  }finally{state.scheduleBusy=false;render();}
 }
 for(const [selector,op] of [['save','schedule'],['pause','schedule_pause'],['resume','schedule_resume'],['cancel','schedule_cancel'],['refresh','schedule_refresh'],['approve','schedule_approve']])$('#schedule-'+selector).addEventListener('click',()=>act(async()=>{
  if(['schedule_cancel','schedule_refresh','schedule_approve'].includes(op)&&!confirm(op==='schedule_approve'?'Approve this exact final audience and message for held queue preparation only? No SMS will be sent.':op==='schedule_refresh'?'Replace the final audience and revoke any existing approval? A new review and approval will be required.':'Cancel this schedule and revoke approval?'))return;
  await scheduleAction(op);
 }));
 $('#schedule-review').addEventListener('click',()=>{filterReview('all',true);showStep(1);root.scrollIntoView({behavior:'smooth'});});
 function providerView(p,fill=false){
  state.provider=p;
  const valid=p.balance_verified&&p.balance!==null;
  put('#points-balance',valid?formatPoints(p.balance):'Unavailable');
  put('#points-connection',p.connection_status==='verified'?'Connected':!p.configured||p.connection_status==='not_checked'?'Disconnected':'Error');put('#points-environment',p.environment===2?'Test (2)':'Live (1) — blocked');put('#points-updated',valid&&p.last_balance_check?date(p.last_balance_check):'Not verified');
  if(fill){$('#provider-username').value=p.username||'';$('#provider-sender').value=p.sender||'';$('#provider-environment').value=String(p.environment);}
  put('#provider-status',`${p.configured?'Configured':'Not configured'} · ${p.connection_status} via POST Balance · ${p.environment===2?'Test':'Live'} selected · ${config.m6?'Bulk & automatic Live BLOCKED; explicit owner-gated confirmation only':'Automatic sending OFF · Live BLOCKED'}`);
  put('#provider-secret-status',p.password_saved?'Encrypted password is saved. It is never returned to this page. Leave the password field blank to retain it.':'No encrypted password saved.');
  put('#provider-result',`Provider status: ${p.last_status}${p.last_code?' ('+p.last_code+')':''}\nLast HTTP status: ${p.last_http_status||'Not checked'}\nLast successful connection: ${p.last_success?date(p.last_success):'Not verified'}\nLast balance check: ${p.last_balance_check?date(p.last_balance_check):'Not checked'}\n${p.balance_verified&&p.balance!==null?'Provider account balance: '+p.balance+' · verified response; provider units (not an EGP assumption).':'Balance unavailable or not verified.'}\nConnection tests use only POST balance inquiry. No SMS is sent. Acceptance never means delivery.`);
  for(const id of ['#provider-test','#provider-balance','#provider-diagnostic'])$(id).disabled=state.providerBusy||!p.configured;
  $('#provider-save').disabled=!!state.providerBusy;$('#provider-reload').disabled=!!state.providerBusy;renderSchedule();
 }
 async function providerAction(op){
  if(op==='provider_save'){state.confirmToken=null;state.controlled=null;controlledView();}
  if(state.providerBusy)return;state.providerBusy=true;if(state.provider)providerView(state.provider);
  try{const data=op==='provider_save'?{revision:String(state.provider?.revision||0),username:$('#provider-username').value,password:$('#provider-password').value,sender:$('#provider-sender').value,environment:$('#provider-environment').value}:{};const r=await api(op,data);providerView(r.provider,true);}
  finally{if(op==='provider_save')$('#provider-password').value='';state.providerBusy=false;if(state.provider)providerView(state.provider);}
 }
 $('#provider-save').addEventListener('click',()=>act(()=>providerAction('provider_save')));
 for(const id of ['#provider-test','#provider-balance'])$(id).addEventListener('click',()=>act(()=>providerAction('provider_probe')));
 $('#provider-diagnostic').addEventListener('click',()=>act(async()=>{if(state.providerBusy)return;state.providerBusy=true;if(state.provider)providerView(state.provider);put('#provider-diagnostic-result','Checking Balance with POST…');try{const r=await api('provider_diagnostic');const d=r.balance_diagnostic;put('#provider-diagnostic-result',`Method: ${d.request_method}\nHTTP status: ${d.http_status||'Unavailable'}\nFormat: ${d.response_format}\nSanitized response (balance replaced with 5000):\n${d.sanitized_body}\nSending remains disabled.`);}catch(e){put('#provider-diagnostic-result','Diagnostic unavailable. No raw response was displayed.');throw e;}finally{state.providerBusy=false;if(state.provider)providerView(state.provider);}}));
 $('#provider-reload').addEventListener('click',()=>act(()=>providerAction('provider_get')));
 $('#provider-queue-preview').addEventListener('click',()=>act(async()=>{const r=await api('provider_queue_preview',{campaign:state.campaign.id});put('#provider-result',`Read-only held queue preview: ${r.provider_preview.inspected} inspected, ${r.provider_preview.eligible_in_preview} currently eligible in this bounded sample. Submitted: 0. No provider message request was made.`);}));
 if(config.provider_admin===true){$('#sms-provider').hidden=false;act(()=>providerAction('provider_get'));}
 function controlledView(){if(config.m6)return;put('#controlled-package',state.campaign?`Campaign #${state.campaign.id} · Revision ${state.campaign.revision}\nReady unique recipients: ${state.audience?.counts.prepared??0} · Provider language for approval: ${$('#controlled-language').value}\nMessage: ${$('#sms-message').value}\nReview the destination in Audience Review before approving.`:'No campaign selected.');const c=state.controlled;put('#controlled-status',c?`${c.phase} · ${c.reason}${c.masked_recipient?' · '+c.masked_recipient:''}`:'Select a campaign to inspect controlled Test eligibility.');$('#controlled-prepare').disabled=!!state.controlBusy||!c?.can_prepare||state.dirty;$('#controlled-approve').disabled=!!state.controlBusy||state.dirty||!c?.can_prepare||state.audience?.state!=='complete'||state.audience?.counts.prepared!==1||!['preparing','approved'].includes(c?.phase);$('#controlled-confirm').disabled=!!state.controlBusy||state.dirty||!c?.can_confirm||!state.confirmToken||!$('#controlled-ack').checked||$('#controlled-confirm-text').value!=='SEND TEST CAMPAIGN 5';}
 function historyRows(selector,items,format){const box=$(selector);box.replaceChildren();if(!items.length){box.textContent='No records.';return;}for(const item of items){const row=document.createElement('div');row.className='azs-history-row';row.textContent=format(item);box.append(row);}}
 async function activity(more=false){if(!config.m5||!state.campaign)return;const id=state.campaign.id;const r=await api('m5_activity',{campaign:id,after:more?state.activityCursor||0:0});if(state.campaign?.id!==id)return;state.activityCursor=r.next;state.attempts=r.attempts;state.events=more?[...(state.events||[]),...r.events]:r.events;historyRows('#activity-events',state.events,e=>`${date(e.at)} · ${e.event.replaceAll('_',' ')} · ${Object.entries(e.details).map(([k,v])=>k+': '+v).join(' · ')}`);historyRows('#approval-history',r.approvals,a=>`${date(a.at)} · ${a.scope} · audience #${a.audience_id} · expires ${date(a.expires)}`);historyRows('#submission-history',r.attempts,a=>`Attempt #${a.id} · ${a.state} · ${date(a.claimed)} · HTTP ${a.http_status} · code ${a.code||'unknown'} · SMS ID ${a.smsid||'unavailable'} · cost ${a.cost??'unavailable'} provider units · Delivery not verified`);$('#activity-more').disabled=!r.more;put('#activity-submitted',r.attempts.filter(a=>a.state==='accepted').length);put('#activity-delivered','Not verified');put('#activity-failed',r.attempts.filter(a=>a.state==='rejected').length);const c=await api('m5_status',{campaign:id});if(state.campaign?.id!==id)return;state.controlled=c;controlledView();}
 async function controlled(op){if(state.controlBusy||!state.campaign)return;state.controlBusy=true;controlledView();try{
  if(state.dirty)throw new Error('Save and review message changes before controlled actions.');
  const campaign=state.campaign.id;let r;
  if(op==='m5_prepare'){state.confirmToken=null;r=await api(op,{campaign,language:$('#controlled-language').value});load(r.status);}
  if(op==='m5_approve'){load(await api('review',{campaign,revision:state.campaign.revision}));r=await api(op,{campaign,revision:state.campaign.revision,language:$('#controlled-language').value,approve:'one_test_recipient'});state.confirmToken=r.confirmation_token;$('#controlled-ack').checked=false;$('#controlled-confirm-text').value='';}
  if(op==='m5_confirm'){const token=state.confirmToken;state.confirmToken=null;r=await api(op,{campaign,token,ack:$('#controlled-ack').checked?'1':'0',confirm_text:$('#controlled-confirm-text').value});put('#controlled-result',`Attempt #${r.attempt.id}: ${r.attempt.state}. ${r.attempt.state==='unknown'?'Outcome unknown. Do not retry; reconcile with the provider.':'Provider acceptance is not confirmed delivery.'}`);$('#controlled-ack').checked=false;$('#controlled-confirm-text').value='';load(await api(config.m6?'m6_campaign_state':'status',{campaign}));}
  await activity();
 }catch(e){state.confirmToken=null;put('#controlled-result',e.message+' Review activity before any further confirmation.');await activity().catch(()=>{});throw e;}finally{state.controlBusy=false;controlledView();}}
 for(const [id,op] of [['controlled-prepare','m5_prepare'],['controlled-approve','m5_approve'],['controlled-confirm','m5_confirm']])$('#'+id).addEventListener('click',()=>act(()=>controlled(op)));
 $('#controlled-language').addEventListener('change',()=>{state.confirmToken=null;state.controlled=null;controlledView();put('#controlled-result','Language selection changed. Refresh activity, then start fresh validation to bind the new language before approval.');});
 $('#activity-refresh').addEventListener('click',()=>act(()=>activity()));$('#activity-more').addEventListener('click',()=>act(()=>activity(true)));for(const id of ['#controlled-ack','#controlled-confirm-text'])$(id).addEventListener('input',controlledView);
 function segments(items) { for (const item of items) { if (state.segments.some(s => Number(s.id) === Number(item.id))) continue; state.segments.push(item); const option = document.createElement('option'); option.value = item.id; option.textContent = `${item.name} (#${item.id}) — ${Number.isSafeInteger(item.customer_count) && item.customer_count >= 0 ? item.customer_count.toLocaleString() + " customers" : "customer count unavailable"}`; $('#segment-select').append(option); } $('#more-segments').hidden = items.length < 100; }
 async function openCampaign(id) {
  if(state.busy)return;if(state.dirty&&!confirm('Discard unsaved message changes?'))return;
  const request=++state.selection;state.busy=true;state.dirty=false;state.campaign=null;state.audience=null;state.schedule=null;resetScheduleTime();render();
  try{const result=await api(config.m6?'m6_campaign_state':'status',{campaign:id});if(request!==state.selection)return;load(result,true);if(config.m61&&window.AzulaStudioFlow?.restoreTiming)await window.AzulaStudioFlow.restoreTiming(Number(id));if(request===state.selection)showStep(2);}
  finally{if(request===state.selection){state.busy=false;render();}}
 }
 all('[data-step]').forEach(b => b.addEventListener('click', () => config.m61?act(()=>window.AzulaStudioFlow.navigate(Number(b.dataset.step))):showStep(Number(b.dataset.step))));
 all('[data-next]').forEach(b => b.addEventListener('click', () => act(async () => { const target = Number(b.dataset.next);if(config.m61){await window.AzulaStudioFlow.navigate(target);return;} if (target >= 3) await saveMessage(); showStep(target); })));
 all('[data-tab]').forEach(b=>b.addEventListener('click',()=>filterReview(b.dataset.tab)));
 all('.azs-overview [data-category]').forEach(card=>{const click=()=>{if(!state.audience){notice('Select a campaign audience first.');return;}filterReview(card.dataset.category,true);};card.addEventListener('click',click);card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();click();}});});
 $('#audience-filter').addEventListener('change',()=>filterReview($('#audience-filter').value));
 for(const id of ['#audience-sort','#audience-direction','#audience-reason'])$(id).addEventListener('change',()=>act(()=>details(1)));
 let searchTimer;$('#audience-search').addEventListener('input',()=>{state.reviewLoading=true;render();clearTimeout(searchTimer);searchTimer=setTimeout(()=>act(()=>details(1)),300);});
 $('#download-csv').addEventListener('click',()=>act(async()=>{
  if(!reviewContext().audience||reviewContext().audience.state!=='complete')throw new Error('Complete audience preparation before exporting.');
  if(state.loadedSpec!==`${reviewContext().campaign.id}/${reviewContext().audience.id}/${JSON.stringify(reviewSpec(1))}`)throw new Error('Wait for the selected filters to finish loading before exporting.');
  const campaign=reviewContext().campaign.id,audience=reviewContext().audience.id,spec=reviewSpec(1);state.exporting=true;render();
  try{const response=await fetch(config.export_url,{method:'POST',credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'azula_sms_export',nonce:config.nonce,campaign,audience,...spec})});if(!response.ok||!response.headers.get('content-type')?.includes('text/csv'))throw new Error('CSV unavailable. Check completed generation, authorization, retained evidence and size limits.');const blob=await response.blob(),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=`azula-campaign-${campaign}-audience-${audience}-${spec.category}.csv`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);notice('CSV downloaded from the selected completed generation. Keep customer data private.');}
  finally{state.exporting=false;render();}
 }));
 all('[data-nav]').forEach(b => b.addEventListener('click', () => {if(config.m61){workspace(b.dataset.nav);return;}if(b.dataset.nav==='audience')showStep(1);else if(b.dataset.nav==='campaigns')showStep(state.step===1?0:state.step);else workspace(b.dataset.nav);}));all('[data-open-view]').forEach(b=>b.addEventListener('click',()=>workspace(b.dataset.openView)));
 $('#segment-select').addEventListener('change', () => act(() => select()));
 $('#refresh-audience').addEventListener('click', () => {if(config.m6){root.dispatchEvent(new CustomEvent('azula:audience-refresh'));return;}act(()=>select(true));});
 $('#sms-message').addEventListener('input', () => { state.dirty = true;state.confirmToken=null;controlledView(); put('#review-result', 'Message changed. Save a new review package after validation.'); render(); });
 $('#save-message').addEventListener('click', () => act(saveMessage));
 $('#first-page').addEventListener('click', () => act(() => details(0))); $('#next-page').addEventListener('click', () => act(() => details(state.next)));
 $('#save-review').addEventListener('click', () => act(async () => {
  if(state.savingReview)return;state.savingReview=true;render();
  try{await saveMessage();if(state.dirty)throw new Error('The message changed while saving. Review and save the newest draft again.');const result=await api('review',{campaign:state.campaign.id,revision:state.campaign.revision});load(result);put('#review-result',state.dirty?'Saved revision is older than your unsaved changes. Review again.':'Saved for approval. No send authorization or dispatch was created.');}
  finally{state.savingReview=false;render();}
 }));
 $('#check-health').addEventListener('click', () => act(async () => updateHealth((await api('probe')).health)));
 $('#save-price').addEventListener('click', () => act(async () => { state.rate = (await api('pricing', { rate_minor: $('#price-rate').value })).rate_minor; notice('Local pricing saved. Prior saved review bindings were invalidated.'); render(); }));
 $('#more-segments').addEventListener('click', () => act(async () => { const r=await api('segments', { after: state.segments.at(-1)?.id || 0 }); Object.assign(state.historical,r.historical||{}); segments(r.segments); }));
 $('#new-campaign').addEventListener('click', () => { if (state.busy||state.scheduleBusy) return; if (state.dirty && !confirm('Discard unsaved message changes?')) return; state.selection++; state.detailsRequest++; state.draftToken=draftToken(); state.campaign = null;state.attempts=null;state.events=[];state.confirmToken=null;state.controlled=null; state.audience = null; state.schedule=null; resetScheduleTime(); state.dirty = false; $('#sms-message').value = ''; $('#segment-select').value = ''; put('#campaign-ref', 'New draft'); put('#review-result', ''); renderHistory(null); changeUrl(null); notice(''); showStep(0);if(config.m61)root.dispatchEvent(new CustomEvent('azula:new')); });
 window.addEventListener('beforeunload', e => { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });
 async function poll() { try { if (state.campaign && !state.busy) { const id = state.campaign.id; const result = await api(config.m6?'m6_campaign_state':'status', { campaign: id }); if (!state.busy && state.campaign?.id === id) load(result); } } catch { put('#draft-status', 'Connection interrupted. Captured work remains on the server.'); } finally { setTimeout(poll, 5000); } }
 act(async () => { const result = await api('init'); updateHealth(result.health);renderSchema(result);segments(result.segments||[]);await remainingSegments(result.segments||[]);if(result.needs_upgrade){load(result);put('#recent-campaigns','Existing campaigns are preserved. Complete SMS database setup to load them.');if(result.catalog_error)notice('Customer Segments catalog is separately unavailable. SMS schema setup is also pending; neither has been treated as an empty saved-segment list.');return;} state.rate = result.rate_minor; state.historical=result.historical||{}; $('#price-rate').value = state.rate ?? ''; const recent = $('#recent-campaigns'); recent.replaceChildren(); for (const c of result.campaigns) { const b = document.createElement('button'); b.className = 'azs-campaign-row'; const name = document.createElement('span'), status = document.createElement('small'); name.textContent = c.name; status.textContent = c.schedule_state ? scheduleLabel(c.schedule_state) + " · " + date(Number(c.scheduled_at)) : human(c.state); b.append(name, status); b.addEventListener('click', () => act(() => openCampaign(c.id))); recent.append(b); } if (!result.campaigns.length) recent.textContent = 'Your first campaign starts above.'; const initialView=new URL(location.href).searchParams.get('view');const id = new URL(location.href).searchParams.get('campaign'); if (id && /^[1-9][0-9]*$/.test(id)&&!(config.m8&&initialView==='analytics')) await openCampaign(id);if(initialView)workspace(initialView);render(); });
 window.AzulaStudioWorkspace={state,api,load,render,workspace,showStep,notice,saveMessage,details,select,openCampaign,metrics,validationError,date,draftToken};
 setTimeout(poll, 5000);
})();


