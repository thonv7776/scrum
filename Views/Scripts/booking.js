/* Sprint 1 real PHP/MySQL integrations. The supplied HTML/CSS and map remain visually intact.
 * No browser-side mock login, approval, or payment success.
 */
'use strict';
const PHC = {
    csrf: '',
    user: null,
    services: [],
    categories: [],
    links: [],
    activeService: 3,
    activeDetailService: 3,
    quote: null,
    availability: null,
    driverTrip: 20,
    driverDay: 20,
    serverDate: null,
    held: false,
    initialized: false
};
const $ = id => document.getElementById(id);
const gbp = n => '£' + Number(n || 0).toFixed(2);
const oldGoStep = goStep;
const oldUpdateRoute = updateMapRouteUI;
const oldSelectBookingService = selectBookingService;
const oldViewServiceDetails = viewServiceDetails;
const oldSwitchPage = switchPage;
const oldApplyAuthState = applyAuthState;
const oldPickVehicleSize = pickVehicleSize;
function notice(id, text) {
    let e = $(id);
    if (!e)
        return;
    e.textContent = text || '';
    e.classList.toggle('hidden', !text);
}

function setBusy(id, busy) {
    const e = $(id);
    if (e)
        e.disabled = !!busy;
}

async function api(action, data) {
    const r = await fetch('api.php?action=' + encodeURIComponent(action), {
        method: data === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: data === undefined ? {} : {
            'Content-Type': 'application/json',
            'X-CSRF-Token': PHC.csrf
        },
        body: data === undefined ? undefined : JSON.stringify(data)
    });
    let j;
    try {
        j = await r.json();
    }
    catch (_) {
        throw new Error('Server is unavailable. Start Apache/MySQL and open the site using http://localhost/... (not file://).');
    }
    if (!r.ok || !j.ok) {
        const ex = new Error(j.error || 'Request failed');
        ex.fields = j.fields || {};
        ex.status = r.status;
        throw ex;
    }
    return j;
}

function showLoginIfUnauthorized(e) {
    if (e.status === 401 || e.status === 419) {
        if (e.status === 401) {
            PHC.user = null;
            isGuest = true;
            oldApplyAuthState();
            oldSwitchPage('login');
        }
        else
            location.reload();
        return true;
    }
    return false;
}

function updateAuth() {
    isGuest = !PHC.user;
    oldApplyAuthState();
    if (PHC.user) {
        $('userStatusLabel').textContent = PHC.user.full_name;
        $('userAvatar').textContent = PHC.user.full_name.trim().split(/\s+/).slice(0, 2).map(x => x.charAt(0)).join('').toUpperCase();
    }
}
window.switchPage = function (page) {
    if (page === 'booking' && !PHC.user) {
        oldSwitchPage('login');
        return;
    }
    oldSwitchPage(page);
    if (page === 'booking')
        oldGoStep(currentStep);
};
window.toggleGuestMode = async function () {
    if (!PHC.user)
        return;
    try {
        const r = await api('logout', {});
        PHC.csrf = r.csrf;
        PHC.user = null;
        PHC.availability = null;
        PHC.held = false;
        updateAuth();
        oldSwitchPage('services');
    }
    catch (e) {
        alert(e.message);
    }
};

function clearRegistrationErrors() {
    document.querySelectorAll('#page-register .field-group').forEach(group => group.classList.remove('has-error'));
    document.querySelectorAll('#page-register .backend-error').forEach(e => e.remove());
    document.querySelectorAll('#page-register .field-error[data-original-text]').forEach(e => {
        const text = [
            ...e.childNodes
        ].reverse().find(n => n.nodeType === 3);
        if (text)
            text.nodeValue = e.dataset.originalText;
    });
    notice('registerGlobalError', '');
}

function registrationFieldError(field, msg) {
    const input = $('reg_' + field);
    if (!input)
        return;
    const group = input.closest('.field-group');
    if (!group)
        return;
    group.classList.add('has-error');
    // Use the original field-error area, instead of showing an unrelated hardcoded
    // duplicate-email message together with a second dynamic error.
    const err = group.querySelector('.field-error');
    if (err) {
        let text = [
            ...err.childNodes
        ].reverse().find(n => n.nodeType === 3);
        if (!text) {
            text = document.createTextNode('');
            err.append(text);
        }
        if (!err.hasAttribute('data-original-text'))
            err.dataset.originalText = text.nodeValue;
        text.nodeValue = ' ' + msg;
    }
    else {
        const n = document.createElement('div');
        n.className = 'backend-error';
        n.textContent = msg;
        input.insertAdjacentElement('afterend', n);
    }
}
window.submitRegistration = async function () {
    // A previous successful submission must never remain visible when the next
    // submission fails (especially with an already registered email).
    $('registerSuccessAlert').classList.add('hidden');
    $('devVerificationLink').classList.add('hidden');
    clearRegistrationErrors();
    const d = {};
    [
        'full_name',
        'email',
        'residential_address',
        'telephone_number',
        'password'
    ].forEach(k => d[k] = $('reg_' + k).value.trim());
    d.email = d.email.toLowerCase();
    let failed = false;
    for (const k of [
        'full_name',
        'email',
        'residential_address',
        'telephone_number',
        'password'
    ])
        if (!d[k]) {
            registrationFieldError(k, 'This field is required.');
            failed = true;
        }
    if (d.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) {
        registrationFieldError('email', 'Use a valid email address.');
        failed = true;
    }
    if (d.telephone_number && !/^\d{10,11}$/.test(d.telephone_number)) {
        registrationFieldError('telephone_number', 'Telephone must contain 10–11 digits.');
        failed = true;
    }
    if (d.password && d.password.length < 8) {
        registrationFieldError('password', 'Password must have at least 8 characters.');
        failed = true;
    }
    if (failed)
        return;
    const btn = document.querySelector('#registerFormCard button[onclick="submitRegistration()"]');
    if (btn?.disabled)
        return;
    if (btn)
        btn.disabled = true;
    try {
        const result = await api('register', d);
        $('registerSuccessAlert').classList.remove('hidden');
        const p = $('registerSuccessAlert').querySelector('p');
        p.textContent = 'Your account is Pending Verification. Confirmation link created for ' + result.email + '. Activate within 24 hours.';
        if (result.mail.delivery !== 'submitted_to_mail_server' && result.mail.delivery !== 'submitted_to_provider')
            p.textContent += ' Email delivery is not configured on this server; use the local development link below or configure mail.';
        $('resendEmail').value = result.email;
        const link = $('devVerificationLink');
        link.classList.toggle('hidden', !result.mail.development_link);
        if (result.mail.development_link)
            link.href = result.mail.development_link;
        $('reg_password').value = '';
        notice('registerGlobalError', '');
    }
    catch (e) {
        // The backend is authoritative: a duplicate must remain an error even if
        // this page previously showed the success message.
        $('registerSuccessAlert').classList.add('hidden');
        $('devVerificationLink').classList.add('hidden');
        Object.entries(e.fields || {}).forEach(([k, v]) => registrationFieldError(k, v));
        notice('registerGlobalError', e.message || 'Registration failed. Please try again.');
    }
    finally {
        if (btn)
            btn.disabled = false;
    }
};
window.resendVerification = async function () {
    const email = $('resendEmail').value.trim() || $('loginEmail').value.trim();
    if (!email) {
        alert('Enter your email address to resend verification.');
        return;
    }
    try {
        const r = await api('resend', {
            email
        });
        alert(r.message + (r.mail.development_link ? '\nDevelopment verification link: ' + r.mail.development_link : ''));
    }
    catch (e) {
        alert(e.message);
    }
};
window.signInAndContinue = async function () {
    [
        'loginPendingAlert',
        'loginInvalidAlert',
        'loginVerifiedAlert'
    ].forEach(k => $(k).classList.add('hidden'));
    try {
        const r = await api('login', {
            email: $('loginEmail').value.trim(),
            password: $('loginPassword').value
        });
        PHC.user = r.user;
        PHC.csrf = r.csrf;
        updateAuth();
        $('loginPassword').value = '';
        notice('bookingGlobalError', '');
        oldSwitchPage('booking');
        oldGoStep(1);
    }
    catch (e) {
        if (e.status === 403) {
            $('loginPendingAlert').classList.remove('hidden');
            $('resendEmail').value = $('loginEmail').value;
        }
        else
            $('loginInvalidAlert').classList.remove('hidden');
    }
};
window.goToLoginVerified = function () {
    oldSwitchPage('login');
    $('loginVerifiedAlert').classList.remove('hidden');
};

function serviceRecord(id) {
    return PHC.services.find(s => Number(s.service_id) === Number(id));
}

function categoryRecord(id) {
    return PHC.categories.find(c => Number(c.category_id) === Number(id));
}

function selectedCategoryId() {
    return ({
        Saloon: 1,
        MPV: 2,
        Minibus: 3
    })[$('vehicleSizeSelect').value] || 1;
}

function hireMode() {
    return $('hireDeliverOption').checked ? 'delivery' : 'collection';
}

function hireHandoverLabel() {
    return hireMode() === 'delivery' ? 'Vehicle delivery: ' + $('hireDeliveryAddress').value.trim() : 'Customer collection: 20 Cong Hoa, Bay Hien, Ho Chi Minh City';
}

function getPayload() {
    const daily = PHC.activeService === 4;
    return {
        service_id: PHC.activeService,
        category_id: selectedCategoryId(),
        passenger_count: Number($('passengerInput').value),
        pickup_key: daily ? '' : pinA.key || '',
        destination_key: daily ? '' : pinB.key || '',
        hire_handover_mode: daily ? hireMode() : undefined,
        hire_delivery_address: daily && hireMode() === 'delivery' ? $('hireDeliveryAddress').value.trim() : '',
        flight_number: $('flightInput').value.trim(),
        hire_days: Number($('hireDaysInput').value),
        include_driver: daily && $('includeDriverToggle').checked
    };
}

function syncHireHandoverSummary() {
    const daily = PHC.activeService === 4;
    $('summaryPickupLabel').textContent = daily ? 'Vehicle handover' : 'Pickup Location';
    $('summaryDestLabel').textContent = daily ? 'Driver option' : 'Destination';
    $('summaryTimeLabel').textContent = daily ? 'Vehicle handover date & time' : 'Pickup Date & Time';
    if (daily) {
        $('summaryPickupText').textContent = hireHandoverLabel();
        $('summaryDestText').textContent = $('includeDriverToggle').checked ? 'With additional driver' : 'Self-drive';
    }
    else {
        $('summaryPickupText').textContent = pinA.name;
        $('summaryDestText').textContent = pinB.name;
    }
}

function syncHireModeUI() {
    const daily = PHC.activeService === 4;
    $('transferJourneyFields').classList.toggle('hidden', daily);
    if (daily)
        $('sameAddressAlert').classList.add('hidden');
    else
        oldUpdateRoute();
    $('dailyHireHandoverFields').classList.toggle('hidden', !daily);
    $('dailyHireDriverBox').classList.toggle('hidden', !daily);
    $('journeyStepHeading').textContent = daily ? 'Vehicle handover and car size' : 'Journey and minicab size';
    $('scheduleStepHeading').textContent = daily ? 'Vehicle handover date and time' : 'Pickup date and time';
    $('pickupDateLabel').textContent = daily ? 'Handover Date *' : 'Pickup Date *';
    $('pickupTimeLabel').textContent = daily ? 'Handover Time *' : 'Pickup Time *';
    $('fareDistanceLine').classList.toggle('hidden', daily);
    $('fareDriverLine').classList.toggle('hidden', !daily);
    const deliver = hireMode() === 'delivery';
    $('hireDeliveryAddressBox').classList.toggle('hidden', !deliver);
    $('hireCollectionLocation').classList.toggle('hidden', deliver);
    $('hireCollectCard').classList.toggle('selected', !deliver);
    $('hireDeliverCard').classList.toggle('selected', deliver);
    $('hireDeliveryAddress').required = daily && deliver;
    if (!daily) {
        $('includeDriverToggle').checked = false;
        syncDriverButton();
    }
    // Reuse the existing confirmation label, only the daily-hire wording changes.
    const n = $('bookingConfirmText');
    const amount = $('confirmAmount');
    n.replaceChildren(document.createTextNode(daily
        ? 'I confirm my vehicle handover choice, date, time, vehicle size, and final discounted hire amount ('
        : 'I confirm that my pickup location, destination, date, time, vehicle size, and final discounted fare ('), amount, document.createTextNode(daily ? ') are accurate. Hold my vehicle slot for 10 minutes.' : ') are accurate. Hold my minicab slot for 10 minutes.'));
    syncHireHandoverSummary();
}
window.hireHandoverChanged = function () {
    syncHireModeUI();
    invalidateAvailability();
    refreshQuote();
};

function invalidateAvailability() {
    PHC.availability = null;
    PHC.held = false;
    $('continueToSummaryBtn').disabled = true;
    $('fleetAvailableBox').classList.add('hidden');
    $('fleetUnavailableBox').classList.add('hidden');
    $('confirmScheduleCheckbox').checked = false;
    $('confirmScheduleCheckbox').disabled = false;
    $('proceedPaymentBtn').innerHTML = '<span class="material-symbols-outlined">lock_clock</span> Confirm Schedule & Proceed to Payment';
    toggleCheckoutButton();
    $('bookingHoldBanner').classList.add('hidden');
    $('paymentScopeNotice').classList.add('hidden');
}

function updateDriverHint() {
    $('driverRateHint').textContent = `Optional driver: +${gbp(PHC.driverDay)} per hire day. Self-drive is included in the standard daily-hire price.`;
}

function syncDriverButton() {
    const btn = $('includeDriverButton');
    if (!btn)
        return;
    const selected = $('includeDriverToggle').checked;
    btn.setAttribute('aria-pressed', String(selected));
    btn.classList.toggle('m3-btn-primary', selected);
    btn.classList.toggle('m3-btn-outlined', !selected);
    btn.innerHTML = '<span class="material-symbols-outlined">' + (selected ? 'check_circle' : 'person_add') + '</span> Include driver';
}
window.toggleIncludeDriver = function () {
    if (PHC.activeService !== 4)
        return;
    $('includeDriverToggle').checked = !$('includeDriverToggle').checked;
    syncDriverButton();
    driverChanged();
};
window.driverChanged = function () {
    syncDriverButton();
    syncHireHandoverSummary();
    invalidateAvailability();
    refreshQuote();
};
window.selectBookingService = function (id) {
    if (!serviceRecord(id))
        return;
    if (PHC.activeService !== Number(id)) {
        $('flightInput').value = '';
        $('hireDaysInput').value = '1';
        $('includeDriverToggle').checked = false;
        invalidateAvailability();
    }
    PHC.activeService = Number(id);
    oldSelectBookingService(id);
    syncHireModeUI();
    syncDriverButton();
    checkCapacityInline();
    updateDriverHint();
    refreshQuote();
};
window.startBookingWithService = function (id) {
    PHC.activeDetailService = id;
    if (!PHC.user) {
        oldSwitchPage('login');
        return;
    }
    selectBookingService(id);
    oldSwitchPage('booking');
    oldGoStep(1);
};
window.handleBookFromDetail = function () {
    closeServiceDetails();
    if (!PHC.user) {
        oldSwitchPage('login');
        return;
    }
    startBookingWithService(PHC.activeDetailService);
};
window.viewServiceDetails = function (id) {
    PHC.activeDetailService = id;
    const s = serviceRecord(id);
    if (!s) {
        oldViewServiceDetails(id);
        return;
    }
    oldViewServiceDetails(id);
    $('detailServiceTitle').textContent = s.service_name;
    $('detailServiceDescription').textContent = id === 4 ? 'Self-drive daily vehicle hire. Choose delivery to your address or collect the vehicle yourself; an optional driver can be added for an extra daily charge.' : s.scope_description;
    $('detailBaseRate').textContent = gbp(s.base_flag_fall_rate);
    $('detailSurcharge').textContent = gbp(s.airport_surcharge);
    $('detailMileageRate').textContent = id === 4 ? `${gbp(s.daily_hire_rate)} per day (up to 10h/day)` : '£2.00 – £3.50/mile by size';
    const grid = $('detailVehicleGrid');
    grid.replaceChildren();
    PHC.categories.filter(c => PHC.links.some(l => Number(l.service_id) === id && Number(l.category_id) === Number(c.category_id))).forEach(c => {
        const el = document.createElement('div');
        el.className = 'vehicle-card';
        el.innerHTML = '<div class="veh-icon"><span class="material-symbols-outlined">directions_car</span></div>';
        const heading = document.createElement('strong');
        heading.textContent = c.category_name;
        const specs = document.createElement('div');
        specs.className = 'veh-specs';
        specs.textContent = `Up to ${c.max_passengers} passengers · ${c.max_luggage} suitcases · ${gbp(c.per_mile_rate)}/mile`;
        el.append(heading, specs);
        grid.append(el);
    });
};
window.applyCatalogueFilter = function () {
    const svc = $('filterCategory').value;
    const pax = $('filterPassengers').value;
    const size = $('filterSize').value;
    const sizeID = size === 'Saloon' ? 1 : size === 'MPV' ? 2 : size === 'Minibus' ? 3 : null;
    let shown = 0;
    document.querySelectorAll('#catalogueGrid .service-card').forEach(card => {
        const id = Number(card.dataset.id);
        const compatible = PHC.links.filter(l => Number(l.service_id) === id).map(l => Number(l.category_id));
        const candidateCats = PHC.categories.filter(c => compatible.includes(Number(c.category_id)) && (sizeID === null || Number(c.category_id) === sizeID));
        const match = (svc === 'all' || Number(svc) === id) && candidateCats.some(c => pax === 'all' || Number(c.max_passengers) >= Number(pax));
        card.classList.toggle('hidden', !match);
        if (match)
            shown++;
    });
    $('serviceMatchCounter').textContent = `Showing ${shown} of ${PHC.services.length} services`;
    $('catalogueEmptyState').classList.toggle('hidden', shown !== 0);
    $('catalogueGrid').classList.toggle('hidden', shown === 0);
};
window.resetCatalogueFilter = function () {
    [
        'filterCategory',
        'filterPassengers',
        'filterSize'
    ].forEach(id => $(id).value = 'all');
    applyCatalogueFilter();
};

function updateFare(f) {
    PHC.quote = f;
    const s = serviceRecord(PHC.activeService);
    $('fareFlagFall').textContent = gbp(f.flag_fall);
    $('fareDistance').textContent = gbp(f.distance_fare);
    $('fareDistanceLabel').textContent = `Distance rate (${Number(f.distance_miles).toFixed(2)} miles × ${gbp(f.per_mile_rate)}/mile)`;
    $('fareDistanceLine').classList.toggle('hidden', PHC.activeService === 4);
    $('fareDriverLine').classList.toggle('hidden', PHC.activeService !== 4);
    $('fareDailyLine').classList.toggle('hidden', PHC.activeService !== 4);
    $('fareDailyLabel').textContent = `Daily hire (${Number($('hireDaysInput').value)} day(s) × ${gbp(f.daily_rate)})`;
    $('fareDaily').textContent = gbp(f.daily_fare);
    $('fareSurcharge').textContent = gbp(f.airport_surcharge);
    $('fareDriver').textContent = gbp(f.driver_fee);
    $('fareBaseTotal').textContent = gbp(f.base_total);
    $('fareDiscount').textContent = '-' + gbp(f.discount);
    $('fareFinalTotal').textContent = gbp(f.final_total);
    $('confirmAmount').textContent = gbp(f.final_total);
    $('summaryService').textContent = s?.service_name || 'Not selected';
    $('summaryExtra').textContent = PHC.activeService === 3 ? $('flightInput').value.trim() : PHC.activeService === 4 ? $('hireDaysInput').value + ' daily-hire days' : 'Standard point-to-point';
    $('summaryVehicle').textContent = `${$('passengerInput').value} passengers · ${categoryRecord(selectedCategoryId())?.category_name || 'Unknown size'}` + (PHC.activeService === 4 ? ` · Optional driver: ${$('includeDriverToggle').checked ? 'Yes' : 'No'}` : '');
    syncHireHandoverSummary();
    $('summaryDateTime').textContent = $('pickupDateInput').value && $('pickupTimeInput').value ? $('pickupDateInput').value + ' ' + $('pickupTimeInput').value + ' (London time)' : 'Not selected';
}
let quoteSerial = 0;
async function refreshQuote() {
    if (!PHC.initialized || !PHC.user)
        return;
    const mine = ++quoteSerial;
    try {
        const r = await api('quote', getPayload());
        if (mine === quoteSerial) {
            updateFare(r.quote);
            notice('routeError', '');
        }
    }
    catch (e) {
        if (mine === quoteSerial) {
            PHC.quote = null;
            notice('routeError', e.message);
        }
    }
}
window.pickVehicleSize = function (size) {
    oldPickVehicleSize(size);
    invalidateAvailability();
    refreshQuote();
    checkCapacityInline();
};

function checkCapacityInline() {
    const n = Number($('passengerInput').value), c = categoryRecord(selectedCategoryId());
    const over = !c || n < 1 || n > 8 || n > Number(c.max_passengers);
    $('capacityErrorAlert').classList.toggle('hidden', !over);
    const p = $('capacityErrorAlert').querySelector('p');
    if (over && p)
        p.textContent = `You selected ${n} passengers; ${c?.category_name || 'this size'} holds at most ${c?.max_passengers || 0}. Choose a larger minicab or correct the number.`;
    $('continueToScheduleBtn').disabled = over || (PHC.activeService !== 4 && pinA.key === pinB.key);
    return !over;
}
window.updateMapRouteUI = function () {
    oldUpdateRoute();
    const same = PHC.activeService !== 4 && pinA.key === pinB.key;
    $('continueToScheduleBtn').disabled = same || !checkCapacityInline();
    $('routeVerifiedChip').textContent = same ? 'Invalid route' : (!pinA.key || !pinB.key || pinA.key === 'custom' || pinB.key === 'custom') ? 'Select listed locations' : 'Estimated route (demo map)';
    if (PHC.initialized) {
        invalidateAvailability();
        refreshQuote();
    }
};
window.handleMapCanvasClick = function (evt) {
    // The supplied SVG is a stylized illustration, not a geocoding system.
    // Snap to a known landmark rather than incorrectly claiming arbitrary clicks are verified addresses.
    const svg = $('londonMapSvg');
    const p = svg.createSVGPoint();
    p.x = evt.clientX;
    p.y = evt.clientY;
    const m = svg.getScreenCTM();
    if (!m)
        return;
    const pos = p.matrixTransform(m.inverse());
    const closest = Object.values(londonLandmarks).sort((a, b) => Math.hypot(a.x - pos.x, a.y - pos.y) - Math.hypot(b.x - pos.x, b.y - pos.y))[0];
    assignPinLocation(mapPinMode, closest);
    setMapPinMode(mapPinMode === 'pickup' ? 'dest' : 'pickup');
};
window.goStep = async function (n) {
    if (n < 1 || n > 4)
        return;
    if (!PHC.user) {
        oldSwitchPage('login');
        return;
    }
    if (n <= currentStep) {
        oldGoStep(n);
        return;
    }
    if (n !== currentStep + 1) {
        notice('bookingGlobalError', 'Please complete the previous booking step first.');
        return;
    }
    notice('serviceError', '');
    notice('routeError', '');
    notice('scheduleError', '');
    notice('summaryError', '');
    try {
        if (currentStep === 1 && n === 2) {
            const d = getPayload();
            if (d.service_id === 3 && !d.flight_number)
                throw new Error('Enter your flight number and terminal (max 20 characters).');
            if (d.service_id === 4 && (!Number.isInteger(d.hire_days) || d.hire_days < 1 || d.hire_days > 30))
                throw new Error('Hire days must be 1–30.');
            await api('select_service', {
                service_id: d.service_id,
                flight_number: d.flight_number,
                hire_days: d.hire_days
            });
        }
        if (currentStep === 2 && n === 3) {
            if (!checkCapacityInline())
                throw new Error('Please correct the passenger count or choose a larger vehicle.');
            const d = getPayload();
            if (d.service_id === 4 && d.hire_handover_mode === 'delivery' && d.hire_delivery_address.length < 5)
                throw new Error('Enter a vehicle delivery address (at least 5 characters).');
            const r = await api('save_route', d);
            updateFare(r.quote);
            invalidateAvailability();
        }
        if (currentStep === 3 && n === 4) {
            if (!PHC.availability || !PHC.availability.available)
                throw new Error('Check availability and select an available slot first.');
            updateFare(PHC.quote);
            $('summaryDateTime').textContent = $('pickupDateInput').value + ' ' + $('pickupTimeInput').value + ' (London time)';
        }
        oldGoStep(n);
    }
    catch (e) {
        if (showLoginIfUnauthorized(e))
            return;
        notice(currentStep === 1 ? 'serviceError' : currentStep === 2 ? 'routeError' : 'scheduleError', e.message);
    }
};
window.jumpStep = function (n) {
    if (n <= currentStep)
        oldGoStep(n);
};
window.checkAvailability = async function () {
    notice('scheduleError', '');
    invalidateAvailability();
    const date = $('pickupDateInput').value, time = $('pickupTimeInput').value;
    if (!date || !time) {
        notice('scheduleError', 'Select both pickup date and time.');
        return;
    }
    setBusy('checkAvailabilityBtn', true);
    try {
        const r = await api('availability', {
            date,
            time
        });
        PHC.availability = {
            ...r,
            date,
            time
        };
        $('fleetAvailableBox').classList.toggle('hidden', !r.available);
        $('fleetUnavailableBox').classList.toggle('hidden', r.available);
        if (r.available) {
            $('fleetAvailableBox').querySelector('strong').textContent = `Available: ${categoryRecord(selectedCategoryId()).category_name} on ${date} at ${time}`;
            $('fleetAvailableBox').querySelector('p').textContent = `Vehicle ${r.vehicle.registration_no} (${r.vehicle.make_and_model}) is available for the estimated ${r.duration_minutes}-minute booking window.`;
            $('continueToSummaryBtn').disabled = false;
        }
        else {
            $('fleetUnavailableBox').querySelector('strong').textContent = `No ${categoryRecord(selectedCategoryId()).category_name} available at ${time} on ${date}`;
            $('fleetUnavailableBox').querySelector('p').textContent = 'Please select one of the genuinely available times below (if any), or try a different vehicle size.';
            const slots = $('fleetUnavailableBox').querySelectorAll('.slot-chip');
            slots.forEach((b, i) => {
                if (i < r.alternatives.length) {
                    const a = r.alternatives[i];
                    b.classList.remove('hidden');
                    b.textContent = a.date + ' ' + a.time + ' (Available)';
                    b.onclick = () => {
                        $('pickupDateInput').value = a.date;
                        $('pickupTimeInput').value = a.time;
                        checkAvailability();
                    };
                }
                else if (i === slots.length - 1) {
                    b.classList.remove('hidden');
                    b.textContent = 'Change vehicle size';
                    b.onclick = () => oldGoStep(2);
                }
                else
                    b.classList.add('hidden');
            });
            $('continueToSummaryBtn').disabled = true;
        }
    }
    catch (e) {
        if (!showLoginIfUnauthorized(e))
            notice('scheduleError', e.message);
    }
    finally {
        setBusy('checkAvailabilityBtn', false);
    }
};
window.toggleCheckoutButton = function () {
    const ready = $('confirmScheduleCheckbox').checked && !!PHC.availability?.available && !PHC.held;
    $('proceedPaymentBtn').disabled = !ready;
    $('confirmHint').classList.toggle('hidden', ready);
};
window.triggerHoldConfirmation = async function () {
    notice('summaryError', '');
    if (!$('confirmScheduleCheckbox').checked) {
        notice('summaryError', 'Confirm the schedule before reserving.');
        return;
    }
    setBusy('proceedPaymentBtn', true);
    try {
        const r = await api('hold', {
            confirmed: true
        });
        PHC.held = true;
        const banner = $('bookingHoldBanner');
        banner.classList.remove('hidden');
        banner.querySelector('strong').textContent = `Minicab Slot Reserved: 10-Minute Hold Active (Booking #${r.booking_id})`;
        const p = banner.querySelector('p');
        p.textContent = `${r.vehicle?.registration_no || 'Reserved vehicle'} · Final payable ${gbp(r.final_total)} · No payment has been charged.`;
        const badge = banner.querySelector('.rate-badge');
        badge.textContent = '10:00 remaining';
        // Date/time is stored in the London timezone; countdown based on 10-min hold start.
        const serverExpires = r.hold_until_iso ? Date.parse(r.hold_until_iso) : NaN;
        const expires = Number.isFinite(serverExpires) ? serverExpires : Date.now() + 600000;
        const timer = setInterval(() => {
            if (!PHC.held) {
                clearInterval(timer);
                return;
            }
            const left = Math.max(0, Math.ceil((expires - Date.now()) / 1000));
            badge.textContent = `Time Remaining: ${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
            if (!left) {
                clearInterval(timer);
                banner.classList.add('hidden');
                PHC.held = false;
                invalidateAvailability();
                notice('summaryError', 'Your 10-minute hold has expired. Please check availability again.');
            }
        }, 1000);
        $('paymentScopeNotice').classList.remove('hidden');
        $('proceedPaymentBtn').textContent = 'Booking slot held — payment not yet implemented';
        $('confirmScheduleCheckbox').disabled = true;
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }
    catch (e) {
        if (!showLoginIfUnauthorized(e))
            notice('summaryError', e.message);
        setBusy('proceedPaymentBtn', false);
    }
};

function londonParts(dt) {
    const p = {};
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/London',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
    }).formatToParts(dt).forEach(x => p[x.type] = x.value);
    return {
        date: p.year + '-' + p.month + '-' + p.day,
        time: p.hour + ':' + p.minute
    };
}

function updateTimeMin() {
    const earliest = londonParts(new Date(Date.now() + 31 * 60000));
    $('pickupDateInput').min = londonParts(new Date()).date;
    $('pickupTimeInput').min = $('pickupDateInput').value === earliest.date ? earliest.time : '00:00';
}

function setDefaultDate(serverNow) {
    const d = new Date(serverNow);
    const loc = londonParts(new Date(d.getTime() + 3600000));
    $('pickupDateInput').value = loc.date;
    $('pickupDateInput').min = londonParts(d).date;
    $('pickupTimeInput').value = loc.time;
    updateTimeMin();
}

async function initSprint1() {
    PHC.initialized = false;
    PHC.user = null;
    updateAuth();
    $('bookingHoldBanner').classList.add('hidden');
    try {
        const r = await api('bootstrap');
        PHC.csrf = r.csrf;
        PHC.services = r.services;
        PHC.categories = r.categories;
        PHC.links = r.service_categories;
        PHC.user = r.user;
        PHC.serverDate = r.server_time;
        PHC.driverTrip = r.driver_fee_per_trip;
        PHC.driverDay = r.driver_fee_per_day;
        updateAuth();
        setDefaultDate(r.server_time);
        syncHireModeUI();
        updateDriverHint();
        syncDriverButton();
        PHC.initialized = true;
        // Pull service summaries and pricing from database; do not use HTML demo values.
        PHC.services.forEach(s => {
            const card = document.querySelector(`#catalogueGrid .service-card[data-id="${s.service_id}"]`);
            if (card) {
                const h = card.querySelector('h3'), p = card.querySelector('p'), rate = card.querySelector('.rate-badge');
                if (h)
                    h.textContent = s.service_name;
                if (p)
                    p.textContent = Number(s.service_id) === 4 ? 'Self-drive daily vehicle hire. Car delivery or self-collection; optional driver available.' : s.short_tagline;
                if (rate)
                    rate.textContent = Number(s.service_id) === 4 ? gbp(s.daily_hire_rate) + '/day' : 'From ' + gbp(s.base_flag_fall_rate) + ' base';
            }
        });
        applyCatalogueFilter();
        if (r.flash?.type === 'verified') {
            oldSwitchPage('login');
            $('loginVerifiedAlert').classList.remove('hidden');
        }
        else if (r.flash?.type === 'verify_error') {
            oldSwitchPage('verify');
            setVerifyDemoState('expired');
        }
        else if (PHC.user) {
            oldSwitchPage('services');
        }
        else {
            oldSwitchPage('services');
        }
        // Default to the original selected Meet & Greet card, but without fake fares.
        await refreshQuote();
    }
    catch (e) {
        PHC.initialized = false;
        PHC.user = null;
        updateAuth();
        const box = document.createElement('div');
        box.className = 'm3-alert error';
        box.style.marginBottom = '16px';
        box.textContent = 'Could not connect to the booking service: ' + e.message;
        $('page-services').prepend(box);
    }
}
[
    'passengerInput',
    'flightInput',
    'hireDaysInput',
    'hireDeliveryAddress'
].forEach(id => $(id).addEventListener('input', () => {
    invalidateAvailability();
    checkCapacityInline();
    if (id === 'hireDeliveryAddress')
        syncHireHandoverSummary();
    refreshQuote();
}));
[
    'pickupDateInput',
    'pickupTimeInput'
].forEach(id => $(id).addEventListener('change', () => {
    updateTimeMin();
    invalidateAvailability();
    if (PHC.quote)
        updateFare(PHC.quote);
}));
window.addEventListener('DOMContentLoaded', initSprint1);
