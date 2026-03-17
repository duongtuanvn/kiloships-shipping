# Đánh giá dự án Kiloships Shipping Plugin

**Ngày:** 2025-02-27  
**Đối chiếu:** [Kiloships API Documentation](https://kiloships.com/docs)

---

## 1. Tổng quan dự án

Plugin WooCommerce tích hợp Kiloships để tạo/hủy shipping label domestic (USPS), quản lý địa chỉ gửi (suppliers), báo cáo và export CSV. Codebase gọn, có README chi tiết, hỗ trợ HPOS.

**Phiên bản:** Header 1.1.0, constant `KILOSHIPS_VERSION` = 1.0.0 → **nên thống nhất.**

---

## 2. So sánh với Kiloships API Docs

### 2.1 Các endpoint đã dùng đúng

| Endpoint | Docs | Plugin | Ghi chú |
|----------|------|--------|---------|
| **POST** `/shipping-labels/domestic` | Create domestic label | ✅ `Kiloships_API::create_label()` | Request body: shipment (addressTo, addressFrom, parcels), servicelevelToken. Đúng format. |
| **DELETE** `/shipping-labels/domestic/{trackingNumber}` | Cancel label | ✅ `Kiloships_API::cancel_label()` | Đúng. |
| **GET** `/organizations/balance` | Balance | ✅ `Kiloships_API::get_balance()` | Đúng. |
| **GET** `/addresses/city-state?zipCode=` | City/State lookup | ✅ `Kiloships_API::lookup_city_state()` | Đúng. |
| **POST** `/addresses/address` | Address standardization | ✅ Method có trong `Kiloships_API::standardize_address()` | **Chưa dùng trong UI.** |

**Kết luận:** Create/Cancel label, Balance, City-State lookup khớp docs. Address standardization có trong API class nhưng không có nút/flow nào gọi.

---

### 2.2 Các endpoint chưa dùng

| Endpoint | Mô tả (theo docs) | Đề xuất |
|----------|--------------------|---------|
| **GET** `/tracking/{trackingNumber}` | Tracking theo tracking number | Có thể thêm: link “Track” trên order hoặc tab tracking trong meta box. |
| **POST** `/scan-form` | Tạo SCAN form cho nhiều tracking | Hữu ích nếu giao nhiều gói cùng lúc: trang Reports có thể có “Create SCAN Form” cho các label đã chọn. |
| **POST** `/addresses/zipcode` | ZIP lookup từ địa chỉ | Có thể dùng thay/bổ sung city-state khi người dùng nhập full address. |
| **GET** `/addresses/zone/number?originZIPCode=&destinationZIPCode=&mailingDate=` | Zone giữa 2 ZIP | Có thể hiển thị zone trên order hoặc trong báo cáo (tùy nhu cầu). |

---

### 2.3 Request/Response so với docs

**Create Label**

- **Docs:** `shipment.async`, `addressTo`/`addressFrom` (name, street1, street2, city, state, zip, country), `ignoreBadAddress` (optional), `parcels[]` (weight, massUnit, length, width, height, distanceUnit), `servicelevelToken`, `metadata` (optional).
- **Plugin:** Gửi đủ addressTo, addressFrom, parcels, servicelevelToken; `async: false`. **Thiếu:** `ignoreBadAddress`, `metadata` (chưa cho user nhập).
- **Response:** Plugin dùng `labelUrl`, `trackingNumber`, `objectId`, `chargeAmount` → khớp docs (docs còn `labelImageUrl`, thực tế trùng với label URL).

**Cancel Label**

- **Docs:** Response có `status`: `CANCELLED` hoặc `DISPUTED`.
- **Plugin:** Không kiểm tra `status` trong response; chỉ cần HTTP success. Nếu sau này cần thông báo “đang dispute” thì nên parse `data.status`.

**ZIP / validation**

- Docs có chỗ nói “five digits” nhưng Create Label ghi “5-digit or 9-digit ZIP”. Plugin validate 5 hoặc 9 (regex `\d{5}(-\d{4})?`) → **đúng với Create Label.**

---

## 3. Điểm mạnh

1. **Luồng chính đầy đủ:** Tạo label từ order, hủy label, lưu meta + bảng reports, order note.
2. **Bảo mật:** Nonce, capability `manage_woocommerce` cho create/cancel/lookup; export CSV dùng `manage_options`.
3. **HPOS:** Meta box đăng ký đúng screen cho Custom Orders Table.
4. **Trải nghiệm:** Tabs Parcel/From/To/Options, quick supplier, ZIP lookup city/state, validation client + server.
5. **Báo cáo:** Lọc theo tháng/năm/status, tổng cost, export CSV.
6. **Error handling:** API class map 401/402/429 và parse `error.errors[]` từ docs.

---

## 4. Điểm cần cải thiện / rủi ro

1. **Version:** Đồng bộ plugin header version và `KILOSHIPS_VERSION`.
2. **Address standardization:** Có method nhưng không dùng → có thể thêm nút “Validate address” (From/To) gọi `standardize_address` và điền lại form.
3. **Tracking:** Không gọi GET tracking → có thể thêm link đến carrier hoặc gọi GET `/tracking/{trackingNumber}` để hiển thị trạng thái.
4. **Cancel response:** Nên đọc `data.status` (CANCELLED/DISPUTED) để hiển thị thông báo phù hợp.
5. **SCAN form:** Chưa có → nếu cần batch drop-off thì thêm flow tạo SCAN từ danh sách tracking (vd trong Reports).
6. **create_table:** Được gọi ở activation và trong Admin; `register_activation_hook` gọi từ main file. Ổn, nhưng cần đảm bảo chỉ 1 nơi thực sự chạy migration để tránh conflict sau này.

---

## 5. Bảng tổng hợp API

| API (theo docs) | Implemented | Used in UI |
|-----------------|-------------|------------|
| Create Domestic Label | ✅ | ✅ Order meta box |
| Cancel Domestic Label | ✅ | ✅ Order meta box |
| Get Balance | ✅ | ✅ Settings > API tab |
| City/State Lookup | ✅ | ✅ Order + Suppliers |
| Address Standardization | ✅ | ❌ |
| ZIP Code Lookup | ❌ | ❌ |
| Get Zone Number | ❌ | ❌ |
| Track Label | ❌ | ❌ |
| SCAN Form | ❌ | ❌ |

---

## 6. Kết luận và đề xuất ưu tiên

- **Đánh giá chung:** Plugin thực hiện đúng phần cốt lõi so với [Kiloships API](https://kiloships.com/docs): tạo/hủy label, balance, city-state lookup. Cấu trúc rõ ràng, README tốt, phù hợp dùng trong WooCommerce.
- **Nên làm ngay:** Thống nhất version (1.1.0), cân nhắc parse `status` khi cancel để hiển thị CANCELLED/DISPUTED.
- **Tùy nhu cầu:** Dùng Address Standardization trong UI; thêm Tracking (link hoặc GET tracking); SCAN Form và Get Zone nếu cần nghiệp vụ tương ứng.

File plan: `plans/2025-02-27-kiloships-evaluation/plan.md`
