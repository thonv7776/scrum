/* UI adapter for the exact user-provided index_sprint2(5).html.
   All writes use Sprint 2 API and the existing Sprint 1 database/session.
   The original Sprint 1 files are neither edited nor replaced. */
(() => {
    'use strict';
    const el = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));
    const pound = value => '£' + Number(value || 0).toFixed(2);
    const dateText = value => value ? String(value).replace('T', ' ').slice(0, 19) : '—';
    const sizeKey = value => /minibus/i.test(value || '') ? 'Minibus' : (/mpv/i.test(value || '') ? 'MPV' : 'Saloon');
    const isPaid = b => [
        'Paid & Confirmed',
        'Forwarded to Control Centre'
    ].includes(b.status) && b.channel !== 'Telephone';
    const keyNames = [
        'heathrow',
        'paddington',
        'baker',
        'westminster',
        'savoy',
        'kingscross',
        'canary',
        'cityairport',
        'gatwick'
    ];
    const state = {
        csrf: '',
        customer: null,
        staff: null,
        booking: null,
        bookingId: Number(new URLSearchParams(location.search).get('booking_id') || 0),
        receipt: null,
        bookings: [],
        callers: [],
        caller: null,
        fleet: [],
        phoneCars: [],
        quote: null,
        dispatch: [],
        staffTotal: 0,
        categories: [],
        services: [],
        activePage: 'auth',
        loading: true,
        lastError: '',
        timers: []
    };
    const originalSwitch = window.switchPage;
    const originalPhInit = window.phInit;
    const originalPhShow = window.phShow;
    const originalFillProfile = window.fillProfile;
    const originalOpenEdit = window.openEdit;
    const originalRenderDispatch = window.renderDispatch;
    const originalOpenDispatch = window.openDispatch;
    const originalRenderSizes = window.renderSizes;
    const originalResetCreate = window.resetCreate;
    const originalValidateCard = window.validateCard;
    const originalPayView = window.payView;
    const originalSetPayStep = window.setPayStep;
    const originalPayCancel = window.visaCancel;
    const originalRenderNav = window.renderNav;
    async function api(action, data) {
        const get = action === 'bootstrap';
        let response;
        try {
            response = await fetch('sprint2_api.php?action=' + encodeURIComponent(action), {
                method: get ? 'GET' : 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: get ? {} : {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': state.csrf
                },
                ...(get ? {} : {
                    body: JSON.stringify(data || {})
                })
            });
        }
        catch (_) {
            throw new Error('Cannot connect to PHP. Start WAMP and open this page through localhost.');
        }
        let value;
        try {
            value = await response.json();
        }
        catch (_) {
            throw new Error('The PHP API did not return JSON. Check WAMP/PHP error logs.');
        }
        if (!response.ok || value.ok === false) {
            const ex = new Error(value.error || `Request failed (${response.status})`);
            ex.fields = value.fields || {};
            ex.status = response.status;
            throw ex;
        }
        if (value.csrf)
            state.csrf = value.csrf;
        return value;
    }
    function safeMessage(text, target) {
        const node = typeof target === 'string' ? el(target) : target;
        if (node) {
            node.textContent = text;
            node.classList.remove('hidden');
        }
        else
            toast(text);
    }
    function errorOf(err) {
        return err && err.message ? err.message : 'Request failed. Please try again.';
    }
    function displayErrors(err, fieldPrefix) {
        if (err?.fields)
            for (const [id, text] of Object.entries(err.fields))
                if (el(id))
                    setErr(id, String(text));
        if (fieldPrefix) {
            const n = el(fieldPrefix);
            if (n) {
                n.classList.remove('hidden');
                n.textContent = errorOf(err);
            }
        }
        else
            toast(errorOf(err));
    }
    function setPage(id) {
        document.querySelectorAll('.page-view').forEach(node => node.classList.remove('active'));
        const page = el('page-' + id);
        if (page)
            page.classList.add('active');
        state.activePage = id;
    }
    function accessFor(page) {
        if ([
            'payment',
            'receipt',
            'bookings',
            'arrival'
        ].includes(page))
            return !!state.customer;
        if ([
            'staff',
            'profile',
            'create'
        ].includes(page))
            return !!state.staff && state.staff.role === 'Admin' && !state.staff.must_change_password;
        if ([
            'fleet',
            'phone',
            'dispatch'
        ].includes(page))
            return !!state.staff && !state.staff.must_change_password && [
                'Admin',
                'Manager',
                'Call Centre Staff'
            ].includes(state.staff.role);
        return true;
    }
    function showGate(message) {
        state.activePage = 'auth';
        setPage('auth');
        el('authInfo').textContent = message || 'Sign in with your customer account, or sign in as staff.';
        el('authLoginError').classList.add('hidden');
    }
    function updateIdentity() {
        if (state.customer) {
            ROLES.customer.name = state.customer.full_name;
            ROLES.customer.sub = state.customer.email;
        }
        if (state.staff) {
            for (const r of (state.staff.role === 'Admin' ? [
                'admin',
                'staff'
            ] : [
                'staff'
            ])) {
                ROLES[r].name = state.staff.name;
                ROLES[r].sub = state.staff.role + ' · ' + state.staff.id;
            }
        }
        window.renderNav();
    }
    function permitted(target) {
        if (target === 'customer')
            return !!state.customer;
        if (target === 'admin')
            return state.staff?.role === 'Admin';
        if (target === 'staff')
            return !!state.staff;
        return false;
    }
    window.setRole = function (target) {
        if (!permitted(target)) {
            showGate(target === 'customer' ? 'Log in as a customer before continuing.' : 'Sign in to the Staff Portal first.');
            return;
        }
        role = target;
        updateIdentity();
        window.switchPage(ROLES[target].home);
    };
    window.toggleRoleMenu = function (event) {
        if (event)
            event.stopPropagation();
        const menu = el('roleMenu');
        menu.innerHTML = '';
        const options = [
            [
                'customer',
                'Customer workspace'
            ],
            [
                'staff',
                'Staff workspace'
            ],
            [
                'admin',
                'Admin workspace'
            ]
        ];
        for (const [key, title] of options) {
            if (!permitted(key))
                continue;
            const b = document.createElement('button');
            b.className = 'menu-item';
            b.type = 'button';
            b.textContent = title;
            b.addEventListener('click', () => window.setRole(key));
            menu.append(b);
        }
        const anchor = document.createElement('a');
        anchor.className = 'menu-item';
        anchor.href = '#sprint1';
        anchor.textContent = 'Book a Minicab · Customer sign-in';
        menu.append(anchor);
        if (state.staff) {
            const out = document.createElement('button');
            out.className = 'menu-item';
            out.textContent = 'Sign out of staff';
            out.addEventListener('click', async () => {
                try {
                    await api('staff_logout', {});
                    state.staff = null;
                    await start();
                }
                catch (e) {
                    toast(errorOf(e));
                }
            });
            menu.append(out);
        }
        menu.classList.toggle('hidden');
    };
    window.switchPage = function (page) {
        if (!accessFor(page)) {
            showGate(page === 'payment' ? 'Sign in and reserve a vehicle before checkout.' : 'You are not authenticated for this workspace.');
            return;
        }
        role = [
            'payment',
            'receipt',
            'bookings',
            'arrival'
        ].includes(page) ? 'customer' : ([
            'staff',
            'profile',
            'create'
        ].includes(page) ? 'admin' : 'staff');
        updateIdentity();
        originalSwitch(page);
        state.activePage = page;
        // Existing prototype handlers are retained for layout but their data are always overwritten by live results.
        if (page === 'payment')
            void loadPayment();
        if (page === 'bookings')
            void loadBookings();
        if (page === 'arrival')
            void loadArrival();
    };
    // -------- US15 / US20: authoritative checkout from the actual Sprint 1 hold --------
    function payWarning(message) {
        el('payForm').classList.add('hidden');
        const box = el('payAlert');
        box.innerHTML = '';
        const p = document.createElement('p');
        p.textContent = message;
        box.append(p);
        box.classList.remove('hidden');
        // Display errors even when card form is hidden.
        let info = el('s2CheckoutNotice');
        if (!info) {
            info = document.createElement('div');
            info.id = 's2CheckoutNotice';
            info.className = 'm3-alert warning';
            el('payForm').parentNode.prepend(info);
        }
        info.replaceChildren(document.createTextNode(message + ' '));
        const link = document.createElement('a');
        link.href = '#sprint1';
        link.textContent = 'Back to Home';
        info.append(link);
        info.classList.remove('hidden');
        el('payHoldBanner').classList.add('hidden');
    }
    function setTrip(b) {
        const strongs = [
            ...document.querySelectorAll('#page-payment .sticky tbody tr td strong')
        ];
        [
            b.service,
            b.pickup,
            b.dest,
            dateText(b.date_time),
            `${b.pax} · ${b.size}`
        ].forEach((v, i) => {
            if (strongs[i])
                strongs[i].textContent = v || '—';
        });
        const prices = el('page-payment').querySelectorAll('.sticky .price-line');
        if (prices[0])
            prices[0].querySelector('strong').textContent = pound(b.base);
        if (prices[1])
            prices[1].querySelector('span:last-child').textContent = '-' + pound(b.discount);
        if (prices[2])
            prices[2].querySelector('span:last-child').textContent = pound(b.total);
        el('payBtnAmount').textContent = pound(b.total);
        el('optInEmail').checked = true;
        window.optInHint();
        const banner = el('payHoldBanner');
        banner.classList.remove('hidden');
        banner.querySelector('p').textContent = `Booking ${b.ref} · ${b.size} · ${b.pickup}. No payment has been charged.`;
        clearInterval(state.holdInterval);
        const update = () => {
            const seconds = b.hold_until_iso ? Math.max(0, Math.floor((Date.parse(b.hold_until_iso) - Date.now()) / 1000)) : 0;
            el('payHoldTimer').textContent = `Time remaining: ${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
            if (seconds === 0) {
                clearInterval(state.holdInterval);
                el('payBtnAmount').textContent = 'Hold expired';
                const btn = el('payBtnAmount').closest('button');
                if (btn)
                    btn.disabled = true;
            }
        };
        update();
        state.holdInterval = setInterval(update, 1000);
    }
    async function loadPayment() {
        el('payForm').classList.add('hidden');
        el('payHoldBanner').classList.add('hidden');
        const notice = el('s2CheckoutNotice');
        if (notice)
            notice.classList.add('hidden');
        try {
            const r = await api('payment_context', {
                booking_id: state.bookingId || undefined
            });
            state.booking = r.booking;
            state.bookingId = r.booking.id;
            if (r.booking.status !== 'Held') {
                window.openReceipt(r.booking.ref);
                return;
            }
            el('payForm').classList.remove('hidden');
            el('payAlert').classList.add('hidden');
            originalPayView('form');
            originalSetPayStep(2);
            setTrip(r.booking);
            const btn = el('payBtnAmount').closest('button');
            if (btn)
                btn.disabled = false;
        }
        catch (e) {
            payWarning(errorOf(e));
        }
    }
    window.optInHint = function () {
        const email = esc(state.customer?.email || 'your registered email');
        el('optInHint').innerHTML = el('optInEmail').checked ? `Your confirmation will be queued for <strong>${email}</strong> after payment. Delivery requires a configured mail worker.` : 'No confirmation email will be queued. Your receipt remains in My Bookings.';
    };
    window.payNow = function () {
        if (!state.booking || state.booking.status !== 'Held') {
            toast('Reserve a vehicle before proceeding to payment.');
            return;
        }
        if (!originalValidateCard())
            return;
        el('visaLast4').textContent = el('cardNumber').value.replace(/\D/g, '').slice(-4);
        const visaAmount = el('visaChallenge').querySelectorAll('.price-line strong');
        if (visaAmount[2])
            visaAmount[2].textContent = pound(state.booking.total);
        originalSetPayStep(3);
        openDlg('visaDialog');
        el('visaProcessing').classList.add('hidden');
        el('visaChallenge').classList.remove('hidden');
    };
    window.visaVerify = async function (approved) {
        if (approved && el('visaCode').value.trim() !== '123456') {
            el('visaCode').setCustomValidity('For the coursework sandbox, enter test code 123456.');
            el('visaCode').reportValidity();
            return;
        }
        el('visaCode').setCustomValidity('');
        el('visaChallenge').classList.add('hidden');
        el('visaProcessing').classList.remove('hidden');
        el('visaProcTitle').textContent = 'Verifying sandbox card payment…';
        const d = {
            booking_id: state.bookingId,
            card_name: el('cardName').value.trim(),
            card_number: el('cardNumber').value.replace(/\D/g, ''),
            expiry: el('cardExp').value,
            cvv: el('cardCvv').value,
            opt_in_email: el('optInEmail').checked,
            verification: approved ? 'approved' : 'rejected'
        };
        try {
            const result = await api('pay', d);
            closeDlg('visaDialog');
            state.receipt = result.receipt;
            state.booking = result.receipt;
            el('cardCvv').value = '';
            el('cardNumber').value = '';
            originalPayView('approved');
            originalSetPayStep(4);
            el('payApproved').querySelector('p strong').textContent = result.receipt.ref;
            const sums = el('payApproved').querySelectorAll('.price-line strong');
            if (sums[0])
                sums[0].textContent = result.receipt.txn;
            if (sums[1])
                sums[1].textContent = '•••• ' + result.receipt.card_last4;
            el('redirText').textContent = 'Opening your saved receipt…';
            setTimeout(() => void window.openReceipt(result.receipt.ref), 500);
        }
        catch (e) {
            closeDlg('visaDialog');
            originalPayView('declined');
            originalSetPayStep(2);
            el('declTitle').textContent = 'Payment not completed';
            el('declText').textContent = errorOf(e);
            displayErrors(e);
        }
    };
    window.visaCancel = () => originalPayCancel();
    window.openEmailPreview = function () {
        const body = el('emailDialog');
        if (!body)
            return;
        const b = state.receipt || state.booking;
        if (!b) {
            toast('Please select a booking.');
            return;
        }
        const head = body.querySelector('.email-head');
        if (head)
            head.innerHTML = `<div><span class="muted">From:</span> PrivateHire Cars</div><div><span class="muted">To:</span> ${esc(b.email || state.customer?.email || '—')}</div><div><span class="muted">Subject:</span> Booking ${esc(b.ref)} confirmation (preview)</div>`;
        const intro = body.querySelector('.email-body p');
        if (intro)
            intro.textContent = `Dear ${b.customer || state.customer?.full_name || 'customer'}, this is a preview of the confirmation email. Actual delivery depends on the mail worker/provider.`;
        const fields = [
            ...body.querySelectorAll('.email-body .price-line strong')
        ];
        [
            b.ref,
            b.pickup,
            b.dest,
            dateText(b.date_time),
            b.size,
            pound(b.total)
        ].forEach((v, i) => {
            if (fields[i])
                fields[i].textContent = v || '—';
        });
        openDlg('emailDialog');
    };
    // -------- US17: booking history and receipts (no prototype records) --------
    window.filterBookings = function (filter = 'all') {
        bkFilter = filter;
        el('bkFilters').innerHTML = [
            [
                'all',
                'All'
            ],
            [
                'paid',
                'Paid & Confirmed'
            ],
            [
                'held',
                'Awaiting payment'
            ],
            [
                'cancelled',
                'Cancelled'
            ]
        ].map(([k, v]) => `<button class="slot-chip ${filter === k ? 'active' : ''}" onclick="filterBookings('${k}')">${v}</button>`).join('');
        const rows = state.bookings.filter(b => filter === 'all' || (filter === 'paid' ? isPaid(b) : filter === 'held' ? b.status === 'Held' : /cancel/i.test(b.status)));
        el('bkBody').innerHTML = rows.map(b => {
            const paid = isPaid(b), held = b.status === 'Held';
            const statusClass = paid ? 'success' : 'warning';
            const action = paid ? `<button class="m3-btn m3-btn-tonal m3-btn-sm" onclick="openReceipt('${esc(b.ref)}')">View receipt</button>` : held ? `<button class="m3-btn m3-btn-primary m3-btn-sm" onclick="s2Checkout(${Number(b.id)})">Pay now</button>` : '—';
            return `<tr><td><strong>${esc(b.ref)}</strong></td><td>${esc(b.service)}</td><td>${esc(dateText(b.date_time))}</td><td>${esc(b.pickup)}<br><span class="muted">→ ${esc(b.dest)}</span></td><td><span class="chip ${statusClass}">${esc(b.status)}</span></td><td style="text-align:right"><strong>${pound(b.total)}</strong></td><td>${action}</td></tr>`;
        }).join('');
        el('bkEmpty').classList.toggle('hidden', rows.length > 0);
        el('bkBody').closest('.m3-card').classList.toggle('hidden', !rows.length);
    };
    window.s2Checkout = function (id) {
        state.bookingId = Number(id);
        window.switchPage('payment');
    };
    async function loadBookings() {
        el('bkBody').innerHTML = '';
        el('bkEmpty').classList.add('hidden');
        try {
            const result = await api('my_bookings', {});
            state.bookings = result.bookings || [];
            window.filterBookings(bkFilter || 'all');
        }
        catch (e) {
            state.bookings = [];
            window.filterBookings('all');
            toast(errorOf(e));
        }
    }
    window.openReceipt = async function (ref) {
        if (!state.customer) {
            showGate('Customer login required to view a receipt.');
            return;
        }
        originalSwitch('receipt');
        state.activePage = 'receipt';
        el('receiptMain').classList.add('hidden');
        el('receiptDenied').classList.add('hidden');
        el('receiptMissing').classList.add('hidden');
        try {
            const result = await api('receipt', {
                ref
            });
            const b = result.receipt;
            state.receipt = b;
            el('receiptMain').classList.remove('hidden');
            const text = (id, value) => {
                if (el(id))
                    el(id).textContent = value ?? '—';
            };
            for (const [id, value] of Object.entries({
                rcRef: b.ref,
                rcService: b.service,
                rcPickup: b.pickup,
                rcDest: b.dest,
                rcDate: dateText(b.date_time),
                rcSize: b.size,
                rcTxn: b.txn,
                rcPaidAt: dateText(b.paid_at),
                rcCard: b.card_last4,
                rcBase: pound(b.base),
                rcDisc: '-' + pound(b.discount),
                rcFinal: pound(b.paid),
                rcIssued: dateText(b.paid_at)
            }))
                text(id, value);
            const name = el('receiptMain').querySelector('.kv dd');
            if (name)
                name.textContent = b.customer;
            const status = String(b.email_status || 'Not requested');
            el('rcEmailChip').innerHTML = `<span class="chip ${status === 'Sent' ? 'success' : status === 'Failed' ? 'error' : 'warning'}">${esc(status)}</span>`;
            el('rcEmailText').textContent = status === 'Sent' ? 'Configured delivery provider accepted the email message.' : status === 'Queued' ? 'Waiting in the email queue; delivery is not yet confirmed.' : status === 'Failed' ? 'Email delivery failed; receipt remains available here.' : 'No email was requested for this booking.';
        }
        catch (e) {
            el('receiptMissing').classList.remove('hidden');
            toast(errorOf(e));
        }
    };
    // Replace prototype PDF success toast with actual browser Print / Save as PDF.
    const pdfBtn = [
        ...el('page-receipt').querySelectorAll('button')
    ].find(b => b.textContent.includes('Download PDF'));
    if (pdfBtn) {
        pdfBtn.onclick = null;
        pdfBtn.addEventListener('click', () => window.print());
        pdfBtn.title = 'Select Save as PDF in the browser print dialog';
    }
    // -------- US23: SMS status from stored events, never fabricated --------
    window.setArrivalState = function () { };
    async function loadArrival() {
        try {
            const bookings = await api('my_bookings', {});
            const b = (bookings.bookings || []).find(x => isPaid(x));
            if (!b) {
                el('arrStateChip').textContent = 'No confirmed bookings';
                el('arrEta').textContent = 'No arrival tracking available';
                el('arrDelivery').textContent = 'Not sent';
                return;
            }
            const r = await api('arrival_log', {
                booking_id: b.id
            });
            const logs = r.log || [];
            const latest = logs[0];
            el('arrStateChip').className = 'chip ' + (latest?.status === 'Sent' ? 'success' : 'warning');
            el('arrStateChip').textContent = latest?.status === 'Sent' ? 'Arrival SMS sent' : latest?.status === 'Failed' ? 'SMS delivery failed' : 'Waiting for GPS arrival event';
            el('arrEta').textContent = latest?.eta_minutes != null ? latest.eta_minutes === 0 ? 'Arriving at pickup' : 'Arriving in ' + latest.eta_minutes + ' minutes' : 'ETA not yet reported';
            const spans = el('page-arrival').querySelectorAll('.split .m3-card.elevated');
            const first = spans[0];
            if (first) {
                const muted = first.querySelectorAll('.muted');
                if (muted[0])
                    muted[0].textContent = `Booking ${b.ref} · ${dateText(b.date_time)}`;
                if (muted[1])
                    muted[1].textContent = 'Pickup: ' + b.pickup;
                const mile = first.querySelector('.chip.success');
                if (mile)
                    mile.textContent = 'GPS location pending';
            }
            const vals = el('page-arrival').querySelectorAll('dl.kv dd');
            [
                r.vehicle?.plate,
                r.vehicle?.model,
                r.vehicle?.colour,
                r.vehicle?.driver,
                latest?.eta_minutes != null ? latest.eta_minutes + ' mins' : 'Not available',
                b.pickup
            ].forEach((x, i) => {
                if (vals[i])
                    vals[i].textContent = x || 'Not assigned';
            });
            el('arrSentAt').textContent = dateText(latest?.sent_at);
            el('arrDelivery').textContent = latest?.status || 'Not sent';
            const tr = el('arrSentAt').closest('tr');
            if (tr) {
                tr.querySelector('td:first-child').textContent = b.ref;
                tr.querySelector('td:nth-child(2)').textContent = dateText(latest?.trigger_at);
            }
            const carTrack = el('page-arrival').querySelector('.car-track');
            if (carTrack)
                carTrack.style.display = 'none';
            const phone = el('page-arrival').querySelector('.sms-bubble');
            if (phone)
                phone.textContent = latest?.body_text || 'An SMS will be generated after a GPS arrival event. No message has been sent yet.';
            const phoneText = el('page-arrival').querySelector('.sticky p strong');
            if (phoneText)
                phoneText.textContent = r.mobile || 'not registered';
            el('arrFailed').classList.toggle('hidden', latest?.status !== 'Failed');
            el('arrMissingMobile').classList.toggle('hidden', !!r.mobile);
        }
        catch (e) {
            el('arrStateChip').textContent = 'Tracking unavailable';
            toast(errorOf(e));
        }
    }
    // -------- US28: live fleet from MySQL with slot/size/zone filtering --------
    function soonDate(hours = 48) {
        const d = new Date(Date.now() + hours * 3600000);
        return d.toISOString().slice(0, 16);
    }
    window.renderFleet = async function () {
        const dt = el('flDate').value || soonDate();
        const [date, time = '14:30'] = dt.split('T');
        try {
            const result = await api('fleet', {
                date,
                time,
                size: el('flSize').value,
                zone: el('flZone').value,
                duration: 90
            });
            state.fleet = result.vehicles || [];
            el('stAvail').textContent = state.fleet.length;
            el('stTotal').textContent = result.total;
            el('stBusy').textContent = Math.max(0, Number(result.total) - state.fleet.length);
            el('fleetBody').innerHTML = state.fleet.map(c => `<tr><td><strong>VH-${Number(c.minicab_id)}</strong></td><td>${esc(c.registration_no)}</td><td>${esc(c.make_and_model)}</td><td>${esc(c.vehicle_colour)}</td><td>${esc(c.max_passengers)} seats · ${esc(c.category_name)}</td><td>${esc(c.zone || 'Not assigned')}</td><td><span class="chip success">Available</span></td><td><button class="m3-btn m3-btn-tonal m3-btn-sm" onclick="switchPage('phone')">Book by phone</button></td></tr>`).join('');
            el('fleetCount').textContent = `Showing ${state.fleet.length} available of ${result.total} vehicles`;
            el('fleetTableCard').classList.toggle('hidden', !state.fleet.length);
            el('fleetEmpty').classList.toggle('hidden', !!state.fleet.length);
            el('fleetLive').textContent = 'Live · updated ' + new Date().toLocaleTimeString('en-GB');
        }
        catch (e) {
            state.fleet = [];
            el('fleetBody').innerHTML = '';
            el('fleetTableCard').classList.add('hidden');
            el('fleetEmpty').classList.remove('hidden');
            el('fleetLive').textContent = 'Cannot refresh';
            toast(errorOf(e));
        }
    };
    window.refreshFleet = () => window.renderFleet();
    window.resetFleetFilters = function () {
        el('flDate').value = soonDate();
        el('flSize').value = 'all';
        el('flZone').value = 'all';
        window.renderFleet();
    };
    // Sprint 1 and Sprint 2 share ONE index.html; never navigate to a missing index.php.
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href="#sprint1"]');
        if (!link)
            return;
        event.preventDefault();
        event.stopPropagation();
        if (typeof window.parent?.closeSprint2Embedded === 'function')
            window.parent.closeSprint2Embedded();
        else
            location.hash = 'sprint1';
    }, true);
    // -------- US31: telephone booking, same Sprint 1 pricing/cars and actual customers --------
    function phServiceId() {
        return el('phService').selectedIndex + 1;
    }
    function categoryId() {
        const c = state.categories.find(x => sizeKey(x.category_name) === phSize);
        return Number(c?.category_id || 0);
    }
    function routePayload() {
        return {
            service_id: phServiceId(),
            category_id: categoryId(),
            pickup_key: keyNames[Number(el('phPickup').value)],
            destination_key: keyNames[Number(el('phDest').value)],
            passenger_count: Number(el('phPass').value),
            hire_days: Number(el('s2HireDays')?.value || 1),
            flight_number: el('s2Flight')?.value || '',
            include_driver: false,
            date: el('phDate').value,
            time: el('phTime').value
        };
    }
    function ensureCallerProofFields() {
        if (el('s2CallerProof'))
            return;
        const cards = document.querySelectorAll('#phStep1 .idchk');
        const last = cards[cards.length - 1]?.closest('.check-row');
        if (!last)
            return;
        const div = document.createElement('div');
        div.id = 's2CallerProof';
        div.className = 'm3-card tonal';
        div.style.marginTop = '14px';
        div.innerHTML = `<h3 style="margin:0 0 10px">Details provided by the caller (do not copy from the account)</h3>
  <div class="field-group"><label class="field-label" for="s2CallerName">Caller states their full name *</label><input class="m3-input" id="s2CallerName" autocomplete="off" maxlength="100" placeholder="Ask caller to state their full name"></div>
  <div class="field-group"><label class="field-label" for="s2CallerAddress">Caller states their residential address *</label><input class="m3-input" id="s2CallerAddress" autocomplete="off" maxlength="255" placeholder="Ask for house, street and postcode"></div>
  <div class="field-group"><label class="field-label" for="s2CallerPhone">Caller states their registered phone *</label><input class="m3-input" id="s2CallerPhone" autocomplete="off" inputmode="numeric" maxlength="11" placeholder="10–11 digits"></div>
  <p class="field-hint">These values must match the selected customer at the server. Staff checkboxes alone are not verification.</p>`;
        last.parentNode.after(div);
    }
    function callerProof() {
        return {
            name: el('s2CallerName')?.value.trim(),
            address: el('s2CallerAddress')?.value.trim(),
            phone: el('s2CallerPhone')?.value.trim()
        };
    }
    function callerProofComplete() {
        const d = callerProof();
        return d.name.length >= 2 && d.address.length >= 5 && /^\d{10,11}$/.test(d.phone);
    }
    window.phInit = function () {
        // Retain the supplied UI markup, service selector and visual stepper.
        if (el('phPickup').options.length === 0) {
            el('phPickup').innerHTML = PLACES.map((p, i) => `<option value="${i}">${esc(p[0])}</option>`).join('');
            el('phDest').innerHTML = el('phPickup').innerHTML;
            el('phPickup').value = '0';
            el('phDest').value = '4';
        }
        if (!el('s2ServiceExtra')) {
            const div = document.createElement('div');
            div.id = 's2ServiceExtra';
            div.className = 'form-grid-2';
            div.innerHTML = '<div class="field-group" id="s2FlightWrap"><label class="field-label" for="s2Flight">Flight number and terminal *</label><input class="m3-input" id="s2Flight" maxlength="20" placeholder="BA2490 – Terminal 5"></div><div class="field-group hidden" id="s2HireWrap"><label class="field-label" for="s2HireDays">Number of daily hire days *</label><input class="m3-input" id="s2HireDays" type="number" min="1" max="30" value="1"></div>';
            el('phService').closest('.field-group').parentNode.after(div);
            el('phService').addEventListener('change', () => {
                el('s2FlightWrap').classList.toggle('hidden', phServiceId() !== 3);
                el('s2HireWrap').classList.toggle('hidden', phServiceId() !== 4);
                state.quote = null;
            });
        }
        el('phService').dispatchEvent(new Event('change'));
        if (!el('phDate').value || el('phDate').value < new Date().toISOString().slice(0, 10))
            el('phDate').value = soonDate().slice(0, 10);
        if (!state.categories.length)
            el('phNext1').disabled = true;
        else
            el('phNext1').disabled = false;
        originalRenderSizes();
        phMax = 1;
        state.caller = null;
        state.callers = [];
        phMode = 'existing';
        el('callerSearch').value = '';
        document.querySelectorAll('.idchk').forEach(x => x.checked = false);
        el('phConfirmChk').checked = false;
        el('phConfirmBtn').disabled = true;
        el('phDone').classList.add('hidden');
        ensureCallerProofFields();
        [
            's2CallerName',
            's2CallerAddress',
            's2CallerPhone'
        ].forEach(id => {
            if (el(id))
                el(id).value = '';
        });
        window.callerMode('existing');
        originalPhShow(1);
        window.renderCallers();
    };
    window.renderCallers = async function () {
        const query = el('callerSearch').value.trim();
        if (!query || query.length < 2) {
            el('callerResults').innerHTML = '<p class="muted">Enter at least 2 characters to search registered customers.</p>';
            return;
        }
        try {
            const result = await api('customer_search', {
                q: query
            });
            state.callers = result.customers || [];
            el('callerResults').innerHTML = state.callers.length ? state.callers.map((c, i) => `<button type="button" class="service-card book-svc ${state.caller?.customer_id === c.customer_id ? 'selected' : ''}" style="padding:14px 52px 14px 16px;text-align:left" onclick="s2ChooseCaller(${i})"><strong>${esc(c.full_name)}</strong><span class="muted">${esc(c.telephone_number)} · ${esc(c.email)}</span><span class="muted">${esc(c.residential_address)}</span></button>`).join('') : '<p class="muted">No registered customer matches. Choose First-time caller to register.</p>';
        }
        catch (e) {
            el('callerResults').textContent = errorOf(e);
        }
    };
    window.s2ChooseCaller = function (i) {
        state.caller = state.callers[i];
        [
            's2CallerName',
            's2CallerAddress',
            's2CallerPhone'
        ].forEach(id => {
            if (el(id))
                el(id).value = '';
        });
        el('callerResults').querySelectorAll('.book-svc').forEach((n, k) => n.classList.toggle('selected', i === k));
    };
    window.phGo = function (next) {
        if (next < phStep) {
            originalPhShow(next);
            return;
        }
        if (next === 2) {
            if (phMode === 'existing' && !state.caller) {
                phErr('phErr1', 'Select a registered customer from the database.');
                return;
            }
            if (phMode === 'new' && (!el('ncName').value.trim() || !el('ncAddr').value.trim() || !/^\d{10,11}$/.test(el('ncPhone').value.replace(/\s/g, '')) || !EMAIL_RE.test(el('ncEmail').value.trim()))) {
                phErr('phErr1', 'Provide name, valid email, address and 10–11 digit phone for a new caller.');
                return;
            }
            if (![
                ...document.querySelectorAll('.idchk')
            ].every(x => x.checked)) {
                phErr('phErr1', 'Complete all three caller verification checks.');
                return;
            }
            if (phMode === 'existing' && !callerProofComplete()) {
                phErr('phErr1', 'Ask the caller to state their name, address and registered phone number.');
                return;
            }
            phErr('phErr1', '');
            el('phCallerName').textContent = phMode === 'existing' ? state.caller.full_name : el('ncName').value.trim();
        }
        if (next === 3) {
            if (!phCapacity() || !phRoute()) {
                phErr('phErr2', 'Correct passenger capacity and pickup/destination.');
                return;
            }
            if (!el('phDate').value || !el('phTime').value) {
                phErr('phErr2', 'Pickup date and time are required.');
                return;
            }
            if (phServiceId() === 3 && !el('s2Flight').value.trim()) {
                phErr('phErr2', 'Flight number and terminal are required for Meet & Greet.');
                return;
            }
            if (phServiceId() === 4 && !(Number(el('s2HireDays').value) >= 1 && Number(el('s2HireDays').value) <= 30)) {
                phErr('phErr2', 'Daily hire must be 1–30 days.');
                return;
            }
            phErr('phErr2', '');
            state.phoneCars = [];
            phVeh = null;
        }
        if (next === 4 && !state.phoneCars.find(v => Number(v.minicab_id) === Number(phVeh))) {
            phErr('phErr4', 'Select an available vehicle first.');
            return;
        }
        originalPhShow(next);
        if (next === 2)
            el('phCallerName').textContent = phMode === 'existing' ? state.caller.full_name : el('ncName').value.trim();
    };
    window.phAvail = async function () {
        const date = el('phDate').value, time = el('phTime').value, cat = phSize;
        el('phNext3').disabled = true;
        el('phVehList').textContent = 'Checking the live fleet…';
        try {
            const result = await api('fleet', {
                date,
                time,
                size: cat,
                zone: 'all',
                duration: phServiceId() === 4 ? Math.min(Number(el('s2HireDays').value || 1), 30) * 600 : 90
            });
            const rows = (result.vehicles || []).filter(v => Number(v.category_id) === categoryId());
            state.phoneCars = rows;
            if (!rows.some(v => Number(v.minicab_id) === Number(phVeh)))
                phVeh = rows[0]?.minicab_id || null;
            el('phAvailOk').classList.toggle('hidden', !rows.length);
            el('phAvailNone').classList.toggle('hidden', !!rows.length);
            el('phAvailLead').textContent = `${cat} vehicles available at ${dateText(date + ' ' + time)}.`;
            el('phVehList').innerHTML = rows.map(v => `<button type="button" class="service-card book-svc ${Number(v.minicab_id) === Number(phVeh) ? 'selected' : ''}" style="text-align:left;padding:14px 52px 14px 16px" onclick="s2SelectVehicle(${Number(v.minicab_id)})"><strong>${esc(v.registration_no)} · ${esc(v.make_and_model)}</strong><span class="muted">${esc(v.vehicle_colour)} · ${esc(v.max_passengers)} seats · ${esc(v.zone || 'Location unassigned')}</span></button>`).join('');
            el('phNext3').disabled = !phVeh;
            if (!rows.length) {
                el('phAvailNone').querySelector('strong').textContent = 'No vehicle is available for the selected slot.';
            }
        }
        catch (e) {
            el('phAvailOk').classList.add('hidden');
            el('phAvailNone').classList.remove('hidden');
            el('phAvailNone').querySelector('strong').textContent = errorOf(e);
            state.phoneCars = [];
            phVeh = null;
        }
    };
    window.s2SelectVehicle = function (id) {
        phVeh = id;
        el('phVehList').querySelectorAll('.book-svc').forEach((node, i) => node.classList.toggle('selected', Number(state.phoneCars[i]?.minicab_id) === id));
        el('phNext3').disabled = false;
    };
    window.phFare = async function () {
        el('phConfirmBtn').disabled = true;
        el('phConfirmChk').checked = false;
        state.quote = null;
        try {
            const r = await api('phone_quote', routePayload());
            const q = r.quote;
            state.quote = q;
            el('phFlag').textContent = pound(q.flag_fall);
            el('phDist').textContent = pound(q.distance_fare + q.daily_fare);
            el('phDistLbl').textContent = phServiceId() === 4 ? 'Daily hire' : 'Distance fare (' + q.distance_miles + ' mi)';
            el('phSur').textContent = pound(q.airport_surcharge);
            el('phBase').textContent = pound(q.base_total);
            el('phTotal').textContent = pound(q.final_total);
            el('phConfirmAmt').textContent = pound(q.final_total);
            el('phSumCaller').textContent = phMode === 'existing' ? state.caller.full_name : el('ncName').value.trim();
            el('phSumService').textContent = el('phService').value;
            el('phSumRoute').textContent = PLACES[el('phPickup').value][0] + ' → ' + PLACES[el('phDest').value][0];
            el('phSumDate').textContent = dateText(el('phDate').value + ' ' + el('phTime').value);
            const car = state.phoneCars.find(v => Number(v.minicab_id) === Number(phVeh));
            el('phSumVeh').textContent = el('phPass').value + ' · ' + (car?.registration_no || '—') + ' (' + phSize + ')';
            phErr('phErr4', '');
        }
        catch (e) {
            phErr('phErr4', errorOf(e));
        }
    };
    window.phConfirm = async function () {
        if (!state.quote || !phVeh || !el('phConfirmChk').checked) {
            phErr('phErr4', 'Verify fare, vehicle and caller confirmation before saving.');
            return;
        }
        const d = {
            ...routePayload(),
            minicab_id: Number(phVeh),
            caller_mode: phMode,
            verified: [
                ...document.querySelectorAll('.idchk')
            ].map(x => x.checked)
        };
        if (phMode === 'existing') {
            d.customer_id = state.caller.customer_id;
            d.caller_claims = callerProof();
        }
        else
            d.customer = {
                name: el('ncName').value.trim(),
                phone: el('ncPhone').value.replace(/\s/g, ''),
                email: el('ncEmail').value.trim(),
                address: el('ncAddr').value.trim()
            };
        const btn = el('phConfirmBtn');
        btn.disabled = true;
        try {
            const result = await api('phone_create', d);
            el('phStep4').classList.remove('active');
            el('phDone').classList.remove('hidden');
            el('page-phone').querySelector('.booking-stepper').classList.add('hidden');
            el('phDone').querySelector('p strong').textContent = result.ref;
            el('phDone').querySelector('p').lastChild.textContent = ' saved in the shared database; queued for Control Centre dispatch.';
            toast('Telephone booking ' + result.ref + ' saved. Dispatch is queued.');
        }
        catch (e) {
            btn.disabled = false;
            phErr('phErr4', errorOf(e));
        }
    };
    // -------- US33: actual dispatch queue and non-fabricated GPS state --------
    function dispatchRow(j) {
        const p = j.payload || {}, status = String(j.dispatch_status || 'Pending');
        return {
            ref: esc(j.ref),
            id: j.id,
            ch: esc(j.channel),
            cust: esc(j.customer),
            phone: esc(p.customer?.phone || '—'),
            route: esc((j.pickup || '—') + ' → ' + (j.dest || '—')),
            when: esc(dateText(j.date_time)),
            pax: Number(j.pax),
            size: esc(j.size),
            veh: esc(j.vehicle || '—'),
            pu: p.pickup ? `${Number(p.pickup.lat)}, ${Number(p.pickup.lng)}` : '—',
            de: p.destination ? `${Number(p.destination.lat)}, ${Number(p.destination.lng)}` : '—',
            st: status.toLowerCase(),
            fwd: esc(dateText(j.forwarded_at)),
            gps: esc(j.gps_reference || '—'),
            bad: esc(j.dispatch_bad || ''),
            err: esc(j.dispatch_error || '')
        };
    }
    async function loadDispatch() {
        el('dqBody').innerHTML = '';
        el('gpsSyncText').textContent = 'Checking real dispatch queue…';
        try {
            const r = await api('dispatch_list', {});
            state.dispatch = r.jobs || [];
            DQ.splice(0, DQ.length, ...state.dispatch.map(dispatchRow));
            originalRenderDispatch();
            const configured = !!state.gpsConfigured;
            el('gpsChip').className = 'chip ' + (configured ? 'info' : 'warning');
            el('gpsChip').textContent = configured ? 'GPS integration configured' : 'GPS not configured';
            el('gpsSyncText').textContent = configured ? 'Forwarding uses configured GPS endpoint; check each job status.' : 'Bookings are queued in MySQL. No actual GPS forwarding until a provider is configured.';
        }
        catch (e) {
            DQ.splice(0, DQ.length);
            originalRenderDispatch();
            el('gpsChip').textContent = 'Dispatch unavailable';
            el('gpsSyncText').textContent = errorOf(e);
        }
    }
    window.dqForward = async function (i) {
        const item = DQ[i];
        if (!item)
            return;
        try {
            const r = await api('dispatch_forward', {
                booking_id: item.id
            });
            toast(r.status === 'Pending' ? 'Booking retained in pending queue (GPS not configured).' : 'Dispatch status: ' + r.status);
            await loadDispatch();
        }
        catch (e) {
            toast(errorOf(e));
            await loadDispatch();
        }
    };
    window.forwardAllPending = async function () {
        const pending = DQ.filter(x => x.st === 'pending' && !x.bad);
        if (!pending.length) {
            toast('No dispatch-ready bookings');
            return;
        }
        for (const j of pending) {
            try {
                await api('dispatch_forward', {
                    booking_id: j.id
                });
            }
            catch (e) {
                toast(j.ref + ': ' + errorOf(e));
            }
        }
        await loadDispatch();
    };
    window.openDispatch = function (i) {
        originalOpenDispatch(i);
    };
    window.renderDispatch = function () {
        void loadDispatch();
    };
    window.dqDialogAction = async function () {
        closeDlg('dqDialog');
        await window.dqForward(dqCur);
    };
    // -------- US56/57/59/60: persistent Admin staff management --------
    function mapStaff(s) {
        return {
            id: s.id,
            name: s.name,
            email: s.email,
            phone: s.phone,
            role: s.role,
            status: s.status,
            created: dateText(s.created),
            login: dateText(s.login),
            sess: 0,
            self: !!s.self,
            deactAt: dateText(s.deactAt),
            mustChange: s.must_change_password
        };
    }
    window.renderStaff = async function () {
        const per = Number(el('staffPer').value || 10);
        let r;
        try {
            r = await api('staff_list', {
                page: staffPage,
                per
            });
        }
        catch (e) {
            el('staffBody').innerHTML = '';
            el('staffRange').textContent = errorOf(e);
            return;
        }
        staffPage = r.page;
        state.staffTotal = r.total;
        const rows = (r.staff || []).map(mapStaff);
        STAFF.splice(0, STAFF.length, ...rows);
        el('staffCard').classList.toggle('hidden', !r.total);
        el('staffEmpty').classList.toggle('hidden', !!r.total);
        el('staffBody').innerHTML = rows.map(s => `<tr class="row-click" tabindex="0" onclick="openProfile('${esc(s.id)}')" onkeydown="if(event.key==='Enter')openProfile('${esc(s.id)}')"><td><strong>${esc(s.id)}</strong></td><td>${esc(s.name)}${s.self ? ' <span class="chip">You</span>' : ''}</td><td>${esc(s.email)}</td><td>${roleChip(esc(s.role))}</td><td>${statusChip(s.status)}</td><td><span class="material-symbols-outlined">chevron_right</span></td></tr>`).join('');
        el('staffRange').textContent = r.total ? `${(staffPage - 1) * per + 1}–${Math.min(staffPage * per, r.total)} of ${r.total} staff` : '0 staff';
        const pages = Math.max(1, Math.ceil(r.total / per));
        let out = '';
        for (let i = 1; i <= pages; i++) {
            out += `<button class="page-btn ${i === staffPage ? 'active' : ''}" onclick="staffPage=${i};renderStaff()">${i}</button>`;
        }
        el('staffPager').innerHTML = `<button class="page-btn" ${staffPage === 1 ? 'disabled' : ''} onclick="staffPage--;renderStaff()">‹</button>` + out + `<button class="page-btn" ${staffPage === pages ? 'disabled' : ''} onclick="staffPage++;renderStaff()">›</button>`;
    };
    window.openProfile = async function (id) {
        originalSwitch('profile');
        state.activePage = 'profile';
        el('profileMain').classList.add('hidden');
        el('profileMissing').classList.add('hidden');
        try {
            const r = await api('staff_profile', {
                id
            });
            curStaff = mapStaff(r.staff);
            originalFillProfile();
        }
        catch (e) {
            curStaff = null;
            el('profileMissing').classList.remove('hidden');
            toast(errorOf(e));
        }
    };
    window.openEdit = function () {
        if (curStaff)
            originalOpenEdit(false);
    };
    window.saveEdit = async function () {
        if (!curStaff)
            return;
        const d = {
            id: curStaff.id,
            name: el('edName').value.trim(),
            email: el('edMail').value.trim(),
            phone: el('edPhone').value.replace(/\s/g, '')
        };
        [
            'edName',
            'edMail',
            'edPhone'
        ].forEach(id => setErr(id, ''));
        try {
            await api('staff_edit', d);
            closeDlg('editDialog');
            toast('Staff details updated in database.');
            await window.openProfile(d.id);
        }
        catch (e) {
            displayErrors(e);
        }
    };
    window.confirmDeactivate = async function () {
        if (!curStaff || !el('dtChk').checked)
            return;
        try {
            await api('staff_deactivate', {
                id: curStaff.id
            });
            closeDlg('deactDialog');
            toast('Account deactivated; existing sessions revoked.');
            await window.openProfile(curStaff.id);
        }
        catch (e) {
            toast(errorOf(e));
        }
    };
    window.submitCreate = async function () {
        const d = {
            id: el('crId').value.trim(),
            name: el('crName').value.trim(),
            email: el('crMail').value.trim(),
            phone: el('crPhone').value.replace(/\s/g, ''),
            role: el('crRole').value,
            password: el('crPw').value
        };
        [
            'crId',
            'crName',
            'crMail',
            'crPhone',
            'crRole',
            'crPw'
        ].forEach(id => setErr(id, ''));
        el('crDup').classList.add('hidden');
        try {
            const result = await api('staff_create', d);
            el('crPw').value = '';
            el('crFormWrap').classList.add('hidden');
            el('crMailFail').classList.toggle('hidden', result.onboarding !== 'failed');
            el('crSuccess').classList.toggle('hidden', result.onboarding === 'failed');
            el('crSuccessMail').textContent = result.sandbox ? d.email + ' (sandbox: local log only)' : d.email;
            toast(result.sandbox ? 'Staff created; onboarding written to local sandbox log.' : 'Staff account created.');
        }
        catch (e) {
            displayErrors(e);
            if (e.status === 409) {
                el('crDupText').textContent = errorOf(e);
                el('crDup').classList.remove('hidden');
            }
        }
    };
    // Only ask for independently supplied identity facts for an existing customer.
    const callerModeBeforeVerification = window.callerMode;
    window.callerMode = function (mode) {
        callerModeBeforeVerification(mode);
        if (el('s2CallerProof'))
            el('s2CallerProof').classList.toggle('hidden', mode !== 'existing');
    };
    // -------- bootstrap & real role login --------
    async function start() {
        try {
            const r = await api('bootstrap');
            state.customer = r.customer || null;
            state.staff = r.staff || null;
            state.gpsConfigured = !!r.gps_configured;
            // Bootstrap Sprint 1 catalogue as read-only metadata for telephone bookings.
            try {
                const b = await (await fetch('api.php?action=bootstrap', {
                    credentials: 'same-origin',
                    cache: 'no-store'
                })).json();
                state.categories = b.categories || [];
                state.services = b.services || [];
            }
            catch (_) {
                state.categories = [];
            }
            if (state.staff?.must_change_password) {
                showGate('You must change your temporary staff password before opening the portal.');
                el('authChange').classList.remove('hidden');
                el('authStaffLogin').classList.add('hidden');
                return;
            }
            el('authStaffLogin').classList.remove('hidden');
            el('authChange').classList.add('hidden');
            if (state.customer) {
                role = 'customer';
                updateIdentity();
                window.switchPage(new URLSearchParams(location.search).get('page') || 'payment');
            }
            else if (state.staff) {
                role = state.staff.role === 'Admin' ? 'admin' : 'staff';
                updateIdentity();
                window.switchPage(ROLES[role].home);
            }
            else
                showGate('Sign in as a customer or use your staff credentials below.');
        }
        catch (e) {
            showGate(errorOf(e));
            el('authLoginError').textContent = errorOf(e);
            el('authLoginError').classList.remove('hidden');
        }
    }
    async function staffLogin() {
        try {
            const r = await api('staff_login', {
                email: el('authEmail').value.trim(),
                password: el('authPassword').value
            });
            state.staff = r.staff;
            el('authPassword').value = '';
            await start();
        }
        catch (e) {
            el('authLoginError').textContent = errorOf(e);
            el('authLoginError').classList.remove('hidden');
        }
    }
    async function changePassword() {
        try {
            await api('staff_change_password', {
                old_password: el('authOld').value,
                new_password: el('authNew').value
            });
            el('authOld').value = '';
            el('authNew').value = '';
            await start();
        }
        catch (e) {
            el('authLoginError').textContent = errorOf(e);
            el('authLoginError').classList.remove('hidden');
        }
    }
    const gate = document.createElement('section');
    gate.className = 'page-view';
    gate.id = 'page-auth';
    gate.innerHTML = `<div class="m3-card elevated" style="max-width:590px;width:100%;margin:30px auto"><h1 style="font-size:24px;margin-bottom:12px">PrivateHire Cars · Customer & Staff Access</h1><p class="muted" id="authInfo">Checking your session…</p><div class="m3-alert error hidden" id="authLoginError" role="alert" style="margin:16px 0"></div><div style="margin:18px 0"><a class="m3-btn m3-btn-outlined" href="#sprint1">Open Booking · Customer login</a></div><div id="authStaffLogin"><h2 class="section-title">Internal Staff Portal</h2><div class="field-group"><label class="field-label" for="authEmail">Work Email</label><input class="m3-input" type="email" id="authEmail" autocomplete="username"></div><div class="field-group"><label class="field-label" for="authPassword">Staff Password</label><input class="m3-input" type="password" id="authPassword" autocomplete="current-password"></div><button class="m3-btn m3-btn-primary" id="authSignIn">Staff sign in</button></div><div id="authChange" class="hidden"><h2 class="section-title">Change temporary password</h2><div class="field-group"><label class="field-label" for="authOld">Temporary password</label><input class="m3-input" id="authOld" type="password"></div><div class="field-group"><label class="field-label" for="authNew">New password (10+ chars, uppercase & digit)</label><input class="m3-input" id="authNew" type="password"></div><button class="m3-btn m3-btn-primary" id="authSavePw">Change password</button></div></div>`;
    document.querySelector('.main-container').prepend(gate);
    el('authSignIn').addEventListener('click', staffLogin);
    el('authSavePw').addEventListener('click', changePassword);
    el('authPassword').addEventListener('keydown', e => {
        if (e.key === 'Enter')
            void staffLogin();
    });
    const approvedButton = el('payApproved').querySelector('button[onclick]');
    if (approvedButton) {
        approvedButton.setAttribute('onclick', 's2OpenCurrentReceipt()');
    }
    window.s2OpenCurrentReceipt = () => {
        if (state.receipt?.ref)
            void window.openReceipt(state.receipt.ref);
    };
    const sandboxText = el('visaChallenge').querySelector('h2 + p');
    if (sandboxText)
        sandboxText.textContent = 'Coursework SANDBOX: no real bank or SMS code is sent. Enter test code 123456 to approve a test payment. Use test cards only.';
    el('visaTitle').textContent = 'Confirm sandbox payment';
    el('visaCode').value = '';
    el('visaCode').placeholder = 'Sandbox code: 123456';
    const falseFailBtn = el('visaChallenge').querySelector('.back-link');
    if (falseFailBtn)
        falseFailBtn.textContent = 'Sandbox: simulate failed verification';
    const cardSafety = el('payForm').querySelector('.m3-alert.info');
    if (cardSafety)
        cardSafety.textContent = 'Coursework payment SANDBOX. No real VISA Check or bank processing is connected. Never enter a real bank card or CVV on localhost.';
    el('demoToolbar').classList.add('hidden'); // Prototype-only role switching & fake scenario buttons must never simulate backend success.
    // Add a link in the existing Sprint 2 nav only; Sprint 1 HTML/CSS is untouched.
    const originalNav = window.renderNav;
    window.renderNav = function () {
        originalNav();
        const b = document.createElement('a');
        b.className = 'nav-tab';
        b.href = '#sprint1';
        b.textContent = '← Back to Home';
        el('navLinks').prepend(b);
    };
    // Keep real booking state instead of prefilled example while loading.
    setPage('auth');
    if (el('flDate'))
        el('flDate').value = soonDate();
    // Live fleet refresh when the staff page is visible.
    setInterval(() => {
        if (state.activePage === 'fleet' && state.staff && !document.hidden)
            void window.renderFleet();
    }, 10000);
    void start();
})();
