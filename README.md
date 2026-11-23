# ⚡ HI Quick-Buy — Plugin Mua Hàng Nhanh cho WooCommerce

![Version](https://img.shields.io/badge/version-1.0.0-d70018?style=flat-square)
![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B%20(HPOS)-96588a?style=flat-square&logo=woocommerce&logoColor=white)
![Vanilla JS](https://img.shields.io/badge/Frontend-Pure%20Vanilla%20JS-f7df1e?style=flat-square&logo=javascript&logoColor=black)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-16a34a?style=flat-square)

**HI Quick-Buy** là plugin WordPress/WooCommerce hiện đại, siêu nhẹ giúp khách hàng đặt hàng nhanh trong một popup duy nhất mà không cần qua trang Giỏ hàng hay Thanh toán rườm rà. Được thiết kế tối ưu cho thị trường Việt Nam, tương thích cả **Classic Theme** lẫn **Block Theme** và **WooCommerce HPOS**.

---

## ✨ Tính năng nổi bật

### 1. Hiển thị nút Mua nhanh linh hoạt
- **Trang chi tiết sản phẩm:** Tự động hiển thị sau nút Thêm vào giỏ hàng (tương thích cả Classic Theme và WooCommerce Block Theme).
- **Trang Cửa hàng / Danh mục / Tìm kiếm:** Tự động gắn nút Mua nhanh gọn nhẹ dưới mỗi sản phẩm.
- **Shortcode chèn tự do:** `[hi_quickbuy id="123"]` hỗ trợ tùy biến tiêu đề, tiêu đề phụ và CSS class.

### 2. Popup siêu nhẹ (Pure Vanilla JS — 0 jQuery)
- Không phụ thuộc jQuery, `jquery.bpopup` hay `jquery.validate`; dùng thuần **Vanilla JS (Fetch API)** để tải form lười (lazy-load) khi khách bấm nút.
- **Hỗ trợ đầy đủ Sản phẩm Biến thể (Variable Product):** Tự động đồng bộ 2 chiều lựa chọn thuộc tính, ảnh, giá, tồn kho giữa trang sản phẩm và popup.
- **Hỗ trợ sản phẩm chưa có giá (`Liên hệ`):** Vẫn hiển thị nút Mua nhanh, tự động ẩn số lượng/vận chuyển/thanh toán/tổng tiền và tạo đơn yêu cầu báo giá sạch sẽ.
- **Tính tổng tiền thời gian thực:** `Tổng cộng = (Đơn giá × Số lượng) + Phí vận chuyển`.

### 3. Form chuẩn Việt Nam & Địa giới hành chính 2 cấp mới
- Danh xưng (`Anh` / `Chị`), Họ tên, Số điện thoại Việt Nam (hỗ trợ chuẩn hóa tự động `+84`, `84`, dấu cách/chấm/gạch ngang, chấp nhận cả di động 10 số và số cố định 11 số), Email (tắt / không bắt buộc / bắt buộc), Địa chỉ, Ghi chú.
- **Dropdown 2 cấp chuẩn mới có tìm kiếm tiếng Việt không dấu:** Tích hợp sẵn **34 Tỉnh/Thành phố** và **3.321 Phường/Xã/Đặc khu** kèm **mã bưu chính (`postcode`)**.

### 4. Tính phí vận chuyển độc lập & Tạo đơn chuẩn WooCommerce CRUD
- **Không can thiệp `WC()->cart`:** Tính phí vận chuyển qua gói giả lập độc lập theo WooCommerce Shipping Zones (không làm mất giỏ hàng hiện tại của khách).
- Tạo đơn hàng chuẩn WooCommerce CRUD API (`wc_create_order`), trừ kho tự động (`wc_reduce_stock_levels`), bắn hook `woocommerce_checkout_order_processed` để tương thích email, webhook, CRM.

### 5. Thanh toán COD / Chuyển khoản ngân hàng (BACS) & Mã QR chính chủ
- Hỗ trợ chọn nhanh **COD** và **Chuyển khoản ngân hàng (BACS)**.
- Khi khách chọn **BACS**, popup chúc mừng hiển thị trực tiếp thông tin tài khoản từ WooCommerce BACS, **ảnh mã QR do chủ shop tự upload qua Media Library**, **mẫu nội dung chuyển khoản tùy chỉnh** (`{order_id}`, `{phone}`, `{customer_name}`) và nút **Sao chép** 1 chạm.

### 6. Giao diện quản trị hiện đại & Tự động cập nhật từ GitHub
- Trang cài đặt **WooCommerce → HI Quick-Buy** xây dựng trên chuẩn WordPress Settings API, cho phép chọn **Màu chủ đạo** đồng bộ toàn bộ nút và popup.
- Tích hợp sẵn cơ chế **Auto-Update trực tiếp từ GitHub Releases** ngay trong mục **Plugins** của WordPress.

---

## 📋 Yêu cầu hệ thống

- **WordPress:** `6.9+`
- **PHP:** `8.1+`
- **WooCommerce:** `8.0+` (đã khai báo tương thích HPOS `custom_order_tables`)

---

## 🚀 Cài đặt

### Cách 1: Tải file ZIP phát hành (Khuyến nghị)
1. Tải file `hi-quick-buy.zip` mới nhất tại trang [Releases](https://github.com/LeMinhQuan09/plugin_hiquickbuy/releases).
2. Vào **WP Admin → Plugins → Add New → Upload Plugin**, chọn file `hi-quick-buy.zip` và bấm **Install Now**.
3. Bấm **Activate** và vào **WooCommerce → HI Quick-Buy** để cấu hình.

### Cách 2: Clone cho môi trường phát triển
```bash
cd wp-content/plugins
git clone https://github.com/LeMinhQuan09/plugin_hiquickbuy.git hi-quick-buy
```

---

## 🧩 Sử dụng Shortcode

Chèn nút Mua nhanh tại bất kỳ bài viết, trang đích (Landing Page) hoặc trình dựng trang nào:

```text
[hi_quickbuy id="123"]
```

Các tham số tùy chọn:

| Tham số | Mặc định | Mô tả |
|---|---|---|
| `id` | ID bài viết/sản phẩm hiện tại | ID sản phẩm WooCommerce cần đặt mua |
| `title` | Theo cài đặt Admin | Ghi đè tiêu đề chính của nút |
| `subtitle` | Theo cài đặt Admin | Ghi đè tiêu đề phụ bên dưới nút |
| `class` | *(trống)* | Thêm CSS class tùy chỉnh cho nút |

Ví dụ:
```text
[hi_quickbuy id="20" title="Đặt mua ưu đãi" subtitle="Giao nhanh trong 2 giờ" class="my-custom-btn"]
```

---

## 🔄 Tự động cập nhật (GitHub Auto-Update)

Plugin tự động kiểm tra bản phát hành mới từ `LeMinhQuan09/plugin_hiquickbuy` (cache 6 giờ) và hiển thị thông báo cập nhật ngay trong **WP Admin → Plugins**, kèm nút **Kiểm tra cập nhật** thủ công 1 chạm.

- **Với Public Repository:** Hoạt động tự động hoàn toàn, không cần cấu hình thêm.
- **Với Private Repository:** Thêm Fine-grained Personal Access Token (quyền `Contents: Read-only`) vào `wp-config.php`:
  ```php
  define( 'HIQB_GITHUB_TOKEN', 'github_pat_xxxxxxxxxxxxxxxx' );
  ```

### Quy trình phát hành bản mới (cho Developer)
1. Cập nhật số phiên bản trong `hi-quick-buy.php` (`Version:` và `HIQB_VERSION`).
2. Commit và đẩy tag mới:
   ```bash
   git add .
   git commit -m "Release v1.0.1"
   git tag v1.0.1
   git push origin main --tags
   ```
3. GitHub Actions (`.github/workflows/release.yml`) sẽ tự động kiểm tra cú pháp PHP, đóng gói `hi-quick-buy.zip` sạch và xuất bản GitHub Release.

---

## 🛠️ Hooks & Filters dành cho Developer

| Filter Hook | Mô tả |
|---|---|
| `hiqb_trigger_button_html` | Tùy biến HTML nút Mua nhanh |
| `hiqb_is_product_eligible` | Kiểm soát điều kiện hiển thị nút theo từng sản phẩm |
| `hiqb_vietnam_provinces` | Ghi đè/mở rộng danh sách Tỉnh/Thành phố |
| `hiqb_vietnam_wards` | Ghi đè/mở rộng danh sách Phường/Xã/Đặc khu |
| `hiqb_calculated_shipping_rates` | Tùy biến danh sách gói cước vận chuyển trả về |
| `hiqb_new_order_status` | Thay đổi trạng thái đơn hàng mới tạo (mặc định `processing` cho COD, `on-hold` cho BACS) |
| `hiqb_bacs_accounts` | Tùy biến danh sách tài khoản ngân hàng hiển thị trong popup |
| `hiqb_bacs_qr_url` | Tùy biến URL ảnh mã QR chuyển khoản |
| `hiqb_bacs_transfer_ref` | Tùy biến chuỗi nội dung chuyển khoản |

---

## 📁 Kiến trúc thư mục

```text
hi-quick-buy/
├── hi-quick-buy.php                     # Bootstrap, Plugin Header, Autoloader, Lifecycle hooks
├── uninstall.php                        # Dọn dẹp options & transients khi gỡ plugin
├── includes/
│   ├── class-plugin.php                 # Bộ điều phối chính, kiểm tra WooCommerce & nạp assets
│   ├── class-activator.php              # Khởi tạo cài đặt mặc định khi kích hoạt
│   ├── class-deactivator.php            # Dọn cache khi tắt plugin
│   ├── class-settings.php               # Quản lý cấu hình & sanitize dữ liệu
│   ├── class-trigger.php                # Hiển thị nút Mua nhanh (Single, Loop, Block, Shortcode)
│   ├── class-popup-form.php             # Khung modal & AJAX lazy-load form
│   ├── class-locations.php              # Địa giới hành chính VN 2 cấp + mã bưu chính
│   ├── class-shipping-calculator.php    # Tính phí vận chuyển độc lập (không đụng WC()->cart)
│   ├── class-order-handler.php          # Tạo đơn WooCommerce CRUD API & thông tin BACS/QR
│   └── admin/
│       ├── class-admin-settings.php     # Trang cài đặt WooCommerce → HI Quick-Buy
│       └── class-github-updater.php     # Cơ chế Auto-Update từ GitHub Releases
├── assets/
│   ├── css/
│   │   ├── hi-quick-buy.css             # Giao diện nút & popup frontend
│   │   └── hi-quick-buy-admin.css       # Giao diện Card UI & Toggle Switch trong Admin
│   ├── js/
│   │   ├── hi-quick-buy.js              # Pure Vanilla JS (Modal, Combobox tìm kiếm, Sync biến thể)
│   │   └── hi-quick-buy-admin.js        # Media Library picker cho ảnh mã QR
│   └── data/
│       └── vn-locations.json            # Dữ liệu 34 Tỉnh/Thành & 3.321 Phường/Xã/Đặc khu
└── .github/
    └── workflows/
        └── release.yml                  # CI/CD tự động build ZIP & phát hành GitHub Release
```

---

## 👤 Tác giả & Giấy phép

- **Tác giả:** [hiseo](https://hiseo.vn)
- **Giấy phép:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
