import json
from pathlib import Path
from playwright.sync_api import sync_playwright
base=Path(__file__).parent.parent
html=(base/'index.html').read_text().replace('<script src="app.js" defer></script>', '<script>'+ (base/'app.js').read_text()+'</script>')
SERVICES=[dict(service_id=i,service_name=name,short_tagline='Test service',scope_description='Details',base_flag_fall_rate=base,airport_surcharge=sur,daily_hire_rate=daily) for i,name,base,sur,daily in [
 (1,'Local/Long Distance Journeys',5,0,None),(2,'Airport & Hotel Transfers',8,5,None),(3,'Meet & Greet at Airports',10,12,None),(4,'Driver & Car for Daily Hire',15,0,140)]]
CATS=[dict(category_id=i,category_name=name,max_passengers=pax,max_luggage=luggage,per_mile_rate=rate) for i,name,pax,luggage,rate in [(1,'4-Seat Saloon',4,2,2),(2,'6-Seat MPV',6,4,2.75),(3,'8-Seat Minibus',8,8,3.5)]]
LINKS=[dict(service_id=i,category_id=j) for i in range(1,5) for j in range(1,4)]
bootstrap=dict(ok=True,csrf='testcsrf',user=None,services=SERVICES,categories=CATS,service_categories=LINKS,flash=None,server_time='2026-11-19T10:00:00+00:00',driver_fee_per_trip=20,driver_fee_per_day=20)
pre_script = r"""
<script>
window.mockCalls={};
window.mockBootstrap=__BOOTSTRAP__;
window.fetch=async (input,options={})=>{
 const action=String(input).split('action=')[1].split('&')[0];
 const body=options.body?JSON.parse(options.body):{};
 window.mockCalls[action]=(window.mockCalls[action]||0)+1;
 let response={ok:true};
 if(action==='bootstrap')response=window.mockBootstrap;
 else if(action==='login')response={ok:true,csrf:'testcsrf',user:{customer_id:1,full_name:'Test User',email:'test@example.com'}};
 else if(action==='select_service')response={ok:true,booking_id:7};
 else if(action==='quote'||action==='save_route'){
   const services=window.mockBootstrap.services,categories=window.mockBootstrap.categories;
   const s=services.find(s=>s.service_id===body.service_id),c=categories.find(c=>c.category_id===body.category_id);
   if(body.pickup_key===body.destination_key)response={ok:false,error:'Pickup and destination must be different'};
   else if(body.passenger_count>c.max_passengers)response={ok:false,error:'Vehicle seating capacity exceeded'};
   else if(body.service_id===3&&!body.flight_number)response={ok:false,error:'Enter flight number'};
   else{
    const miles=18.5,flag=Number(s.base_flag_fall_rate),distance=body.service_id===4?0:Math.round(miles*c.per_mile_rate*100)/100;
    const daily=body.service_id===4?body.hire_days*140:0,extra=s.airport_surcharge;
    const driver=body.include_driver?(body.service_id===4?body.hire_days*20:20):0;
    const base=Number((flag+distance+daily+extra+driver).toFixed(2)),discount=Number((base*.15).toFixed(2));
    response={ok:true,quote:{flag_fall:flag,distance_fare:distance,daily_fare:daily,airport_surcharge:extra,driver_fee:driver,base_total:base,discount,final_total:Number((base-discount).toFixed(2)),distance_miles:miles,per_mile_rate:c.per_mile_rate,daily_rate:s.daily_hire_rate||0},booking_id:7};
   }
 }
 else if(action==='availability')response={ok:true,available:true,vehicle:{registration_no:'LD24 MPV',make_and_model:'Volkswagen Sharan MPV'},duration_minutes:70,alternatives:[]};
 else if(action==='hold')response={ok:true,booking_id:7,vehicle:{registration_no:'LD24 MPV'},final_total:64.5};
 else if(action==='logout')response={ok:true,csrf:'abc'};
 return new Response(JSON.stringify(response),{status:response.ok?200:422,headers:{'Content-Type':'application/json'}});
};
</script>
""".replace('__BOOTSTRAP__',json.dumps(bootstrap))
html=html.replace('</head>',pre_script+'</head>')
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 page=browser.new_page(viewport={'width':1440,'height':1000})
 errors=[]
 page.on('pageerror',lambda e: errors.append(str(e)))
 page.set_content(html,wait_until="load");page.wait_for_timeout(400)
 assert page.locator('#authButtons').is_visible(), 'Guest buttons visible'
 assert page.locator('#serviceMatchCounter').inner_text()=='Showing 4 of 4 services'
 page.locator('#filterPassengers').select_option('8');page.locator('#filterSize').select_option('Saloon')
 assert page.locator('#catalogueEmptyState').is_visible(), 'Impossible combination has empty state'
 page.get_by_role('button',name='Reset Filters').first.click()
 assert page.locator('#serviceMatchCounter').inner_text()=='Showing 4 of 4 services'
 page.locator('#tab-booking').click();assert page.locator('#page-login').is_visible(), 'Booking guest redirected to login'
 page.locator('#loginEmail').fill('test@example.com');page.locator('#loginPassword').fill('securepass8')
 page.locator('#page-login button',has_text='Sign In').first.click();page.wait_for_timeout(300)
 assert page.locator('#page-booking').is_visible(), 'Login opens booking'
 for i in range(1,5):
  page.locator('#bookSvc'+str(i)).click();page.wait_for_timeout(130)
  page.locator('#stepService button',has_text='Continue').click();page.wait_for_timeout(250)
  if i==3:
   # Meet-and-greet requires a flight number; leave blank first to test inline guard
   assert page.locator('#serviceError').is_visible(), 'Flight validation'
   page.locator('#flightInput').fill('BA2490 T5')
   page.locator('#stepService button',has_text='Continue').click();page.wait_for_timeout(220)
  assert page.locator('#stepRoute').is_visible(),f'Service {i} enters Journey'
  assert page.locator('#includeDriverButton').is_visible(),f'Include driver is in Journey for service {i}'
  page.locator('#includeDriverButton').click();page.wait_for_timeout(200)
  assert '£' in page.locator('#fareDriver').inner_text(),f'Driver line for service {i}'
  if i==4:assert page.locator('#fareDriver').inner_text()=='£20.00'
  page.locator('#stepRoute button',has_text='Back').first.click();page.wait_for_timeout(100)
 # End on service 2 for complete journey
 page.locator('#bookSvc2').click()
 page.locator('#stepService button',has_text='Continue').click();page.wait_for_timeout(240)
 page.locator('#passengerInput').fill('7')
 page.wait_for_timeout(140)
 assert page.locator('#capacityErrorAlert').is_visible(), 'Capacity error visible'
 page.locator('#passengerInput').fill('5');page.wait_for_timeout(130)
 page.locator('#includeDriverButton').click();page.wait_for_timeout(180)
 page.locator('#continueToScheduleBtn').click();page.wait_for_timeout(280)
 assert page.locator('#stepSchedule').is_visible(),'Continue to schedule'
 page.locator('#checkAvailabilityBtn').click();page.wait_for_timeout(280)
 assert page.locator('#fleetAvailableBox').is_visible(),'Fleet available'
 page.locator('#continueToSummaryBtn').click();page.wait_for_timeout(230)
 assert page.locator('#tripSummaryCard').is_visible(),'Trip summary'
 assert page.locator('#fareDriver').inner_text()=='£20.00','Driver fee in fare'
 assert page.locator('#proceedPaymentBtn').is_disabled(),'Confirmation guard'
 page.locator('#confirmScheduleCheckbox').check()
 assert page.locator('#proceedPaymentBtn').is_enabled()
 page.locator('#proceedPaymentBtn').click();page.wait_for_timeout(240)
 assert page.locator('#bookingHoldBanner').is_visible(),'10-minute hold visible'
 assert 'No payment has been charged' in page.locator('#bookingHoldBanner').inner_text()
 print('PASS browser: guest redirect, catalogue combined filtering/empty state, login, four service journeys, driver fee, flight validation, capacity, date, availability, quote, checkbox and hold')
 print('API actions:',page.evaluate('window.mockCalls'))
 print('JS console page errors:',errors)
 page.screenshot(path='/mnt/data/PrivateHire_Cars_Sprint1/tests/browser_result.png',full_page=False)
 browser.close()
 if errors:raise RuntimeError('Javascript errors: '+repr(errors))
