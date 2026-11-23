# PrivateHire Cars — Sprint 1 (COMP1807)

Dự án PHP + MySQL + JavaScript, giữ giao diện `index.html` đã được bạn cung cấp, thay các thao tác demo bằng kết nối backend và database. Phạm vi được lấy từ **US->Task** và **Prioritised** (Sprint = 1), tổng cộng **12 user stories**.

## 1. Cài lần đầu trên WAMP (Windows)

1. Mở WAMP, bật Apache và MySQL, đợi cả hai hoạt động.
2. Giải nén nguyên thư mục `PrivateHire_Cars_Sprint1` vào `C:\wamp64\www\` (không tách `index.html` ra khỏi `api.php`, `app.js`, `lib.php`).
3. Vào `http://localhost/phpmyadmin/` → tab **Import** → chọn **`init_database.sql`** → **Import**. File này sẽ tự tạo database `privatehire_cars_db`, các bảng, dữ liệu dịch vụ/xe mẫu, trạng thái `Pending Verification` và cột phí `include_driver`/`driver_fee`.
4. Mở `config.php`, kiểm tra `db_host`, `db_port`, `db_name`, `db_user`, `db_password` theo phpMyAdmin của bạn. WAMP thông thường dùng `127.0.0.1`, `3306`, `root`, mật khẩu rỗng — **chỉ dùng khi máy bạn thực sự được cấu hình vậy**. Nếu MySQL chạy cổng 3307/3308, sửa cổng đúng.
5. Mở `http://localhost/PrivateHire_Cars_Sprint1/index.html` (không nhấn đúp mở dạng `file://`).
6. Nếu trang báo lỗi database, kiểm tra MySQL đang chạy, PHP đã bật `pdo_mysql`, và tên database đã import đúng. Bật extension trong file `php.ini` nếu cần rồi restart WAMP.

**Cảnh báo:** `init_database.sql` chứa lệnh `DROP TABLE` để cài **lần đầu**, chạy lại sẽ xóa toàn bộ khách hàng, tokens và bookings trong các bảng này. Hãy sao lưu database trước khi import.

### Nếu đã import file SQL gốc từ trước

**Không import `init_database.sql` đè lên dữ liệu đang dùng**. Sau khi sao lưu database, chạy `migrate_existing_database.sql` **một lần** trên database hiện có để bổ sung cột phí tài xế và chuẩn hóa trạng thái `Pending Verification`. Không chạy migration hai lần vì sẽ báo cột đã tồn tại.

## 2. Cách demo Sprint 1

1. **Guest**: xem bốn dịch vụ → lọc Service/Passengers/Size → View Details. Khi chọn Book sẽ được chuyển sang Sign In.
2. **Create Account**: nhập đủ tên, email, địa chỉ, điện thoại 10–11 chữ số và password tối thiểu 8 ký tự. Trùng email/sai định dạng hiển thị lỗi ngay dưới ô.
3. **Verify Email**: tài khoản được tạo ở trạng thái `Pending Verification`. Click link xác nhận 24 giờ (chỉ dùng một lần). Tài khoản chưa xác minh không đăng nhập được.
4. **Sign In**: đăng nhập tài khoản Active, vào Book a Minicab.
5. **Service**: chọn một trong bốn dịch vụ; Meet & Greet yêu cầu flight number/terminal, Daily Hire yêu cầu số ngày.
6. **Journey**: chọn pickup/destination trong danh sách hoặc chọn landmark từ bản đồ; nhập hành khách, chọn loại xe. Nút **Include driver** xuất hiện tại đây cho cả bốn dịch vụ, bật/tắt là phụ thu và tổng tiền cập nhật ngay.
7. **Date & time**: chọn ngày/giờ trong tương lai (giờ London), tối thiểu 30 phút; Check Availability lấy lịch xe trong MySQL, không phải alert demo. Nếu hết xe, hệ thống thử tìm khung giờ lân cận.
8. **Fare**: đọc tổng hành trình, phụ thu tài xế, phí dịch vụ, giảm 15%, số tiền cuối. Chỉ khi tick xác nhận mới giữ một xe thật trong database **10 phút**.

## 3. Cấu hình phí `Include driver`

Backlog/SQL gốc **không ấn định giá phụ thu driver**. Để có thể code và demo tính giá, dự án dùng mức **ví dụ, có thể thay đổi** trong `config.php`:

- `driver_extra_per_trip_gbp = 20.00`: cộng £20 khi bật Include driver cho dịch vụ 1–3.
- `driver_extra_per_day_gbp = 20.00`: cộng £20 **mỗi ngày** khi bật Include driver cho dịch vụ 4.

Dịch vụ **Driver & Car for Daily Hire** đã có tài xế trong gói £140/ngày của SQL gốc. Vì bạn yêu cầu nút bật tài xế có phụ thu cho **tất cả** dịch vụ, phần bổ sung £20/ngày tại đây được mô tả là **additional driver add-on**, không phải phí tài xế đã có trong gói. Cần được Product Owner chốt giá/chính sách nếu sử dụng thật.

Ví dụ với Heathrow T5 → Savoy, MPV (18.50 miles) và Airport & Hotel Transfer: £8 flag-fall + £50.88 mileage + £5 airport + £20 driver = **£83.88**; online discount 15% = **£12.58**; payable = **£71.30**. Tắt Include driver: tổng tự giảm £20 trước khi tính lại discount.

## 4. Email xác minh trên WAMP

- Backend tạo token 256-bit, chỉ lưu SHA-256 token trong database, hết hạn sau 24h, một lần sử dụng.
- Hệ thống thử gửi email thực qua hàm PHP `mail()`. **WAMP thường không có SMTP mặc định**, nên bạn phải cấu hình mail transport/sendmail/SMTP relay thực tế để email có thể đến inbox. `mail()` trả về true chỉ xác nhận mail đã được gửi vào hệ thống mail, không bảo đảm người nhận đã nhận được.
- Trong môi trường **local development**, nếu mail chưa cấu hình, trang đăng ký cung cấp **Open local verification link (development only)** để bạn thử luồng kích hoạt mà không nói dối rằng email đã được gửi. Link cũng được ghi vào file log hệ thống tạm `/tmp/phc_sprint1_mail.log` (Windows: thư mục temp của PHP). **Không bật chế độ development trên máy chủ public.**
- Khi triển khai ngoài local: cấu hình HTTPS, SMTP thật, `PHC_DEVELOPMENT=0`, địa chỉ mail_from có thật, và không hiển thị dev activation links.

## 5. Những gì Sprint 1 thực hiện và giới hạn

Hoàn thiện các luồng Sprint 1 của 12 US về form/auth, service catalogue/filter/detail, session-backed draft, hành trình, capacity, lịch xe, giá và hold. Có validate phía server, prepared statements, bcrypt, CSRF token, PHP session, transaction `READ COMMITTED` và khóa row minicab khi giữ chỗ.

**Giới hạn quan trọng cần ghi đúng khi báo cáo / demo:**

- **Route distance**: bản đồ gốc là SVG minh họa, không kết nối API bản đồ thực tế. Server chỉ nhận **9 địa điểm/landmarks** đã cung cấp trong giao diện và dùng **ước lượng** quãng đường của bản đồ demo (cặp Heathrow–Savoy = 18.50 miles). Không xác minh tọa độ/địa chỉ bất kỳ, postcode tự nhập hoặc tuyến đường lái xe thực. Để đạt AC định vị ngoài các địa điểm mẫu, cần tích hợp một dịch vụ geocoding/routing thật và kiểm thử riêng.
- **Thanh toán**: Sprint 1 kết thúc ở **Confirm Schedule & Proceed to Payment** và giữ xe 10 phút. Chưa tích hợp PayPal/VISA Check, chưa thanh toán tiền và **không** đổi trạng thái `Held` thành `Paid`. Các US về thanh toán nằm ở Sprint khác theo sheet Prioritised.
- **Email thật**: chỉ hoạt động nếu máy chủ có mail transport; local dev có activation link để kiểm thử.
- **Live deployment**: chưa kiểm thử trực tiếp với WAMP/MySQL và SMTP của người dùng trong môi trường này. Không được coi test JavaScript giả lập API là xác nhận rằng PDO-MySQL/SMTP vận hành trên máy người dùng.
- Bản demo chỉ mô hình hóa fleet theo bảng `minicabs` bạn gửi (4 xe mẫu), không phải 150 xe thật.

## 6. Kiểm thử

- Chạy `php tests/test_logic.php` ở terminal có PHP CLI. Test gồm fare từng service, addon, 15% discount, Telephone không giảm, email/phone, same-address, invalid route, timestamp >=30 phút.
- Kiểm tra cú pháp: `php -l api.php`, `php -l lib.php`, `php -l verify.php`, `node --check app.js`.
- `python tests/browser_smoke.py` (cần Playwright + Chromium): chạy luồng trình duyệt **với API được giả lập** nhằm kiểm tra DOM, chuyển trang và chức năng giao diện; **không** thay thế kiểm thử backend trên MySQL thật.
- Kiểm thử database thực: tạo 2 tài khoản Active; giữ cùng một timeslot cho **6-Seat MPV** bằng tài khoản A, rồi kiểm tra tài khoản B thấy unavailable / alternative; chờ 10 phút kiểm tra slot được giải phóng; kiểm tra bảng `bookings` chứa `include_driver`, `driver_fee`, `base_total_fare`, `online_discount_15`, `final_payable_fare`, `minicab_id`, `slot_hold_until`.

## 7. Bộ file chính

| File | Vai trò |
|---|---|
| `index.html` | Giao diện gốc đã chỉnh tương tác Sprint 1 |
| `app.js` | Điều khiển booking wizard, gọi backend, nút Include driver |
| `api.php` | API đăng ký/login/catalogue/quote/booking/availability/hold |
| `verify.php` | Xử lý click token xác minh qua email |
| `lib.php` | Database/PDO, session, validation, fare, route distance, fleet query |
| `config.php` | Kết nối database, giá driver, mail/development config |
| `init_database.sql` | Import sạch lần đầu |
| `migrate_existing_database.sql` | Cập nhật database đã import bản gốc mà không xóa dữ liệu |
| `SPRINT1_ACCEPTANCE_MAPPING.md` | Bảng đối chiếu từng US Sprint 1 và các giới hạn |
