# Kiloships Shipping Plugin for WooCommerce
Plugin tích hợp dịch vụ tạo shipping label của Kiloships vào WooCommerce.

---

## Mục Lục

1. [Tổng Quan](#tổng-quan)
2. [Cấu Trúc Thư Mục](#cấu-trúc-thư-mục)
3. [Cấu Trúc Database](#cấu-trúc-database)
4. [Cấu Trúc File & Classes](#cấu-trúc-file--classes)
5. [Luồng Hoạt Động](#luồng-hoạt-động)
6. [API Endpoints](#api-endpoints)
7. [WordPress Options](#wordpress-options)
8. [AJAX Actions](#ajax-actions)
9. [Hướng Dẫn Sửa Chữa](#hướng-dẫn-sửa-chữa)

---

## Tổng Quan

Plugin này cho phép:
- Tích hợp API của Kiloships để tạo shipping labels
- Tạo label trực tiếp từ trang WooCommerce Order
- Quản lý danh sách suppliers (địa chỉ người gửi)
- Theo dõi và báo cáo tất cả labels đã tạo
- Hủy labels đã tạo
- Xuất báo cáo dạng CSV

---

## Cấu Trúc Thư Mục

```
kiloships-shipping/
├── kiloships-shipping.php          # File chính của plugin
├── README.md                        # File này
└── includes/                        # Thư mục chứa các class
    ├── class-kiloships-admin.php           # Quản lý admin menu & settings page
    ├── class-kiloships-admin-api.php       # Tab API Configuration
    ├── class-kiloships-admin-suppliers.php # Tab Suppliers Management
    ├── class-kiloships-admin-reports.php   # Tab Reports & Database operations
    ├── class-kiloships-api.php             # API handler cho Kiloships API
    └── class-kiloships-order.php           # Meta box trên order page
```

---

## Cấu Trúc Database

### Bảng: `wp_kiloships_labels`

Plugin tạo 1 custom table để lưu lịch sử labels.

**Cột:**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) | Primary key, auto increment |
| `order_id` | bigint(20) | WooCommerce Order ID |
| `order_number` | varchar(100) | WooCommerce Order Number |
| `tracking_number` | varchar(100) | Tracking number từ Kiloships |
| `object_id` | varchar(100) | Label ID từ Kiloships API |
| `status` | varchar(20) | `active` hoặc `cancelled` |
| `cost` | decimal(10,2) | Chi phí tạo label |
| `service_level` | varchar(100) | Loại dịch vụ (usps_ground_advantage, usps_priority, etc.) |
| `from_name` | varchar(255) | Tên người gửi |
| `from_address` | text | Địa chỉ người gửi (formatted) |
| `to_name` | varchar(255) | Tên người nhận |
| `to_address` | text | Địa chỉ người nhận (formatted) |
| `website` | varchar(255) | Tên website tạo label |
| `created_at` | datetime | Thời gian tạo label |
| `cancelled_at` | datetime | Thời gian hủy label (nullable) |

**Indexes:**
- PRIMARY KEY: `id`
- INDEX: `order_id`
- INDEX: `tracking_number`
- INDEX: `status`
- INDEX: `created_at`

---

## Cấu Trúc File & Classes

### 1. `kiloships-shipping.php` - Main Plugin File

**Class:** `Kiloships_Shipping`

**Nhiệm vụ:**
- Singleton pattern để khởi tạo plugin
- Load các class dependencies
- Đăng ký activation hook để tạo database table

**Constants:**
- `KILOSHIPS_VERSION` - Phiên bản plugin
- `KILOSHIPS_PLUGIN_DIR` - Đường dẫn thư mục plugin
- `KILOSHIPS_PLUGIN_URL` - URL thư mục plugin

---

### 2. `class-kiloships-admin.php` - Admin Settings Manager

**Class:** `Kiloships_Admin`

**Nhiệm vụ:**
- Đăng ký admin menu page trong WooCommerce
- Quản lý tab navigation (API, Suppliers, Reports)
- Load và khởi tạo các tab classes

**Hook sử dụng:**
- `admin_menu` - Đăng ký menu page
- `admin_init` - Đăng ký settings

**Methods:**
- `add_admin_menu()` - Tạo submenu trong WooCommerce menu
- `register_settings()` - Đăng ký WordPress settings
- `render_settings_page()` - Render trang settings với tabs

**Vị trí menu:**
- **WooCommerce > Kiloships Shipping**
- Capability required: `manage_woocommerce` (Shop Manager & Admin)

---

### 3. `class-kiloships-admin-api.php` - API Configuration Tab

**Class:** `Kiloships_Admin_API`

**Nhiệm vụ:**
- Quản lý API key configuration
- Quản lý default "From Address" (địa chỉ người gửi mặc định)
- Hiển thị trạng thái kết nối API
- Hiển thị số dư tài khoản Kiloships

**WordPress Options sử dụng:**
- `kiloships_api_key` - API key từ kiloships.com
- `kiloships_from_name` - Tên người gửi mặc định
- `kiloships_from_street1` - Địa chỉ 1
- `kiloships_from_street2` - Địa chỉ 2
- `kiloships_from_city` - Thành phố
- `kiloships_from_state` - State (2-letter code)
- `kiloships_from_zip` - ZIP code
- `kiloships_from_country` - Country (luôn là "US")

**Methods:**
- `register_settings()` - Static method đăng ký settings
- `render()` - Static method render tab content

---

### 4. `class-kiloships-admin-suppliers.php` - Suppliers Management Tab

**Class:** `Kiloships_Admin_Suppliers`

**Nhiệm vụ:**
- Quản lý danh sách suppliers (địa chỉ người gửi thường xuyên sử dụng)
- AJAX lookup city/state từ ZIP code
- Add/Remove suppliers dynamically

**WordPress Options sử dụng:**
- `kiloships_suppliers` - Array chứa danh sách suppliers

**Cấu trúc Supplier:**
```php
array(
    'name'    => 'Supplier Name',
    'street1' => '123 Main St',
    'street2' => 'Suite 100',
    'city'    => 'New York',
    'state'   => 'NY',
    'zip'     => '10001'
)
```

**AJAX Actions:**
- `wp_ajax_kiloships_admin_lookup_city_state` - Lookup city/state

**Methods:**
- `register_settings()` - Đăng ký settings
- `init_hooks()` - Đăng ký AJAX hooks
- `render()` - Render tab content
- `render_supplier_row()` - Render một supplier row
- `ajax_lookup_city_state()` - AJAX handler cho ZIP lookup

**JavaScript Features:**
- Dynamic add/remove supplier rows
- ZIP code lookup tự động fill city/state
- Form validation

---

### 5. `class-kiloships-admin-reports.php` - Reports & Database

**Class:** `Kiloships_Admin_Reports`

**Nhiệm vụ:**
- Quản lý database table
- Lưu lịch sử labels
- Hiển thị báo cáo labels theo tháng/năm
- Export CSV
- Update status khi cancel label

**Constants:**
- `TABLE_NAME` = `'kiloships_labels'`

**AJAX Actions:**
- `wp_ajax_kiloships_export_csv` - Export CSV

**Methods:**
- `create_table()` - Static, tạo database table
- `save_label($data)` - Static, lưu label mới
- `cancel_label($tracking_number)` - Static, update status thành cancelled
- `get_labels($filters)` - Static, query labels với filters
- `render()` - Static, render reports tab
- `ajax_export_csv()` - AJAX handler export CSV

**Filter Parameters:**
```php
array(
    'month'  => 1-12,      // Tháng
    'year'   => 2024,      // Năm
    'status' => 'active'   // 'active', 'cancelled', hoặc '' (all)
)
```

---

### 6. `class-kiloships-api.php` - API Handler

**Class:** `Kiloships_API`

**Nhiệm vụ:**
- Xử lý tất cả HTTP requests tới Kiloships API
- Handle errors và format responses

**API Base URL:**
- `https://kiloships.com/api`

**Methods:**

#### `create_label($data)`
Tạo shipping label mới.

**Endpoint:** `POST /shipping-labels/domestic`

**Request Format:**
```php
array(
    'shipment' => array(
        'async' => false,
        'addressTo' => array(
            'name'    => 'John Doe',
            'street1' => '123 Main St',
            'street2' => '',
            'city'    => 'New York',
            'state'   => 'NY',
            'zip'     => '10001',
            'country' => 'US'
        ),
        'addressFrom' => array(
            'name'    => 'Jane Smith',
            'street1' => '456 Oak Ave',
            'street2' => '',
            'city'    => 'Los Angeles',
            'state'   => 'CA',
            'zip'     => '90001',
            'country' => 'US'
        ),
        'parcels' => array(
            array(
                'weight'       => '1.5',
                'massUnit'     => 'lb',
                'length'       => '10',
                'width'        => '6',
                'height'       => '4',
                'distanceUnit' => 'in'
            )
        )
    ),
    'servicelevelToken' => 'usps_ground_advantage'
)
```

**Response:**
```php
array(
    'labelUrl'       => 'https://...',
    'trackingNumber' => 'TRACK123456',
    'objectId'       => 'label_abc123',
    'chargeAmount'   => 5.99
)
```

**Error Codes:**
- `401` - Invalid API Key
- `402` - Insufficient Balance
- `429` - Rate Limit Exceeded

#### `cancel_label($tracking_number)`
Hủy một label đã tạo.

**Endpoint:** `DELETE /shipping-labels/domestic/{trackingNumber}`

**Error Codes:**
- `401` - Invalid API Key
- `404` - Label not found or already cancelled
- `429` - Rate Limit

#### `get_balance()`
Lấy số dư tài khoản.

**Endpoint:** `GET /organizations/balance`

**Response:** `float` - Current balance

#### `lookup_city_state($zip_code)`
Tra cứu city/state từ ZIP code.

**Endpoint:** `GET /addresses/city-state?zipCode={zip}`

**Response:**
```php
array(
    'city'  => 'New York',
    'state' => 'NY'
)
```

#### `standardize_address($address)`
Chuẩn hóa địa chỉ.

**Endpoint:** `POST /addresses/address`

---

### 7. `class-kiloships-order.php` - Order Meta Box

**Class:** `Kiloships_Order`

**Nhiệm vụ:**
- Hiển thị meta box trên WooCommerce order page
- Cho phép tạo label từ order
- Hiển thị thông tin label nếu đã tạo
- Cho phép cancel label

**Hook sử dụng:**
- `add_meta_boxes` - Đăng ký meta box
- `wp_ajax_kiloships_create_label` - AJAX create label
- `wp_ajax_kiloships_cancel_label` - AJAX cancel label
- `wp_ajax_kiloships_lookup_city_state` - AJAX lookup ZIP

**Order Meta Keys:**
- `_kiloships_label_url` - URL của PDF label
- `_kiloships_tracking_number` - Tracking number
- `_kiloships_object_id` - Label object ID
- `_kiloships_charge_amount` - Chi phí tạo label

**Methods:**
- `add_meta_box()` - Đăng ký meta box (hỗ trợ HPOS)
- `render_meta_box($post)` - Render UI của meta box
- `create_label()` - AJAX handler tạo label
- `cancel_label()` - AJAX handler hủy label
- `lookup_city_state()` - AJAX handler lookup ZIP

**UI Features:**
- **Tabs:** Parcel, From, To, Options
- **Quick Supplier Select:** Dropdown chọn supplier đã lưu
- **Auto-fill:** ZIP lookup tự động fill city/state
- **Validation:** Client-side và server-side validation
- **Weight calculation:** Tự động tính tổng weight từ products

**Service Levels:**
- `usps_ground_advantage` - USPS Ground Advantage
- `usps_priority` - USPS Priority Mail
- `usps_priority_express` - USPS Priority Mail Express
- `usps_media_mail` - USPS Media Mail

---

## Luồng Hoạt Động

### Tạo Label Flow

```
1. User mở WooCommerce Order
   ↓
2. Meta box "Kiloships Shipping" hiển thị
   ↓
3. User điền thông tin (hoặc chọn supplier)
   ↓
4. Click "Create Label"
   ↓
5. JavaScript validation
   ↓
6. AJAX POST tới wp_ajax_kiloships_create_label
   ↓
7. Server-side validation
   ↓
8. class-kiloships-api.php gọi Kiloships API
   ↓
9. Nhận response (labelUrl, trackingNumber, etc.)
   ↓
10. Lưu vào order meta
    ↓
11. Lưu vào wp_kiloships_labels table
    ↓
12. Add order note
    ↓
13. Reload page → Hiển thị label info + Download button
```

### Cancel Label Flow

```
1. User click "Cancel Label" button
   ↓
2. Confirmation dialog
   ↓
3. AJAX POST tới wp_ajax_kiloships_cancel_label
   ↓
4. class-kiloships-api.php gọi DELETE API
   ↓
5. Xóa order meta data
   ↓
6. Update wp_kiloships_labels status = 'cancelled'
   ↓
7. Add order note
   ↓
8. Reload page
```

---

## API Endpoints

### Kiloships API Endpoints Used

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/shipping-labels/domestic` | Tạo label mới |
| DELETE | `/shipping-labels/domestic/{tracking}` | Hủy label |
| GET | `/organizations/balance` | Lấy số dư tài khoản |
| GET | `/addresses/city-state?zipCode={zip}` | Lookup city/state |
| POST | `/addresses/address` | Chuẩn hóa địa chỉ |

**Authentication:**
- Header: `Authorization: Bearer {api_key}`
- Content-Type: `application/json`

---

## WordPress Options

| Option Name | Type | Description |
|-------------|------|-------------|
| `kiloships_api_key` | string | API key từ kiloships.com |
| `kiloships_from_name` | string | Tên người gửi mặc định |
| `kiloships_from_street1` | string | Địa chỉ 1 mặc định |
| `kiloships_from_street2` | string | Địa chỉ 2 mặc định |
| `kiloships_from_city` | string | City mặc định |
| `kiloships_from_state` | string | State code mặc định (2-letter) |
| `kiloships_from_zip` | string | ZIP code mặc định |
| `kiloships_from_country` | string | Country mặc định (luôn "US") |
| `kiloships_suppliers` | array | Danh sách suppliers |

---

## AJAX Actions

### Action: `kiloships_create_label`

**Capability:** `manage_woocommerce`
**Nonce:** `kiloships_create_label`

**POST Parameters:**
```php
array(
    'order_id'     => int,
    'weight'       => float,
    'length'       => float,
    'width'        => float,
    'height'       => float,
    'from_name'    => string,
    'from_street1' => string,
    'from_street2' => string,
    'from_city'    => string,
    'from_state'   => string (2-letter),
    'from_zip'     => string,
    'from_country' => 'US',
    'to_name'      => string,
    'to_street1'   => string,
    'to_street2'   => string,
    'to_city'      => string,
    'to_state'     => string (2-letter),
    'to_zip'       => string,
    'to_country'   => 'US',
    'service'      => string
)
```

**Response:**
```json
{
    "success": true,
    "data": null
}
```

---

### Action: `kiloships_cancel_label`

**Capability:** `manage_woocommerce`
**Nonce:** `kiloships_cancel_label`

**POST Parameters:**
```php
array(
    'tracking_number' => string,
    'order_id'        => int
)
```

---

### Action: `kiloships_lookup_city_state`

**Capability:** `manage_woocommerce`
**Nonce:** `kiloships_create_label`

**POST Parameters:**
```php
array(
    'zip_code' => string
)
```

**Response:**
```json
{
    "success": true,
    "data": {
        "city": "New York",
        "state": "NY"
    }
}
```

---

### Action: `kiloships_admin_lookup_city_state`

**Capability:** `manage_options`
**Nonce:** `kiloships_admin_settings`

Dùng trong suppliers management tab.

---

### Action: `kiloships_export_csv`

**Capability:** `manage_options`
**Nonce:** `kiloships_export_csv`

**GET Parameters:**
```php
array(
    'month'  => int (1-12),
    'year'   => int,
    'status' => string ('active', 'cancelled', '')
)
```

**Response:** CSV file download

---

## Hướng Dẫn Sửa Chữa

### 1. Thay đổi vị trí menu

**File:** `includes/class-kiloships-admin.php`
**Method:** `add_admin_menu()`

Hiện tại menu nằm trong WooCommerce:
```php
add_submenu_page(
    'woocommerce',              // Parent slug
    'Kiloships Shipping',       // Page title
    'Kiloships Shipping',       // Menu title
    'manage_woocommerce',       // Capability
    'kiloships-shipping',       // Menu slug
    array($this, 'render_settings_page')
);
```

Để chuyển về Settings (chỉ admin):
```php
add_options_page(
    'Kiloships Shipping',
    'Kiloships Shipping',
    'manage_options',           // Chỉ admin
    'kiloships-shipping',
    array($this, 'render_settings_page')
);
```

---

### 2. Thêm Service Level mới

**File:** `includes/class-kiloships-order.php`
**Line:** ~440

Thêm option vào dropdown:
```php
<select id="ks_service">
    <option value="usps_ground_advantage">USPS Ground Advantage</option>
    <option value="usps_priority">USPS Priority Mail</option>
    <option value="usps_priority_express">USPS Priority Mail Express</option>
    <option value="usps_media_mail">USPS Media Mail</option>
    <!-- Thêm service mới ở đây -->
    <option value="new_service_token">New Service Name</option>
</select>
```

---

### 3. Thay đổi validation rules

**File:** `includes/class-kiloships-order.php`

**Client-side validation:** Line ~506-565 (JavaScript function `validateForm()`)

**Server-side validation:** Line ~696-758 (PHP method `create_label()`)

Ví dụ: Cho phép weight = 0:
```php
// OLD
if ($weight <= 0) {
    $errors[] = 'Weight must be greater than 0';
}

// NEW
if ($weight < 0) {
    $errors[] = 'Weight must be 0 or greater';
}
```

---

### 4. Thêm field vào supplier

**File:** `includes/class-kiloships-admin-suppliers.php`

**Thêm input field:** Line ~196-232 (method `render_supplier_row()`)

**Thêm vào JavaScript:** Line ~88-129 (add supplier button handler)

Ví dụ thêm field "Phone":
```php
// Trong render_supplier_row()
<div>
    <label>Phone</label>
    <input type="text"
           name="kiloships_suppliers[<?php echo $index; ?>][phone]"
           value="<?php echo esc_attr($supplier['phone'] ?? ''); ?>"
           class="regular-text" />
</div>
```

---

### 5. Thay đổi default parcel dimensions

**File:** `includes/class-kiloships-order.php`
**Line:** ~336-344

```php
<input type="number" id="ks_length" value="10" step="0.1">  // Thay đổi value="10"
<input type="number" id="ks_width" value="6" step="0.1">    // Thay đổi value="6"
<input type="number" id="ks_height" value="4" step="0.1">   // Thay đổi value="4"
```

---

### 6. Debug API errors

**File:** `includes/class-kiloships-api.php`

Thêm logging vào method `create_label()`:
```php
public function create_label($data)
{
    // ... existing code ...

    // Log request
    error_log('Kiloships API Request: ' . print_r($data, true));

    $response = wp_remote_post(...);

    // Log response
    error_log('Kiloships API Response: ' . wp_remote_retrieve_body($response));

    // ... existing code ...
}
```

Logs sẽ xuất hiện trong `wp-content/debug.log` (nếu `WP_DEBUG_LOG` enabled).

---

### 7. Thêm column vào Reports table

**File:** `includes/class-kiloships-admin-reports.php`

**Thay đổi database schema:** Method `create_table()` line ~36-57

**Thêm column vào UI:** Method `render()` line ~264-322

**Thêm column vào CSV export:** Method `ajax_export_csv()` line ~377-407

---

### 8. Thay đổi capability requirements

Hiện tại:
- **Settings page:** `manage_woocommerce` (Shop Manager + Admin)
- **Order meta box:** `manage_woocommerce`
- **Admin suppliers tab:** `manage_options` trong AJAX (Admin only)

Để thống nhất cho Shop Manager:

**File:** `includes/class-kiloships-admin-suppliers.php`
**Line:** ~244

```php
// OLD
if (! current_user_can('manage_options')) {
    wp_send_json_error('Permission denied.');
}

// NEW
if (! current_user_can('manage_woocommerce')) {
    wp_send_json_error('Permission denied.');
}
```

---

### 9. Rebuild database table

Nếu cần rebuild table sau khi thay đổi schema:

```php
// Chạy trong WordPress admin console hoặc qua plugin temporary
global $wpdb;
$table_name = $wpdb->prefix . 'kiloships_labels';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
Kiloships_Admin_Reports::create_table();
```

---

### 10. Lỗi thường gặp

#### "Permission denied" khi tạo label
**Nguyên nhân:** User không có capability `manage_woocommerce`
**Giải pháp:** Assign role Shop Manager hoặc Administrator

#### "API Key is missing"
**Nguyên nhân:** Chưa cấu hình API key
**Giải pháp:** Đi tới WooCommerce > Kiloships Shipping > API Configuration

#### ZIP lookup không hoạt động
**Nguyên nhân:** API key không hợp lệ hoặc network error
**Giải pháp:** Kiểm tra API key và connection status

#### Database table không tồn tại
**Nguyên nhân:** Plugin chưa được activate hoặc upgrade script chưa chạy
**Giải pháp:** Deactivate và activate lại plugin

---

## Liên Hệ & Hỗ Trợ

- **Plugin URI:** https://kiloships.com
- **Author:** DuongTuanVn
- **Author URI:** https://tuan.digital

---

## Changelog

### Version 1.1.0
- Initial release
- Tích hợp Kiloships API
- Quản lý suppliers
- Reports và export CSV
- HPOS compatibility
