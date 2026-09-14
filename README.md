# Tino Domain – module registrar cho WHMCS 8/9

Đăng ký / gia hạn / quản lý tên miền qua `https://api.tino.vn`.

## Cài đặt

Chép các file vào thư mục gốc WHMCS, giữ nguyên đường dẫn:

| File | Vai trò |
|---|---|
| `modules/registrars/tinowhmcs/` | Module registrar (`tinowhmcs.php`, `lib/TinoApi.php`) |
| `resources/domains/additionalfields.php` | Form thông tin chủ thể (Tỉnh/Thành, Phường/Xã lấy từ API) – gộp vào file sẵn có nếu đã có |
| `includes/hooks/tinowhmcs.php` | JS lọc Phường/Xã theo Tỉnh/Thành trên trang đặt hàng và admin |

Vào **Configuration → System Settings → Domain Registrars**, kích hoạt *Tino Domain*, nhập email + mật khẩu tài khoản Tino. Khi lưu, module đăng nhập thử; sai thông tin sẽ báo lỗi ngay.

## Xác thực

`POST /login` → `token` + `refresh`. Mọi request gửi `Authorization: Bearer`. Khi API trả `invalid_token` / `token_expired`, module gọi `POST /token` (refresh, xoay cả hai token) rồi thử lại; refresh hỏng thì đăng nhập lại. Token và danh sách địa phương (cache 1 ngày) lưu trong bảng `mod_tinowhmcs` (tự tạo).

## Kiểm tra

```sh
php modules/registrars/tinowhmcs/tests/run.php                       # kiểm tra thuần
TINO_USER=... TINO_PASS=... php modules/registrars/tinowhmcs/tests/run.php   # + gọi API thật (chỉ đọc)
```
