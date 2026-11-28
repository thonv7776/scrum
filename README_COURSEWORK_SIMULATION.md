# Coursework simulation: US15, US23, US33

This mode is **not a bank/VISA integration and not a live GPS platform**. It is intended only for local WAMP classroom demonstrations.

## Scope

- **US15 (Visa Check)**: Existing sandbox verifies test cards with a test challenge. Card `4242 4242 4242 4242`, expiry `12/30`, CVV `123`; sandbox verification flow. No real money is charged. A declined sandbox transaction creates **no receipt**, dispatch job, or arrival event.
- **US33 (GPS Dispatch)**: The existing worker automatically forwards newly confirmed bookings to mock `DEMO-GPS-...` dispatch when the demo flag is enabled, without Staff clicking Forward. Real GPS is not contacted. For daily hire, it records the handover address rather than inventing a route.
- **US23 (Arrival SMS)**: A unique demo ETA-12 marker is created **only when a new booking is confirmed**. About 20–26 seconds after confirmation, the worker queues a real SMS with vehicle registration, model, colour, assigned driver, pickup and ETA 12 min through textbee. There is no real vehicle movement. Self-drive daily hire **without a driver** does not generate an arrival SMS.
- **Existing 10-second payment confirmation SMS stays enabled**. This is separate from the arrival message, so one online demo booking may produce **two real SMS messages**, which can incur SIM charges.
- Email/verification and SMS transport remain real, not simulated.

## One-click run

1. Back up the current source and export the database.
2. Keep `Models/Dispatch/COURSEWORK_DEMO_ON.flag` present (already included) and **do not set `PHC_GPS_URL`** when running mock GPS. For live providers, remove the flag and configure the provider separately.
3. Keep the Gmail and textbee secrets **outside `www`**, e.g. `C:\wamp64\privatehire_notifications.local.php`. Never commit credentials.
4. Run `START_PRIVATEHIRE.bat` as before. It opens the site and worker. Keep the textbee Android app online and use **your own/consenting phone number** for SMS testing.
5. Create a new online booking in services 1, 2 or 3; pay using the sandbox card; the receipt appears immediately. Worker processes mock dispatch and two SMS jobs (confirmation at ~10 s, arrival at ~20–26 s).
6. Confirm the result in `coursework_demo.php` (staff login required) and `notification_jobs` in phpMyAdmin. No extra Staff click is needed.

## Verification SQL (read-only)

```sql
SELECT b.booking_id, b.booking_status, d.status AS dispatch_status,
       d.gps_reference, a.eta_minutes, a.notification_id
FROM bookings b
LEFT JOIN dispatch_jobs d ON d.booking_id=b.booking_id
LEFT JOIN arrival_events a ON a.booking_id=b.booking_id
    AND a.provider_event_id=CONCAT('coursework-auto-eta12-',b.booking_id)
ORDER BY b.booking_id DESC LIMIT 10;

SELECT booking_id, channel, event_key, status, attempts, provider_reference, last_error
FROM notification_jobs
WHERE channel='SMS' ORDER BY notification_id DESC LIMIT 10;
```

## Important

- Existing paid bookings **do not** receive a new arrival text retroactively; only bookings created after this patch will have auto demo ETA markers.
- Worker normally polls every 3 seconds; delivery latency is controlled by Android, Internet, textbee and the mobile network. `Queued` means a request has not yet been confirmed as sent.
- The `COURSEWORK_DEMO_ON.flag` opts into simulation. Only use it on local/demo installations, not production.
- Source HTML, CSS, UI JavaScript, public routes, Gmail/textbee code and SQL schema are unchanged.
