# FILE GUIDE — PrivateHire Cars

## Nhớ nhanh
- **Models** = dữ liệu và quy tắc nghiệp vụ.
- **Views** = thứ người dùng nhìn thấy.
- **Controllers** = nhận yêu cầu và gọi Models.

## Customer
| Chức năng | File |
|---|---|
| Tài khoản, đăng ký, đăng nhập | `Controllers/Actions/Customer/Account.php` |
| Đặt xe, chọn dịch vụ, giữ chỗ | `Controllers/Actions/Customer/Booking.php` |
| Thanh toán và xác nhận | `Controllers/Actions/Customer/Payment.php` |
| Giá và giảm giá | `Models/Bookings/Fare.php` |
| Địa điểm và tuyến đường | `Models/Bookings/Location.php` |
| Dữ liệu booking | `Models/Bookings/BookingData.php` |

## Staff
| Chức năng | File |
|---|---|
| Đăng nhập Staff | `Controllers/Actions/Staff/Login.php` |
| Quản lý tài khoản Staff | `Controllers/Actions/Staff/Staff.php` |
| Đặt xe qua điện thoại, đội xe | `Controllers/Actions/Staff/Fleet.php` |
| Điều phối, cảnh báo đến nơi | `Controllers/Actions/Staff/Dispatch.php` |
| Kiểm tra xe có sẵn | `Models/Staff/Fleet.php` |
| Phân quyền | `Models/Staff/Staff.php` |

## Thanh toán và thông báo
| Chức năng | File |
|---|---|
| Xử lý thanh toán sandbox | `Models/Payments/Payment.php` |
| Tạo hóa đơn | `Models/Bookings/Booking.php` |
| Email | `Models/Notifications/Email.php`, `Gmail.php` |
| SMS | `Models/Notifications/Sms.php` |
| Hàng đợi gửi | `Models/Notifications/Notifications.php` |
| Cấu hình thông báo | `Models/Notifications/Settings.php` |

## Chạy / cài đặt
| Chức năng | File |
|---|---|
| Bắt đầu chương trình | `START_PRIVATEHIRE.bat` |
| Kiểm tra/cài database mới | `Controllers/Setup/Install.php` |
| Tạo Admin đầu tiên | `Controllers/Setup/Admin.php` |
| Test Email/SMS | `Controllers/Setup/TestEmailSms.php` |
| Worker gửi thông báo | `Controllers/Jobs/Worker.php`, `Worker.ps1` |
| Cấu hình database | `Models/Config/App.php` |
| SQL cài mới | `Models/Database/install.sql` |
| SQL nâng cấp | `Models/Database/upgrade.sql` |

## Giao diện
- Trang chính: `Controllers/Home.php`.
- Các mảnh HTML: `Views/Layouts/`.
- Portal: `Views/Portal/Portal.html`.
- JavaScript: `Views/Scripts/`.
- CSS: `Views/Styles/`.


## Cách đọc code
1. Mở `Controllers` để xem request nào đang được xử lý.
2. Theo `require`/`require_once` để tìm phần xử lý trong `Models`.
3. Mở `Views` nếu cần sửa HTML, CSS hoặc JavaScript.
4. Các chú thích `USxx` giúp nhận biết user story liên quan.
5. Không sửa file `install.sql` để thay đổi giao diện; file này chỉ dùng để tạo database ban đầu.
