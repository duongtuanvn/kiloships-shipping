<?php

/**
 * Kiloships Admin - Reports Tab.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Admin_Reports
{

    /**
     * Database table name.
     */
    const TABLE_NAME = 'kiloships_labels';

    /**
     * Database schema version for upgrades.
     */
    const DB_VERSION = 5;

    /**
     * Default labels per page in reports.
     */
    const PER_PAGE = 50;

    /**
     * Initialize hooks for AJAX and CSV export.
     */
    public static function init_hooks()
    {
        add_action('wp_ajax_kiloships_export_csv', array(__CLASS__, 'ajax_export_csv'));
        add_action('wp_ajax_kiloships_rescan_orders', array(__CLASS__, 'ajax_rescan_orders'));
        add_action('wp_ajax_kiloships_link_suppliers', array(__CLASS__, 'ajax_link_suppliers'));
        add_action('wp_ajax_kiloships_check_tracking', array(__CLASS__, 'ajax_check_tracking'));
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade_db'));
    }

    /**
     * Run dbDelta if DB version has increased.
     */
    public static function maybe_upgrade_db()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $saved = (int) get_option('kiloships_db_version', 0);
        if ($saved >= self::DB_VERSION) {
            return;
        }
        self::create_table();
        self::ensure_columns();

        if (class_exists('Kiloships_Admin_Suppliers')) {
            Kiloships_Admin_Suppliers::create_table();
            if ($saved < 2) {
                Kiloships_Admin_Suppliers::migrate_from_options();
            }
        }

        self::recover_labels_from_orders();

        update_option('kiloships_db_version', self::DB_VERSION);
    }

    /**
     * Create database table for storing label history.
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        // dbDelta does NOT support IF NOT EXISTS -- must omit it
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            order_number varchar(100) NOT NULL,
            tracking_number varchar(100) NOT NULL,
            object_id varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            cost decimal(10,2) DEFAULT 0.00,
            service_level varchar(100) DEFAULT NULL,
            from_name varchar(255) DEFAULT NULL,
            from_address text DEFAULT NULL,
            to_name varchar(255) DEFAULT NULL,
            to_address text DEFAULT NULL,
            website varchar(255) DEFAULT NULL,
            supplier_id bigint(20) DEFAULT NULL,
            tracking_status varchar(100) DEFAULT NULL,
            tracking_location varchar(255) DEFAULT NULL,
            tracking_date varchar(100) DEFAULT NULL,
            tracking_checked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            cancelled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY tracking_number (tracking_number),
            KEY status (status),
            KEY created_at (created_at),
            KEY supplier_id (supplier_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Ensure all required columns exist on the labels table.
     * Handles cases where dbDelta silently failed to add columns.
     */
    public static function ensure_columns()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $required_columns = array(
            'order_id'        => 'bigint(20) NOT NULL',
            'order_number'    => 'varchar(100) NOT NULL',
            'tracking_number' => 'varchar(100) NOT NULL',
            'object_id'       => 'varchar(100) DEFAULT NULL',
            'status'          => "varchar(20) NOT NULL DEFAULT 'active'",
            'cost'            => 'decimal(10,2) DEFAULT 0.00',
            'service_level'   => 'varchar(100) DEFAULT NULL',
            'from_name'       => 'varchar(255) DEFAULT NULL',
            'from_address'    => 'text DEFAULT NULL',
            'to_name'         => 'varchar(255) DEFAULT NULL',
            'to_address'      => 'text DEFAULT NULL',
            'website'         => 'varchar(255) DEFAULT NULL',
            'supplier_id'          => 'bigint(20) DEFAULT NULL',
            'tracking_status'      => 'varchar(100) DEFAULT NULL',
            'tracking_location'    => 'varchar(255) DEFAULT NULL',
            'tracking_date'        => 'varchar(100) DEFAULT NULL',
            'tracking_checked_at'  => 'datetime DEFAULT NULL',
            'created_at'           => 'datetime NOT NULL',
            'cancelled_at'         => 'datetime DEFAULT NULL',
        );

        $existing = $wpdb->get_col("DESCRIBE `{$table}`", 0);
        if (empty($existing)) {
            return;
        }

        foreach ($required_columns as $col_name => $col_def) {
            if (! in_array($col_name, $existing, true)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col_name}` {$col_def}");
            }
        }

        $indexes = array('order_id', 'tracking_number', 'status', 'created_at', 'supplier_id');
        $existing_keys = $wpdb->get_col("SHOW INDEX FROM `{$table}` WHERE Key_name != 'PRIMARY'", 2);
        foreach ($indexes as $idx) {
            if (! in_array($idx, $existing_keys, true)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$idx}` (`{$idx}`)");
            }
        }
    }

    /**
     * Recover labels from WooCommerce order meta when the labels table is empty.
     * Order meta (_kiloships_tracking_number, _kiloships_charge_amount, etc.)
     * survives even if the labels table was lost.
     */
    public static function recover_labels_from_orders()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($existing > 0) {
            return;
        }

        $result = self::scan_orders_for_labels();
        if ($result['recovered'] > 0) {
            update_option('kiloships_labels_recovered', $result['recovered']);
        }
    }

    /**
     * Scan WooCommerce orders for label meta and insert into labels table.
     * Checks BOTH postmeta and wc_orders_meta tables for maximum coverage.
     *
     * @param bool $force_rescan Skip existing-row check (for manual re-scan).
     * @return array Diagnostic info with keys: recovered, skipped, errors, debug.
     */
    public static function scan_orders_for_labels($force_rescan = false)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $debug = array();
        $order_ids = array();

        if (! function_exists('wc_get_orders')) {
            return array('recovered' => 0, 'skipped' => 0, 'errors' => array('WooCommerce not loaded'), 'debug' => $debug);
        }

        // Check postmeta table
        $postmeta_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kiloships_tracking_number' AND meta_value != ''"
        );
        $debug['postmeta_count'] = count($postmeta_ids);
        $order_ids = array_merge($order_ids, $postmeta_ids);

        // Check wc_orders_meta table (HPOS)
        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $hpos_exists = $wpdb->get_var("SHOW TABLES LIKE '$hpos_table'");
        if ($hpos_exists) {
            $hpos_ids = $wpdb->get_col(
                "SELECT DISTINCT order_id FROM $hpos_table WHERE meta_key = '_kiloships_tracking_number' AND meta_value != ''"
            );
            $debug['wc_orders_meta_count'] = count($hpos_ids);
            $order_ids = array_merge($order_ids, $hpos_ids);
        } else {
            $debug['wc_orders_meta_count'] = 'table not found';
        }

        // Also check for orders with _kiloships_label_cancelled meta (cancelled labels still have tracking)
        $cancelled_postmeta = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kiloships_label_cancelled' AND meta_value = 'yes'"
        );
        $debug['cancelled_postmeta'] = count($cancelled_postmeta);
        $order_ids = array_merge($order_ids, $cancelled_postmeta);

        if ($hpos_exists) {
            $cancelled_hpos = $wpdb->get_col(
                "SELECT DISTINCT order_id FROM $hpos_table WHERE meta_key = '_kiloships_label_cancelled' AND meta_value = 'yes'"
            );
            $debug['cancelled_hpos'] = count($cancelled_hpos);
            $order_ids = array_merge($order_ids, $cancelled_hpos);
        }

        // Also try searching order notes for "Kiloships" to find orders that had labels
        $note_order_ids = $wpdb->get_col(
            "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_content LIKE '%Kiloships%' AND comment_content LIKE '%Tracking%'"
        );
        $debug['order_notes_count'] = count($note_order_ids);
        $order_ids = array_merge($order_ids, $note_order_ids);

        $order_ids = array_unique(array_filter($order_ids));
        $debug['total_unique_orders'] = count($order_ids);

        if (empty($order_ids)) {
            return array('recovered' => 0, 'skipped' => 0, 'errors' => array(), 'debug' => $debug);
        }

        $recovered = 0;
        $skipped = 0;
        $errors = array();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (! $order) {
                $errors[] = "Order #{$order_id}: not found";
                continue;
            }

            $tracking = $order->get_meta('_kiloships_tracking_number');

            // If no tracking in meta, try to extract from order notes
            if (empty($tracking)) {
                $notes = wc_get_order_notes(array('order_id' => $order_id));
                foreach ($notes as $note) {
                    if (preg_match('/Tracking:\s*([A-Z0-9]+)/i', $note->content, $m)) {
                        $tracking = $m[1];
                        break;
                    }
                }
            }

            if (empty($tracking)) {
                $errors[] = "Order #{$order_id}: no tracking number found";
                continue;
            }

            if (! $force_rescan) {
                $already = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE tracking_number = %s",
                    $tracking
                ));
                if ($already) {
                    $skipped++;
                    continue;
                }
            }

            $charge   = $order->get_meta('_kiloships_charge_amount');
            $obj_id   = $order->get_meta('_kiloships_object_id');
            $label_url = $order->get_meta('_kiloships_label_url');
            $cancelled = $order->get_meta('_kiloships_label_cancelled');
            $sup_id   = $order->get_meta('_kiloships_supplier_id');

            $shipping = $order->get_address('shipping');
            $to_name  = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
            $to_addr  = trim(($shipping['address_1'] ?? '') . ', ' . ($shipping['city'] ?? '') . ', ' . ($shipping['state'] ?? '') . ' ' . ($shipping['postcode'] ?? ''), ', ');

            $label_cancelled = ($cancelled === 'yes') || empty($label_url);

            // Try to extract charge from order notes if not in meta
            if (empty($charge)) {
                $notes = wc_get_order_notes(array('order_id' => $order_id));
                foreach ($notes as $note) {
                    if (preg_match('/Charge:\s*\$?([\d.]+)/i', $note->content, $m)) {
                        $charge = $m[1];
                        break;
                    }
                }
            }

            $order_date = $order->get_date_created();
            $created_at = $order_date ? $order_date->date('Y-m-d H:i:s') : current_time('mysql');

            $inserted = $wpdb->insert($table, array(
                'order_id'        => $order_id,
                'order_number'    => $order->get_order_number(),
                'tracking_number' => $tracking,
                'object_id'       => $obj_id ?: null,
                'status'          => $label_cancelled ? 'cancelled' : 'active',
                'cost'            => $charge ? floatval($charge) : 0.00,
                'service_level'   => null,
                'from_name'       => null,
                'from_address'    => null,
                'to_name'         => $to_name ?: null,
                'to_address'      => $to_addr ?: null,
                'website'         => get_bloginfo('name'),
                'supplier_id'     => $sup_id ? (int) $sup_id : null,
                'created_at'      => $created_at,
                'cancelled_at'    => $label_cancelled ? $created_at : null,
            ));

            if ($inserted) {
                $recovered++;
            } else {
                $errors[] = "Order #{$order_id}: DB insert failed - " . $wpdb->last_error;
            }
        }

        return array('recovered' => $recovered, 'skipped' => $skipped, 'errors' => $errors, 'debug' => $debug);
    }

    /**
     * AJAX: Re-scan WooCommerce orders for label data (manual trigger).
     */
    public static function ajax_rescan_orders()
    {
        check_ajax_referer('kiloships_rescan_orders', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        // Fix schema before scanning
        self::create_table();
        self::ensure_columns();

        $result = self::scan_orders_for_labels(false);

        wp_send_json_success(array(
            'recovered' => $result['recovered'],
            'skipped'   => $result['skipped'],
            'errors'    => $result['errors'],
            'debug'     => $result['debug'],
            'message'   => $result['recovered'] > 0
                ? sprintf('%d label(s) recovered!', $result['recovered'])
                : 'No new labels found. See debug info below.',
        ));
    }

    /**
     * Save label to database.
     *
     * @param array $data Label data.
     * @return int|false Insert ID or false on failure.
     */
    public static function save_label($data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $insert_data = array(
            'order_id'        => $data['order_id'],
            'order_number'    => $data['order_number'],
            'tracking_number' => $data['tracking_number'],
            'object_id'       => $data['object_id'] ?? null,
            'status'          => 'active',
            'cost'            => $data['cost'] ?? 0.00,
            'service_level'   => $data['service_level'] ?? null,
            'from_name'       => $data['from_name'] ?? null,
            'from_address'    => $data['from_address'] ?? null,
            'to_name'         => $data['to_name'] ?? null,
            'to_address'      => $data['to_address'] ?? null,
            'website'         => $data['website'] ?? get_bloginfo('name'),
            'supplier_id'     => ! empty($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'created_at'      => current_time('mysql'),
        );

        $result = $wpdb->insert($table_name, $insert_data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update label status to cancelled.
     *
     * @param string $tracking_number Tracking number.
     * @return bool True on success, false on failure.
     */
    public static function cancel_label($tracking_number)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            array(
                'status'       => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ),
            array('tracking_number' => $tracking_number),
            array('%s', '%s'),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Get total count of labels matching filters (no pagination).
     *
     * @param array $filters Filter parameters.
     * @return int Count.
     */
    public static function get_labels_count($filters = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $where = array('1=1');
        $params = array();

        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
            $params[] = $filters['year'];
            $params[] = $filters['month'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'supplier_id = %d';
            $params[] = (int) $filters['supplier_id'];
        }
        $where_clause = implode(' AND ', $where);
        if (!empty($params)) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE $where_clause",
                $params
            );
        } else {
            $query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
        }
        return (int) $wpdb->get_var($query);
    }

    /**
     * Get labels with filters and optional pagination.
     *
     * @param array $filters Filter parameters (month, year, status, per_page, page).
     * @return array Labels data.
     */
    public static function get_labels($filters = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $where = array('1=1');
        $params = array();

        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
            $params[] = $filters['year'];
            $params[] = $filters['month'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'supplier_id = %d';
            $params[] = (int) $filters['supplier_id'];
        }
        $where_clause = implode(' AND ', $where);
        $limit_sql = '';
        if (!empty($filters['per_page'])) {
            $per_page = max(1, min(100, (int) $filters['per_page']));
            $page = !empty($filters['page']) ? max(1, (int) $filters['page']) : 1;
            $offset = ($page - 1) * $per_page;
            $params[] = $per_page;
            $params[] = $offset;
            $limit_sql = ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
        } else {
            $limit_sql = ' ORDER BY created_at DESC';
        }

        if (!empty($params)) {
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where_clause" . $limit_sql,
                $params
            );
        } else {
            $query = "SELECT * FROM $table_name WHERE $where_clause" . $limit_sql;
        }
        return $wpdb->get_results($query);
    }

    /**
     * Render reports tab content.
     */
    public static function render()
    {
        // Get filter parameters
        $current_month = isset($_GET['filter_month']) ? intval($_GET['filter_month']) : date('n');
        $current_year = isset($_GET['filter_year']) ? intval($_GET['filter_year']) : date('Y');
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        $filter_supplier = isset($_GET['filter_supplier']) ? intval($_GET['filter_supplier']) : 0;

        $all_suppliers = class_exists('Kiloships_Admin_Suppliers') ? Kiloships_Admin_Suppliers::get_all() : array();
        $supplier_map = array();
        foreach ($all_suppliers as $s) {
            $supplier_map[$s->id] = $s->nickname ?: $s->name;
        }

        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = self::PER_PAGE;

        $filters = array(
            'month' => $current_month,
            'year'  => $current_year,
        );
        if ($filter_status !== '') {
            $filters['status'] = $filter_status;
        }
        if ($filter_supplier > 0) {
            $filters['supplier_id'] = $filter_supplier;
        }

        $total_items = self::get_labels_count($filters);
        $total_pages = $per_page > 0 ? max(1, (int) ceil($total_items / $per_page)) : 1;
        $current_page = min($current_page, $total_pages);

        $filters['per_page'] = $per_page;
        $filters['page'] = $current_page;
        $labels = self::get_labels($filters);

        $total_cost = 0;
        $total_active = 0;
        $total_cancelled = 0;
        foreach ($labels as $label) {
            if ($label->status === 'active') {
                $total_cost += $label->cost;
                $total_active++;
            } else {
                $total_cancelled++;
            }
        }
?>

        <div class="wrap">
            <h2>Label Reports</h2>

            <?php
            $recovered_count = get_option('kiloships_labels_recovered', 0);
            if ($recovered_count > 0) : ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom: 15px;">
                <p><strong>Recovery complete!</strong> <?php echo (int) $recovered_count; ?> label(s) recovered from WooCommerce order data. Some fields (From Address, Service Level) may be missing for recovered labels.</p>
            </div>
            <?php delete_option('kiloships_labels_recovered'); endif; ?>

            <!-- Filter Form -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                <h3>Filter Labels</h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="kiloships-shipping" />
                    <input type="hidden" name="tab" value="reports" />

                    <table class="form-table">
                        <tr>
                            <th>Month</th>
                            <td>
                                <select name="filter_month">
                                    <?php
                                    for ($m = 1; $m <= 12; $m++) {
                                        $selected = ($m == $current_month) ? 'selected' : '';
                                        echo '<option value="' . $m . '" ' . $selected . '>' . date('F', mktime(0, 0, 0, $m, 1)) . '</option>';
                                    }
                                    ?>
                                </select>

                                <select name="filter_year" style="margin-left: 10px;">
                                    <?php
                                    $start_year = 2024;
                                    $end_year = date('Y') + 1;
                                    for ($y = $end_year; $y >= $start_year; $y--) {
                                        $selected = ($y == $current_year) ? 'selected' : '';
                                        echo '<option value="' . $y . '" ' . $selected . '>' . $y . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <select name="filter_status">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php selected($filter_status, 'active'); ?>>Active</option>
                                    <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>>Cancelled</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Supplier</th>
                            <td>
                                <select name="filter_supplier">
                                    <option value="0">All Suppliers</option>
                                    <?php foreach ($all_suppliers as $s) : ?>
                                    <option value="<?php echo (int) $s->id; ?>" <?php selected($filter_supplier, $s->id); ?>><?php echo esc_html($s->nickname ?: $s->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary">Filter</button>
                        <button type="button" class="button" id="export-csv">Export to CSV</button>
                    </p>
                </form>
            </div>

            <!-- Summary -->
            <?php
            global $wpdb;
            $debug_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kiloships_labels");
            $debug_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}kiloships_labels'");
            $debug_col = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'supplier_id'",
                DB_NAME,
                $wpdb->prefix . 'kiloships_labels'
            ));
            $debug_sup_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}kiloships_suppliers'");
            $debug_sup_count = $debug_sup_table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kiloships_suppliers") : 0;
            ?>
            <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                <h3>Summary for <?php echo esc_html(date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year))); ?></h3>
                <p>
                    <strong>Total Labels (filtered):</strong> <?php echo (int) $total_items; ?><br>
                    <strong>Active Labels:</strong> <?php echo $total_active; ?><br>
                    <strong>Cancelled Labels:</strong> <?php echo $total_cancelled; ?><br>
                    <strong>Total Cost (Active):</strong> $<?php echo number_format($total_cost, 2); ?>
                </p>
                <p style="color: #646970; font-size: 12px; border-top: 1px solid #f0f0f1; padding-top: 8px; margin-top: 8px;">
                    DB Debug: Labels table <?php echo $debug_table_exists ? '&#10004;' : '<span style="color:red;">MISSING</span>'; ?>
                    &bull; Total rows (all months): <strong><?php echo $debug_total; ?></strong>
                    &bull; supplier_id col: <?php echo $debug_col ? '&#10004;' : '<span style="color:red;">NO</span>'; ?>
                    &bull; Suppliers table: <?php echo $debug_sup_table ? '&#10004; (' . $debug_sup_count . ' rows)' : '<span style="color:red;">MISSING</span>'; ?>
                    &bull; DB Version: <?php echo get_option('kiloships_db_version', '?'); ?>
                </p>
                <div style="border-top: 1px solid #f0f0f1; padding-top: 8px; margin-top: 8px;">
                    <button type="button" class="button" id="rescan-orders-btn">Re-scan Orders for Labels</button>
                    <button type="button" class="button" id="link-suppliers-btn" style="margin-left: 6px;">Link From Data → Suppliers</button>
                    <span id="rescan-spinner" class="spinner" style="float: none;"></span>
                    <div id="rescan-result" style="margin-top: 8px; display: none;"></div>
                </div>
            </div>

            <!-- Tracking check button -->
            <div style="margin-bottom: 10px;">
                <button type="button" class="button" id="check-tracking-btn">Check Tracking Status</button>
                <span id="tracking-spinner" class="spinner" style="float: none;"></span>
                <span id="tracking-progress" style="margin-left: 8px; color: #646970;"></span>
            </div>

            <!-- Labels Table -->
            <style>
                .ks-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; line-height:1.4; }
                .ks-badge-delivered { background:#d4edda; color:#155724; }
                .ks-badge-transit { background:#cce5ff; color:#004085; }
                .ks-badge-alert { background:#fff3cd; color:#856404; }
                .ks-badge-returned { background:#f8d7da; color:#721c24; }
                .ks-badge-pre { background:#e2e3e5; color:#383d41; }
                .ks-badge-unknown { background:#f0f0f1; color:#646970; }
            </style>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Created Date</th>
                        <th>Status</th>
                        <th>Order ID</th>
                        <th>Tracking Number</th>
                        <th>Tracking Status</th>
                        <th>Supplier</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Service</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($labels)) : ?>
                        <tr>
                            <td colspan="10" style="text-align: center;">No labels found for the selected period.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($labels as $label) :
                            $is_delivered = (strpos(strtolower($label->tracking_status ?? ''), 'delivered') !== false);
                            $checked_today = (! empty($label->tracking_checked_at) && date('Y-m-d', strtotime($label->tracking_checked_at)) === date('Y-m-d'));
                        ?>
                            <tr data-tracking="<?php echo esc_attr($label->tracking_number); ?>" data-label-status="<?php echo esc_attr($label->status); ?>" data-delivered="<?php echo $is_delivered ? '1' : '0'; ?>" data-checked-today="<?php echo $checked_today ? '1' : '0'; ?>">
                                <td>
                                    <?php echo date('Y-m-d H:i', strtotime($label->created_at)); ?>
                                    <?php if ($label->status === 'cancelled' && $label->cancelled_at) : ?>
                                        <br><small style="color: #999;">Cancelled: <?php echo date('Y-m-d H:i', strtotime($label->cancelled_at)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($label->status === 'active') : ?>
                                        <span style="color: green; font-weight: bold;">Active</span>
                                    <?php else : ?>
                                        <span style="color: red; font-weight: bold;">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $label->order_id . '&action=edit'); ?>" target="_blank">
                                        #<?php echo $label->order_number; ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($label->tracking_number); ?></td>
                                <td class="ks-tracking-cell"><?php
                                    $ts = $label->tracking_status ?? '';
                                    $tl = $label->tracking_location ?? '';
                                    $td_val = $label->tracking_date ?? '';
                                    $tc = $label->tracking_checked_at ?? '';
                                    if ($ts) {
                                        $cls = 'ks-badge-unknown';
                                        $sl = strtolower($ts);
                                        if (strpos($sl, 'delivered') !== false) $cls = 'ks-badge-delivered';
                                        elseif (strpos($sl, 'transit') !== false || strpos($sl, 'accepted') !== false || strpos($sl, 'origin') !== false || strpos($sl, 'departed') !== false || strpos($sl, 'arrived') !== false || strpos($sl, 'processed') !== false || strpos($sl, 'in transit') !== false) $cls = 'ks-badge-transit';
                                        elseif (strpos($sl, 'alert') !== false || strpos($sl, 'exception') !== false || strpos($sl, 'notice') !== false) $cls = 'ks-badge-alert';
                                        elseif (strpos($sl, 'return') !== false) $cls = 'ks-badge-returned';
                                        elseif (strpos($sl, 'pre') !== false || strpos($sl, 'label') !== false || strpos($sl, 'created') !== false) $cls = 'ks-badge-pre';
                                        echo '<span class="ks-badge ' . $cls . '">' . esc_html($ts) . '</span>';
                                        if ($tl) echo '<br><small style="color:#646970;">' . esc_html($tl) . '</small>';
                                        if ($td_val) echo '<br><small style="color:#646970;">' . esc_html($td_val) . '</small>';
                                    } else {
                                        echo '<span class="ks-badge ks-badge-unknown">-</span>';
                                    }
                                ?></td>
                                <td><?php
                                    $sid = isset($label->supplier_id) ? (int) $label->supplier_id : 0;
                                    echo $sid && isset($supplier_map[$sid]) ? esc_html($supplier_map[$sid]) : '-';
                                ?></td>
                                <td>
                                    <strong><?php echo esc_html($label->from_name); ?></strong>
                                    <?php if ($label->from_address) : ?>
                                        <br><small><?php echo esc_html($label->from_address); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($label->to_name); ?></strong>
                                    <?php if ($label->to_address) : ?>
                                        <br><small><?php echo esc_html($label->to_address); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($label->service_level ?? '-'); ?></td>
                                <td>$<?php echo number_format($label->cost, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom" style="margin-top: 15px;">
                    <div class="alignleft actions"></div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo (int) $total_items; ?> items</span>
                        <span class="pagination-links">
                            <?php
                            $base = admin_url('admin.php?page=kiloships-shipping&tab=reports&filter_month=' . $current_month . '&filter_year=' . $current_year . '&filter_status=' . rawurlencode($filter_status) . '&filter_supplier=' . $filter_supplier . '&paged=%#%');
                            echo paginate_links(array(
                                'base'    => $base,
                                'format'  => '',
                                'current' => $current_page,
                                'total'   => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ));
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                /* Re-scan orders for label data */
                $('#rescan-orders-btn').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $('#rescan-spinner').addClass('is-active');
                    $('#rescan-result').hide();

                    $.post(ajaxurl, {
                        action: 'kiloships_rescan_orders',
                        nonce: '<?php echo wp_create_nonce("kiloships_rescan_orders"); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false);
                        $('#rescan-spinner').removeClass('is-active');
                        var $r = $('#rescan-result');

                        if (response.success) {
                            var d = response.data;
                            var html = '<strong>' + d.message + '</strong>';
                            html += '<br><small style="color:#646970;">';
                            html += 'Recovered: ' + d.recovered + ' &bull; Skipped (already exists): ' + d.skipped;
                            if (d.debug) {
                                html += '<br>Sources checked: ';
                                html += 'postmeta=' + d.debug.postmeta_count;
                                html += ', wc_orders_meta=' + d.debug.wc_orders_meta_count;
                                html += ', cancelled_postmeta=' + (d.debug.cancelled_postmeta || 0);
                                html += ', order_notes=' + d.debug.order_notes_count;
                                html += ', total_unique=' + d.debug.total_unique_orders;
                            }
                            if (d.errors && d.errors.length > 0) {
                                html += '<br>Errors: ' + d.errors.join('; ');
                            }
                            html += '</small>';
                            $r.html(html).css('color', d.recovered > 0 ? 'green' : '#646970').show();

                            if (d.recovered > 0) {
                                setTimeout(function() { location.reload(); }, 2000);
                            }
                        } else {
                            $r.html('<span style="color:red;">Error: ' + (response.data || 'Unknown') + '</span>').show();
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#rescan-spinner').removeClass('is-active');
                        $('#rescan-result').html('<span style="color:red;">Network error.</span>').show();
                    });
                });

                /* Link From data to Suppliers */
                $('#link-suppliers-btn').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $('#rescan-spinner').addClass('is-active');
                    $('#rescan-result').hide();

                    $.post(ajaxurl, {
                        action: 'kiloships_link_suppliers',
                        nonce: '<?php echo wp_create_nonce("kiloships_link_suppliers"); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false);
                        $('#rescan-spinner').removeClass('is-active');
                        var $r = $('#rescan-result');

                        if (response.success) {
                            var d = response.data;
                            var html = '<strong>' + d.message + '</strong>';
                            $r.html(html).css('color', d.created > 0 || d.linked > 0 ? 'green' : '#646970').show();
                            if (d.created > 0 || d.linked > 0) {
                                setTimeout(function() { location.reload(); }, 2000);
                            }
                        } else {
                            $r.html('<span style="color:red;">Error: ' + (response.data || 'Unknown') + '</span>').show();
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#rescan-spinner').removeClass('is-active');
                        $('#rescan-result').html('<span style="color:red;">Network error.</span>').show();
                    });
                });

                $('#export-csv').on('click', function() {
                    var month = $('select[name="filter_month"]').val();
                    var year = $('select[name="filter_year"]').val();
                    var status = $('select[name="filter_status"]').val();
                    var supplierId = $('select[name="filter_supplier"]').val() || 0;

                    var url = ajaxurl + '?action=kiloships_export_csv&month=' + month + '&year=' + year + '&status=' + status + '&supplier_id=' + supplierId + '&nonce=<?php echo wp_create_nonce('kiloships_export_csv'); ?>';
                    window.location.href = url;
                });

                /* Check Tracking Status - one at a time, skip delivered & checked today */
                function badgeClass(status) {
                    var s = (status || '').toLowerCase();
                    if (s.indexOf('delivered') !== -1) return 'ks-badge-delivered';
                    if (s.indexOf('transit') !== -1 || s.indexOf('accepted') !== -1 || s.indexOf('origin') !== -1 || s.indexOf('departed') !== -1 || s.indexOf('arrived') !== -1 || s.indexOf('processed') !== -1) return 'ks-badge-transit';
                    if (s.indexOf('alert') !== -1 || s.indexOf('exception') !== -1 || s.indexOf('notice') !== -1) return 'ks-badge-alert';
                    if (s.indexOf('return') !== -1) return 'ks-badge-returned';
                    if (s.indexOf('pre') !== -1 || s.indexOf('created') !== -1 || s.indexOf('label') !== -1) return 'ks-badge-pre';
                    return 'ks-badge-unknown';
                }

                function updateCell($row, info) {
                    var $cell = $row.find('.ks-tracking-cell');
                    if (info.error) {
                        $cell.html('<span class="ks-badge ks-badge-unknown" title="' + info.error + '">Error</span>');
                        return;
                    }
                    var cls = badgeClass(info.status);
                    var html = '<span class="ks-badge ' + cls + '">' + info.status + '</span>';
                    if (info.location) html += '<br><small style="color:#646970;">' + info.location + '</small>';
                    if (info.date) html += '<br><small style="color:#646970;">' + info.date + '</small>';
                    $cell.html(html);
                    if (info.status.toLowerCase().indexOf('delivered') !== -1) {
                        $row.attr('data-delivered', '1');
                    }
                    $row.attr('data-checked-today', '1');
                }

                $('#check-tracking-btn').on('click', function() {
                    var $btn = $(this);
                    var queue = [];

                    $('tr[data-tracking]').each(function() {
                        var $r = $(this);
                        if ($r.data('label-status') !== 'active') return;
                        if ($r.attr('data-delivered') === '1') return;
                        if ($r.attr('data-checked-today') === '1') return;
                        queue.push($r.data('tracking'));
                    });

                    var skippedDelivered = $('tr[data-delivered="1"]').length;
                    var skippedToday = $('tr[data-checked-today="1"][data-delivered="0"]').length;

                    if (queue.length === 0) {
                        var msg = 'Nothing to check.';
                        if (skippedDelivered > 0) msg += ' ' + skippedDelivered + ' delivered (skipped).';
                        if (skippedToday > 0) msg += ' ' + skippedToday + ' already checked today.';
                        $('#tracking-progress').text(msg);
                        return;
                    }

                    $btn.prop('disabled', true);
                    $('#tracking-spinner').addClass('is-active');
                    var total = queue.length;
                    var done = 0;
                    var skipMsg = '';
                    if (skippedDelivered > 0) skipMsg += skippedDelivered + ' delivered';
                    if (skippedToday > 0) skipMsg += (skipMsg ? ', ' : '') + skippedToday + ' checked today';
                    if (skipMsg) skipMsg = ' (skipped: ' + skipMsg + ')';

                    $('#tracking-progress').text('Checking 0/' + total + '...' + skipMsg);

                    function checkNext(idx) {
                        if (idx >= queue.length) {
                            $btn.prop('disabled', false);
                            $('#tracking-spinner').removeClass('is-active');
                            $('#tracking-progress').text('Done! ' + total + '/' + total + ' checked.' + skipMsg);
                            return;
                        }

                        var tn = queue[idx];
                        var $row = $('tr[data-tracking="' + tn + '"]');
                        $row.find('.ks-tracking-cell').html('<span class="ks-badge ks-badge-unknown">Checking...</span>');

                        $.post(ajaxurl, {
                            action: 'kiloships_check_tracking',
                            nonce: '<?php echo wp_create_nonce("kiloships_check_tracking"); ?>',
                            tracking_number: tn
                        }, function(resp) {
                            done++;
                            if (resp.success) {
                                updateCell($row, resp.data);
                            }
                            $('#tracking-progress').text('Checking ' + done + '/' + total + '...' + skipMsg);
                            setTimeout(function() { checkNext(idx + 1); }, 3000);
                        }).fail(function() {
                            done++;
                            $row.find('.ks-tracking-cell').html('<span class="ks-badge ks-badge-unknown">Network Error</span>');
                            setTimeout(function() { checkNext(idx + 1); }, 3000);
                        });
                    }

                    checkNext(0);
                });

            });
        </script>
<?php
    }

    /**
     * Handle CSV export.
     */
    /**
     * AJAX: Check tracking status for a single tracking number, save to DB.
     */
    public static function ajax_check_tracking()
    {
        check_ajax_referer('kiloships_check_tracking', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $tn = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        if (empty($tn)) {
            wp_send_json_error('No tracking number.');
        }

        if (! class_exists('Kiloships_API')) {
            require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-api.php';
        }

        $api  = new Kiloships_API();
        $data = $api->get_tracking($tn, 'SUMMARY');

        if (is_wp_error($data)) {
            wp_send_json_success(array(
                'tracking_number' => $tn,
                'status'   => 'Error',
                'location' => '',
                'date'     => '',
                'error'    => $data->get_error_message(),
            ));
        }

        $status   = '';
        $location = '';
        $date     = '';

        // Try multiple response formats from Kiloships/USPS API
        if (! empty($data['trackingEvents']) && is_array($data['trackingEvents'])) {
            $latest = $data['trackingEvents'][0];
            $status = $latest['eventDescription'] ?? ($latest['status'] ?? '');
            if (! empty($latest['eventCity']) || ! empty($latest['eventState'])) {
                $location = trim(($latest['eventCity'] ?? '') . ', ' . ($latest['eventState'] ?? '') . ' ' . ($latest['eventZIPCode'] ?? ''), ', ');
            }
            $date = $latest['eventDate'] ?? ($latest['eventTimestamp'] ?? '');
        } elseif (! empty($data['trackingSummary']) && is_array($data['trackingSummary'])) {
            $latest = $data['trackingSummary'][0];
            $status = $latest['eventDescription'] ?? ($latest['event'] ?? '');
            if (! empty($latest['eventCity'])) {
                $location = trim(($latest['eventCity'] ?? '') . ', ' . ($latest['eventState'] ?? '') . ' ' . ($latest['eventZIPCode'] ?? ''), ', ');
            }
            $date = $latest['eventDate'] ?? '';
        }

        if (empty($status) && ! empty($data['statusCategory'])) {
            $status = $data['statusCategory'];
        }
        if (empty($status) && ! empty($data['statusSummary'])) {
            $status = $data['statusSummary'];
        }
        if (empty($status) && ! empty($data['status'])) {
            $status = $data['status'];
        }
        if (empty($status)) {
            $status = 'Unknown';
        }

        // Save to DB
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->update(
            $table,
            array(
                'tracking_status'     => sanitize_text_field($status),
                'tracking_location'   => sanitize_text_field($location),
                'tracking_date'       => sanitize_text_field($date),
                'tracking_checked_at' => current_time('mysql'),
            ),
            array('tracking_number' => $tn),
            array('%s', '%s', '%s', '%s'),
            array('%s')
        );

        wp_send_json_success(array(
            'tracking_number' => $tn,
            'status'   => $status,
            'location' => $location,
            'date'     => $date,
        ));
    }

    public static function ajax_export_csv()
    {
        check_ajax_referer('kiloships_export_csv', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

        $filters = array(
            'month' => $month,
            'year'  => $year,
        );

        if ($status !== '') {
            $filters['status'] = $status;
        }
        if ($supplier_id > 0) {
            $filters['supplier_id'] = $supplier_id;
        }

        $labels = self::get_labels($filters);

        $csv_supplier_map = array();
        if (class_exists('Kiloships_Admin_Suppliers')) {
            foreach (Kiloships_Admin_Suppliers::get_all() as $s) {
                $csv_supplier_map[$s->id] = $s->nickname ?: $s->name;
            }
        }

        // Set CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=kiloships-labels-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.csv');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Add column headers
        fputcsv($output, array(
            'Created Date',
            'Status',
            'Order ID',
            'Order Number',
            'Tracking Number',
            'Supplier',
            'From Name',
            'From Address',
            'To Name',
            'To Address',
            'Service Level',
            'Cost',
            'Cancelled Date',
        ));

        // Add data rows
        foreach ($labels as $label) {
            $csv_sid = isset($label->supplier_id) ? (int) $label->supplier_id : 0;
            fputcsv($output, array(
                date('Y-m-d H:i:s', strtotime($label->created_at)),
                ucfirst($label->status),
                $label->order_id,
                $label->order_number,
                $label->tracking_number,
                ($csv_sid && isset($csv_supplier_map[$csv_sid])) ? $csv_supplier_map[$csv_sid] : '',
                $label->from_name,
                $label->from_address,
                $label->to_name,
                $label->to_address,
                $label->service_level ?? '-',
                number_format($label->cost, 2),
                $label->cancelled_at ? date('Y-m-d H:i:s', strtotime($label->cancelled_at)) : '',
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * AJAX: Extract From data from labels and create/link suppliers.
     */
    public static function ajax_link_suppliers()
    {
        check_ajax_referer('kiloships_link_suppliers', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        if (! class_exists('Kiloships_Admin_Suppliers')) {
            wp_send_json_error('Suppliers class not loaded.');
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Find labels with from_name but no supplier_id
        $orphan_labels = $wpdb->get_results(
            "SELECT DISTINCT from_name, from_address FROM $table WHERE from_name IS NOT NULL AND from_name != '' AND (supplier_id IS NULL OR supplier_id = 0)"
        );

        if (empty($orphan_labels)) {
            wp_send_json_success(array(
                'created' => 0,
                'linked'  => 0,
                'message' => 'All labels are already linked to suppliers, or no From data to extract.',
            ));
        }

        $existing = Kiloships_Admin_Suppliers::get_all();
        $name_map = array();
        foreach ($existing as $s) {
            $name_map[strtolower(trim($s->name))] = $s->id;
            if (! empty($s->nickname)) {
                $name_map[strtolower(trim($s->nickname))] = $s->id;
            }
        }

        $created = 0;
        $linked = 0;

        foreach ($orphan_labels as $row) {
            $from_name = trim($row->from_name);
            $lookup_key = strtolower($from_name);

            if (isset($name_map[$lookup_key])) {
                $supplier_id = $name_map[$lookup_key];
            } else {
                $addr_parts = self::parse_from_address($row->from_address);
                $supplier_id = Kiloships_Admin_Suppliers::save(array(
                    'nickname' => $from_name,
                    'name'     => $from_name,
                    'street1'  => $addr_parts['street1'],
                    'street2'  => '',
                    'city'     => $addr_parts['city'],
                    'state'    => $addr_parts['state'],
                    'zip'      => $addr_parts['zip'],
                ));
                if ($supplier_id) {
                    $name_map[$lookup_key] = $supplier_id;
                    $created++;
                }
            }

            if ($supplier_id) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET supplier_id = %d WHERE from_name = %s AND (supplier_id IS NULL OR supplier_id = 0)",
                    $supplier_id,
                    $from_name
                ));
                $linked += (int) $updated;
            }
        }

        wp_send_json_success(array(
            'created' => $created,
            'linked'  => $linked,
            'message' => sprintf(
                '%d new supplier(s) created, %d label(s) linked.',
                $created,
                $linked
            ),
        ));
    }

    /**
     * Parse a "street, city, state zip" string into components.
     */
    private static function parse_from_address($address)
    {
        $result = array('street1' => '', 'city' => '', 'state' => '', 'zip' => '');
        if (empty($address)) {
            return $result;
        }

        $parts = array_map('trim', explode(',', $address));

        if (count($parts) >= 3) {
            $result['street1'] = $parts[0];
            $result['city'] = $parts[count($parts) - 2];
            $state_zip = trim($parts[count($parts) - 1]);
        } elseif (count($parts) === 2) {
            $result['street1'] = $parts[0];
            $state_zip = $parts[1];
        } else {
            $state_zip = $parts[0];
        }

        if (! empty($state_zip) && preg_match('/^([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i', trim($state_zip), $m)) {
            $result['state'] = strtoupper($m[1]);
            $result['zip'] = $m[2];
        } elseif (! empty($state_zip)) {
            $result['city'] = $state_zip;
        }

        return $result;
    }
}
