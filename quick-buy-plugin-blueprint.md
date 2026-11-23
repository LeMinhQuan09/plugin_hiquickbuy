# 📦 Blueprint Thiết Kế Plugin Mua Hàng Nhanh (WooCommerce Quick Buy)

> **Mục tiêu:** Bản đặc tả kiến trúc (blueprint) tóm tắt các chức năng cốt lõi dựa trên việc bóc tách plugin `devvn-quick-buy`, dùng làm tài liệu tham khảo để xây dựng lại một plugin Mua Hàng Nhanh mới, sạch, hiện đại và bảo mật hơn.

---

## 1. Khối Hiển Thị Nút Mua Hàng (Trigger Layer)

- **Vị trí hiển thị:**
  - **Trang chi tiết sản phẩm (Single Product):** Hook vào `woocommerce_single_product_summary` (priority `35` — sau nút Add to Cart mặc định).
  - **Danh sách / Danh mục sản phẩm (Shop Loop):** Hook vào `woocommerce_after_shop_loop_item` (priority `15`).
  - **Tự do chèn vị trí bất kỳ:** Cung cấp shortcode `[custom_quickbuy id="..."]`.
- **Dữ liệu gắn trên nút:** Chứa thuộc tính `data-product-id` để JavaScript xác định chính xác sản phẩm cần mở form.

---

## 2. Khối Giao Diện Popup & Đồng Bộ Sản Phẩm (UI & Client Sync)

- **Modal Popup:** Sử dụng modal nhẹ (khuyến nghị dùng Vanilla JS hoặc thư viện nhẹ như MicroModal, không phụ thuộc `jquery.bpopup`).
- **Lazy-load Form qua AJAX:** Khi người dùng click nút mới fetch HTML form về gắn vào DOM (giúp giảm tải dung lượng DOM ban đầu của trang web).
- **Hỗ trợ sản phẩm biến thể (Variable Product):**
  - Render form thuộc tính WooCommerce (`woocommerce_template_single_add_to_cart`).
  - Đồng bộ hai chiều giữa form ngoài trang và form trong popup (chọn size/màu ngoài trang hay trong popup thì giá và ảnh đều tự nhảy theo).
- **Tính tổng tiền thời gian thực:**
  $$\text{Tổng tiền} = (\text{Đơn giá} \times \text{Số lượng}) + \text{Phí vận chuyển}$$

---

## 3. Khối Form Thu Thập Thông Tin Khách Hàng (Form & Validation)

- **Trường thông tin cơ bản (phù hợp thị trường Việt Nam):**
  - Danh xưng: Radio `Anh` / `Chị`.
  - Họ và tên (bắt buộc).
  - Số điện thoại (bắt buộc, validate format di động Việt Nam: 10 chữ số, bắt đầu bằng `0`).
  - Email (tùy chọn bật/tắt hoặc không bắt buộc).
  - Địa chỉ / Ghi chú đơn hàng.
- **Tùy chọn Địa phương & Cước vận chuyển:**
  - Dropdown 3 cấp: Tỉnh/Thành → Quận/Huyện → Phường/Xã (tương thích các plugin địa giới hành chính VN).
  - Tự động gọi API tính cước vận chuyển khi khách chọn Tỉnh/Thành hoặc Quận/Huyện.
- **Phương thức thanh toán:**
  - Lựa chọn nhanh: `COD` (Thanh toán khi nhận hàng) hoặc `BACS` (Chuyển khoản ngân hàng).

---

## 4. Khối Xử Lý Tạo Đơn Hàng (Backend AJAX Order Handler)

- **Endpoint tiếp nhận:** `wp_ajax_custom_quickbuy` và `wp_ajax_nopriv_custom_quickbuy`.
- **Quy trình tạo đơn qua WooCommerce CRUD API:**
  1. Khởi tạo đối tượng đơn: `$order = wc_create_order();`
  2. Gán sản phẩm, số lượng, biến thể: `$order->add_product($product, $qty, $args);`
  3. Gán địa chỉ: `$order->set_address($address, 'billing');` và `$order->set_address($address, 'shipping');`
  4. Gán cổng thanh toán (`COD` / `BACS`) và item phí ship (`WC_Order_Item_Shipping`).
  5. Tính tổng: `$order->calculate_totals();`
  6. Chuyển trạng thái: `$order->update_status('processing', 'Đơn hàng nhanh');`
  7. Trừ kho tồn tự động: `wc_reduce_stock_levels($order->get_id());`
  8. Kích hoạt hook chuẩn: `do_action('woocommerce_checkout_order_processed', $order->get_id(), ...);` (giúp kích hoạt email thông báo admin, webhook, CRM...).
- **Phản hồi:** Trả về JSON `{ success: true, order_id: ..., thankyou_link: ... }`:
  - Hiển thị popup chúc mừng kèm mã đơn `Order #...`
  - Hoặc điều hướng trực tiếp sang trang Thank You (`order-received`).

---

## 5. Khối Cài Đặt Admin (Settings Page)

- Quản lý tại **Settings** hoặc menu con của **WooCommerce**:
  - Bật / tắt chức năng.
  - Tùy chỉnh câu chữ nút bấm (tiêu đề chính, tiêu đề phụ).
  - Bật / tắt các trường (Email, Địa chỉ chi tiết, Ghi chú, Lựa chọn cổng thanh toán).
  - Soạn thảo mẫu thông báo đặt hàng thành công / thất bại.

---

## 🚀 2 Cải Tiến Quan Trọng Khi Viết Mới

| Hạng mục | Bản cũ (`devvn-quick-buy`) | Bản mới nên làm |
|---|---|---|
| **Bảo mật Nonce** | Để trống `prod_nonce=""`, không check trong PHP | Thêm `wp_create_nonce()` và kiểm tra bằng `check_ajax_referer()` để chặn triệt để bot spam đơn hàng ảo. |
| **Công nghệ JS** | Dùng jQuery, `jquery.validate`, `jquery.bpopup` | Dùng **Vanilla JS (Fetch API)**, HTML5 Form Validation hoặc module nhẹ; không phụ thuộc thư viện bên ngoài. |
| **Tính phí vận chuyển** | Xóa giỏ hàng thật để nhồi sản phẩm ảo tính cước (dễ mất session giỏ hàng của khách) | Dùng filter/instance shipping giả lập riêng, không can thiệp vào `WC()->cart` thật. |

