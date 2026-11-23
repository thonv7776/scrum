/* Sprint 2: database-backed integration for the supplied sprint2.html UI.
   Sprint 1 files (index.html, app.js, api.php, lib.php) are NOT modified. */
(() => {
 'use strict';
 let s2csrf = '', s2user = null, s2staff = null, s2BookId = 0, s2Receipt = null;
 let s2Bookings = [], s2Vehicles = [], s2PhoneVehicles = [], s2Callers = [], s2Dispatch = [], s2StaffTotal = 0, s2Busy = false, s2HoldTimer;
 const by = id => document.getElementById(id);
 const html = s => String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const price = n => '£'+Number(n||0).toFixed(2);
 const fmt = s => s ? String(s).replace('T',' ').slice(0,19) : '—';
 const sz = name => /Minibus/i.test(name||'')?'Minibus':/MPV/i.test(name||'')?'MPV':'Saloon';
 const keyNames = ['heathrow','paddington','baker','westminster','savoy','kingscross','canary','cityairport','gatwick'];
 let interval = null;
 const authView = document.createElement('section');authView.id='s2Auth';authView.className='page-view';
 authView.innerHTML=`<div class="m3-card elevated" style="max-width:560px;margin:30px auto;width:100%"><h1 style="font-size:24px;margin-bottom:10px">Sprint 2 – Secure sign-in</h1>
 <p class="muted" style="margin-bottom:15px">Customer checkout uses your existing verified Sprint 1 login. Staff must sign in with an internal account.</p>
 <div id="s2AuthError" class="m3-alert error hidden" role="alert"></div>
 <p style="margin:12px 0"><a class="s2-link" href="index.html">Open Sprint 1 – Register / Sign in / Hold a booking</a></p>
 <div class="field-group"><label class="field-label" for="s2LoginEmail">Staff work email</label><input class="m3-input" id="s2LoginEmail" type="email" autocomplete="username"></div>
 <div class="field-group"><label class="field-label" for="s2LoginPw">Staff password</label><input class="m3-input" id="s2LoginPw" type="password" autocomplete="current-password"></div>
 <button id="s2LoginButton" class="m3-btn m3-btn-primary" type="button">Sign in to Staff Portal</button></div>`;
 document.querySelector('main.main-container').prepend(authView);
 const pwView = document.createElement('section');pwView.id='s2ChangePw';pwView.className='page-view';
 pwView.innerHTML=`<div class="m3-card elevated" style="max-width:560px;margin:30px auto;width:100%"><h2>First login: change temporary password</h2><div id="s2PwError" class="m3-alert error hidden" role="alert"></div>
 <div class="field-group"><label class="field-label">Temporary password</label><input id="s2Old" class="m3-input" type="password"></div>
 <div class="field-group"><label class="field-label">New password (10+ characters, uppercase, digit)</label><input id="s2New" class="m3-input" type="password"></div><button id="s2ChangeButton" class="m3-btn m3-btn-primary">Change password and continue</button></div>`;
 document.querySelector('main.main-container').prepend(pwView);
 document.querySelector('#page-payment .card-visual')?.insertAdjacentHTML('beforebegin', '<div class="s2-notice" id="s2SandboxNotice">Coursework SANDBOX — test card 4242 4242 4242 4242 only; no real money or bank/VISA Check transaction. Do not enter real card data.</div>');
 const originalSwitch = switchPage;
 const originalPhInit = phInit;
 const originalPhShow = phShow;
 const originalPhReset = phReset;
 const originalPhGo = phGo;
 const originalFillProfile = fillProfile;
 const originalOpenEdit = openEdit;
 const originalShowReceipt = showReceiptState;
 fillProfile = function(){ originalFillProfile(); if(curStaff){
   by('pfDeactText').textContent = 'Account deactivated on '+(curStaff.deactAt||'date not recorded')+'. Staff access has been revoked; booking history is retained.';
   by('pfSess').textContent = curStaff.status==='Deactivated'?'Access revoked':'Active session count unavailable';
 } };
 const originalPayView = payView;
 const originalRenderSizes = renderSizes;
 async function request(action, data={}){
  let r;
  try{r=await fetch('sprint2_api.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':s2csrf},body:JSON.stringify(data)});}catch(e){throw Error('Unable to reach Sprint 2 API (check Apache/MySQL).');}
  let body;try{body=await r.json();}catch(e){throw Error('API returned an invalid response. Check PHP error logs.');}
  if(!r.ok||!body.ok){const er=Error(body.error||'Request failed');er.status=r.status;er.fields=body.fields||{};throw er;}
  return body;
 }
 function errView(id,msg){const el=by(id);if(el){el.textContent=msg;el.classList.toggle('hidden',!msg);}}
 function displayError(e,location){const msg=e?.message||String(e);if(location)errView(location,msg);else toast(msg);if(e.status===401)console.warn('Session expired; sign in again');}
 function showAuth(){document.querySelectorAll('.page-view').forEach(e=>e.classList.remove('active'));by('s2Auth').classList.add('active');by('navLinks').innerHTML='';by('userStatusLabel').textContent='Not signed in';}
 function loggedRole(){return s2staff ? (s2staff.role==='Admin'?'admin':'staff') : s2user?'customer':null;}
 function loginStage(){const who=loggedRole();if(!who){showAuth();return;}if(s2staff?.must_change_password){document.querySelectorAll('.page-view').forEach(e=>e.classList.remove('active'));by('s2ChangePw').classList.add('active');return;}
  role=who;ROLES[who].name=s2staff?.name||s2user?.full_name||'Customer';renderNav();switchPage(ROLES[who].home);
 }
 async function bootstrap(){try{
  const r=await fetch('sprint2_api.php?action=bootstrap',{credentials:'same-origin'});const b=await r.json();if(!r.ok)throw Error(b.error||'Bootstrap failed');s2csrf=b.csrf;s2user=b.customer;s2staff=b.staff;
  by('s2SandboxNotice').classList.toggle('hidden',!b.sandbox);loginStage();
 }catch(e){showAuth();errView('s2AuthError',e.message);}}
 by('s2LoginButton').onclick=async()=>{try{const r=await request('staff_login',{email:by('s2LoginEmail').value,password:by('s2LoginPw').value});s2csrf=r.csrf;s2staff=r.staff;by('s2LoginPw').value='';loginStage();}catch(e){displayError(e,'s2AuthError');}};
 by('s2ChangeButton').onclick=async()=>{try{await request('staff_change_password',{old_password:by('s2Old').value,new_password:by('s2New').value});by('s2Old').value=by('s2New').value='';s2staff.must_change_password=false;loginStage();}catch(e){displayError(e,'s2PwError');}};
 // The original prototype role switcher is intentionally DISABLED; changing role cannot grant access.
 toggleRoleMenu = function(e){e?.stopPropagation();const m=by('roleMenu');m.innerHTML=`<div class="menu-head">${html(s2staff?.name||s2user?.full_name||'')}: ${html(s2staff?.role||'Customer')}</div><button class="menu-item" type="button" id="s2LogoutAction">Sign out of ${s2staff?'Staff':'Customer'} portal</button><a class="menu-item" href="index.html">Sprint 1 booking</a>`;m.classList.toggle('hidden');by('s2LogoutAction').onclick=async()=>{if(s2staff){const r=await request('staff_logout');s2csrf=r.csrf;s2staff=null;showAuth();}else{window.location.href='index.html';}};};
 setRole = function(){toast('Roles are assigned by server authentication, not a preview switch.');};
 switchPage = function(p){
  const who=loggedRole();if(!who){showAuth();return;}if(s2staff?.must_change_password){loginStage();return;}
  const required=PAGE_ROLE[p];if(required&&required!==who){originalSwitch('denied');return;}
  originalSwitch(p);
  if(p==='payment')loadPayment();
  if(p==='bookings')loadBookings();
  if(p==='arrival')loadArrival();
  if(p==='fleet')loadFleet();
  if(p==='phone')loadCallers('');
  if(p==='dispatch')loadDispatch();
  if(p==='staff')loadStaff();
  if(p==='profile'&&curStaff)originalFillProfile();
 };
 renderDemo = function(){ /* Prototype role/state switcher is disabled in Sprint 2. */ };
 // CUSTOMER: outstanding Sprint 1 hold -> real checkout.
 async function loadPayment(){if(!s2user)return;try{
  const r=await request('payment_context',s2BookId?{booking_id:s2BookId}:{});const b=r.booking;s2BookId=b.id;
  if(b.status!=='Held'){originalPayView('approved');by('payStatusChip').innerHTML=html(b.status);by('payHoldBanner').classList.add('hidden');return;}
  originalPayView('form');by('payBtnAmount').textContent=price(b.total);
  const summary=by('page-payment').querySelector('.sticky');const cells=summary.querySelectorAll('table strong');
  [b.service,b.pickup,b.dest,fmt(b.date_time),b.pax+' · '+b.size].forEach((v,i)=>{if(cells[i])cells[i].textContent=v||'—';});
  const sums=summary.querySelectorAll('.price-line strong,.price-line span:last-child');
  if(sums.length>=3){sums[sums.length-3].textContent=price(b.base);sums[sums.length-2].textContent='-'+price(b.discount);sums[sums.length-1].textContent=price(b.total);}
  optInHint();
  clearInterval(s2HoldTimer);const expiry=Date.parse(b.hold_until_iso||b.hold_until?.replace(' ','T')+'+00:00');
  const update=()=>{const sec=Math.max(0,Math.floor((expiry-Date.now())/1000));by('payHoldTimer').textContent=`Time remaining: ${Math.floor(sec/60)}:${String(sec%60).padStart(2,'0')}`;if(sec===0){by('payBtnAmount').closest('button').disabled=true;errView('payAlert','Booking hold expired; return to Sprint 1.');}};
  update();s2HoldTimer=setInterval(update,1000);
 }catch(e){originalPayView('form');by('payBtnAmount').closest('button').disabled=true;by('payHoldBanner').classList.add('hidden');errView('payAlert',e.message);}}
 optInHint = function(){by('optInHint').textContent=by('optInEmail').checked ? `We will queue a confirmation email to ${s2user?.email||'your registered address'} after successful payment.`:'No email will be sent. You can still access your receipt.';};
 payNow = function(){if(!validateCard())return;by('visaLast4').textContent=by('cardNumber').value.replace(/\D/g,'').slice(-4);openVisa(true);by('visaCode').value='';by('visaProcTitle').textContent='Sandbox challenge (test only)';};
 visaVerify = async function(ok){if(s2Busy)return;
  if(!ok){closeDlg('visaDialog');decline('checkfail');return;}
  const code=by('visaCode').value.trim();if(!/^\d{6}$/.test(code)){toast('Enter the 6-digit sandbox challenge code (any six digits).');return;}
  s2Busy=true;by('visaProcTitle').textContent='Validating the sandbox transaction...';by('visaChallenge').classList.add('hidden');by('visaProcessing').classList.remove('hidden');
  try{const r=await request('pay',{booking_id:s2BookId,card_name:by('cardName').value,card_number:by('cardNumber').value.replace(/\D/g,''),expiry:by('cardExp').value,cvv:by('cardCvv').value,verification:'approved',opt_in_email:by('optInEmail').checked});
   by('cardNumber').value=by('cardCvv').value='';cardPreview();s2Receipt=r.receipt;closeDlg('visaDialog');clearInterval(s2HoldTimer);originalPayView('approved');setPayStep(4);
   const panel=by('payApproved');panel.querySelector('p strong').textContent=r.receipt.ref;panel.querySelectorAll('.price-line strong')[0].textContent=r.receipt.txn;
   panel.querySelectorAll('.price-line strong')[1].textContent='•••• '+r.receipt.card_last4;
   panel.querySelector('button').onclick=()=>openReceipt(r.receipt.ref);
   await openReceipt(r.receipt.ref);
  }catch(e){closeDlg('visaDialog');decline(e.message.toLowerCase().includes('declined')?'declined':'checkfail');by('declText').textContent=e.message;}finally{s2Busy=false;}
 };
 // US17: database receipts and owner-checked access.
 async function loadBookings(){if(!s2user)return;try{const r=await request('my_bookings');s2Bookings=r.bookings;filterBookings(bkFilter);}catch(e){displayError(e);}}
 filterBookings = function(f){bkFilter=f;by('bkFilters').innerHTML=BK_FILTERS.map(x=>`<button class="slot-chip ${x[0]===f?'active':''}" onclick="filterBookings('${x[0]}')">${html(x[1])}</button>`).join('');
  const data=s2Bookings.filter(b=>f==='all'||(f==='paid'?['Paid & Confirmed','Forwarded to Control Centre'].includes(b.status):f==='held'?b.status==='Held':b.status==='Cancelled'));
  by('bkBody').innerHTML=data.map(b=>{const paid=['Paid & Confirmed','Forwarded to Control Centre'].includes(b.status);return `<tr><td><strong>${html(b.ref)}</strong></td><td>${html(b.service)}</td><td>${html(fmt(b.date_time))}</td><td>${html(b.pickup)}<br>→ ${html(b.dest)}</td><td><span class="chip ${paid?'success':'warning'}">${html(b.status)}</span></td><td style="text-align:right"><strong>${price(b.total)}</strong></td><td><button class="m3-btn m3-btn-tonal m3-btn-sm" data-book="${b.id}" data-action="${paid?'receipt':'pay'}">${paid?'View Receipt':'Pay now'}</button></td></tr>`;}).join('');
  by('bkBody').querySelectorAll('button').forEach(btn=>btn.onclick=()=>{s2BookId=+btn.dataset.book;btn.dataset.action==='receipt'?openReceipt(s2Bookings.find(x=>x.id===s2BookId).ref):switchPage('payment');});
  by('bkEmpty').classList.toggle('hidden',data.length>0);by('bkBody').closest('.m3-card').classList.toggle('hidden',!data.length);
 };
 openReceipt = async function(ref){if(!s2user)return;originalSwitch('receipt');try{
  const r=await request('receipt',{ref});const b=r.receipt;s2Receipt=b;s2BookId=b.id;originalShowReceipt('ok');
  const fields={rcRef:b.ref,rcService:b.service,rcPickup:b.pickup,rcDest:b.dest,rcDate:fmt(b.date_time),rcSize:b.size,rcTxn:b.txn,rcPaidAt:fmt(b.paid_at),rcCard:b.card_last4,rcBase:price(b.base),rcDisc:'-'+price(b.discount),rcFinal:price(b.paid),rcIssued:fmt(b.paid_at)};
  Object.entries(fields).forEach(([id,v])=>{if(by(id))by(id).textContent=v;});
  const d=by('page-receipt').querySelector('dl.kv dd'); if(d)d.textContent=b.customer;
  by('rcEmailText').textContent=b.opt_in_email?`Booking email: ${b.email_status}${b.gateway_mode==='Sandbox'?' (sandbox mail transport may use local outbox)':''}`:'Email opted out.';
  by('rcEmailChip').innerHTML=`<span class="chip">${html(b.email_status)}</span>`;

 }catch(e){originalShowReceipt(e.status===403?'denied':'missing');toast(e.message);}}
 openEmailPreview = function(){const b=s2Receipt||s2Bookings.find(x=>x.id===s2BookId);const info=by('emailDialog').querySelector('.email-mock');
  if(b){info.innerHTML=`<div class="email-head"><div>From: PrivateHire Cars</div><div>To: ${html(s2user?.email)}</div><div>Subject: Booking ${html(b.ref)}</div></div><div class="email-body"><p>Booking reference: ${html(b.ref)}</p><p>Pickup: ${html(b.pickup)}<br>Destination: ${html(b.dest)}<br>Date/time: ${html(fmt(b.date_time))}<br>Minicab size: ${html(b.size)}<br>Total paid: ${price(b.total)}<br>Cancel/amend no less than 24 hours before pickup.</p></div>`;}
  openDlg('emailDialog');
 };
 // US23: backed by GPS event audit, not hard-coded demo SMS.
 setArrivalState=function(){ /* Live status will be read from the booking audit API. */ };
 async function loadArrival(){if(!s2user)return;try{
  if(!s2Bookings.length){const z=await request('my_bookings');s2Bookings=z.bookings;}
  const b=s2Bookings.find(x=>['Paid & Confirmed','Forwarded to Control Centre'].includes(x.status));
  if(!b){by('arrEta').textContent='No confirmed upcoming booking';by('arrStateChip').textContent='Awaiting booking';by('arrDelivery').textContent='—';by('page-arrival').querySelector('.sms-bubble').textContent='No arrival event recorded.';return;}
  const r=await request('arrival_log',{booking_id:b.id});const log=r.log[0];const info=r.vehicle;
  by('arrEta').textContent=log?`ETA ${log.eta_minutes} min · SMS ${log.status}`:'No GPS arrival event yet';by('arrStateChip').textContent=log?`SMS ${log.status}`:'Waiting for Control Centre event';
  by('arrSentAt').textContent=log?.sent_at||'—';by('arrDelivery').textContent=log?.status==='Sent'?'Submitted to gateway':log?.status||'Not triggered';
  by('page-arrival').querySelector('.sms-bubble').textContent=log?.body_text||'SMS will be prepared when verified GPS arrival event is received.';
  const labels=by('page-arrival').querySelectorAll('dl.kv dd');[info.plate,info.model,info.colour,info.driver,log?log.eta_minutes+' minutes':'Awaiting GPS ETA',b.pickup].forEach((v,i)=>{if(labels[i])labels[i].textContent=v||'Not assigned';});
  const table=by('arrSentAt').closest('tr');if(table){table.querySelector('td strong').textContent=b.ref;table.querySelectorAll('td')[1].textContent=log?.trigger_at||'Not triggered';}
  const card=by('arrEta').closest('.m3-card');card.querySelector('.muted').textContent='Booking '+b.ref+' · '+fmt(b.date_time);card.querySelectorAll('.muted')[1].textContent='Pickup: '+b.pickup;card.querySelector('.chip').textContent=log?'ETA from last GPS event':'GPS distance unavailable';card.querySelector('.car-track').classList.add('hidden');
  by('page-arrival').querySelector('.sms-time').textContent=log?.sent_at||'Not sent';
  by('page-arrival').querySelector('.phone').previousElementSibling.innerHTML='Registered mobile: <strong>'+html(r.mobile||'Not registered')+'</strong> · '+(log?.status==='Sent'?'Submitted to gateway':'Not delivered');
  by('arrFailed').classList.toggle('hidden',log?.status!=='Failed');if(log?.status==='Failed')by('arrFailed').querySelector('p').textContent=log.last_error||'SMS provider unavailable';
 }catch(e){displayError(e);}}
 // Shared fleet mapping: only real rows from MySQL, never sample prototype list.
 function fleetRows(rows){return rows.map(v=>({id:v.minicab_id,plate:v.registration_no,model:v.make_and_model,colour:v.vehicle_colour,size:sz(v.category_name),cap:+v.max_passengers,zone:v.zone||'Unspecified',status:v.operational_status||'Available'}));}
 async function loadFleet(){if(!s2staff)return;
  try{const dt=by('flDate').value, [date,time]=(dt||'').split('T');const r=await request('fleet',{date,time,size:by('flSize').value,zone:by('flZone').value});const list=fleetRows(r.vehicles);s2Vehicles=list;
   by('stAvail').textContent=list.length;by('stTotal').textContent=r.total;by('stBusy').textContent=Math.max(0,r.total-list.length);
   by('fleetBody').innerHTML=list.map(v=>`<tr><td>VH-${v.id}</td><td><strong>${html(v.plate)}</strong></td><td>${html(v.model)}</td><td>${html(v.colour)}</td><td>${v.cap} seats · ${v.size}</td><td>${html(v.zone)}</td><td><span class="chip success">Available</span></td><td><button class="m3-btn m3-btn-tonal m3-btn-sm" onclick="switchPage('phone')">Book by phone</button></td></tr>`).join('');
   by('fleetCount').textContent=`Showing ${list.length} of ${r.total} vehicles`;by('fleetTableCard').classList.toggle('hidden',!list.length);by('fleetEmpty').classList.toggle('hidden',!!list.length);by('fleetLive').textContent='Database · refreshed now';
  }catch(e){by('fleetBody').replaceChildren();by('stAvail').textContent='—';displayError(e);}
 }
 renderFleet=loadFleet;refreshFleet=loadFleet;
 resetFleetFilters=function(){by('flDate').value=nextLondonTime();by('flSize').value='all';by('flZone').value='all';loadFleet();};
 function nextLondonTime(){const x=new Date(Date.now()+3600000*3);const obj={};new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(x).forEach(p=>obj[p.type]=p.value);return `${obj.year}-${obj.month}-${obj.day}T${obj.hour}:${obj.minute}`;}
 // STAFF: directory uses server-side pagination.
 async function loadStaff(){if(s2staff?.role!=='Admin')return;try{const r=await request('staff_list',{page:staffPage,per:+by('staffPer').value});s2StaffTotal=r.total;staffPage=r.page;STAFF.length=0;STAFF.push(...r.staff);renderStaff();}catch(e){displayError(e);}}
 renderStaff=function(){const per=+by('staffPer').value,pages=Math.max(1,Math.ceil(s2StaffTotal/per));
  by('staffCard').classList.toggle('hidden',s2StaffTotal===0);by('staffEmpty').classList.toggle('hidden',s2StaffTotal!==0);
  by('staffBody').innerHTML=STAFF.map(s=>`<tr class="row-click" tabindex="0" data-id="${html(s.id)}"><td><strong>${html(s.id)}</strong></td><td>${html(s.name)}${s.self?' (You)':''}</td><td>${html(s.email)}</td><td>${html(s.role)}</td><td>${html(s.status)}</td><td>View</td></tr>`).join('');
  by('staffBody').querySelectorAll('tr').forEach(tr=>{tr.onclick=()=>openProfile(tr.dataset.id);tr.onkeydown=e=>{if(e.key==='Enter')openProfile(tr.dataset.id);};});
  by('staffRange').textContent=s2StaffTotal?`${(staffPage-1)*per+1}–${Math.min(staffPage*per,s2StaffTotal)} of ${s2StaffTotal} staff`:'0 staff';
  let nav=`<button class="page-btn" ${staffPage<=1?'disabled':''} data-page="${staffPage-1}">‹</button>`;
  for(let n=1;n<=pages;n++)nav+=`<button class="page-btn ${n===staffPage?'active':''}" data-page="${n}">${n}</button>`;
  nav+=`<button class="page-btn" ${staffPage>=pages?'disabled':''} data-page="${staffPage+1}">›</button>`;by('staffPager').innerHTML=nav;
  by('staffPager').querySelectorAll('button:not(:disabled)').forEach(b=>b.onclick=()=>{staffPage=+b.dataset.page;loadStaff();});
 };
 openProfile=async function(id){if(s2staff?.role!=='Admin')return;try{const r=await request('staff_profile',{id});curStaff={...r.staff,sess:0,created:fmt(r.staff.created),login:fmt(r.staff.login),deactAt:fmt(r.staff.deactAt)};originalSwitch('profile');fillProfile();}catch(e){curStaff=null;originalSwitch('profile');fillProfile();toast(e.message);}};
 saveEdit=async function(){if(!curStaff)return;
  try{await request('staff_edit',{id:curStaff.id,name:by('edName').value,email:by('edMail').value,phone:by('edPhone').value});closeDlg('editDialog');await openProfile(curStaff.id);toast('Staff profile saved in database');}catch(e){Object.entries(e.fields||{}).forEach(([id,msg])=>setErr(id,msg));toast(e.message);}
 };
 confirmDeactivate=async function(){if(!curStaff||!by('dtChk').checked)return;try{await request('staff_deactivate',{id:curStaff.id});closeDlg('deactDialog');await openProfile(curStaff.id);toast('Account deactivated; active sessions revoked');}catch(e){toast(e.message);}};
 submitCreate=async function(){if(s2staff?.role!=='Admin')return;
  const data={id:by('crId').value,name:by('crName').value,email:by('crMail').value,phone:by('crPhone').value,role:by('crRole').value,password:by('crPw').value};
  try{const r=await request('staff_create',data);by('crPw').value='';by('crFormWrap').classList.add('hidden');
   if(r.onboarding==='submitted'){by('crSuccessMail').textContent=data.email+' (submitted to '+(r.sandbox?'development outbox':'mail transport')+')';by('crSuccess').classList.remove('hidden');}
   else by('crMailFail').classList.remove('hidden');toast('Staff account saved');
  }catch(e){Object.entries(e.fields||{}).forEach(([id,msg])=>setErr(id,msg));errView('crDup',e.message);by('crDupText').textContent=e.message;by('crDup').classList.remove('hidden');}
 };
 // US31: verified callers, database availability and standard fare (no online discount).
 function phonePayload(){return {service_id:by('phService').selectedIndex+1,category_id:{Saloon:1,MPV:2,Minibus:3}[phSize],pickup_key:keyNames[+by('phPickup').value],destination_key:keyNames[+by('phDest').value],passenger_count:+by('phPass').value,hire_days:+(by('phDays')?.value||1),flight_number:by('phFlight')?.value||'',date:by('phDate').value,time:by('phTime').value,include_driver:false};}
 const extra=document.createElement('div');extra.className='form-grid-2';extra.id='s2PhoneExtra';extra.innerHTML=`<div class="field-group" id="s2FlightGroup"><label class="field-label">Flight number and terminal (Meet & Greet)</label><input class="m3-input" id="phFlight" placeholder="BA123 T5"></div><div class="field-group" id="s2DaysGroup"><label class="field-label">Daily hire days (1–30)</label><input class="m3-input" id="phDays" type="number" min="1" max="30" value="1"></div>`;
 by('phService').closest('.form-grid-2').after(extra);
 const toggleExtras=()=>{by('s2FlightGroup').classList.toggle('hidden',by('phService').selectedIndex!==2);by('s2DaysGroup').classList.toggle('hidden',by('phService').selectedIndex!==3);};by('phService').addEventListener('change',toggleExtras);toggleExtras();
 const ownPhShow=phShow;
 phInit=function(){originalPhInit();by('phDate').value=nextLondonTime().split('T')[0];by('phTime').value=nextLondonTime().split('T')[1];findCallers(by('callerSearch').value);};
 async function loadCallers(q){if(!s2staff)return;if(q.trim().length<2){CALLERS.length=0;by('callerResults').innerHTML='<p class="muted">Enter at least 2 characters to search for an existing customer.</p>';return;}
  try{const r=await request('customer_search',{q});CALLERS.length=0;CALLERS.push(...r.customers.map(c=>({customer_id:c.customer_id,name:c.full_name,phone:c.telephone_number,email:c.email,addr:c.residential_address})));phCaller=0;originalRenderCallers();}catch(e){displayError(e);}}
 const originalRenderCallers=renderCallers;
 renderCallers=function(){loadCallers(by('callerSearch').value);};
 function showCallerMatches(){const q=by('callerSearch').value.trim().toLowerCase();const arr=CALLERS.map((c,i)=>[c,i]).filter(x=>(x[0].name+x[0].phone+x[0].email).toLowerCase().includes(q));by('callerResults').innerHTML=arr.map(([c,i])=>`<div class="service-card book-svc ${i===phCaller?'selected':''}" data-index="${i}" style="padding:14px"><strong>${html(c.name)}</strong><span class="muted">${html(c.phone)} · ${html(c.email)}</span><span class="muted">${html(c.addr)}</span></div>`).join('')||'<p>No matching customer – register a new caller.</p>';by('callerResults').querySelectorAll('[data-index]').forEach(el=>el.onclick=()=>{phCaller=+el.dataset.index;showCallerMatches();});}
 // Use custom display to avoid invoking prototype's CALLERS data.
 async function findCallers(q){if(!s2staff)return;if(q.trim().length<2){CALLERS.length=0;by('callerResults').innerHTML='<p class="muted">Search existing customers by name, phone, or email (2+ characters).</p>';return;}try{const r=await request('customer_search',{q});CALLERS.length=0;CALLERS.push(...r.customers.map(c=>({customer_id:c.customer_id,name:c.full_name,phone:c.telephone_number,email:c.email,addr:c.residential_address})));phCaller=0;showCallerMatches();}catch(e){displayError(e);}}
 renderCallers=function(){findCallers(by('callerSearch').value);};
 phGo=function(n){if(n>phStep&&phStep===1&&phMode==='existing'&&!CALLERS[phCaller])return phErr('phErr1','<strong>Search for and select a real customer first.</strong>');
  if(n>phStep&&phStep===1&&phMode==='new'&&!by('ncEmail').value.trim())return phErr('phErr1','<strong>New customer needs an email address for the new profile.</strong>');
  if(n>phStep&&phStep===2&&by('phService').selectedIndex===2&&!by('phFlight').value.trim())return phErr('phErr2','<strong>Meet & Greet requires flight number and terminal.</strong>');
  if(n>phStep&&phStep===2&&by('phService').selectedIndex===3&&!(+by('phDays').value>=1&&+by('phDays').value<=30))return phErr('phErr2','<strong>Daily hire must be 1–30 days.</strong>');
  // original flow does validation and transitions; overridden phAvail/phFare call real API.
  originalPhGo(n);
 };
 // Store original function reference before overrides. (Top-level functions reside on window.)
 phAvail=async function(){if(!s2staff)return;
  phVeh=null;by('phNext3').disabled=true;by('phAvailLead').textContent='Checking database availability…';
  const p=phonePayload();const start=by('phDate').value+'T'+by('phTime').value;
  try{const r=await request('fleet',{date:p.date,time:p.time,size:phSize,zone:'all',duration:p.service_id===4?(p.hire_days*600):Math.max(60,Math.ceil(phMiles()*2.95)+15)});
   // Ignore a response if the user changed the date/time while querying.
   if(start!==by('phDate').value+'T'+by('phTime').value)return;
   s2PhoneVehicles=fleetRows(r.vehicles);const list=s2PhoneVehicles.filter(v=>v.size===phSize);
   const ok=list.length>0;by('phAvailOk').classList.toggle('hidden',!ok);by('phAvailNone').classList.toggle('hidden',ok);
   if(!ok)by('phAvailNone').querySelector('strong').textContent='No '+phSize+' is available in this slot. Choose another date/time.';
   by('phAvailLead').textContent=`${list.length} available ${phSize} vehicles at ${html(start.replace('T',' '))} (MySQL).`;
   by('phVehList').innerHTML=list.map(v=>`<div class="service-card book-svc" data-id="${v.id}" style="padding:14px;cursor:pointer"><strong>${html(v.plate)} · ${html(v.model)}</strong><small>${html(v.zone)} · ${v.cap} seats</small></div>`).join('');
   by('phVehList').querySelectorAll('[data-id]').forEach(el=>el.onclick=()=>{phVeh='VH-'+el.dataset.id;by('phNext3').disabled=false;by('phVehList').querySelectorAll('.book-svc').forEach(x=>x.classList.toggle('selected',x===el));});
  }catch(e){phErr('phErr2',html(e.message));by('phAvailLead').textContent='Unable to check availability';by('phAvailOk').classList.add('hidden');by('phAvailNone').classList.remove('hidden');}
 };
 phDemoAvail=function(){const date=by('phDate').value;const time=by('phTime').value;const d=new Date(date+'T'+time+':00');d.setHours(d.getHours()+1);by('phDate').value=d.toISOString().slice(0,10);by('phTime').value=String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');phAvail();};
 phFare=async function(){if(!s2staff)return;try{const q=await request('phone_quote',phonePayload()),b=q.quote;
  by('phFlag').textContent=price(b.flag_fall);by('phDist').textContent=price(b.distance_fare+b.daily_fare);
  by('phDistLbl').textContent=phonePayload().service_id===4?`Daily hire (${phonePayload().hire_days} day(s))`:`Distance rate (${b.distance_miles} mi × ${price(b.per_mile_rate)})`;
  by('phSur').textContent=price(b.airport_surcharge);by('phBase').textContent=price(b.base_total);by('phTotal').textContent=price(b.final_total);by('phConfirmAmt').textContent=price(b.final_total);
  const caller=phMode==='existing'?CALLERS[phCaller]?.name:by('ncName').value;
  by('phSumCaller').textContent=caller||'Unknown';by('phSumService').textContent=by('phService').value;
  by('phSumRoute').textContent=PLACES[+by('phPickup').value][0]+' → '+PLACES[+by('phDest').value][0];
  by('phSumDate').textContent=fmt(by('phDate').value+' '+by('phTime').value);
  const v=s2PhoneVehicles.find(x=>'VH-'+x.id===phVeh);
  by('phSumVeh').textContent=by('phPass').value+' · '+(v?.plate||'—')+' ('+SIZES[phSize].name+')';
  if(b.discount!==0)phErr('phErr4','Telephone booking must not receive a 15% online discount.');else phErr('phErr4','');
 }catch(e){phErr('phErr4',html(e.message));}}
 phConfirm=async function(){if(s2Busy)return;if(!by('phConfirmChk').checked)return phErr('phErr4','Confirm the fare first.');const v=s2PhoneVehicles.find(x=>'VH-'+x.id===phVeh);if(!v)return phErr('phErr4','Vehicle not selected.');
  const p=phonePayload();Object.assign(p,{minicab_id:v.id,caller_mode:phMode,verified:[...document.querySelectorAll('.idchk')].map(c=>c.checked),customer_id:CALLERS[phCaller]?.customer_id,verified_phone:CALLERS[phCaller]?.phone,customer:{name:by('ncName').value,email:by('ncEmail').value,phone:by('ncPhone').value,address:by('ncAddr').value}});
  s2Busy=true;try{const r=await request('phone_create',p);
   by('phStep4').classList.remove('active');by('phDone').classList.remove('hidden');document.querySelector('#page-phone .booking-stepper').classList.add('hidden');
   by('phDone').querySelector('h2,h3').textContent='Telephone booking saved: '+r.ref;
   const msg=by('phDone').querySelector('p');if(msg)msg.textContent=`Confirmed standard fare ${price(r.fare.final_total)}. Dispatch queued; GPS delivery requires Control Centre setup.`;
   toast('Saved booking '+r.ref+' to MySQL');
  }catch(e){phErr('phErr4','<strong>Unable to confirm booking:</strong> '+html(e.message));}finally{s2Busy=false;}
 };
 // US33: Control Centre dispatch data, not demo rows.
 async function loadDispatch(){if(!s2staff)return;try{const r=await request('dispatch_list');s2Dispatch=r.jobs;DQ.length=0;
  for(const b of s2Dispatch){const p=b.payload||{customer:{phone:'—'},pickup:{lat:'—',lng:'—'},destination:{lat:'—',lng:'—'}};DQ.push({id:b.id,ref:b.ref,ch:b.channel,cust:b.customer,phone:p.customer.phone,route:b.pickup+' → '+b.dest,when:fmt(b.date_time),pax:b.pax,size:b.size,veh:b.vehicle,pu:`${p.pickup.lat}, ${p.pickup.lng}`,de:`${p.destination.lat}, ${p.destination.lng}`,st:({Pending:'pending',Forwarded:'forwarded',Synced:'synced',Failed:'failed'})[b.dispatch_status]||'pending',fwd:fmt(b.forwarded_at),gps:b.gps_reference||'—',err:b.dispatch_error||'',bad:b.dispatch_bad||''});}
  renderDispatch();by('gpsSyncText').textContent='Control Centre link: '+(s2Dispatch.some(x=>x.dispatch_status==='Forwarded')?'Some bookings accepted':'No confirmed remote sync yet');
  by('gpsChip').textContent='Database queue';
 }catch(e){DQ.length=0;renderDispatch();displayError(e);}}
 const originalRenderDispatch=renderDispatch;renderDispatch=function(){originalRenderDispatch();};
 dqForward=async function(i){const b=DQ[i];if(!b)return;try{const r=await request('dispatch_forward',{booking_id:b.id});toast(r.status==='Pending'?'Dispatch queued; GPS endpoint not configured':b.ref+' forwarded successfully');await loadDispatch();}catch(e){toast(e.message);await loadDispatch();}};
 forwardAllPending=async function(){const targets=DQ.filter(x=>x.st==='pending'||x.st==='failed');for(const b of targets){try{await request('dispatch_forward',{booking_id:b.id});}catch(e){toast(b.ref+': '+e.message);}}await loadDispatch();};
 const originalOpenDispatch=openDispatch;
 openDispatch=function(i){if(!DQ[i])return;originalOpenDispatch(i);const row=s2Dispatch.find(x=>x.id===DQ[i].id);if(row){by('dqSms').textContent='GPS and SMS require configured providers. No delivery is implied by Pending status.';}}
 // Disable prototype demo command buttons, and replace printed static state with server data.
 by('demoToolbar')?.remove();
 BOOKINGS.length=0;FLEET.length=0;CALLERS.length=0;STAFF.length=0;DQ.length=0;
 by('flDate').value=nextLondonTime();
 // Very lightweight periodic refresh; no background server worker is implied.
 interval=setInterval(()=>{if(loggedRole()==='staff'&&by('page-fleet').classList.contains('active'))loadFleet();},30000);
 bootstrap();
})();
