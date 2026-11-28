/* Add-on only. The original Sprint 1 scripts and functions are unchanged. */
(() => {
    'use strict';
    const hold = window.triggerHoldConfirmation;
    if (typeof hold !== 'function') {
        console.error('Original Sprint 1 checkout hook missing.');
        return;
    }
    window.triggerHoldConfirmation = async function (...args) {
        await hold.apply(this, args);
        if (typeof PHC === 'undefined' || !PHC.held)
            return;
        try {
            const response = await fetch('sprint2_api.php?action=bootstrap', {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json();
            if (response.ok && data.ok && data.customer) {
                window.openSprint2Embedded();
            }
            else {
                alert('Your booking is held. Could not confirm the payment session. Open Payments / Staff from this page after signing in.');
            }
        }
        catch (err) {
            alert('Your booking is held, but the The payment service is unavailable. Check WAMP/MySQL and reopen Payments / Staff.');
        }
    };
})();
