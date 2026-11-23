"""Runs Chromium + supplied UI with a MOCK fetch, no MySQL/gateway access."""
from pathlib import Path
import json,re
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
html=(ROOT/'sprint2.html').read_text(encoding='utf-8')
js=(ROOT/'sprint2.js').read_text(encoding='utf-8')
html=re.sub(r'<link[^>]*>', '', html)
html=html.replace('<script src="sprint2.js"></script>','<script>'+js+'</script>')
booking={'id':7,'ref':'PHC-000007','customer':'Test Customer','email':'test@example.com','service':'Airport & Hotel Transfers','pickup':'Heathrow Airport, Terminal 5','dest':'The Savoy Hotel, Strand','date_time':'2026-12-01 14:30:00','size':'6-Seat MPV','pax':2,'base':100,'discount':15,'total':85,'channel':'Online','status':'Held','hold_until':'2026-12-01 13:30:00','hold_until_iso':'2026-12-01T13:30:00+00:00','vehicle':'LD24 MPV','email_status':'Queued','opt_in_email':True}
paid={**booking,'status':'Paid & Confirmed','txn':'SBOX-123456789AB','card_last4':'4242','method':'Credit Card','paid_at':'2026-11-21 14:30:00','gateway_mode':'Sandbox','paid':85}
errors=[]

def document(mode):
 staff={'staff_id':1,'id':'EMP-0001','name':'Coursework Admin','email':'admin@example.com','phone':'07700900101','role':'Admin','status':'Active','must_change_password':False,'created':'2026-11-21 09:00:00','login':'2026-11-21 09:00:00','deactAt':None,'self':True}
 responses={
  'bootstrap':{'ok':True,'csrf':'test-token','customer':{'customer_id':1,'full_name':'Test Customer','email':'test@example.com'} if mode=='customer' else None,'staff':({**staff,'role':'Call Centre Staff','name':'Test Operator','id':'EMP-0002','self':False} if mode=='staff' else staff) if mode in ('staff','admin') else None,'sandbox':True},
  'payment_context':{'ok':True,'booking':booking},'my_bookings':{'ok':True,'bookings':[paid]},'receipt':{'ok':True,'receipt':paid},
  'arrival_log':{'ok':True,'booking':paid,'mobile':'07700900101','vehicle':{'plate':'LD24 MPV','model':'VW Sharan','colour':'Grey','driver':'Sample Driver 3'},'log':[]},
  'fleet':{'ok':True,'vehicles':[{'minicab_id':3,'registration_no':'LD24 MPV','make_and_model':'VW Sharan','vehicle_colour':'Grey','category_id':2,'category_name':'6-Seat MPV','max_passengers':6,'zone':'Heathrow','operational_status':'Available'}],'total':4},
  'dispatch_list':{'ok':True,'jobs':[]},'customer_search':{'ok':True,'customers':[]},
  'staff_list':{'ok':True,'staff':[staff],'page':1,'per':10,'total':1},'staff_profile':{'ok':True,'staff':staff},
 }
 shim=f'''<script>window.fetch=async function(url,options){{const action=new URL(String(url),'http://test.local/').searchParams.get('action');const data={json.dumps(responses)}[action]||{{ok:false,error:'Mock does not support '+action}};return {{ok:data.ok,status:data.ok?200:404,json:async()=>data}};}};</script>'''
 return html.replace('<script>\n    // =====================================================================\n    // SPRINT 2 PROTOTYPE SCRIPT',shim+'\n<script>\n    // =====================================================================\n    // SPRINT 2 PROTOTYPE SCRIPT')

with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for mode in ('customer','admin','staff'):
  page=browser.new_page()
  page.on('pageerror',lambda exc: errors.append(str(exc)))
  page.set_content(document(mode),wait_until='domcontentloaded')
  page.wait_for_timeout(150)
  assert page.locator('.page-view.active').count()==1,mode+' active view missing'
  actual=page.locator('.page-view.active').get_attribute('id')
  print(mode, 'active page:',actual,'displayed name:',page.locator('#userStatusLabel').inner_text(),'error:',page.locator('#s2AuthError').inner_text())
  if mode=='customer':
   assert actual=='page-payment',actual
   assert page.locator('#payBtnAmount').inner_text()=='£85.00'
   page.evaluate("switchPage('bookings')")
   page.wait_for_timeout(120)
   print('booking rows:',page.locator('#bkBody tr').count())
   page.locator('#bkBody button').click()
   page.wait_for_timeout(120)
   assert page.locator('#rcRef').inner_text()=='PHC-000007'
   assert page.locator('#page-receipt dl.kv dd').first.inner_text()=='Test Customer'
   print('receipt fetched with customer name, reference and amount')
  elif mode=='admin':
   assert actual=='page-staff',actual
   page.wait_for_timeout(120)
   assert page.locator('#staffBody tr').count()==1
   page.locator('#staffBody tr').first.click()
   page.wait_for_timeout(120)
   assert page.locator('#pfName').inner_text()=='Coursework Admin'
   print('admin directory and profile mapped from API')
  elif mode=='staff':
   assert actual=='page-fleet'
   page.wait_for_timeout(140)
   assert page.locator('#fleetBody tr').count()==1
   assert page.locator('#fleetBody tr').inner_text().find('LD24 MPV')>=0
   assert page.locator('#fleetBody').inner_text().find('LD24 AAC')==-1
   print('call centre real fleet API data rendered (not prototype rows)')
  page.close()
 browser.close()
print('Browser JS exceptions:', errors)
if errors:raise SystemExit(1)
