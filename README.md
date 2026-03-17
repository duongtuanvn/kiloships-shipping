# Kiloships Shipping

WooCommerce plugin to create USPS shipping labels via the [Kiloships](https://kiloships.com) API — directly from the order page.

## Features

- Create USPS shipping labels from WooCommerce orders
- Cancel labels with one click
- Manage multiple supplier addresses (from addresses)
- Auto-lookup city/state from ZIP code
- Track all labels with monthly reports
- Export reports as CSV
- HPOS (High Performance Order Storage) compatible

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.2+
- Kiloships API key ([kiloships.com](https://kiloships.com))

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Activate via **Plugins** menu
3. Go to **WooCommerce > Kiloships Shipping** to enter your API key

## Setup

1. **API Key** — Get your key from [kiloships.com](https://kiloships.com) and enter it in the API Configuration tab
2. **Default From Address** — Set your default sender address
3. **Suppliers** (optional) — Add frequently used sender addresses for quick selection

## Usage

### Creating a Label

1. Open any WooCommerce order
2. Find the **Kiloships Shipping** meta box
3. Fill in parcel dimensions and weight (or use defaults)
4. Select a supplier or enter a custom from address
5. Choose a service level
6. Click **Create Label**

### Supported Service Levels

| Service | Token |
|---|---|
| USPS Ground Advantage | `usps_ground_advantage` |
| USPS Priority Mail | `usps_priority` |
| USPS Priority Mail Express | `usps_priority_express` |
| USPS Media Mail | `usps_media_mail` |

### Reports

Go to **WooCommerce > Kiloships Shipping > Reports** to view all created labels filtered by month, year, and status. Export to CSV for accounting.

## Database

The plugin creates a `wp_kiloships_labels` table to store label history:

| Column | Description |
|---|---|
| `order_id` | WooCommerce Order ID |
| `tracking_number` | Carrier tracking number |
| `object_id` | Kiloships label ID |
| `status` | `active` or `cancelled` |
| `cost` | Label cost |
| `service_level` | Service used |
| `from_name` / `from_address` | Sender info |
| `to_name` / `to_address` | Recipient info |
| `created_at` / `cancelled_at` | Timestamps |

## Order Meta

When a label is created, the following meta is saved on the order:

- `_kiloships_label_url` — PDF label URL
- `_kiloships_tracking_number` — Tracking number
- `_kiloships_object_id` — Label object ID
- `_kiloships_charge_amount` — Label cost

## Project Structure

```
kiloships-shipping/
├── kiloships-shipping.php                  # Main plugin file
└── includes/
    ├── class-kiloships-admin.php           # Admin menu & settings page
    ├── class-kiloships-admin-api.php       # API configuration tab
    ├── class-kiloships-admin-suppliers.php  # Suppliers management tab
    ├── class-kiloships-admin-reports.php    # Reports tab & database
    ├── class-kiloships-api.php             # Kiloships API client
    └── class-kiloships-order.php           # Order meta box (create/cancel label)
```

## License

GPL v2 or later

## Author

**Tuan Duong** — [tuan.digital](https://tuan.digital) | [@duongtuanvn](https://github.com/duongtuanvn)
