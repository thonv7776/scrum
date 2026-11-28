<?php

/** Renders the exact original HTML response from readable View components.
 * Editing any layout/asset does not require changing the controller.
 */
declare(strict_types = 1);
header('Content-Type: text/html; charset=utf-8');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/Head.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/BookingStyleOpen.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Styles/booking.css');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/BookingStyleClose.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/Body.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/BookingScriptOpen.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Scripts/booking-inline.js');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/BookingScriptClose.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/BookingScripts.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalStyleOpen.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Styles/portal.css');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalStyleClose.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalButton.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalHtmlOpen.html');
echo base64_encode(file_get_contents(dirname(__DIR__) . '/Views/Portal/Portal.html'));
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalHtmlClose.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalGap.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalScriptOpen.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Scripts/portal-inline.js');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/PortalScriptClose.html');
echo file_get_contents(dirname(__DIR__) . '/Views/Layouts/Footer.html');
