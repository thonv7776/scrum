/* Booking page: service selection, journey fields, and form interactions. */
let isGuest = false;
const serviceData = {
    1: {
        title: 'Local/Long Distance Journeys',
        desc: 'Door-to-door minicab service for local London errands or long-distance intercity journeys across the UK. Priced transparently by base flag-fall plus distance mileage rate according to your chosen vehicle category.',
        base: '£5.00',
        surcharge: '£0.00 (Standard point-to-point)',
        mileage: '£2.00 – £3.50 per mile',
        flagNum: 5.00,
        surNum: 0.00
    },
    2: {
        title: 'Airport & Hotel Transfers',
        desc: 'Direct, reliable scheduled transfers linking Heathrow, Gatwick, Stansted, Luton, and London City Airport with central London hotels and residences. Includes standard kerbside pickup/drop-off and full luggage assistance.',
        base: '£8.00',
        surcharge: '£5.00 Airport Transfer Surcharge',
        mileage: '£2.00 – £3.50 per mile',
        flagNum: 8.00,
        surNum: 5.00
    },
    3: {
        title: 'Meet & Greet at Airports',
        desc: 'Priority airport pickup service where your dedicated chauffeur monitors your live flight arrival time, waits inside the terminal arrivals hall holding a personalised name board, and assists with your luggage directly to the waiting minicab. Includes up to 45 minutes of complimentary waiting time after landing.',
        base: '£10.00',
        surcharge: '£12.00 (Includes 45 mins free waiting)',
        mileage: '£2.00 – £3.50 per mile',
        flagNum: 10.00,
        surNum: 12.00
    },
    4: {
        title: 'Driver & Car for Daily Hire',
        desc: 'Self-drive vehicle hire by the day (up to 10 hours per day). Choose vehicle delivery or customer collection, and add a driver if you prefer.',
        base: '£15.00',
        surcharge: '£140.00 per day (Up to 10 hours/day)',
        mileage: 'Included in Daily Hire package',
        flagNum: 15.00,
        surNum: 0.00
    }
};
// ===================================================================
// INTERACTIVE MAP ROUTE PICKER STATE & FUNCTIONS
// ===================================================================
const londonLandmarks = {
    heathrow: {
        key: 'heathrow',
        name: 'Heathrow Airport, Terminal 5',
        short: 'Heathrow T5',
        x: 95,
        y: 168
    },
    paddington: {
        key: 'paddington',
        name: 'Paddington Station',
        short: 'Paddington',
        x: 280,
        y: 125
    },
    baker: {
        key: 'baker',
        name: '221B Baker Street, Marylebone',
        short: 'Baker St',
        x: 332,
        y: 108
    },
    westminster: {
        key: 'westminster',
        name: 'Westminster Abbey, Central London',
        short: 'Westminster',
        x: 365,
        y: 168
    },
    savoy: {
        key: 'savoy',
        name: 'The Savoy Hotel, Strand',
        short: 'The Savoy Hotel',
        x: 405,
        y: 142
    },
    kingscross: {
        key: 'kingscross',
        name: "King's Cross St. Pancras",
        short: "King's Cross",
        x: 398,
        y: 86
    },
    canary: {
        key: 'canary',
        name: 'Canary Wharf Financial District',
        short: 'Canary Wharf',
        x: 518,
        y: 156
    },
    cityairport: {
        key: 'cityairport',
        name: 'London City Airport (LCY)',
        short: 'City Airport',
        x: 605,
        y: 152
    },
    gatwick: {
        key: 'gatwick',
        name: 'Gatwick Airport, South Terminal',
        short: 'Gatwick LGW',
        x: 395,
        y: 278
    }
};
let mapPinMode = 'pickup'; // 'pickup' | 'dest'
let pinA = {
    ...londonLandmarks.heathrow
};
let pinB = {
    ...londonLandmarks.savoy
};

function renderMapLandmarks() {
    const layer = document.getElementById('mapLandmarksLayer');
    if (!layer)
        return;
    layer.innerHTML = '';
    Object.values(londonLandmarks).forEach(loc => {
        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.setAttribute('class', 'map-landmark-node');
        g.setAttribute('transform', `translate(${loc.x}, ${loc.y})`);
        g.addEventListener('click', (e) => {
            e.stopPropagation();
            assignPinLocation(mapPinMode, {
                ...loc
            });
            // Automatically switch to Destination after picking Pickup for smooth UX
            if (mapPinMode === 'pickup')
                setMapPinMode('dest');
        });
        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('r', '5.5');
        circle.setAttribute('fill', '#ffffff');
        circle.setAttribute('stroke', '#0b57d0');
        circle.setAttribute('stroke-width', '2');
        const labelBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        const labelWidth = loc.short.length * 6.2 + 10;
        labelBg.setAttribute('x', String(-labelWidth / 2));
        labelBg.setAttribute('y', '9');
        labelBg.setAttribute('width', String(labelWidth));
        labelBg.setAttribute('height', '16');
        labelBg.setAttribute('rx', '6');
        labelBg.setAttribute('fill', 'rgba(255,255,255,0.88)');
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', '0');
        text.setAttribute('y', '20');
        text.setAttribute('fill', '#1f1f1f');
        text.setAttribute('font-size', '10');
        text.setAttribute('font-weight', '700');
        text.setAttribute('text-anchor', 'middle');
        text.textContent = loc.short;
        g.appendChild(circle);
        g.appendChild(labelBg);
        g.appendChild(text);
        layer.appendChild(g);
    });
}

function setMapPinMode(mode) {
    mapPinMode = mode;
    const isPickup = (mode === 'pickup');
    document.getElementById('modePickupBtn').classList.toggle('active-pickup', isPickup);
    document.getElementById('modeDestBtn').classList.toggle('active-dest', !isPickup);
    document.getElementById('pickupPinCard').classList.toggle('active-pickup', isPickup);
    document.getElementById('destPinCard').classList.toggle('active-dest', !isPickup);
    const badge = document.getElementById('mapHintBadge');
    const hintText = document.getElementById('mapHintText');
    badge.className = 'pin-dot ' + (isPickup ? 'pickup' : 'dest');
    badge.textContent = isPickup ? 'A' : 'B';
    hintText.textContent = isPickup
        ? 'Click any landmark or point on the map to set Pickup (A)'
        : 'Click any landmark or point on the map to set Destination (B)';
}

function selectPresetLocation(which, key) {
    const loc = londonLandmarks[key];
    if (!loc)
        return;
    assignPinLocation(which, {
        ...loc
    });
}

function handleMapCanvasClick(evt) {
    const svg = document.getElementById('londonMapSvg');
    const pt = svg.createSVGPoint();
    pt.x = evt.clientX;
    pt.y = evt.clientY;
    const svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
    const x = Math.max(24, Math.min(676, Math.round(svgP.x)));
    const y = Math.max(28, Math.min(288, Math.round(svgP.y)));
    // Snap to a preset landmark if clicked within 18px
    let nearest = null;
    let minDist = 18;
    Object.values(londonLandmarks).forEach(loc => {
        const d = Math.hypot(loc.x - x, loc.y - y);
        if (d < minDist) {
            minDist = d;
            nearest = loc;
        }
    });
    if (nearest) {
        assignPinLocation(mapPinMode, {
            ...nearest
        });
    }
    else {
        const zoneName = getCustomMapAreaName(x, y);
        assignPinLocation(mapPinMode, {
            key: 'custom',
            name: `Pinned Point (${zoneName})`,
            short: zoneName,
            x,
            y
        });
    }
    if (mapPinMode === 'pickup')
        setMapPinMode('dest');
}

function getCustomMapAreaName(x, y) {
    if (x < 170)
        return 'West London / Heathrow Corridor';
    if (x > 540)
        return 'East London / Docklands';
    if (y < 105)
        return 'North London';
    if (y > 220)
        return 'South London / Surrey Border';
    return 'Central London';
}

function swapMapLocations() {
    const temp = {
        ...pinA
    };
    pinA = {
        ...pinB
    };
    pinB = temp;
    updateMapRouteUI();
}

function assignPinLocation(which, locObj) {
    if (which === 'pickup') {
        pinA = locObj;
    }
    else {
        pinB = locObj;
    }
    updateMapRouteUI();
}

function updateMapRouteUI() {
    // Move Pin A & Pin B markers
    document.getElementById('mapPinA').setAttribute('transform', `translate(${pinA.x}, ${pinA.y})`);
    document.getElementById('mapPinB').setAttribute('transform', `translate(${pinB.x}, ${pinB.y})`);
    // Curved path between A and B
    const midX = (pinA.x + pinB.x) / 2;
    const midY = (pinA.y + pinB.y) / 2 - 22;
    const pathData = `M ${pinA.x} ${pinA.y} Q ${midX} ${midY} ${pinB.x} ${pinB.y}`;
    document.getElementById('mapRouteLine').setAttribute('d', pathData);
    document.getElementById('mapRouteGlow').setAttribute('d', pathData);
    document.getElementById('mapDistanceBadgeGroup').setAttribute('transform', `translate(${midX}, ${midY + 6})`);
    // Update card labels & dropdowns
    document.getElementById('pickupPinLabel').textContent = pinA.name;
    document.getElementById('destPinLabel').textContent = pinB.name;
    document.getElementById('pickupInput').value = pinA.name;
    document.getElementById('destinationInput').value = pinB.name;
    const sumPickup = document.getElementById('summaryPickupText');
    const sumDest = document.getElementById('summaryDestText');
    if (sumPickup)
        sumPickup.textContent = pinA.name;
    if (sumDest)
        sumDest.textContent = pinB.name;
    if (pinA.key && pinA.key !== 'custom') {
        document.getElementById('pickupPresetSelect').value = pinA.key;
    }
    if (pinB.key && pinB.key !== 'custom') {
        document.getElementById('destPresetSelect').value = pinB.key;
    }
    // Check if same location
    const pixelDist = Math.hypot(pinB.x - pinA.x, pinB.y - pinA.y);
    const isSameSpot = (pixelDist < 6 || pinA.name === pinB.name);
    const addrAlert = document.getElementById('sameAddressAlert');
    const capAlertHidden = document.getElementById('capacityErrorAlert').classList.contains('hidden');
    addrAlert.classList.toggle('hidden', !isSameSpot);
    document.getElementById('continueToScheduleBtn').disabled = isSameSpot || !capAlertHidden;
    if (isSameSpot) {
        document.getElementById('mapBadgeText').textContent = '0.0 miles';
        document.getElementById('routeDistanceSummary').textContent = 'Same point selected — Please pick two distinct pins';
        document.getElementById('routeVerifiedChip').textContent = 'Invalid Route';
        return;
    }
    // Compute realistic London mileage (Heathrow T5 -> Savoy is exactly 18.50 miles)
    let miles = 18.50;
    if (!(pinA.key === 'heathrow' && pinB.key === 'savoy') && !(pinA.key === 'savoy' && pinB.key === 'heathrow')) {
        miles = Math.max(1.8, +(pixelDist * 0.0595).toFixed(2));
    }
    const mins = Math.max(8, Math.round(miles * 2.95));
    document.getElementById('mapBadgeText').textContent = `${miles.toFixed(1)} miles`;
    document.getElementById('routeDistanceSummary').textContent = `${miles.toFixed(2)} miles • Approx. ${mins} mins via London Route`;
    document.getElementById('routeVerifiedChip').textContent = 'Verified Route';
}

function switchPage(pageKey) {
    document.querySelectorAll('.page-view').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    const targetPage = document.getElementById('page-' + pageKey);
    const targetTab = document.getElementById('tab-' + pageKey);
    if (targetPage)
        targetPage.classList.add('active');
    if (targetTab)
        targetTab.classList.add('active');
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

function toggleGuestMode() {
    isGuest = !isGuest;
    applyAuthState();
}
// Guest: show Sign In / Create Account buttons. Signed in: show the customer pill.
function applyAuthState() {
    document.getElementById('authButtons').classList.toggle('hidden', !isGuest);
    document.getElementById('userStatusPill').classList.toggle('hidden', isGuest);
}

function signInAndContinue() {
    isGuest = false;
    applyAuthState();
    switchPage('booking');
}

function goToLoginVerified() {
    switchPage('login');
    document.getElementById('loginVerifiedAlert').classList.remove('hidden');
}
// Booking wizard: one step visible at a time
let currentStep = 1;
let maxStepReached = 1;
const wizardStepIds = [
    'stepService',
    'stepRoute',
    'stepSchedule',
    'tripSummaryCard'
];
function goStep(n) {
    currentStep = n;
    maxStepReached = Math.max(maxStepReached, n);
    wizardStepIds.forEach((id, i) => {
        document.getElementById(id).classList.toggle('active', i + 1 === n);
    });
    for (let i = 1; i <= 4; i++) {
        const pill = document.getElementById('stepPill' + i);
        pill.classList.toggle('current', i === n);
        pill.classList.toggle('done', i < n);
        pill.classList.toggle('upcoming', i > n);
    }
    document.querySelector('.booking-stepper').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}

function jumpStep(n) {
    if (n <= maxStepReached)
        goStep(n);
}

function scrollToSection(id) {
    const el = document.getElementById(id);
    if (el)
        el.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
}
// Vehicle-size cards drive the (hidden) select so the rest of the logic is unchanged
function pickVehicleSize(size) {
    document.getElementById('vehicleSizeSelect').value = size;
    syncVehicleCards();
}

function syncVehicleCards() {
    const size = document.getElementById('vehicleSizeSelect').value;
    [
        'Saloon',
        'MPV',
        'Minibus'
    ].forEach(k => {
        const c = document.getElementById('vehCard' + k);
        if (!c)
            return;
        c.classList.toggle('selected', k === size);
        c.setAttribute('aria-pressed', String(k === size));
    });
}

function checkAvailability() {
    document.getElementById('availabilityResult').scrollIntoView({
        behavior: 'smooth',
        block: 'nearest'
    });
}

function toggleDemoToolbar() {
    const bar = document.getElementById('demoToolbar');
    const collapsed = bar.classList.toggle('collapsed');
    document.getElementById('demoToggleIcon').textContent = collapsed ? 'expand_less' : 'expand_more';
}

function viewServiceDetails(id) {
    const s = serviceData[id];
    document.getElementById('detailServiceTitle').textContent = s.title;
    document.getElementById('detailServiceDescription').textContent = s.desc;
    document.getElementById('detailBaseRate').textContent = s.base;
    document.getElementById('detailSurcharge').textContent = s.surcharge;
    document.getElementById('detailMileageRate').textContent = s.mileage;
    document.getElementById('serviceDetailDialog').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeServiceDetails() {
    document.getElementById('serviceDetailDialog').classList.add('hidden');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        closeServiceDetails();
});
function handleBookFromDetail() {
    closeServiceDetails();
    if (isGuest) {
        switchPage('login');
    }
    else {
        switchPage('booking');
    }
}

function startBookingWithService(id) {
    if (isGuest) {
        switchPage('login');
        return;
    }
    selectBookingService(id);
    switchPage('booking');
}

function applyCatalogueFilter() {
    const cat = document.getElementById('filterCategory').value;
    const cards = document.querySelectorAll('#catalogueGrid .service-card');
    let shown = 0;
    cards.forEach(c => {
        const match = (cat === 'all' || c.dataset.id === cat);
        c.classList.toggle('hidden', !match);
        if (match)
            shown++;
    });
    document.getElementById('serviceMatchCounter').textContent = `Showing ${shown} of 4 services`;
    document.getElementById('catalogueEmptyState').classList.toggle('hidden', shown > 0);
    document.getElementById('catalogueGrid').classList.toggle('hidden', shown === 0);
}

function resetCatalogueFilter() {
    document.getElementById('filterCategory').value = 'all';
    document.getElementById('filterPassengers').value = 'all';
    document.getElementById('filterSize').value = 'all';
    applyCatalogueFilter();
}

function selectBookingService(id) {
    for (let i = 1; i <= 4; i++) {
        document.getElementById('bookSvc' + i).classList.toggle('selected', i === id);
    }
    const isAirport = (id === 3); // Flight number & terminal: Meet & Greet at Airports only
    const isDaily = (id === 4);
    document.getElementById('dynamicFlightBox').classList.toggle('hidden', !isAirport);
    document.getElementById('dynamicHireDaysBox').classList.toggle('hidden', !isDaily);
    const s = serviceData[id];
    document.getElementById('summaryService').textContent = s.title;
    document.getElementById('summaryExtra').textContent = isAirport
        ? document.getElementById('flightInput').value
        : (isDaily ? '2 Days Hire (Up to 10h/day)' : 'Point-to-Point Direct Transfer');
    // Update live pricing breakdown
    let baseTotal = 0;
    if (isDaily) {
        const days = Number(document.getElementById('hireDaysInput').value || 2);
        const hireCost = days * 140;
        baseTotal = 15 + hireCost;
        document.getElementById('fareFlagFall').textContent = '£15.00';
        document.getElementById('fareDistance').textContent = `£${hireCost.toFixed(2)} (${days} days × £140/day)`;
        document.getElementById('fareSurcharge').textContent = '£0.00';
    }
    else {
        const distCost = +(18.5 * 2.75).toFixed(2);
        baseTotal = +(s.flagNum + distCost + s.surNum).toFixed(2);
        document.getElementById('fareFlagFall').textContent = `£${s.flagNum.toFixed(2)}`;
        document.getElementById('fareDistance').textContent = `£${distCost.toFixed(2)}`;
        document.getElementById('fareSurcharge').textContent = `£${s.surNum.toFixed(2)}`;
    }
    const discount = +(baseTotal * 0.15).toFixed(2);
    const finalPayable = +(baseTotal - discount).toFixed(2);
    document.getElementById('fareBaseTotal').textContent = `£${baseTotal.toFixed(2)}`;
    document.getElementById('fareDiscount').textContent = `-£${discount.toFixed(2)}`;
    document.getElementById('fareFinalTotal').textContent = `£${finalPayable.toFixed(2)}`;
}

function toggleCheckoutButton() {
    const checked = document.getElementById('confirmScheduleCheckbox').checked;
    document.getElementById('proceedPaymentBtn').disabled = !checked;
    document.getElementById('confirmHint').classList.toggle('hidden', checked);
}

function triggerHoldConfirmation() {
    const banner = document.getElementById('bookingHoldBanner');
    banner.classList.remove('hidden');
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

function setRegisterDemoState(mode) {
    const card = document.getElementById('registerFormCard');
    const successAlert = document.getElementById('registerSuccessAlert');
    card.classList.toggle('has-error', mode === 'errors');
    successAlert.classList.toggle('hidden', mode !== 'success');
}

function setVerifyDemoState(mode) {
    document.getElementById('verifyValidCard').classList.toggle('hidden', mode === 'expired');
    document.getElementById('verifyExpiredCard').classList.toggle('hidden', mode !== 'expired');
}

function setBookingDemoState(mode) {
    const capAlert = document.getElementById('capacityErrorAlert');
    const addrAlert = document.getElementById('sameAddressAlert');
    const availBox = document.getElementById('fleetAvailableBox');
    const unavailBox = document.getElementById('fleetUnavailableBox');
    const paxInput = document.getElementById('passengerInput');
    const sizeSelect = document.getElementById('vehicleSizeSelect');
    const routeBlocked = (mode === 'capacity_error' || mode === 'same_address');
    document.getElementById('continueToScheduleBtn').disabled = routeBlocked;
    document.getElementById('continueToSummaryBtn').disabled = (routeBlocked || mode === 'no_car');
    // Update map pins according to demo state
    pinA = {
        ...londonLandmarks.heathrow
    };
    pinB = (mode === 'same_address')
        ? {
            ...londonLandmarks.heathrow
        }
        : {
            ...londonLandmarks.savoy
        };
    updateMapRouteUI();
    capAlert.classList.toggle('hidden', mode !== 'capacity_error');
    addrAlert.classList.toggle('hidden', mode !== 'same_address');
    availBox.classList.toggle('hidden', mode === 'no_car');
    unavailBox.classList.toggle('hidden', mode !== 'no_car');
    if (mode === 'capacity_error') {
        paxInput.value = 6;
        sizeSelect.value = 'Saloon';
        document.getElementById('continueToScheduleBtn').disabled = true;
    }
    else {
        paxInput.value = 5;
        sizeSelect.value = 'MPV';
    }
    syncVehicleCards();
}

function setGlobalDemoState(state, btn) {
    document.querySelectorAll('#demoToolbar .demo-chip').forEach(b => b.classList.remove('active'));
    if (btn)
        btn.classList.add('active');
    // Reset defaults first
    setRegisterDemoState('normal');
    setVerifyDemoState('valid');
    setBookingDemoState('normal');
    resetCatalogueFilter();
    document.getElementById('loginPendingAlert').classList.add('hidden');
    document.getElementById('loginInvalidAlert').classList.add('hidden');
    document.getElementById('loginVerifiedAlert').classList.add('hidden');
    closeServiceDetails();
    maxStepReached = 4; // demo: allow jumping between steps
    goStep(1);
    if (state === 'reg_errors') {
        switchPage('register');
        setRegisterDemoState('errors');
    }
    else if (state === 'token_valid') {
        switchPage('verify');
    }
    else if (state === 'login_verified') {
        goToLoginVerified();
    }
    else if (state === 'same_address') {
        switchPage('booking');
        setBookingDemoState('same_address');
        goStep(2);
    }
    else if (state === 'token_expired') {
        switchPage('verify');
        setVerifyDemoState('expired');
    }
    else if (state === 'login_unverified') {
        switchPage('login');
        document.getElementById('loginPendingAlert').classList.remove('hidden');
    }
    else if (state === 'login_invalid') {
        switchPage('login');
        document.getElementById('loginInvalidAlert').classList.remove('hidden');
    }
    else if (state === 'filter_empty') {
        switchPage('services');
        document.getElementById('catalogueGrid').classList.add('hidden');
        document.getElementById('catalogueEmptyState').classList.remove('hidden');
        document.getElementById('serviceMatchCounter').textContent = 'Showing 0 of 4 services';
    }
    else if (state === 'capacity_error') {
        switchPage('booking');
        setBookingDemoState('capacity_error');
        goStep(2);
    }
    else if (state === 'no_car') {
        switchPage('booking');
        setBookingDemoState('no_car');
        goStep(3);
    }
}
// Initialize interactive map landmarks on load
renderMapLandmarks();
updateMapRouteUI();
