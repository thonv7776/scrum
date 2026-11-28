# PrivateHire Cars — MVC (Simple File Names)

Dự án giữ nguyên giao diện và hành vi của bản nguồn. Chỉ đổi tên file và cập nhật đường dẫn nội bộ để dễ tìm, dễ học và dễ bảo trì.

## Chạy website
1. Giải nén thư mục `PrivateHire_Cars_MVC_CLEAN` vào `C:\wamp64\www\` (có thể nằm trong `scum`).
2. Bật WampServer, Apache (`mod_rewrite`) và MySQL/MariaDB.
3. Nhấp đúp `START_PRIVATEHIRE.bat`.
4. Mở URL thư mục dự án, ví dụ `http://localhost/scum/PrivateHire_Cars_MVC_CLEAN/`.
5. Không mở trực tiếp file trong `Views`.

## Ba thư mục MVC
- `Models`: database, quy tắc nghiệp vụ, giá, booking, thanh toán, Gmail/SMS.
- `Views`: HTML, CSS, JavaScript và hình ảnh.
- `Controllers`: nhận request, xử lý API, tác vụ nền và cài đặt.

## Tìm file nhanh

| Muốn sửa | Mở file |
|---|---|
| Tài khoản khách hàng / đăng ký / đăng nhập | `Controllers/Actions/Customer/Account.php` |
| Chọn dịch vụ / đặt xe / giữ chỗ | `Controllers/Actions/Customer/Booking.php` |
| Tính giá / giảm giá | `Models/Bookings/Fare.php` |
| Lưu và đọc booking | `Models/Bookings/BookingData.php` |
| Địa điểm / tuyến đường | `Models/Bookings/Location.php` |
| Thanh toán | `Controllers/Actions/Customer/Payment.php` và `Models/Payments/Payment.php` |
| Hóa đơn / dữ liệu booking | `Models/Bookings/Booking.php` |
| Nhân viên / phân quyền | `Controllers/Actions/Staff/Staff.php` và `Models/Staff/Staff.php` |
| Đăng nhập nhân viên | `Controllers/Actions/Staff/Login.php` |
| Đặt xe qua điện thoại / đội xe | `Controllers/Actions/Staff/Fleet.php` |
| Điều phối / GPS | `Controllers/Actions/Staff/Dispatch.php` và `Models/Dispatch/Dispatch.php` |
| Gửi Gmail | `Models/Notifications/Gmail.php` và `Models/Notifications/Email.php` |
| Gửi SMS | `Models/Notifications/Sms.php` |
| Hàng đợi email/SMS | `Models/Notifications/Notifications.php` |
| Cấu hình Gmail / textbee | `Models/Notifications/Settings.php` |
| Cấu hình hệ thống | `Models/Config/App.php` |
| Kết nối database | `Models/Database/Database.php` |
| Giao diện booking | `Views/Layouts/`, `Views/Styles/booking.css`, `Views/Scripts/booking.js` |
| Giao diện Staff/Customer Portal | `Views/Portal/Portal.html`, `Views/Scripts/portal.js`, `Views/Styles/portal.css` |

## Database
- Cài mới database trống: `Models/Database/install.sql`.
- Nâng cấp database đã có dữ liệu: sao lưu trước, sau đó xem `Models/Database/upgrade.sql`.
- `START_PRIVATEHIRE.bat` kiểm tra database trước khi cài; không tự ghi đè database đang có dữ liệu.
- Cấu hình database ở `Models/Config/App.php`.

## Cấu hình Gmail và SMS
Đặt file cấu hình bên ngoài thư mục `www` tại `C:\wamp64\privatehire_notifications.local.php`. Không đưa mật khẩu Gmail hoặc API key textbee vào source code/GitHub. Nếu khóa cũ từng được chia sẻ, hãy thu hồi và tạo khóa mới.

## Các file Controller chính
- `Controllers/Home.php`: dựng trang chính từ các View.
- `Controllers/Booking.php`: API booking khách hàng (URL công khai vẫn là `api.php`).
- `Controllers/Portal.php`: API checkout và Staff (URL công khai vẫn là `sprint2_api.php`).
- `Controllers/Verify.php`: xác minh tài khoản.
- `Controllers/Gps.php`: GPS webhook.
- `Controllers/Status.php`: trạng thái hệ thống.
- `Controllers/Demo.php`: trang demo coursework.

## Lưu ý
- Các URL công khai như `api.php`, `verify.php`, `sprint2_api.php`, `sprint2_status.php`, `coursework_demo.php`, `app.js` vẫn giữ nguyên nhờ `.htaccess`.
- Thanh toán là SANDBOX; GPS demo là mô phỏng. Gmail/SMS thật cần cấu hình nhà cung cấp.
- Không import `install.sql` vào database đã có booking.


## Định dạng mã nguồn
- PHP, JavaScript, CSS và script khởi động đã được căn lề, tách dòng và thêm chú thích ngắn ở các phần cần thiết.
- Các phần HTML hiển thị, nội dung giao diện, tên route, logic nghiệp vụ và cấu trúc database được giữ nguyên.
- Khi sửa code, nên giữ cách đặt tên và định dạng hiện tại để dễ đọc và bảo trì.
