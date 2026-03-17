# Đánh giá 4 tiêu chí – Kiloships Shipping Plugin

**Ngày:** 2025-02-27

---

## 1. Đã đủ tính năng trong API chưa?

**Tham chiếu:** [Kiloships API Docs](https://kiloships.com/docs)

| API Endpoint | Trạng thái | Ghi chú |
|--------------|------------|--------|
| **POST** `/shipping-labels/domestic` – Tạo label | ✅ Có | Đủ body: shipment (addressTo, addressFrom, parcels), servicelevelToken. Thiếu optional: `ignoreBadAddress`, `metadata`. |
| **DELETE** `/shipping-labels/domestic/{tracking}` – Hủy label | ✅ Có | Đúng spec. |
| **GET** `/organizations/balance` – Số dư | ✅ Có | Hiển thị ở tab API. |
| **GET** `/addresses/city-state?zipCode=` – City/State | ✅ Có | Dùng ở Order + Suppliers. |
| **POST** `/addresses/address` – Chuẩn hóa địa chỉ | ⚠️ Có method, chưa dùng UI | `Kiloships_API::standardize_address()` tồn tại nhưng không có nút/flow nào gọi. |
| **GET** `/tracking/{trackingNumber}` – Tracking | ❌ Chưa | Không gọi API, không hiển thị trạng thái tracking. |
| **POST** `/scan-form` – SCAN form (nhiều tracking) | ❌ Chưa | Không tạo SCAN form. |
| **POST** `/addresses/zipcode` – ZIP từ địa chỉ | ❌ Chưa | Không dùng. |
| **GET** `/addresses/zone/number` – Zone 2 ZIP | ❌ Chưa | Không dùng. |

**Kết luận:**  
- **Đủ cho nghiệp vụ cốt lõi:** tạo/hủy label, balance, city-state lookup.  
- **Chưa đủ toàn bộ API:** thiếu Tracking, SCAN Form, ZIP lookup, Get Zone; Address standardization có code nhưng chưa gắn UI.

**Điểm:** 6/10 (đủ dùng, chưa phủ hết API).

---

## 2. Đảm bảo các rule bảo mật WooCommerce & WordPress chưa?

### 2.1 Đã làm đúng

| Rule | Áp dụng |
|------|--------|
| Thoát khi gọi trực tiếp | `if (! defined('ABSPATH')) { exit; }` ở mọi file include. |
| Nonce cho AJAX | `check_ajax_referer('...', 'nonce')` cho: `kiloships_create_label`, `kiloships_cancel_label`, `kiloships_lookup_city_state`, `kiloships_export_csv`, `kiloships_admin_lookup_city_state`. |
| Phân quyền | Order actions: `current_user_can('manage_woocommerce')`. Export CSV + admin lookup: `current_user_can('manage_options')`. Menu: `manage_woocommerce`. |
| Sanitize input | `sanitize_text_field()` cho POST/GET (order_id, weight, dimensions, addresses, service, tracking_number, zip_code, tab, filter_month/year/status). `intval()` cho order_id, filter_month, filter_year. |
| Escape output | `esc_html()`, `esc_attr()`, `esc_url()` cho output trong HTML/JS. |
| SQL an toàn | `get_labels()`: dùng `$wpdb->prepare()` khi có tham số (month, year, status). `$wpdb->insert()` / `$wpdb->update()` dùng mảng, WP tự escape. Bảng dùng `$wpdb->prefix`, không nối chuỗi từ user. |
| Options form | Settings dùng `settings_fields()` + `options.php`, có CSRF của WordPress. |

### 2.2 Thiếu / nên bổ sung

| Vấn đề | Mô tả | Đề xuất |
|--------|--------|---------|
| **Sanitize khi lưu settings** | `register_setting('kiloships_api_options', 'kiloships_api_key')` và các option khác **không** có tham số `sanitize_callback`. WordPress lưu giá trị gửi lên, chưa sanitize theo chuẩn plugin. | Thêm `array('sanitize_callback' => 'sanitize_text_field')` cho từng option (hoặc callback tùy chỉnh cho `kiloships_suppliers` để sanitize từng field trong mảng). |
| **Option `kiloships_suppliers`** | Là array, lưu qua `options.php` không có sanitize_callback → dữ liệu có thể chứa HTML/script. | Thêm sanitize_callback duyệt từng phần tử và dùng `sanitize_text_field()` cho từng ô (name, street1, street2, city, state, zip). |
| **Capability Export vs Reports** | Trang Reports dùng `manage_woocommerce` (Shop Manager vào được), nhưng Export CSV yêu cầu `manage_options` (chỉ Admin). Logic ổn nhưng dễ gây thắc mắc. | Giữ như hiện tại hoặc ghi rõ trong tài liệu: “Chỉ Administrator được export CSV”. |

### 2.3 Tóm tắt bảo mật

- **Nonce, capability, escape, prepare SQL:** Đạt.  
- **Sanitize khi lưu settings:** Chưa đạt chuẩn WordPress (thiếu sanitize_callback cho API options và suppliers).

**Điểm:** 7/10 (trừ do thiếu sanitize_callback cho settings).

---

## 3. Đã tối ưu về CSDL chưa?

### 3.1 Điểm tốt

| Nội dung | Đánh giá |
|----------|----------|
| **Index** | Có PRIMARY KEY (`id`), KEY `order_id`, KEY `tracking_number`, KEY `status`, KEY `created_at` → phù hợp truy vấn theo order, tracking, trạng thái, thời gian. |
| **Kiểu dữ liệu** | `bigint` cho id/order_id, `decimal(10,2)` cho cost, `datetime` cho created_at/cancelled_at, `varchar`/`text` hợp lý. |
| **Truy vấn** | `get_labels()` lọc theo `YEAR(created_at)`, `MONTH(created_at)`, `status` → đều có index. |
| **Ghi dữ liệu** | `$wpdb->insert()` với mảng cột rõ ràng; `$wpdb->update()` theo `tracking_number` (có index). |

### 3.2 Chưa tối ưu / rủi ro

| Vấn đề | Mô tả | Đề xuất |
|--------|--------|---------|
| **Không phân trang** | `get_labels()` trả về toàn bộ kết quả thỏa filter, không `LIMIT`. Tháng nhiều label → query nặng, HTML lớn. | Thêm phân trang (LIMIT/OFFSET hoặc page number), ví dụ 50–100 dòng/trang. |
| **SELECT *** | `SELECT * FROM $table_name WHERE ...` lấy hết cột. Với bảng vài cột thì chấp nhận được, nhưng không tối ưu nếu sau này bảng mở rộng. | Có thể chuyển sang liệt kê cột cần dùng (optional). |
| **Schema update** | Chỉ có `create_table()` trong activation, không có cơ chế version schema (db version option + dbDelta khi nâng version). | Khi thêm/sửa cột sau này, cần thêm logic “db version” + gọi dbDelta khi upgrade. |

### 3.3 Tóm tắt CSDL

- Cấu trúc bảng và index **ổn**, truy vấn **an toàn** và dùng index.  
- Thiếu **phân trang** và **quy trình nâng cấp schema** rõ ràng.

**Điểm:** 7/10 (tốt cơ bản, chưa tối ưu khi dữ liệu lớn và chưa có upgrade path).

---

## 4. Điểm tổng hợp

| Tiêu chí | Điểm | Trọng số gợi ý | Ghi chú |
|----------|------|----------------|---------|
| 1. Đủ tính năng API | 6/10 | 25% | Đủ core (create/cancel/balance/city-state), thiếu tracking, SCAN, zone, ZIP lookup; standardize chưa dùng trong UI. |
| 2. Bảo mật WC/WP | 7/10 | 35% | Nonce, capability, escape, prepared SQL đúng; thiếu sanitize_callback cho settings. |
| 3. Tối ưu CSDL | 7/10 | 25% | Index đúng, query an toàn; thiếu phân trang và schema versioning. |
| 4. Chất lượng code & docs | 8/10 | 15% | README rất tốt, cấu trúc rõ, HPOS, validation 2 lớp. |

**Công thức (trọng số gợi ý):**  
`(6×0.25) + (7×0.35) + (7×0.25) + (8×0.15) = 1.5 + 2.45 + 1.75 + 1.2 = 6.9`

### Điểm tổng (làm tròn): **7.0 / 10**

- **Trung bình cộng đơn giản (4 tiêu chí ngang nhau):** (6+7+7+8)/4 = **7.0/10**.

---

## 5. Hành động ưu tiên (đã thực hiện 2025-02-27)

1. **Bảo mật:** Đã thêm `sanitize_callback` cho tất cả `register_setting` (API, from address, suppliers).  
2. **CSDL:** Đã thêm phân trang Reports (50/trang), DB version + `maybe_upgrade_db()` khi đổi schema.  
3. **API/UI:** Đã bổ sung: Tracking (GET + nút "Check Tracking"), SCAN Form (Reports + AJAX), Address standardization (nút "Validate Address" From/To), ignoreBadAddress + metadata khi tạo label, parse cancel response CANCELLED/DISPUTED, đồng bộ `KILOSHIPS_VERSION` 1.1.0.

File: `plans/2025-02-27-kiloships-evaluation/assessment-4-criteria.md`
