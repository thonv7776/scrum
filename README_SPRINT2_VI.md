# PRIVATEHIRE CARS - SPRINT 2 (BẢN SỬA LỖI WAMP DATABASE)

## 0. Giữ nguyên Sprint 1

Toàn bộ file gốc trong `PrivateHire_Cars_Sprint1(2).zip` đều được giữ nguyên từng byte. **KHÔNG sửa** `index.html`, `app.js`, `api.php`, `lib.php`, `config.php`, `init_database.sql`, các file còn lại của Sprint 1.

Sprint 2 là các file bắt đầu `sprint2` cùng file hướng dẫn, bộ test, script WAMP và SQL. Dữ liệu Sprint 1 không bị xóa. Tuy nhiên để hỗ trợ 11 US Sprint 2, **database được mở rộng**: thêm các cột vào bảng `bookings`, mở rộng ENUM trạng thái, thêm bảng Sprint 2 và nếu bảng `bookings` dùng MyISAM thì chuyển *engine* sang InnoDB. Việc chuyển engine không xóa record nhưng là một thay đổi metadata database cần SAO LƯU trước.

## 1. Cách chạy trên WAMP - đã có Sprint 1 / đã import Sprint 2 lỗi #1824

1. Bật WampServer cho biểu tượng W chuyển **xanh lá**.
2. Vào `http://localhost/phpmyadmin/`, đăng nhập MySQL (thường `root`, mật khẩu trống nếu chưa đặt).
3. Chọn database `privatehire_cars_db` → **Export** → Quick / SQL → **Go** để lưu bản sao trước khi sửa.
4. **KHÔNG** chọn `init_database.sql`. File đó thuộc Sprint 1 và có `DROP TABLE`, sẽ làm mất dữ liệu đang có.
5. Chọn tab **Import** → Choose File → chọn **`SPRINT2_02_SAFE_UPGRADE_EXISTING_OR_AFTER_NEW.sql` BẢN MỚI** trong gói này (cùng nội dung `sprint2_migration.sql`) → Go. File mới kiểm tra các cột/bảng đã có trước khi bổ sung, nên có thể chạy lại kể cả khi bản migration cũ đã chạy thành công một phần.
6. Chờ trang phpMyAdmin hiện câu `SPRINT 2 DATABASE UPGRADE COMPLETE`, `bookings_engine=InnoDB`, `sprint2_tables_created=6`. Nếu hiện lỗi đỏ, **đừng xóa database**: chụp ảnh lỗi để xử lý trực tiếp.
7. Nếu thư mục dự án Sprint 1 cũ đang chạy tốt, giữ nguyên các file Sprint 1 tại `C:\wamp64\www\PrivateHire_Cars_Sprint1\`. Chỉ chép **các file bắt đầu `sprint2`** cùng với `TAO_ADMIN_WAMP.bat`, `DB_CHECK_WAMP.bat`, và tài liệu mới vào đó. Nếu muốn chạy một thư mục hoàn toàn mới, giải nén trọn ZIP và đặt `PrivateHire_Cars_Sprint1` vào `C:\wamp64\www\`.
8. Mở trang **kiểm tra kết nối database**: `http://localhost/PrivateHire_Cars_Sprint1/sprint2_status.php`. Các mục PHP, MySQL, bảng và engine phải **ĐẠT**. Trang này không sửa dữ liệu và chỉ truy cập được bằng localhost.

### 2. Cài mới từ đầu (database hoàn toàn trống)

1. Trong `http://localhost/phpmyadmin/`, tạo một database mới tên `privatehire_cars_db` nếu chưa có.
2. **CHỈ KHI DB THỰC SỰ TRỐNG, chưa có dữ liệu cần giữ**, import `SPRINT2_01_NEW_EMPTY_DATABASE_ONLY.sql` từ gói mới. File này dựa trên SQL Sprint 1 nguyên gốc nhưng bổ sung ENGINE=InnoDB cho hai bảng thiếu engine, kiểm tra DB trống và **KHÔNG có DROP TABLE**. File gốc `init_database.sql` được giữ nguyên để đối chiếu, không nên import nhầm khi đã có dữ liệu.
3. Sau đó import **`SPRINT2_02_SAFE_UPGRADE_EXISTING_OR_AFTER_NEW.sql`** (cùng nội dung `sprint2_migration.sql` bản mới; có thể chạy lại, tương tự bước 5 trên).
4. Mở `sprint2_status.php` để xác nhận 6 bảng Sprint 2 và `bookings` InnoDB.
5. Đặt toàn bộ source vào `C:\wamp64\www\PrivateHire_Cars_Sprint1\`.

**Lưu ý về chọn server:** Với WAMP, chọn **MySQL** trong phpMyAdmin; `config.php` Sprint 1 đang dùng `127.0.0.1:3306`, database `privatehire_cars_db`, user `root`, password rỗng. Nếu môi trường đã đổi mật khẩu/port thì cấu hình kết nối phải tương ứng. Không cần đổi file gốc khi các thông số đã đúng.

## 3. Tạo tài khoản Admin và đăng nhập

Sau khi import SQL thành công, nhấp đúp `TAO_ADMIN_WAMP.bat` trong thư mục project. Công cụ sẽ cố tự tìm `php.exe` tại `C:\wamp64\bin\php\php*\php.exe` (hoặc XAMPP) và yêu cầu nhập email, tên, số điện thoại, mật khẩu Admin của **chính bạn**. Phải có mật khẩu tối thiểu 12 ký tự, chữ hoa và số; đây là tiện ích dòng lệnh trên máy local, **mật khẩu hiển thị khi gõ nên đừng nhập trước người khác**. Nếu WAMP cài chỗ khác, mở CMD ở project và dùng full path đến `php.exe` cùng các biến môi trường theo `sprint2_env_example.txt`.

Sau đó:

- Sprint 1: `http://localhost/PrivateHire_Cars_Sprint1/index.html`
- Sprint 2: `http://localhost/PrivateHire_Cars_Sprint1/sprint2.html`
- Trang chẩn đoán: `http://localhost/PrivateHire_Cars_Sprint1/sprint2_status.php`

Sprint 2 dùng lại phiên khách hàng của Sprint 1 trong cùng trình duyệt. Khách đăng ký, xác minh, đăng nhập và giữ chỗ **từ Sprint 1 trước**, rồi mới sang trang thanh toán Sprint 2. Nhân viên đăng nhập từ trang Sprint 2 bằng email và mật khẩu Admin/Staff do bạn tạo.

## 4. Test nhanh bằng tài khoản thật trong DB và thẻ thử

1. Sprint 1 → đăng nhập khách đã xác minh → chọn dịch vụ và xe → chọn giờ tương lai → Confirm Schedule & Proceed to Payment. Database phải có `bookings` trạng thái **Held** (chỉ giữ chỗ 10 phút).
2. Cùng một tab/browser → Sprint 2 → Checkout. Dùng **thẻ TEST SANDBOX** `4242 4242 4242 4242`, tên bất kỳ (>=2 ký tự), expiry tương lai `12/29` (nếu vẫn là tương lai lúc thử) và CVV `123`; challenge nhập 6 chữ số bất kỳ. **Không nhập thẻ thật**.
3. Khi thành công, xem online receipt và My Bookings. Trong phpMyAdmin, xem bảng `payments`, `bookings`, `notification_jobs`, `dispatch_jobs`.
4. Admin → Create Staff → Directory → Edit → Deactivate. Trình duyệt nhân viên sau khi deactivated phải bị khóa khi request tiếp.
5. Staff → Fleet → Telephone Booking → chọn khách có sẵn hoặc thêm khách → chọn một chiếc xe còn trống → nhận giá **không có discount 15%** → Confirm. DB lưu `Telephone Confirmed` và queue dispatch.
6. Để gửi email **vào outbox phát triển**, chạy `php sprint2_worker.php` bằng PHP CLI. File log ở `storage/sprint2_outbox.log`. Có thể gọi `DB_CHECK_WAMP.bat` để chạy các bài unit test nhanh.

## 5. Các giới hạn kỹ thuật phải biết

- **VISA Check thật chưa có:** coursework không cung cấp API/token/cert/processor. Payment đang là SANDBOX có kiểm tra Luhn/MMYY/CVV, không trừ tiền. Tích hợp VISA Check production cần official provider và luồng tokenize / PCI compliant. Bản demo không cố giả là đã charge thật.
- **Email thật chưa có:** phát triển ghi outbox cục bộ; để email đến inbox cần cấu hình mail transport, worker chạy qua Windows Task Scheduler mỗi phút. `Sent` ở local nghĩa là đã ghi vào outbox, không phải đã giao email cho khách.
- **SMS và GPS thật chưa có:** cần `PHC_SMS_URL`/`PHC_SMS_TOKEN`, `PHC_GPS_URL`/`PHC_GPS_TOKEN`, webhook secret từ dịch vụ. Không có thì record giữ `Pending`/`Failed` cùng lý do. Driver trong DB seed là dữ liệu demo phải thay bằng thông tin được xác minh trước khi vận hành thật.
- **Sprint 1 chỉ kiểm tra Held ở tìm xe:** vì tuyệt đối không sửa S1, bộ lọc tìm xe cũ chưa hiển thị chính xác mọi xe đã thanh toán/đặt qua điện thoại. Sprint 2 **kiểm tra xung đột ở bước checkout/phone create** để tránh thanh toán/tranh đặt trùng. Muốn sửa màn hình tìm xe Sprint 1 thì phải được bạn cho phép riêng.
- Chưa có MySQL/PDO-MySQL và provider thật trong môi trường tạo bản ZIP, nên **chưa có kết quả test DB end-to-end trên WAMP của bạn**. Bộ test logic và browser mock chỉ xác nhận code PHP/JS và UI ở mức tương ứng.

## 6. File mới

| File | Mục đích |
|---|---|
| `SPRINT2_01_NEW_EMPTY_DATABASE_ONLY.sql` | Tạo bảng và dữ liệu mẫu Sprint 1 trong **database thực sự trống**, phiên bản WAMP không DROP bảng |
| `SPRINT2_02_SAFE_UPGRADE_EXISTING_OR_AFTER_NEW.sql` / `sprint2_migration.sql` | **Bản nâng cấp SQL mới**: hỗ trợ chạy lại sau khi migration dở, xử lý lỗi engine #1824, không DROP bảng |
| `sprint2_status.php` | Trang read-only kiểm tra WAMP + PHP/MySQL + 6 bảng Sprint 2 |
| `TAO_ADMIN_WAMP.bat` | Tạo admin bằng CLI PHP trên WAMP |
| `DB_CHECK_WAMP.bat` | Chạy kiểm tra logic PHP |
| `sprint2.html` + `sprint2.js` | Giao diện Sprint 2, theo HTML người dùng cung cấp |
| `sprint2_api.php` + `sprint2_lib.php` | API PHP, xử lý thanh toán, khách, nhân viên, Fleet/Phone, Dispatch |
| `sprint2_worker.php` + `sprint2_gps_webhook.php` | Queue email/SMS và sự kiện GPS |
| `sprint2_seed_admin.php` | Tạo Admin CLI; không có admin mặc định mật khẩu cứng |
| `SPRINT1_UNCHANGED_SHA256.txt` | Đối chiếu bản gốc Sprint 1 |
| `SPRINT2_ACCEPTANCE_MAPPING.md` | Liên hệ 11 US Sprint 2 theo 2 sheet Excel |

Không có Sprint 3. Sprint 1 không bị thay đổi mã nguồn.
