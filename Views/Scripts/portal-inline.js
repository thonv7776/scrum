/* Portal page: overlay controls and navigation between customer/staff screens. */
(function () {
    'use strict';
    var overlay = document.getElementById('phc-s2-overlay');
    var frame = document.getElementById('phc-s2-frame');
    var launch = document.getElementById('phc-s2-launch');
    var oldOverflow = '';
    function getEmbeddedDocument() {
        var data = document.getElementById('phc-s2-embedded-ui').textContent.replace(/\s/g, '');
        var binary = atob(data), bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++)
            bytes[i] = binary.charCodeAt(i);
        return new TextDecoder('utf-8').decode(bytes);
    }
    window.openSprint2Embedded = function () {
        oldOverflow = document.documentElement.style.overflow;
        overlay.hidden = false;
        launch.hidden = true;
        document.documentElement.style.overflow = 'hidden';
        // Reinitialize UI with current shared PHP customer / staff session every time.
        frame.srcdoc = getEmbeddedDocument();
        document.getElementById('phc-s2-close').focus();
    };
    window.closeSprint2Embedded = function () {
        overlay.hidden = true;
        launch.hidden = false;
        document.documentElement.style.overflow = oldOverflow;
        // Release live interval timers and stale UI state when returning to Sprint 1.
        frame.removeAttribute('srcdoc');
        frame.src = 'about:blank';
        launch.focus();
    };
})();
