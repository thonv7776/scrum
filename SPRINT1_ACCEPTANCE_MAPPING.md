# Acceptance mapping — Prioritised (Sprint = 1) ↔ US->Task

**Nguồn:** ProductBacklog-main(3).xlsx, tabs `Prioritised` và `US->Task`. Phạm vi Sprint 1 gồm **12** stories: US01, US03, US04, US54, US55, US05, US06, US07, US09, US11, US12, US10.

| Story | Acceptance Criteria được xử lý | Mức hoàn thiện và lưu ý |
|---|---|---|
| **US01** | Form đủ 5 trường, validate email/điện thoại/password, unique email, bcrypt, Pending Verification, inline error | Chức năng code đủ; phát email thật phụ thuộc mail transport ngoài WAMP |
| **US03** | Token 24h, hash, single-use, Pending → Active, lỗi expired, resend, block booking | Có backend và frontend; resend rate-limit 60s; production cần mail thực |
| **US04** | Login email/password, Active-only, generic invalid, PHP session, chuyển Booking | Có |
| **US54** | Guest & Customer cùng xem 4 services, pricing badge, View Details, banner -15% | Có, dữ liệu từ MySQL |
| **US55** | Filter service + passenger group + vehicle size kết hợp không reload; counter và zero match, Reset | Có, dựa trên bảng liên kết `service_minicab_categories` |
| **US05** | Chi tiết scope, vehicle sizes/capacities/luggage, tariff, discount, terms; CTA role-based | Có, từ `transport_services` và `minicab_categories` |
| **US06** | Active-only, đúng một trong bốn services, dynamic Flight & Terminal / Hire Days, reset, lưu draft | Có; Flight + Terminal được nhập chung trong trường 20 kí tự theo SQL gốc |
| **US07** | Pickup/destination khác nhau, passenger 1–8 và capacity, lưu route, tự tính distance | **Một phần**: chỉ 9 preset landmark đã xác định; mileage demo ước lượng; chưa geocode các địa chỉ/postcode tùy ý, chưa route API thực |
| **US09** | Date/time không quá khứ, >=30ph, query availability theo category & overlap duration, suggested slots | Có logic PHP/MySQL; cần xác minh dữ liệu thực trong WAMP và timezone |
| **US11** | Standard fare từ bảng dịch vụ/vehicle rate, breakdown GBP, live recalc khi đổi dữ liệu | Có; thêm phụ thu driver từ config, không phải rate trong Excel |
| **US12** | -15% tự động Online, 2 decimal, không áp dụng Telephone/Walk-In, persist DB | Có (fare engine + persisted online quote); US offline creation không thuộc Sprint 1 |
| **US10** | Summary, Edit Trip Details, confirmation checkbox, giữ xe 10ph bằng transaction | Có; nút kết thúc bằng hold, gateway/thu tiền chưa thuộc Sprint 1 |

## Kết quả test trong môi trường tạo file

- `php tests/test_logic.php`: **18/18 PASS** (unit tests pure PHP).
- `php -l ...`, `node --check app.js`: **PASS**.
- `python tests/browser_smoke.py`: **PASS** (trình duyệt Chromium với **mock API**). Kiểm tra guest redirect, filter combo/no results, login, 4 services, Include driver, flight validation, passenger capacity, availability, fare summary, checkbox guard, 10-minute hold UI. Không có JavaScript page errors trong luồng thử này.
- **Chưa** kiểm thử end-to-end **MySQL thật**, **SMTP thật**, hoặc cạnh tranh đặt xe nhiều phiên trên WAMP: sandbox có PHP CLI nhưng thiếu extension PDO MySQL và MySQL Server. Cần thực hiện kiểm thử thực trên máy có WAMP theo README.
