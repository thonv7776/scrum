<?php
// Change these for your WAMP/phpMyAdmin environment. Do not commit production passwords.
return [
    'db_host' => getenv('PHC_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('PHC_DB_PORT') ?: '3306',
    'db_name' => getenv('PHC_DB_NAME') ?: 'privatehire_cars_db',
    'db_user' => getenv('PHC_DB_USER') ?: 'root',
    'db_password' => getenv('PHC_DB_PASSWORD') ?: '',
    'base_url' => getenv('PHC_BASE_URL') ?: '', // empty: derive current server folder URL
    'development_mode' => getenv('PHC_DEVELOPMENT') !== '0', // set PHC_DEVELOPMENT=0 on production
    'mail_from' => getenv('PHC_MAIL_FROM') ?: 'no-reply@privatehire.example',
    // Coursework has no driver-addon tariff; prototype values may be changed here.
    'driver_extra_per_trip_gbp' => 20.00,
    'driver_extra_per_day_gbp' => 20.00,
    'online_discount_rate' => 0.15,
];
