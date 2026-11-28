<?php
declare(strict_types = 1);

/** PrivateHire Cars services. A function keeps its legacy name to preserve API behavior. */
date_default_timezone_set('Europe/London');
require_once __DIR__ . '/Config/Config.php';
require_once __DIR__ . '/Database/Database.php';
require_once __DIR__ . '/Http/Session.php';
require_once __DIR__ . '/Customers/CustomerRules.php';
require_once __DIR__ . '/Bookings/Location.php';
require_once __DIR__ . '/Bookings/Fare.php';
require_once __DIR__ . '/Bookings/BookingData.php';
require_once __DIR__ . '/Notifications/Settings.php';
require_once __DIR__ . '/Notifications/Gmail.php';
require_once __DIR__ . '/Notifications/Email.php';
require_once __DIR__ . '/Notifications/Sms.php';
require_once __DIR__ . '/Dispatch/GpsDemo.php';
require_once __DIR__ . '/Customers/VerifyEmail.php';
require_once __DIR__ . '/Staff/Staff.php';
require_once __DIR__ . '/Payments/Payment.php';
require_once __DIR__ . '/Bookings/Booking.php';
require_once __DIR__ . '/Staff/Fleet.php';
require_once __DIR__ . '/Notifications/Notifications.php';
require_once __DIR__ . '/Dispatch/Dispatch.php';
