# Tino Domain – module registrar cho WHMCS 8/9

Đăng ký / gia hạn / quản lý tên miền qua `https://api.tino.vn`.

## Cập nhật 27/10/2026: chuyển sang token API cá nhân

### Thay đổi

- Module không còn đăng nhập bằng email/mật khẩu tài khoản Tino. Thay vào đó dùng **token API cá nhân** (`tinopat_…`): chỉ có các quyền bạn cấp, có thể giới hạn theo IP máy chủ, thu hồi bất cứ lúc nào mà không phải đổi mật khẩu tài khoản.
- Trang cấu hình registrar: hai ô *Email tài khoản Tino* + *Mật khẩu* được thay bằng một ô **API Token**.
- Khi lưu cấu hình có token, module xoá email/mật khẩu cũ và token đăng nhập cũ đã lưu trong cơ sở dữ liệu WHMCS.
- Thông báo lỗi ghi rõ nguyên nhân (token sai/hết hạn, thiếu quyền, sai IP) kèm mã lỗi.
- Chức năng không đổi. Chỉ thư mục `modules/registrars/tinowhmcs/` thay đổi; `resources/domains/additionalfields.php` và `includes/hooks/tinowhmcs.php` giữ nguyên.

### Các bước cập nhật

1. **Tạo token trước** để module không bị gián đoạn: đăng nhập Client Area Tino → **Token API** → **Tạo token**.
   - Tên: ví dụ `WHMCS`.
   - Quyền: tick theo bảng ở mục [Xác thực](#xác-thực-token-api-cá-nhân). Tối thiểu `domains:read`, `domains:write`, `dns:read`, `dns:write`; thêm `domains:owner` nếu cần lấy mã EPP và sửa liên hệ tên miền từ WHMCS.
   - Giới hạn IP: IP công khai mà máy chủ WHMCS dùng để gọi ra Internet (thêm cả IPv6 nếu máy có IPv6). **Bắt buộc** khi chọn `domains:owner`, nên đặt cả khi không chọn.
   - Hạn dùng: tuỳ chính sách của bạn. Token hết hạn thì module ngừng gọi được API, cần tạo token mới trước ngày hết hạn.
   - Sao chép token ngay: token chỉ hiện một lần.
2. Sao lưu thư mục `modules/registrars/tinowhmcs/` đang chạy.
3. Chép đè thư mục `modules/registrars/tinowhmcs/` của bản mới vào thư mục gốc WHMCS.
4. **Ngay sau đó** vào **Configuration → System Settings → Domain Registrars → Tino Domain → Configure**, dán token vào ô **API Token**, bấm **Save**. Từ lúc chép file đến lúc lưu token, mọi lệnh tên miền (kể cả cron đồng bộ) sẽ báo lỗi, nên làm bước 3 và 4 liền nhau.
5. Kiểm tra: lưu không báo lỗi nghĩa là token gọi được API. Mở một tên miền trong admin WHMCS, xem các ô Nameserver hiển thị đúng. Nếu bật Module Log (**Utilities → Logs → Module Log**), các lệnh hiện dạng `GET /v1/domain/...`.
6. Nên đổi mật khẩu tài khoản Tino, vì bản cũ đã lưu mật khẩu này trong WHMCS.

Token không sửa được quyền, IP hay hạn dùng sau khi tạo: muốn đổi thì tạo token mới, dán vào WHMCS, rồi thu hồi token cũ ở Client Area.

**Quay lại bản cũ:** chép lại thư mục đã sao lưu ở bước 2 và nhập lại email/mật khẩu (bản mới đã xoá chúng khi lưu).

### Lỗi thường gặp

| Thông báo | Cách xử lý |
|---|---|
| `API Token phải là token cá nhân tinopat_…` | Chưa dán token, hoặc dán nhầm (mật khẩu, token khác). Dán token bắt đầu bằng `tinopat_`. |
| `API Token sai, đã thu hồi hoặc hết hạn (invalid_token)` | Tạo token mới và dán lại. |
| `API Token thiếu quyền cho thao tác này (insufficient_scope)` | Token thiếu quyền cho chức năng vừa dùng (ví dụ lấy EPP cần `domains:owner`). Tạo token mới đủ quyền. |
| `IP máy chủ WHMCS không có trong danh sách IP của API Token (ip_not_allowed)` | IP máy chủ WHMCS gọi ra ngoài khác IP đã khai. Tạo token mới với đúng IP (cả IPv6 nếu có). |

## Cài đặt

Chép các file vào thư mục gốc WHMCS, giữ nguyên đường dẫn:

| File | Vai trò |
|---|---|
| `modules/registrars/tinowhmcs/` | Module registrar (`tinowhmcs.php`, `lib/TinoApi.php`) |
| `resources/domains/additionalfields.php` | Form thông tin chủ thể (Tỉnh/Thành, Phường/Xã lấy từ API) – gộp vào file sẵn có nếu đã có |
| `includes/hooks/tinowhmcs.php` | JS lọc Phường/Xã theo Tỉnh/Thành trên trang đặt hàng và admin |

Vào **Configuration → System Settings → Domain Registrars**, kích hoạt *Tino Domain*, dán **API Token**. Khi lưu, module gọi thử `GET /v1/domain`; token sai, thiếu quyền hoặc sai IP sẽ báo lỗi ngay.

## Xác thực: token API cá nhân

Tạo token ở Client Area Tino → **Token API** (`tinopat_…`, chỉ hiện một lần). Mọi lệnh của tài khoản gọi `https://api.tino.vn/v1/<route>` với `Authorization: Bearer <token>`; không đăng nhập bằng email/mật khẩu, không làm mới token. Tra tên miền, bảng giá TLD và danh sách địa phương là API công khai, không gửi token.

| Quyền (scope) | Dùng cho |
|---|---|
| `domains:read` | Tìm tên miền, đồng bộ, đọc nameserver/khoá chuyển/liên hệ; kiểm tra token khi lưu cấu hình |
| `domains:write` | Đăng ký, chuyển về, gia hạn, sửa nameserver/khoá chuyển/ID protection, child nameserver |
| `dns:read`, `dns:write` | Quản lý bản ghi DNS |
| `domains:owner` | Lấy mã EPP, sửa liên hệ. Tino bắt buộc token có quyền này phải giới hạn IP: nhập IP máy chủ WHMCS |

Token hết hạn hoặc bị thu hồi thì module báo lỗi; xem [Lỗi thường gặp](#lỗi-thường-gặp).

Lần lưu cấu hình có token xoá `Username`/`Password` cũ khỏi `tblregistrars` và token đăng nhập cũ (`auth:*`) khỏi `mod_tinowhmcs`. Bảng `mod_tinowhmcs` (tự tạo) giờ chỉ cache danh sách địa phương 1 ngày.

## Chức năng

Đăng ký, chuyển về, gia hạn, đồng bộ, nameserver, khoá chuyển, ID protection, EPP, liên hệ, child nameserver, kiểm tra tên miền, nhập giá TLD (`GetTldPricing`, giá 1 năm, tiền VND) và quản lý bản ghi DNS (A, AAAA, CNAME, MX, TXT/SPF) trên zone DNS Tino của tên miền.

## Kiểm tra

```sh
php modules/registrars/tinowhmcs/tests/run.php                       # kiểm tra thuần
TINO_TOKEN=tinopat_... php modules/registrars/tinowhmcs/tests/run.php       # + gọi API thật (chỉ đọc)
TINO_LIVE_WRITE=1 ...                                                          # + thêm/xoá 1 bản ghi TXT thử trên zone DNS
```
