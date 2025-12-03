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
     * Initialize hooks for AJAX and CSV export.
     */
    public static function init_hooks()
    {
        add_action('wp_ajax_kiloships_export_csv', array(__CLASS__, 'ajax_export_csv'));
    }

    /**
     * Create database table for storing label history.
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
            created_at datetime NOT NULL,
            cancelled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY tracking_number (tracking_number),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
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
     * Get labels with filters.
     *
     * @param array $filters Filter parameters.
     * @return array Labels data.
     */
    public static function get_labels($filters = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where = array('1=1');
        $params = array();

        // Filter by month/year
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[] = 'YEAR(created_at) = %d AND MONTH(created_at) = %d';
            $params[] = $filters['year'];
            $params[] = $filters['month'];
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($params)) {
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC",
                $params
            );
        } else {
            $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC";
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

        // Get labels
        $filters = array(
            'month' => $current_month,
            'year'  => $current_year,
        );

        if ($filter_status !== '') {
            $filters['status'] = $filter_status;
        }

        $labels = self::get_labels($filters);

        // Calculate totals
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
                    </table>

                    <p>
                        <button type="submit" class="button button-primary">Filter</button>
                        <button type="button" class="button" id="export-csv">Export to CSV</button>
                    </p>
                </form>
            </div>

            <!-- Summary -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                <h3>Summary for <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></h3>
                <p>
                    <strong>Total Labels:</strong> <?php echo count($labels); ?><br>
                    <strong>Active Labels:</strong> <?php echo $total_active; ?><br>
                    <strong>Cancelled Labels:</strong> <?php echo $total_cancelled; ?><br>
                    <strong>Total Cost (Active):</strong> $<?php echo number_format($total_cost, 2); ?>
                </p>
            </div>

            <!-- Labels Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Created Date</th>
                        <th>Status</th>
                        <th>Order ID</th>
                        <th>Tracking Number</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Service</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($labels)) : ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">No labels found for the selected period.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($labels as $label) : ?>
                            <tr>
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
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#export-csv').on('click', function() {
                    var month = $('select[name="filter_month"]').val();
                    var year = $('select[name="filter_year"]').val();
                    var status = $('select[name="filter_status"]').val();

                    var url = ajaxurl + '?action=kiloships_export_csv&month=' + month + '&year=' + year + '&status=' + status + '&nonce=<?php echo wp_create_nonce('kiloships_export_csv'); ?>';
                    window.location.href = url;
                });
            });
        </script>
<?php
    }

    /**
     * Handle CSV export.
     */
    public static function ajax_export_csv()
    {
        check_ajax_referer('kiloships_export_csv', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $filters = array(
            'month' => $month,
            'year'  => $year,
        );

        if ($status !== '') {
            $filters['status'] = $status;
        }

        $labels = self::get_labels($filters);

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
            fputcsv($output, array(
                date('Y-m-d H:i:s', strtotime($label->created_at)),
                ucfirst($label->status),
                $label->order_id,
                $label->order_number,
                $label->tracking_number,
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
}
