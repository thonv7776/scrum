# Sprint 2 — Acceptance Criteria traceability

Nguồn: file **ProductBacklog-main(6).xlsx**; hai sheet **`Prioritised`** (cột `Sprint=2`, `Acceptance Criteria`) và **`US->Task (Sprint 2)`**. Sprint 1 là baseline **không thay code**. Các bảng dưới đây ghi tường minh những phần đã triển khai và những phần phụ thuộc hệ thống ngoài; **không đánh dấu hoàn thành AC nào nếu chưa test end-to-end thật**.

| User Story | Design / Code Sprint 2 | Kiểm tra & trạng thái |
|---|---|---|
| **US15** – Online card + VISA Check | `sprint2.html`, `sprint2.js`; `sprint2_api.php?action=pay`; `s2_card_errors`, `s2_gateway_charge`; `payments` | Form bắt buộc name/16 digits/MMYY/CVV; Luhn, expiry, sandbox challenge; only positive sandbox approval creates payment, changes `Paid & Confirmed`, returns receipt. **Official VISA Check bank verification NOT integrated** without credentials/spec; sandbox only. |
| **US17** – Online Receipt | `action=receipt`, `s2_receipt`, My Bookings | DB-backed reference/name/service/pickup/dest/time/size/txn/timestamp/base/discount/total/masked last4. Open after pay and from history. Browser mocked journey tested, DB persistence not integration-tested. |
| **US20** – Email confirmation opt-in | `optInEmail`, `s2_enqueue_confirmation`, `notification_jobs`, `sprint2_worker.php` | Default checked, full details/cancellation summary, `email_status` + retries. **Real 60s inbox delivery needs configured SMTP + 1-minute scheduler**, development outbox is NOT actual email delivery. |
| **US23** – ETA SMS | `arrival_event`, `sprint2_gps_webhook.php`, `s2_process_notifications`, `arrival_events` | Event ETA 10–15 or arrival triggers queued SMS with plate/model/colour/driver/ETA/location; timestamp and status logged. **Real-time GPS triggers/SMS depend on outside providers**; no provider configured. |
| **US56** – Create Staff | `staff_create`, bcrypt password, Admin restriction, seed Admin CLI | Required ID/name/email/phone/role/temp password; syntax + unique keys; Active + force password change; onboarding email transport needs config. |
| **US57** – Directory & profiles | `staff_list`, `staff_profile`, `staff_accounts` | Admin only, summary table, profile detail (phone, created, last login), pagination 10/20. Chromium mocked flow PASS. |
| **US59** – Edit staff | `staff_edit` | Admin only, name/email/phone validations, unique email constraint, DB update and notification. DB end-to-end pending. |
| **US60** – Deactivate | `staff_deactivate`, `session_version`, `staff_accounts` | Confirmation UI, self-deactivation disallowed, session version revoked, audit booking references preserved by FK SET NULL. DB end-to-end pending. |
| **US28** – Fleet availability | `s2_fleet`, `fleet`, `vehicle_dispatch_profiles` | DB vehicles filtered time/size/zone and operational status, accepts Held/Paid/Telephone conflict, staff UI refresh. **Legacy Sprint1 availability still checks only Held; unchanged by request.** |
| **US31** – Telephone Booking | `customer_search`, `phone_quote`, `phone_create` | Existing/new caller check, 3 verification ticks, route/date/size, available actual minicab, standard fare w/o online 15%, `Telephone Confirmed`, dispatch queue. DB end-to-end pending. |
| **US33** – Forward Dispatch | `dispatch_jobs`, `s2_dispatch_payload`, `s2_forward_job`, `sprint2_worker.php` | Booking/ref/customer/phone/coords/schedule/pax/size payload; only GPS HTTP 2xx records Forwarded timestamp. **Real GPS synchronization depends on provider endpoint and specification**. |

## Source-of-truth notes

- `US->Task (Sprint 2)` provides Design / Code / Test / Document activities for these 11 stories. No extra Sprint 3 stories have been implemented.
- This is an **increment to** original Sprint 1; original files and their SHA-256 hash records are in `SPRINT1_UNCHANGED_SHA256.txt`.
- The source Excel includes third-party requirements (VISA Check, SMS provider, GPS system) but **does not include live credentials or provider contracts**. The implementation must therefore expose legitimate Pending/Sandbox/Failed states rather than implying production transactions or deliveries.
- Review `README_SPRINT2_VI.md` for installation, tests, and known compatibility constraints.
