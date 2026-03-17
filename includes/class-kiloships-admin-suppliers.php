<?php

/**
 * Kiloships Admin - Suppliers Management Tab (DB-backed CRUD).
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Admin_Suppliers
{

    const TABLE_NAME = 'kiloships_suppliers';

    /**
     * Create the suppliers database table.
     */
    public static function create_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            nickname varchar(255) DEFAULT NULL,
            name varchar(255) NOT NULL,
            street1 varchar(255) DEFAULT NULL,
            street2 varchar(255) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(2) DEFAULT NULL,
            zip varchar(10) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Migrate suppliers from wp_options to DB table (one-time).
     */
    public static function migrate_from_options()
    {
        $old = get_option('kiloships_suppliers', array());
        if (! is_array($old) || empty($old)) {
            return;
        }
        foreach ($old as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? $row['name'] : '';
            if (trim($name) === '' && trim($row['nickname'] ?? '') === '') {
                continue;
            }
            self::save(array(
                'nickname' => $row['nickname'] ?? '',
                'name'     => $name,
                'street1'  => $row['street1'] ?? '',
                'street2'  => $row['street2'] ?? '',
                'city'     => $row['city'] ?? '',
                'state'    => $row['state'] ?? '',
                'zip'      => $row['zip'] ?? '',
            ));
        }
        delete_option('kiloships_suppliers');
    }

    // ---- DB helpers ----

    public static function get_all()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_results("SELECT * FROM $table ORDER BY nickname ASC, name ASC");
    }

    public static function get_by_id($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    public static function save($data, $id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $now   = current_time('mysql');

        $row = array(
            'nickname' => sanitize_text_field($data['nickname'] ?? ''),
            'name'     => sanitize_text_field($data['name'] ?? ''),
            'street1'  => sanitize_text_field($data['street1'] ?? ''),
            'street2'  => sanitize_text_field($data['street2'] ?? ''),
            'city'     => sanitize_text_field($data['city'] ?? ''),
            'state'    => strtoupper(sanitize_text_field($data['state'] ?? '')),
            'zip'      => sanitize_text_field($data['zip'] ?? ''),
            'updated_at' => $now,
        );

        if ($id) {
            $wpdb->update($table, $row, array('id' => (int) $id));
            return (int) $id;
        }

        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
        return $wpdb->insert_id ?: false;
    }

    public static function delete_by_id($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->delete($table, array('id' => (int) $id));
    }

    public static function count_labels($supplier_id)
    {
        global $wpdb;
        $labels = $wpdb->prefix . 'kiloships_labels';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $labels WHERE supplier_id = %d",
            $supplier_id
        ));
    }

    // ---- Hooks ----

    public static function init_hooks()
    {
        add_action('wp_ajax_kiloships_save_supplier', array(__CLASS__, 'ajax_save_supplier'));
        add_action('wp_ajax_kiloships_delete_supplier', array(__CLASS__, 'ajax_delete_supplier'));
        add_action('wp_ajax_kiloships_admin_lookup_city_state', array(__CLASS__, 'ajax_lookup_city_state'));
        add_action('wp_ajax_kiloships_export_suppliers', array(__CLASS__, 'ajax_export_suppliers'));
        add_action('wp_ajax_kiloships_import_suppliers', array(__CLASS__, 'ajax_import_suppliers'));
        add_action('wp_ajax_kiloships_merge_suppliers', array(__CLASS__, 'ajax_merge_suppliers'));
    }

    /**
     * No longer needed -- suppliers stored in DB now.
     */
    public static function register_settings()
    {
        // intentionally empty (kept for backward compat call in admin.php)
    }

    // ---- AJAX handlers ----

    public static function ajax_save_supplier()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $id = ! empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
        $data = array(
            'nickname' => $_POST['nickname'] ?? '',
            'name'     => $_POST['name'] ?? '',
            'street1'  => $_POST['street1'] ?? '',
            'street2'  => $_POST['street2'] ?? '',
            'city'     => $_POST['city'] ?? '',
            'state'    => $_POST['state'] ?? '',
            'zip'      => $_POST['zip'] ?? '',
        );

        if (empty(trim($data['name']))) {
            wp_send_json_error('Sender Name is required.');
        }

        $result_id = self::save($data, $id);

        if (! $result_id) {
            wp_send_json_error('Could not save supplier.');
        }

        $supplier = self::get_by_id($result_id);
        wp_send_json_success(array(
            'message'  => $id ? 'Supplier updated.' : 'Supplier added.',
            'supplier' => $supplier,
        ));
    }

    public static function ajax_delete_supplier()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $id = ! empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
        if (! $id) {
            wp_send_json_error('Invalid supplier ID.');
        }
        self::delete_by_id($id);
        wp_send_json_success('Supplier deleted.');
    }

    public static function ajax_lookup_city_state()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $zip_code = isset($_POST['zip_code']) ? sanitize_text_field($_POST['zip_code']) : '';
        if (empty($zip_code)) {
            wp_send_json_error('ZIP Code is required.');
        }

        if (! class_exists('Kiloships_API')) {
            require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-api.php';
        }

        $api    = new Kiloships_API();
        $result = $api->lookup_city_state($zip_code);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    /**
     * AJAX: Export all suppliers as CSV download.
     */
    public static function ajax_export_suppliers()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Permission denied.');
        }

        $suppliers = self::get_all();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=kiloships-suppliers-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, array('Nickname', 'Sender Name', 'Street 1', 'Street 2', 'City', 'State', 'ZIP'));

        foreach ($suppliers as $s) {
            fputcsv($output, array(
                $s->nickname,
                $s->name,
                $s->street1,
                $s->street2,
                $s->city,
                $s->state,
                $s->zip,
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * AJAX: Import suppliers from uploaded CSV.
     */
    public static function ajax_import_suppliers()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('No file uploaded or upload error.');
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error('Only CSV files are accepted.');
        }

        $handle = fopen($file, 'r');
        if (! $handle) {
            wp_send_json_error('Could not read file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            wp_send_json_error('Empty CSV file.');
        }

        $header = array_map(function ($h) {
            return strtolower(trim(str_replace(chr(0xEF) . chr(0xBB) . chr(0xBF), '', $h)));
        }, $header);

        $col_map = array(
            'nickname'    => array('nickname'),
            'name'        => array('sender name', 'name', 'sender_name'),
            'street1'     => array('street 1', 'street1', 'address', 'street'),
            'street2'     => array('street 2', 'street2'),
            'city'        => array('city'),
            'state'       => array('state'),
            'zip'         => array('zip', 'zip code', 'zipcode', 'postal'),
        );

        $indices = array();
        foreach ($col_map as $field => $aliases) {
            $indices[$field] = -1;
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $header);
                if ($idx !== false) {
                    $indices[$field] = $idx;
                    break;
                }
            }
        }

        if ($indices['name'] === -1) {
            fclose($handle);
            wp_send_json_error('CSV must have a "Sender Name" (or "Name") column.');
        }

        $imported = 0;
        $skipped = 0;
        $existing = self::get_all();
        $existing_names = array();
        foreach ($existing as $s) {
            $existing_names[] = strtolower(trim($s->name));
        }

        $skip_duplicates = ! empty($_POST['skip_duplicates']);

        while (($row = fgetcsv($handle)) !== false) {
            $get = function ($field) use ($indices, $row) {
                $i = $indices[$field];
                return ($i >= 0 && isset($row[$i])) ? trim($row[$i]) : '';
            };

            $name = $get('name');
            if (empty($name)) {
                $skipped++;
                continue;
            }

            if ($skip_duplicates && in_array(strtolower($name), $existing_names, true)) {
                $skipped++;
                continue;
            }

            self::save(array(
                'nickname' => $get('nickname'),
                'name'     => $name,
                'street1'  => $get('street1'),
                'street2'  => $get('street2'),
                'city'     => $get('city'),
                'state'    => $get('state'),
                'zip'      => $get('zip'),
            ));

            $existing_names[] = strtolower($name);
            $imported++;
        }

        fclose($handle);

        wp_send_json_success(array(
            'imported' => $imported,
            'skipped'  => $skipped,
            'message'  => sprintf('%d supplier(s) imported, %d skipped.', $imported, $skipped),
        ));
    }

    /**
     * AJAX: Merge multiple suppliers into one primary.
     * Reassigns all labels and order meta, then deletes the duplicates.
     */
    public static function ajax_merge_suppliers()
    {
        check_ajax_referer('kiloships_suppliers_nonce', 'nonce');
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $primary_id = ! empty($_POST['primary_id']) ? intval($_POST['primary_id']) : 0;
        $merge_ids  = ! empty($_POST['merge_ids']) ? array_map('intval', (array) $_POST['merge_ids']) : array();
        $merge_ids  = array_filter($merge_ids, function ($id) use ($primary_id) {
            return $id > 0 && $id !== $primary_id;
        });

        if (! $primary_id || empty($merge_ids)) {
            wp_send_json_error('Select a primary supplier and at least one supplier to merge into it.');
        }

        $primary = self::get_by_id($primary_id);
        if (! $primary) {
            wp_send_json_error('Primary supplier not found.');
        }

        global $wpdb;
        $labels_table = $wpdb->prefix . 'kiloships_labels';
        $labels_moved = 0;
        $deleted_count = 0;

        foreach ($merge_ids as $old_id) {
            // Reassign labels in reports table
            $moved = $wpdb->query($wpdb->prepare(
                "UPDATE $labels_table SET supplier_id = %d WHERE supplier_id = %d",
                $primary_id,
                $old_id
            ));
            $labels_moved += max(0, (int) $moved);

            // Reassign order meta
            if (function_exists('wc_get_orders')) {
                $hpos_table = $wpdb->prefix . 'wc_orders_meta';
                $hpos_exists = $wpdb->get_var("SHOW TABLES LIKE '$hpos_table'");

                // Update postmeta
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_kiloships_supplier_id' AND meta_value = %s",
                    (string) $primary_id,
                    (string) $old_id
                ));

                // Update HPOS meta if available
                if ($hpos_exists) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $hpos_table SET meta_value = %s WHERE meta_key = '_kiloships_supplier_id' AND meta_value = %s",
                        (string) $primary_id,
                        (string) $old_id
                    ));
                }
            }

            // Delete the duplicate supplier
            self::delete_by_id($old_id);
            $deleted_count++;
        }

        $new_total = self::count_labels($primary_id);

        wp_send_json_success(array(
            'message'       => sprintf(
                'Merged %d supplier(s) into "%s". %d label(s) reassigned. New total: %d labels.',
                $deleted_count,
                $primary->nickname ?: $primary->name,
                $labels_moved,
                $new_total
            ),
            'deleted'       => $deleted_count,
            'labels_moved'  => $labels_moved,
            'primary_total' => $new_total,
        ));
    }

    // ---- Render ----

    public static function render()
    {
        $suppliers = self::get_all();
        $nonce = wp_create_nonce('kiloships_suppliers_nonce');

        $states = array('AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FM' => 'Federated States of Micronesia', 'FL' => 'Florida', 'GA' => 'Georgia', 'GU' => 'Guam', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MH' => 'Marshall Islands', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MP' => 'Northern Mariana Islands', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PW' => 'Palau', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VI' => 'Virgin Islands', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
?>
<div class="wrap">
    <h2>Suppliers Management</h2>

    <!-- Form card -->
    <div class="card" style="max-width:100%; margin-bottom:20px; padding:20px;">
        <h3 id="ks-form-title">Add Supplier</h3>
        <input type="hidden" id="ks-sup-id" value="" />
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div>
                <label><strong>Nickname</strong> <small style="color:#999;">(dropdown display)</small></label>
                <input type="text" id="ks-sup-nickname" class="regular-text" style="width:100%;" placeholder="e.g., Warehouse GA" />
            </div>
            <div>
                <label><strong>Sender Name</strong> <small style="color:#999;">(on shipping label)</small></label>
                <input type="text" id="ks-sup-name" class="regular-text" style="width:100%;" placeholder="e.g., John Smith" />
            </div>
            <div><label><strong>Street 1</strong></label><input type="text" id="ks-sup-street1" class="regular-text" style="width:100%;" /></div>
            <div><label><strong>Street 2</strong></label><input type="text" id="ks-sup-street2" class="regular-text" style="width:100%;" /></div>
            <div>
                <label><strong>ZIP Code</strong></label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="ks-sup-zip" class="regular-text" style="flex:1;" placeholder="e.g., 12345" />
                    <button type="button" class="button" id="ks-sup-lookup">Lookup</button>
                </div>
            </div>
            <div><label><strong>City</strong></label><input type="text" id="ks-sup-city" class="regular-text" style="width:100%;" /></div>
            <div>
                <label><strong>State</strong></label>
                <select id="ks-sup-state" class="regular-text" style="width:100%;">
                    <option value="">Select State</option>
                    <?php foreach ($states as $code => $sname) :
                        echo '<option value="' . esc_attr($code) . '">' . esc_html($code . ' - ' . $sname) . '</option>';
                    endforeach; ?>
                </select>
            </div>
        </div>
        <p style="margin-top:15px;">
            <button type="button" class="button button-primary" id="ks-sup-save">Save Supplier</button>
            <button type="button" class="button" id="ks-sup-cancel" style="display:none;">Cancel Edit</button>
            <span id="ks-sup-msg" style="margin-left:10px;"></span>
        </p>
    </div>

    <!-- Merge toolbar (hidden until 2+ checked) -->
    <div id="ks-merge-bar" style="display:none; background:#f0f6fc; border:1px solid #72aee6; border-radius:4px; padding:10px 15px; margin-bottom:12px;">
        <strong>Merge Suppliers:</strong>
        <span id="ks-merge-count">0</span> selected &mdash;
        Keep: <select id="ks-merge-primary" style="min-width:200px;"></select>
        <button type="button" class="button button-primary button-small" id="ks-merge-btn" style="margin-left:8px;">Merge into Selected</button>
        <button type="button" class="button button-small" id="ks-merge-cancel" style="margin-left:4px;">Cancel</button>
        <span id="ks-merge-msg" style="margin-left:8px;"></span>
    </div>

    <!-- Table -->
    <style>
        .ks-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        .ks-sortable:hover { color: #2271b1; }
        .ks-sortable .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: middle; opacity: 0.4; }
        .ks-sortable.ks-asc .dashicons-arrow-up, .ks-sortable.ks-desc .dashicons-arrow-down { opacity: 1; }
    </style>
    <table class="wp-list-table widefat fixed striped" id="ks-suppliers-table">
        <thead>
            <tr>
                <th class="check-column" style="width:30px;"><input type="checkbox" id="ks-check-all" /></th>
                <th style="width:40px;" class="ks-sortable" data-col="0" data-type="num"># <span class="dashicons dashicons-arrow-up"></span><span class="dashicons dashicons-arrow-down"></span></th>
                <th class="ks-sortable" data-col="1" data-type="str">Nickname <span class="dashicons dashicons-arrow-up"></span><span class="dashicons dashicons-arrow-down"></span></th>
                <th class="ks-sortable" data-col="2" data-type="str">Sender Name <span class="dashicons dashicons-arrow-up"></span><span class="dashicons dashicons-arrow-down"></span></th>
                <th class="ks-sortable" data-col="3" data-type="str">Address <span class="dashicons dashicons-arrow-up"></span><span class="dashicons dashicons-arrow-down"></span></th>
                <th style="width:80px;" class="ks-sortable" data-col="4" data-type="num">Labels <span class="dashicons dashicons-arrow-up"></span><span class="dashicons dashicons-arrow-down"></span></th>
                <th style="width:140px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($suppliers)) : ?>
                <tr class="ks-no-suppliers"><td colspan="7" style="text-align:center;">No suppliers yet.</td></tr>
            <?php else : ?>
                <?php foreach ($suppliers as $sup) :
                    $addr = esc_html(trim($sup->street1 . ', ' . $sup->city . ', ' . $sup->state . ' ' . $sup->zip, ', '));
                    $lcount = self::count_labels($sup->id);
                    $reports_url = admin_url('admin.php?page=kiloships-shipping&tab=reports&filter_supplier=' . $sup->id);
                ?>
                <tr data-id="<?php echo (int) $sup->id; ?>">
                    <th class="check-column"><input type="checkbox" class="ks-sup-cb" value="<?php echo (int) $sup->id; ?>" data-label="<?php echo esc_attr(($sup->nickname ?: $sup->name) . ' (' . $lcount . ' labels)'); ?>" /></th>
                    <td data-sort="<?php echo (int) $sup->id; ?>"><?php echo (int) $sup->id; ?></td>
                    <td data-sort="<?php echo esc_attr(strtolower($sup->nickname ?: '-')); ?>"><?php echo esc_html($sup->nickname ?: '-'); ?></td>
                    <td data-sort="<?php echo esc_attr(strtolower($sup->name)); ?>"><?php echo esc_html($sup->name); ?></td>
                    <td data-sort="<?php echo esc_attr(strtolower($addr)); ?>"><small><?php echo $addr; ?></small></td>
                    <td data-sort="<?php echo $lcount; ?>"><?php if ($lcount > 0) : ?><a href="<?php echo esc_url($reports_url); ?>"><?php echo $lcount; ?></a><?php else : ?>0<?php endif; ?></td>
                    <td>
                        <button type="button" class="button button-small ks-edit-sup" data-id="<?php echo (int) $sup->id; ?>" data-nickname="<?php echo esc_attr($sup->nickname); ?>" data-name="<?php echo esc_attr($sup->name); ?>" data-street1="<?php echo esc_attr($sup->street1); ?>" data-street2="<?php echo esc_attr($sup->street2); ?>" data-city="<?php echo esc_attr($sup->city); ?>" data-state="<?php echo esc_attr($sup->state); ?>" data-zip="<?php echo esc_attr($sup->zip); ?>">Edit</button>
                        <button type="button" class="button button-small button-link-delete ks-del-sup" data-id="<?php echo (int) $sup->id; ?>" data-name="<?php echo esc_attr($sup->nickname ?: $sup->name); ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Import / Export -->
    <div class="card" style="max-width:100%; margin-top:20px; padding:15px 20px;">
        <h3 style="margin-top:0;">Import / Export Suppliers</h3>
        <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
            <div>
                <button type="button" class="button" id="ks-export-btn">Export CSV</button>
                <p class="description" style="margin-top:4px;">Download all suppliers as a CSV file.</p>
            </div>
            <div style="border-left:1px solid #c3c4c7; padding-left:20px;">
                <input type="file" id="ks-import-file" accept=".csv" />
                <div style="margin-top:6px;">
                    <label style="display:inline-flex; align-items:center; gap:4px;">
                        <input type="checkbox" id="ks-import-skip-dup" checked />
                        <span>Skip duplicates (same Sender Name)</span>
                    </label>
                </div>
                <button type="button" class="button" id="ks-import-btn" style="margin-top:6px;">Import CSV</button>
                <span id="ks-import-msg" style="margin-left:8px;"></span>
                <p class="description" style="margin-top:4px;">CSV columns: Nickname, Sender Name, Street 1, Street 2, City, State, ZIP</p>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var nonce = '<?php echo $nonce; ?>';

    function clearForm() {
        $('#ks-sup-id').val('');
        $('#ks-sup-nickname, #ks-sup-name, #ks-sup-street1, #ks-sup-street2, #ks-sup-zip, #ks-sup-city').val('');
        $('#ks-sup-state').val('');
        $('#ks-form-title').text('Add Supplier');
        $('#ks-sup-cancel').hide();
        $('#ks-sup-msg').text('');
    }

    $('#ks-sup-cancel').on('click', clearForm);

    $('.ks-edit-sup').on('click', function() {
        var $b = $(this);
        $('#ks-sup-id').val($b.data('id'));
        $('#ks-sup-nickname').val($b.data('nickname'));
        $('#ks-sup-name').val($b.data('name'));
        $('#ks-sup-street1').val($b.data('street1'));
        $('#ks-sup-street2').val($b.data('street2'));
        $('#ks-sup-zip').val($b.data('zip'));
        $('#ks-sup-city').val($b.data('city'));
        $('#ks-sup-state').val($b.data('state'));
        $('#ks-form-title').text('Edit Supplier #' + $b.data('id'));
        $('#ks-sup-cancel').show();
        $('html,body').animate({scrollTop: 0}, 300);
    });

    $('#ks-sup-save').on('click', function() {
        var name = $('#ks-sup-name').val().trim();
        if (!name) { $('#ks-sup-msg').css('color','red').text('Sender Name is required.'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#ks-sup-msg').css('color','').text('Saving...');
        $.post(ajaxurl, {
            action: 'kiloships_save_supplier',
            nonce: nonce,
            supplier_id: $('#ks-sup-id').val(),
            nickname: $('#ks-sup-nickname').val().trim(),
            name: name,
            street1: $('#ks-sup-street1').val().trim(),
            street2: $('#ks-sup-street2').val().trim(),
            city: $('#ks-sup-city').val().trim(),
            state: $('#ks-sup-state').val(),
            zip: $('#ks-sup-zip').val().trim()
        }, function(resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                $('#ks-sup-msg').css('color','green').text(resp.data.message);
                setTimeout(function() { location.reload(); }, 800);
            } else {
                $('#ks-sup-msg').css('color','red').text(resp.data || 'Error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#ks-sup-msg').css('color','red').text('Network error.');
        });
    });

    $('.ks-del-sup').on('click', function() {
        var id = $(this).data('id');
        var nm = $(this).data('name');
        if (!confirm('Delete supplier "' + nm + '"?')) return;
        $.post(ajaxurl, { action: 'kiloships_delete_supplier', nonce: nonce, supplier_id: id }, function(resp) {
            if (resp.success) { location.reload(); } else { alert(resp.data || 'Error'); }
        });
    });

    $('#ks-sup-lookup').on('click', function() {
        var zip = $('#ks-sup-zip').val().trim();
        if (!zip || !/^\d{5}(-\d{4})?$/.test(zip)) { alert('Enter a valid ZIP'); return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('...');
        $.post(ajaxurl, { action: 'kiloships_admin_lookup_city_state', nonce: nonce, zip_code: zip }, function(r) {
            $btn.prop('disabled', false).text('Lookup');
            if (r.success) { $('#ks-sup-city').val(r.data.city); $('#ks-sup-state').val(r.data.state.toUpperCase()); }
            else { alert(r.data || 'Lookup failed'); }
        }).fail(function() { $btn.prop('disabled', false).text('Lookup'); });
    });

    /* ---- Column sorting ---- */
    $('.ks-sortable').on('click', function() {
        var $th = $(this);
        var colIdx = parseInt($th.data('col'));
        var type = $th.data('type');
        var asc = !$th.hasClass('ks-asc');

        $('.ks-sortable').removeClass('ks-asc ks-desc');
        $th.addClass(asc ? 'ks-asc' : 'ks-desc');

        var $tbody = $('#ks-suppliers-table tbody');
        var $rows = $tbody.find('tr').not('.ks-no-suppliers').get();

        $rows.sort(function(a, b) {
            // +1 offset for the checkbox column
            var aVal = $(a).find('td, th').eq(colIdx + 1).attr('data-sort') || '';
            var bVal = $(b).find('td, th').eq(colIdx + 1).attr('data-sort') || '';

            if (type === 'num') {
                aVal = parseFloat(aVal) || 0;
                bVal = parseFloat(bVal) || 0;
            }

            if (aVal < bVal) return asc ? -1 : 1;
            if (aVal > bVal) return asc ? 1 : -1;
            return 0;
        });

        $.each($rows, function(i, row) { $tbody.append(row); });
    });

    /* ---- Merge suppliers ---- */
    function updateMergeBar() {
        var $checked = $('.ks-sup-cb:checked');
        var count = $checked.length;
        if (count >= 2) {
            $('#ks-merge-bar').show();
            $('#ks-merge-count').text(count);
            var $sel = $('#ks-merge-primary').empty();
            $checked.each(function() {
                $sel.append('<option value="' + $(this).val() + '">' + $(this).data('label') + '</option>');
            });
        } else {
            $('#ks-merge-bar').hide();
        }
    }

    $('#ks-check-all').on('change', function() {
        $('.ks-sup-cb').prop('checked', $(this).prop('checked'));
        updateMergeBar();
    });

    $(document).on('change', '.ks-sup-cb', updateMergeBar);

    $('#ks-merge-cancel').on('click', function() {
        $('.ks-sup-cb, #ks-check-all').prop('checked', false);
        $('#ks-merge-bar').hide();
    });

    $('#ks-merge-btn').on('click', function() {
        var primaryId = parseInt($('#ks-merge-primary').val());
        var allIds = [];
        $('.ks-sup-cb:checked').each(function() { allIds.push(parseInt($(this).val())); });
        var mergeIds = allIds.filter(function(id) { return id !== primaryId; });

        if (!primaryId || mergeIds.length === 0) {
            $('#ks-merge-msg').css('color', 'red').text('Select at least 2 suppliers.');
            return;
        }

        var primaryLabel = $('#ks-merge-primary option:selected').text();
        if (!confirm('Merge ' + mergeIds.length + ' supplier(s) into "' + primaryLabel + '"?\n\nAll labels will be reassigned. Duplicates will be deleted. This cannot be undone.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#ks-merge-msg').css('color', '').text('Merging...');

        $.post(ajaxurl, {
            action: 'kiloships_merge_suppliers',
            nonce: nonce,
            primary_id: primaryId,
            merge_ids: mergeIds
        }, function(resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                $('#ks-merge-msg').css('color', 'green').text(resp.data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $('#ks-merge-msg').css('color', 'red').text(resp.data || 'Error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#ks-merge-msg').css('color', 'red').text('Network error.');
        });
    });

    /* ---- Import / Export ---- */
    $('#ks-export-btn').on('click', function() {
        window.location.href = ajaxurl + '?action=kiloships_export_suppliers&nonce=' + nonce;
    });

    $('#ks-import-btn').on('click', function() {
        var fileInput = $('#ks-import-file')[0];
        if (!fileInput.files.length) {
            $('#ks-import-msg').css('color', 'red').text('Select a CSV file first.');
            return;
        }
        var fd = new FormData();
        fd.append('action', 'kiloships_import_suppliers');
        fd.append('nonce', nonce);
        fd.append('csv_file', fileInput.files[0]);
        fd.append('skip_duplicates', $('#ks-import-skip-dup').is(':checked') ? '1' : '');

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#ks-import-msg').css('color', '').text('Importing...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(resp) {
                $btn.prop('disabled', false);
                if (resp.success) {
                    $('#ks-import-msg').css('color', 'green').text(resp.data.message);
                    if (resp.data.imported > 0) {
                        setTimeout(function() { location.reload(); }, 1500);
                    }
                } else {
                    $('#ks-import-msg').css('color', 'red').text(resp.data || 'Error');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $('#ks-import-msg').css('color', 'red').text('Network error.');
            }
        });
    });
});
</script>
<?php
    }
}
