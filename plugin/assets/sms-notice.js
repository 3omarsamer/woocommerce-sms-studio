/* SMS-only preferences. No third-party consent setters, tags, identifiers or localStorage. */
(()=>{'use strict';
 const c=window.AzulaSmsNotice;if(!c?.url||window.__azulaSmsNotice)return;window.__azulaSmsNotice=true;
 if(!document.cookie.split(';').some(v=>v.trim()==='azula_sms_notice=1'))return;
 let state,bar,prefs,details,busy=false;
 const call=async extra=>{const r=await fetch(c.url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'azula_sms_consent',...extra})});const j=await r.json();if(!r.ok||!j.success)throw Error(j?.data?.message||'Could not save your preference. Reload the page and try again.');return j.data;};
 const button=(text,fn)=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.addEventListener('click',fn);return b;};
 const show=()=>{if(!bar)return;bar.hidden=false;prefs.hidden=true;};
 const choose=async allow=>{if(busy)return;busy=true;for(const b of bar.querySelectorAll('button'))b.disabled=true;
  try{state=await call({op:'choice',sms_choice:allow?'allow':'deny',until:state.until,proof:state.proof});bar.hidden=true;prefs.hidden=false;details.hidden=true;
   document.dispatchEvent(new CustomEvent('azula:sms-consent',{detail:{permitted:state.permitted}}));if(state.message){bar.querySelector('[role="status"]').textContent=state.message;show();}
  }catch(e){bar.querySelector('[role="status"]').textContent=e.message;try{state=await call({op:'status'});}catch{}}
  finally{busy=false;for(const b of bar.querySelectorAll('button'))b.disabled=false;}
 };
 const build=()=>{
  bar=document.createElement('section');bar.id='azula-sms-notice';bar.setAttribute('aria-label','SMS cookie preferences');bar.setAttribute('role','region');
  const text=document.createElement('span');text.textContent='We use cookies to improve your experience and measure our SMS campaigns.';
  const actions=document.createElement('div');actions.className='azula-sms-notice-actions';const learn=button('Learn More',()=>{details.hidden=!details.hidden;learn.setAttribute('aria-expanded',String(!details.hidden));});learn.setAttribute('aria-expanded','false');learn.setAttribute('aria-controls','azula-sms-notice-details');
  actions.append(button('Accept',()=>choose(true)),button('Decline',()=>choose(false)),learn);
  details=document.createElement('div');details.id='azula-sms-notice-details';details.hidden=true;
  const explanation=document.createElement('p');explanation.textContent='With your permission, SMS Studio uses a first-party cookie for up to seven days to associate this SMS link with clicks, cart activity, checkout and purchases, and to measure campaign revenue and prepare relevant audiences. A link identifies its intended recipient, not necessarily the person using it. Declining does not affect shopping or SMS link access. Your preference is remembered for up to 30 days. A 20-minute SMS arrival cookie keeps the notice available without recording analytics. You can change it or withdraw at any time using SMS cookie preferences. Earlier permitted records are not erased by withdrawal. These controls apply only to SMS Studio, not other website analytics.';
  const close=button('Close details',()=>{details.hidden=true;learn.setAttribute('aria-expanded','false');});details.append(explanation,close);
  const status=document.createElement('p');status.setAttribute('role','status');status.className='azula-sms-notice-status';
  bar.append(text,actions,details,status);prefs=button('SMS cookie preferences',async()=>{try{state=await call({op:'status'});show();}catch{}});prefs.id='azula-sms-preferences';document.body.append(bar,prefs);bar.hidden=state.choice!=='unknown'||state.permitted;prefs.hidden=!bar.hidden;
 };
 call({op:'status'}).then(v=>{state=v;if(v.visible)build();}).catch(()=>{});
})();
