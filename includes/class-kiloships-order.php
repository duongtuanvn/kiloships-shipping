<?php

/**
 * Kiloships Order Management.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Order
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('wp_ajax_kiloships_create_label', array($this, 'create_label'));
        add_action('wp_ajax_kiloships_cancel_label', array($this, 'cancel_label'));
        add_action('wp_ajax_kiloships_lookup_city_state', array($this, 'lookup_city_state'));
        add_action('wp_ajax_kiloships_standardize_address', array($this, 'standardize_address'));
        add_action('wp_ajax_kiloships_get_tracking', array($this, 'get_tracking'));
    }

    /**
     * Add meta box to order page.
     */
    public function add_meta_box()
    {
        // Safety check for WooCommerce
        if (!function_exists('wc_get_container') || !function_exists('wc_get_page_screen_id')) {
            return;
        }

        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop_order')
            : 'shop_order';

        add_meta_box(
            'kiloships_shipping_box',
            'Kiloships Shipping',
            array($this, 'render_meta_box'),
            $screen,
            'normal',
            'high'
        );
    }

    /**
     * Render meta box content.
     *
     * @param WP_Post|WC_Order $post Order post object or WC_Order object (HPOS).
     */
    public function render_meta_box($post)
    {
        // Safety check for WooCommerce
        if (!function_exists('wc_get_order')) {
            echo '<p>WooCommerce is not properly loaded. Please refresh the page.</p>';
            return;
        }

        // HPOS compatibility: $post can be WC_Order or WP_Post
        if ($post instanceof WC_Order) {
            // HPOS: $post is already a WC_Order object
            $order = $post;
        } elseif ($post instanceof WP_Post) {
            // Legacy: $post is a WP_Post, get order from ID
            $order = wc_get_order($post->ID);
        } else {
            // Fallback: try to get order from $post if it's a numeric ID
            $order = wc_get_order($post);
        }

        if (!$order) {
            echo '<p>Unable to load order data.</p>';
            return;
        }

        // Read labels (supports multiple labels per order)
        $labels = $order->get_meta('_kiloships_labels');
        if (!is_array($labels)) {
            $labels = array();
        }

        // Backward compatibility: migrate old single-label meta to array format
        if (empty($labels)) {
            $old_url = $order->get_meta('_kiloships_label_url');
            $old_trk = $order->get_meta('_kiloships_tracking_number');
            if ($old_url && $old_trk) {
                $labels[] = array(
                    'labelUrl'       => $old_url,
                    'trackingNumber' => $old_trk,
                    'objectId'       => $order->get_meta('_kiloships_object_id'),
                    'chargeAmount'   => $order->get_meta('_kiloships_charge_amount'),
                );
                $order->update_meta_data('_kiloships_labels', $labels);
                $order->delete_meta_data('_kiloships_label_url');
                $order->delete_meta_data('_kiloships_tracking_number');
                $order->delete_meta_data('_kiloships_object_id');
                $order->delete_meta_data('_kiloships_charge_amount');
                $order->save();

                // Also push to WCSTT if not already there
                if (function_exists('wcstt_add_tracking_number')) {
                    wcstt_add_tracking_number($order->get_id(), $old_trk, 'usps');
                }
            }
        }

        // Ensure all existing labels are synced to WCSTT
        if (!empty($labels) && function_exists('wcstt_add_tracking_number')) {
            foreach ($labels as $lbl) {
                if (!empty($lbl['trackingNumber']) && class_exists('WCSTT_Tracking_Repository')
                    && !WCSTT_Tracking_Repository::tracking_exists($order->get_id(), $lbl['trackingNumber'])) {
                    wcstt_add_tracking_number($order->get_id(), $lbl['trackingNumber'], 'usps');
                }
            }
        }

        if (!empty($labels)) {
            $total_cost = 0;
            foreach ($labels as $lbl) {
                if (!empty($lbl['chargeAmount'])) {
                    $total_cost += floatval($lbl['chargeAmount']);
                }
            }
            echo '<div style="margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px; color: #1d2327;">Labels (' . count($labels) . ') &mdash; Total: $' . number_format($total_cost, 2) . '</h4>';
            foreach ($labels as $idx => $lbl) {
                $lbl_url  = isset($lbl['labelUrl']) ? $lbl['labelUrl'] : '';
                $lbl_trk  = isset($lbl['trackingNumber']) ? $lbl['trackingNumber'] : '';
                $lbl_cost = isset($lbl['chargeAmount']) ? $lbl['chargeAmount'] : 0;
                echo '<div class="ks-label-card" style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 12px 15px; margin-bottom: 8px;">';
                echo '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">';
                echo '<div>';
                echo '<strong>#' . ($idx + 1) . '</strong> ';
                echo '<code style="font-size: 12px;">' . esc_html($lbl_trk) . '</code>';
                if ($lbl_cost) {
                    echo ' &mdash; <strong>$' . number_format(floatval($lbl_cost), 2) . '</strong>';
                }
                echo '</div>';
                echo '<div style="display: flex; gap: 6px;">';
                echo '<a href="' . esc_url($lbl_url) . '" class="button button-small button-primary" target="_blank">PDF</a>';
                echo '<button type="button" class="button button-small ks-check-tracking" data-tracking="' . esc_attr($lbl_trk) . '">Track</button>';
                echo '<button type="button" class="button button-small ks-cancel-label" data-tracking="' . esc_attr($lbl_trk) . '" data-order-id="' . esc_attr($order->get_id()) . '" style="color:#b32d2e;">Cancel</button>';
                echo '</div>';
                echo '</div>';
                echo '<div class="ks-tracking-result" data-tracking="' . esc_attr($lbl_trk) . '" style="margin-top: 8px; display: none;"></div>';
                echo '</div>';
            }
            echo '</div>';
?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.ks-cancel-label').on('click', function() {
                        var $btn = $(this);
                        var trackingNumber = $btn.data('tracking');
                        var orderId = $btn.data('order-id');

                        if (!confirm('Cancel label ' + trackingNumber + '?\n\nThis action cannot be undone and may result in a refund.')) {
                            return;
                        }

                        $btn.prop('disabled', true).text('...');
                        $.post(ajaxurl, {
                            action: 'kiloships_cancel_label',
                            tracking_number: trackingNumber,
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('kiloships_cancel_label'); ?>'
                        }, function(response) {
                            if (response.success) {
                                $btn.closest('.ks-label-card').css({'background': '#d4edda', 'border-color': '#c3e6cb'}).fadeOut(800, function() {
                                    location.reload();
                                });
                            } else {
                                alert('Error: ' + (response.data || 'Cancel failed'));
                                $btn.prop('disabled', false).text('Cancel');
                            }
                        }).fail(function() {
                            alert('Network error.');
                            $btn.prop('disabled', false).text('Cancel');
                        });
                    });

                    $('.ks-check-tracking').on('click', function() {
                        var trackingNumber = $(this).data('tracking');
                        var $result = $('.ks-tracking-result[data-tracking="' + trackingNumber + '"]');
                        $result.toggle();
                        if (!$result.is(':visible')) return;
                        $result.css({
                            'padding': '8px 10px', 'background': '#f0f6fc',
                            'border': '1px solid #c5d9ed', 'border-radius': '4px', 'font-size': '12px'
                        }).text('Loading...');
                        $.post(ajaxurl, {
                            action: 'kiloships_get_tracking',
                            tracking_number: trackingNumber,
                            nonce: '<?php echo wp_create_nonce('kiloships_create_label'); ?>'
                        }, function(response) {
                            if (response.success && response.data) {
                                var d = response.data;
                                var html = '<strong>' + (d.status || '-') + '</strong>';
                                if (d.statusSummary) html += ' &mdash; ' + d.statusSummary;
                                if (d.originCity) html += '<br>From: ' + [d.originCity, d.originState, d.originZIP].filter(Boolean).join(', ');
                                if (d.destinationCity) html += '<br>To: ' + [d.destinationCity, d.destinationState, d.destinationZIP].filter(Boolean).join(', ');
                                $result.html(html);
                            } else {
                                $result.css('background', '#f8d7da').text('Error: ' + (response.data || 'Could not load tracking'));
                            }
                        }).fail(function() {
                            $result.css('background', '#f8d7da').text('Network error.');
                        });
                    });
                });
            </script>
        <?php
        }
            // Calculate total weight
            $total_weight = 0;
            foreach ($order->get_items() as $item) {
                if (! $item instanceof WC_Order_Item_Product) {
                    continue;
                }
                $product = $item->get_product();
                if ($product && $product->has_weight()) {
                    $total_weight += (float) $product->get_weight() * $item->get_quantity();
                }
            }
            // Default to 1 if 0
            $total_weight = $total_weight > 0 ? $total_weight : 1;

            // Prepare default values
            $from = array(
                'name'    => get_option('kiloships_from_name', ''),
                'street1' => get_option('kiloships_from_street1', ''),
                'street2' => get_option('kiloships_from_street2', ''),
                'city'    => get_option('kiloships_from_city', ''),
                'state'   => get_option('kiloships_from_state', ''),
                'zip'     => get_option('kiloships_from_zip', ''),
                'country' => get_option('kiloships_from_country', 'US'),
            );

            $shipping_address = $order->get_address('shipping');
            $to = array(
                'name'    => trim($shipping_address['first_name'] . ' ' . $shipping_address['last_name']),
                'street1' => isset($shipping_address['address_1']) ? $shipping_address['address_1'] : '',
                'street2' => isset($shipping_address['address_2']) ? $shipping_address['address_2'] : '',
                'city'    => isset($shipping_address['city']) ? $shipping_address['city'] : '',
                'state'   => isset($shipping_address['state']) ? $shipping_address['state'] : '',
                'zip'     => isset($shipping_address['postcode']) ? $shipping_address['postcode'] : '',
                'country' => isset($shipping_address['country']) ? $shipping_address['country'] : 'US',
            );

        ?>
            <style>
                .kiloships-container { padding: 0; }
                .kiloships-tabs { display: flex; border-bottom: 2px solid #c3c4c7; margin: 0 0 20px; gap: 0; }
                .kiloships-tab {
                    padding: 10px 18px; cursor: pointer; background: #f0f0f1; border: 1px solid #c3c4c7;
                    border-bottom: none; border-radius: 3px 3px 0 0; font-weight: 600; font-size: 13px;
                    margin-right: -1px; color: #50575e; transition: background .2s, color .2s;
                }
                .kiloships-tab:hover { background: #e0e0e0; }
                .kiloships-tab.active { background: #fff; color: #1d2327; border-bottom: 2px solid #fff; margin-bottom: -2px; }
                .kiloships-tab.ks-tab-error { color: #d63638; }
                .kiloships-tab.ks-tab-error::after { content: ' ⚠'; }
                .kiloships-panel { display: none; padding: 0 2px; }
                .kiloships-panel.active { display: block; }
                .kiloships-section-title { font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 12px; padding: 0 0 8px; border-bottom: 1px solid #f0f0f1; }
                .kiloships-form-group { margin-bottom: 14px; }
                .kiloships-form-group label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 12px; color: #1d2327; text-transform: uppercase; letter-spacing: .3px; }
                .kiloships-form-group input,
                .kiloships-form-group select { width: 100%; padding: 7px 10px; border: 1px solid #8c8f94; border-radius: 3px; box-sizing: border-box; font-size: 13px; line-height: 1.5; color: #2c3338; background: #fff; }
                .kiloships-form-group input:focus,
                .kiloships-form-group select:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
                #ks_from_country, #ks_to_country { background: #f0f0f1; color: #8c8f94; }
                .kiloships-row { display: flex; gap: 12px; margin-bottom: 0; }
                .kiloships-col { flex: 1; min-width: 0; }
                .ks-lookup-wrapper { display: flex; gap: 6px; align-items: flex-start; }
                .ks-lookup-wrapper input { flex: 1; }
                .ks-lookup-wrapper .button { padding: 6px 10px; font-size: 12px; white-space: nowrap; line-height: 1.5; }
                .ks-action-row { display: flex; gap: 6px; margin-top: 10px; padding: 10px 0 0; border-top: 1px dashed #dcdcde; }
                .ks-action-row .button { flex: 1; text-align: center; font-size: 12px; }
                .kiloships-footer { margin-top: 16px; border-top: 2px solid #c3c4c7; padding-top: 14px; }
                #kiloships-create-label { width: 100%; padding: 10px 12px; font-size: 14px; font-weight: 600; }
                #kiloships-create-label:disabled { opacity: .5; cursor: not-allowed; }
                #kiloships-message { margin-top: 10px; padding: 10px 12px; border-radius: 3px; font-size: 13px; line-height: 1.6; white-space: pre-wrap; display: none; }
                #kiloships-message.error { display: block; background: #fcf0f1; color: #8a1116; border-left: 4px solid #d63638; }
                #kiloships-message.success { display: block; background: #edfaef; color: #0a4b1c; border-left: 4px solid #00a32a; }
                .ks-supplier-box { background: #f0f6fc; padding: 12px 14px; border-radius: 3px; margin-bottom: 16px; border: 1px solid #c5d9ed; }
                .ks-supplier-box label { color: #135e96; font-size: 12px; }
                .ks-supplier-box select { margin-top: 4px; }
                .ks-supplier-box small { display: block; margin-top: 6px; color: #646970; font-size: 11px; }
                .ks-option-row { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #f6f7f7; border-radius: 3px; margin-bottom: 12px; }
                .ks-option-row input[type="checkbox"] { margin-top: 2px; width: auto; }
                .ks-option-row .ks-opt-text { flex: 1; }
                .ks-option-row .ks-opt-text strong { font-size: 13px; display: block; }
                .ks-option-row .ks-opt-text span { font-size: 11px; color: #646970; }
            </style>

            <div class="kiloships-container">
                <div class="kiloships-tabs">
                    <div class="kiloships-tab active" data-target="parcel">Parcel</div>
                    <div class="kiloships-tab" data-target="from">From</div>
                    <div class="kiloships-tab" data-target="to">To</div>
                    <div class="kiloships-tab" data-target="options">Options</div>
                </div>

                <div id="kiloships-panel-parcel" class="kiloships-panel active">
                    <p class="kiloships-section-title">Package Weight</p>
                    <div class="kiloships-form-group">
                        <label>Weight (lb)</label>
                        <input type="number" id="ks_weight" value="<?php echo esc_attr($total_weight); ?>" step="0.1" min="0">
                    </div>
                    <p class="kiloships-section-title">Package Dimensions</p>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group">
                            <label>Length (in)</label>
                            <input type="number" id="ks_length" value="10" step="0.1" min="0">
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Width (in)</label>
                            <input type="number" id="ks_width" value="6" step="0.1" min="0">
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Height (in)</label>
                            <input type="number" id="ks_height" value="4" step="0.1" min="0">
                        </div>
                    </div>
                </div>

                <div id="kiloships-panel-from" class="kiloships-panel">
                    <div class="ks-supplier-box">
                        <label>Quick Select Supplier</label>
                        <select id="ks_supplier_select">
                            <option value="">-- Manual Entry --</option>
                            <?php
                            if (class_exists('Kiloships_Admin_Suppliers')) {
                                $suppliers = Kiloships_Admin_Suppliers::get_all();
                                foreach ($suppliers as $supplier) {
                                    $display = ! empty($supplier->nickname) ? $supplier->nickname : $supplier->name;
                                    echo '<option value="' . esc_attr($supplier->id) . '">' . esc_html($display) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <input type="hidden" id="ks_supplier_id" value="" />
                        <small>Select a saved supplier or enter manually below. <a href="<?php echo admin_url('admin.php?page=kiloships-shipping&tab=suppliers'); ?>" target="_blank">Manage Suppliers</a></small>
                    </div>
                    <p class="kiloships-section-title">Sender Address</p>
                    <div class="kiloships-form-group"><label>Name</label><input type="text" id="ks_from_name" value="<?php echo esc_attr($from['name']); ?>"></div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>Street 1</label><input type="text" id="ks_from_street1" value="<?php echo esc_attr($from['street1']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group"><label>Street 2</label><input type="text" id="ks_from_street2" value="<?php echo esc_attr($from['street2']); ?>"></div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>City</label><input type="text" id="ks_from_city" value="<?php echo esc_attr($from['city']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>State</label>
                            <select id="ks_from_state">
                                <option value="">Select State</option>
                                <?php
                                $states = array('AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FM' => 'Federated States of Micronesia', 'FL' => 'Florida', 'GA' => 'Georgia', 'GU' => 'Guam', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MH' => 'Marshall Islands', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MP' => 'Northern Mariana Islands', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PW' => 'Palau', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VI' => 'Virgin Islands', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
                                foreach ($states as $code => $name) {
                                    $selected = ($from['state'] === $code) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($code . ' - ' . $name) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>ZIP Code</label><input type="text" id="ks_from_zip" value="<?php echo esc_attr($from['zip']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Country</label>
                            <select id="ks_from_country">
                                <option value="US" selected>US - United States</option>
                            </select>
                        </div>
                    </div>
                    <div class="ks-action-row">
                        <button type="button" class="button ks-lookup-btn" data-target="from">ZIP Lookup</button>
                        <button type="button" class="button ks-validate-address" data-target="from">Validate Address</button>
                    </div>
                    <div id="ks-save-supplier-row" class="ks-option-row" style="margin-top: 10px; padding: 8px 12px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                            <input type="checkbox" id="ks_save_as_supplier" value="1" />
                            <span>Save this address as a new supplier for future use</span>
                        </label>
                        <div id="ks-save-nickname" style="margin-top: 6px; display: none;">
                            <input type="text" id="ks_new_supplier_nickname" placeholder="Supplier nickname (optional)" style="width: 100%;" />
                        </div>
                    </div>
                </div>

                <div id="kiloships-panel-to" class="kiloships-panel">
                    <p class="kiloships-section-title">Recipient Address</p>
                    <div class="kiloships-form-group"><label>Name</label><input type="text" id="ks_to_name" value="<?php echo esc_attr($to['name']); ?>"></div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>Street 1</label><input type="text" id="ks_to_street1" value="<?php echo esc_attr($to['street1']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group"><label>Street 2</label><input type="text" id="ks_to_street2" value="<?php echo esc_attr($to['street2']); ?>"></div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>City</label><input type="text" id="ks_to_city" value="<?php echo esc_attr($to['city']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>State</label>
                            <select id="ks_to_state">
                                <option value="">Select State</option>
                                <?php
                                foreach ($states as $code => $name) {
                                    $selected = ($to['state'] === $code) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($code . ' - ' . $name) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>ZIP Code</label><input type="text" id="ks_to_zip" value="<?php echo esc_attr($to['zip']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Country</label>
                            <select id="ks_to_country">
                                <option value="US" selected>US - United States</option>
                            </select>
                        </div>
                    </div>
                    <div class="ks-action-row">
                        <button type="button" class="button ks-lookup-btn" data-target="to">ZIP Lookup</button>
                        <button type="button" class="button ks-validate-address" data-target="to">Validate Address</button>
                    </div>
                </div>

                <div id="kiloships-panel-options" class="kiloships-panel">
                    <p class="kiloships-section-title">Shipping Service</p>
                    <div class="kiloships-form-group">
                        <label>Service Level</label>
                        <select id="ks_service">
                            <option value="usps_ground_advantage">USPS Ground Advantage</option>
                            <option value="usps_priority">USPS Priority Mail</option>
                            <option value="usps_priority_express">USPS Priority Mail Express</option>
                            <option value="usps_media_mail">USPS Media Mail</option>
                        </select>
                    </div>
                    <p class="kiloships-section-title">Address Validation</p>
                    <div class="ks-option-row">
                        <input type="checkbox" id="ks_ignore_bad_address" value="1">
                        <div class="ks-opt-text">
                            <strong>Ignore bad address</strong>
                            <span>Allow label creation even if USPS address validation fails. Use with caution.</span>
                        </div>
                    </div>
                    <p class="kiloships-section-title">Additional Info</p>
                    <div class="kiloships-form-group">
                        <label>Metadata (optional)</label>
                        <input type="text" id="ks_metadata" placeholder="Reference or message (comma-separated for multiple)">
                    </div>
                </div>

                <div class="kiloships-footer">
                    <button type="button" id="kiloships-create-label" class="button button-primary">Create Label</button>
                    <div id="kiloships-message"></div>
                </div>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Tab switching
                    $('.kiloships-tab').on('click', function() {
                        $('.kiloships-tab').removeClass('active');
                        $(this).addClass('active');
                        $('.kiloships-panel').removeClass('active');
                        $('#kiloships-panel-' + $(this).data('target')).addClass('active');
                    });

                    var suppliersData = <?php
                        $sup_list = array();
                        if (class_exists('Kiloships_Admin_Suppliers')) {
                            foreach (Kiloships_Admin_Suppliers::get_all() as $s) {
                                $sup_list[$s->id] = $s;
                            }
                        }
                        echo json_encode((object) $sup_list);
                    ?>;

                    $('#ks_supplier_select').on('change', function() {
                        var selectedId = $(this).val();
                        $('#ks_supplier_id').val(selectedId);
                        // Show save-as-supplier only when Manual Entry
                        if (selectedId === '') {
                            $('#ks-save-supplier-row').show();
                            return;
                        }
                        $('#ks-save-supplier-row').hide();
                        $('#ks_save_as_supplier').prop('checked', false);
                        $('#ks-save-nickname').hide();
                        var supplier = suppliersData[selectedId];
                        if (supplier) {
                            $('#ks_from_name').val(supplier.name || '');
                            $('#ks_from_street1').val(supplier.street1 || '');
                            $('#ks_from_street2').val(supplier.street2 || '');
                            $('#ks_from_city').val(supplier.city || '');
                            $('#ks_from_state').val(supplier.state || '');
                            $('#ks_from_zip').val(supplier.zip || '');
                        }
                    });

                    $('#ks_save_as_supplier').on('change', function() {
                        $('#ks-save-nickname').toggle($(this).is(':checked'));
                    });

                    function showMessage(message, type) {
                        var $msg = $('#kiloships-message');
                        $msg.removeClass('error success').addClass(type).html(message);
                    }

                    function highlightErrorTab(errText) {
                        $('.kiloships-tab').removeClass('ks-tab-error');
                        if (!errText) return;
                        var lower = errText.toLowerCase();
                        if (lower.indexOf('fromaddress') !== -1 || lower.indexOf('from address') !== -1 || lower.indexOf('from name') !== -1 || lower.indexOf('from street') !== -1 || lower.indexOf('from zip') !== -1 || lower.indexOf('from city') !== -1 || lower.indexOf('from state') !== -1) {
                            $('.kiloships-tab[data-target="from"]').addClass('ks-tab-error');
                        }
                        if (lower.indexOf('toaddress') !== -1 || lower.indexOf('to address') !== -1 || lower.indexOf('to name') !== -1 || lower.indexOf('to street') !== -1 || lower.indexOf('to zip') !== -1 || lower.indexOf('to city') !== -1 || lower.indexOf('to state') !== -1) {
                            $('.kiloships-tab[data-target="to"]').addClass('ks-tab-error');
                        }
                    }

                    function friendlyError(raw) {
                        if (typeof raw !== 'string') return raw;
                        return raw
                            .replace(/fromAddress\.?/gi, 'From Address → ')
                            .replace(/toAddress\.?/gi, 'To Address → ')
                            .replace(/fromaddress/gi, 'From Address')
                            .replace(/toaddress/gi, 'To Address');
                    }

                    // Helper function to validate ZIP code
                    function isValidZip(zip) {
                        return /^\d{5}(-\d{4})?$/.test(zip);
                    }

                    // Helper function to validate state code
                    function isValidState(state) {
                        var validStates = ['AA', 'AE', 'AP', 'AL', 'AK', 'AS', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FM', 'FL', 'GA', 'GU', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MH', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MP', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PW', 'PA', 'PR', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VI', 'VA', 'WA', 'WV', 'WI', 'WY'];
                        return validStates.indexOf(state.toUpperCase()) !== -1;
                    }

                    // Helper function to validate required fields
                    function validateForm() {
                        var errors = [];

                        // Parcel validation
                        if (!$('#ks_weight').val() || parseFloat($('#ks_weight').val()) <= 0) {
                            errors.push('Weight must be greater than 0');
                        }
                        if (!$('#ks_length').val() || parseFloat($('#ks_length').val()) <= 0) {
                            errors.push('Length must be greater than 0');
                        }
                        if (!$('#ks_width').val() || parseFloat($('#ks_width').val()) <= 0) {
                            errors.push('Width must be greater than 0');
                        }
                        if (!$('#ks_height').val() || parseFloat($('#ks_height').val()) <= 0) {
                            errors.push('Height must be greater than 0');
                        }

                        // From address validation
                        if (!$('#ks_from_name').val().trim()) {
                            errors.push('From Name is required');
                        }
                        if (!$('#ks_from_street1').val().trim()) {
                            errors.push('From Street Address is required');
                        }
                        if (!$('#ks_from_city').val().trim()) {
                            errors.push('From City is required');
                        }
                        var fromState = $('#ks_from_state').val();
                        if (!fromState || fromState === '') {
                            errors.push('From State is required');
                        } else if (!isValidState(fromState)) {
                            errors.push('From State must be a valid 2-letter state code');
                        }
                        if (!isValidZip($('#ks_from_zip').val().trim())) {
                            errors.push('From ZIP Code must be 5 or 9 digits (e.g., 12345 or 12345-6789)');
                        }

                        // To address validation
                        if (!$('#ks_to_name').val().trim()) {
                            errors.push('To Name is required');
                        }
                        if (!$('#ks_to_street1').val().trim()) {
                            errors.push('To Street Address is required');
                        }
                        if (!$('#ks_to_city').val().trim()) {
                            errors.push('To City is required');
                        }
                        var toState = $('#ks_to_state').val();
                        if (!toState || toState === '') {
                            errors.push('To State is required');
                        } else if (!isValidState(toState)) {
                            errors.push('To State must be a valid 2-letter state code');
                        }
                        if (!isValidZip($('#ks_to_zip').val().trim())) {
                            errors.push('To ZIP Code must be 5 or 9 digits (e.g., 12345 or 12345-6789)');
                        }

                        return errors;
                    }

                    // Address Lookup
                    $('.ks-lookup-btn').on('click', function() {
                        var target = $(this).data('target');
                        var zip = $('#ks_' + target + '_zip').val().trim();
                        var $btn = $(this);

                        if (!zip) {
                            showMessage('Please enter a ZIP Code', 'error');
                            return;
                        }

                        if (!isValidZip(zip)) {
                            showMessage('ZIP Code must be 5 or 9 digits (e.g., 12345 or 12345-6789)', 'error');
                            return;
                        }

                        $btn.prop('disabled', true).text('Loading...');
                        showMessage('Looking up city and state...', 'success');

                        $.post(ajaxurl, {
                            action: 'kiloships_lookup_city_state',
                            zip_code: zip,
                            nonce: '<?php echo wp_create_nonce('kiloships_create_label'); ?>'
                        }, function(response) {
                            $btn.prop('disabled', false).text('Lookup');
                            if (response.success) {
                                $('#ks_' + target + '_city').val(response.data.city);
                                // Set state dropdown value
                                var stateCode = response.data.state.toUpperCase();
                                $('#ks_' + target + '_state').val(stateCode);

                                // Highlight if state code not found in dropdown
                                if ($('#ks_' + target + '_state').val() !== stateCode) {
                                    showMessage('Warning: State code "' + stateCode + '" not found in list. Please select manually.', 'error');
                                } else {
                                    showMessage('City and State updated successfully!', 'success');
                                }
                            } else {
                                showMessage('Error: ' + response.data, 'error');
                            }
                        }).fail(function() {
                            $btn.prop('disabled', false).text('Lookup');
                            showMessage('Network error. Please try again.', 'error');
                        });
                    });

                    $('.ks-validate-address').on('click', function() {
                        var target = $(this).data('target');
                        var $btn = $(this);
                        var street = $('#ks_' + target + '_street1').val().trim();
                        var city = $('#ks_' + target + '_city').val().trim();
                        var state = $('#ks_' + target + '_state').val();
                        var zip = $('#ks_' + target + '_zip').val().trim();
                        if (!street || !city || !state) {
                            showMessage('Please enter street, city and state first.', 'error');
                            return;
                        }
                        $btn.prop('disabled', true).text('Validating...');
                        showMessage('Validating address...', 'success');
                        $.post(ajaxurl, {
                            action: 'kiloships_standardize_address',
                            target: target,
                            streetAddress: street,
                            secondaryAddress: $('#ks_' + target + '_street2').val().trim(),
                            city: city,
                            state: state,
                            ZIPCode: zip,
                            nonce: '<?php echo wp_create_nonce('kiloships_create_label'); ?>'
                        }, function(response) {
                            $btn.prop('disabled', false).text('Validate Address');
                            if (response.success && response.data) {
                                var a = response.data;
                                if (a.streetAddress) $('#ks_' + target + '_street1').val(a.streetAddress);
                                if (a.secondaryAddress) $('#ks_' + target + '_street2').val(a.secondaryAddress);
                                if (a.city) $('#ks_' + target + '_city').val(a.city);
                                if (a.state) $('#ks_' + target + '_state').val(a.state);
                                if (a.ZIPCode) $('#ks_' + target + '_zip').val(a.ZIPCode);
                                showMessage('Address standardized and filled.', 'success');
                            } else {
                                showMessage('Error: ' + (response.data || 'Could not validate address'), 'error');
                            }
                        }).fail(function() {
                            $btn.prop('disabled', false).text('Validate Address');
                            showMessage('Network error.', 'error');
                        });
                    });

                    // Create Label
                    $('#kiloships-create-label').on('click', function() {
                        var $btn = $(this);
                        var $msg = $('#kiloships-message');

                        // Validate form
                        $('.kiloships-tab').removeClass('ks-tab-error');
                        var errors = validateForm();
                        if (errors.length > 0) {
                            highlightErrorTab(errors.join(' '));
                            showMessage('Please fix:<br>• ' + errors.join('<br>• '), 'error');
                            return;
                        }

                        $btn.prop('disabled', true).text('Creating Label...');
                        $msg.removeClass('error success').html('');

                        var data = {
                            action: 'kiloships_create_label',
                            order_id: <?php echo $order->get_id(); ?>,
                            nonce: '<?php echo wp_create_nonce('kiloships_create_label'); ?>',
                            // Parcel
                            weight: $('#ks_weight').val(),
                            length: $('#ks_length').val(),
                            width: $('#ks_width').val(),
                            height: $('#ks_height').val(),
                            // From
                            from_name: $('#ks_from_name').val().trim(),
                            from_street1: $('#ks_from_street1').val().trim(),
                            from_street2: $('#ks_from_street2').val().trim(),
                            from_city: $('#ks_from_city').val().trim(),
                            from_state: $('#ks_from_state').val(),
                            from_zip: $('#ks_from_zip').val().trim(),
                            from_country: 'US',
                            // To
                            to_name: $('#ks_to_name').val().trim(),
                            to_street1: $('#ks_to_street1').val().trim(),
                            to_street2: $('#ks_to_street2').val().trim(),
                            to_city: $('#ks_to_city').val().trim(),
                            to_state: $('#ks_to_state').val(),
                            to_zip: $('#ks_to_zip').val().trim(),
                            to_country: 'US',
                            // Options
                            service: $('#ks_service').val(),
                            ignore_bad_address: $('#ks_ignore_bad_address').is(':checked') ? '1' : '0',
                            metadata: $('#ks_metadata').val().trim(),
                            supplier_id: $('#ks_supplier_id').val() || '',
                            save_as_supplier: $('#ks_save_as_supplier').is(':checked') ? '1' : '0',
                            new_supplier_nickname: $('#ks_new_supplier_nickname').val() || ''
                        };

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                $('.kiloships-tab').removeClass('ks-tab-error');
                                showMessage('Label created successfully! Reloading page...', 'success');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                $btn.prop('disabled', false).text('Create Label');
                                var errMsg = '';
                                var rawForDetect = '';
                                if (response.data && response.data.message) {
                                    errMsg = friendlyError(response.data.message);
                                    rawForDetect = response.data.message;
                                    if (response.data.details && response.data.details.length) {
                                        var friendly = response.data.details.map(friendlyError);
                                        errMsg += '<br>• ' + friendly.join('<br>• ');
                                        rawForDetect += ' ' + response.data.details.join(' ');
                                    }
                                } else {
                                    errMsg = friendlyError(typeof response.data === 'string' ? response.data : 'Error creating label');
                                    rawForDetect = typeof response.data === 'string' ? response.data : '';
                                }
                                highlightErrorTab(rawForDetect);
                                showMessage(errMsg, 'error');
                            }
                        }).fail(function(xhr, status, error) {
                            $btn.prop('disabled', false).text('Create Label');
                            showMessage('Network error: ' + error + '. Please try again.', 'error');
                        });
                    });
                });
            </script>
<?php
    }

    /**
     * Handle AJAX request to create label.
     */
    public function create_label()
    {
        check_ajax_referer('kiloships_create_label', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order    = wc_get_order($order_id);

        if (! $order) {
            wp_send_json_error('Order not found.');
        }

        // Server-side validation
        $errors = array();

        // Validate parcel dimensions
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 0;
        $length = isset($_POST['length']) ? floatval($_POST['length']) : 0;
        $width  = isset($_POST['width']) ? floatval($_POST['width']) : 0;
        $height = isset($_POST['height']) ? floatval($_POST['height']) : 0;

        if ($weight <= 0) {
            $errors[] = 'Weight must be greater than 0';
        }
        if ($length <= 0) {
            $errors[] = 'Length must be greater than 0';
        }
        if ($width <= 0) {
            $errors[] = 'Width must be greater than 0';
        }
        if ($height <= 0) {
            $errors[] = 'Height must be greater than 0';
        }

        // Validate addresses
        $required_fields = array(
            'from_name'    => 'From Name',
            'from_street1' => 'From Street Address',
            'from_city'    => 'From City',
            'from_state'   => 'From State',
            'from_zip'     => 'From ZIP Code',
            'to_name'      => 'To Name',
            'to_street1'   => 'To Street Address',
            'to_city'      => 'To City',
            'to_state'     => 'To State',
            'to_zip'       => 'To ZIP Code',
        );

        foreach ($required_fields as $field => $label) {
            if (empty($_POST[$field]) || trim($_POST[$field]) === '') {
                $errors[] = $label . ' is required';
            }
        }

        // Validate ZIP codes
        if (!empty($_POST['from_zip']) && !preg_match('/^\d{5}(-\d{4})?$/', $_POST['from_zip'])) {
            $errors[] = 'From ZIP Code must be 5 or 9 digits';
        }
        if (!empty($_POST['to_zip']) && !preg_match('/^\d{5}(-\d{4})?$/', $_POST['to_zip'])) {
            $errors[] = 'To ZIP Code must be 5 or 9 digits';
        }

        // Validate state codes (must be exactly 2 characters and match valid state codes)
        $valid_states = array('AA', 'AE', 'AP', 'AL', 'AK', 'AS', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FM', 'FL', 'GA', 'GU', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MH', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MP', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PW', 'PA', 'PR', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VI', 'VA', 'WA', 'WV', 'WI', 'WY');

        if (!empty($_POST['from_state']) && !in_array(strtoupper($_POST['from_state']), $valid_states)) {
            $errors[] = 'From State must be a valid 2-letter state code';
        }
        if (!empty($_POST['to_state']) && !in_array(strtoupper($_POST['to_state']), $valid_states)) {
            $errors[] = 'To State must be a valid 2-letter state code';
        }

        if (!empty($errors)) {
            wp_send_json_error(implode('; ', $errors));
        }

        $ignore_bad = ! empty($_POST['ignore_bad_address']);
        $address_to = array(
            'name'    => sanitize_text_field($_POST['to_name']),
            'street1' => sanitize_text_field($_POST['to_street1']),
            'street2' => sanitize_text_field($_POST['to_street2']),
            'city'    => sanitize_text_field($_POST['to_city']),
            'state'   => strtoupper(sanitize_text_field($_POST['to_state'])),
            'zip'     => sanitize_text_field($_POST['to_zip']),
            'country' => 'US',
        );
        $address_from = array(
            'name'    => sanitize_text_field($_POST['from_name']),
            'street1' => sanitize_text_field($_POST['from_street1']),
            'street2' => sanitize_text_field($_POST['from_street2']),
            'city'    => sanitize_text_field($_POST['from_city']),
            'state'   => strtoupper(sanitize_text_field($_POST['from_state'])),
            'zip'     => sanitize_text_field($_POST['from_zip']),
            'country' => 'US',
        );
        if ($ignore_bad) {
            $address_to['ignoreBadAddress'] = true;
            $address_from['ignoreBadAddress'] = true;
        }

        $metadata = array();
        if (! empty($_POST['metadata'])) {
            $meta_raw = sanitize_text_field($_POST['metadata']);
            $metadata = array_filter(array_map('trim', preg_split('/[\n,]+/', $meta_raw)));
        }

        $data = array(
            'shipment' => array(
                'async'        => false,
                'addressTo'    => $address_to,
                'addressFrom'  => $address_from,
                'parcels'      => array(
                    array(
                        'weight'       => strval($weight),
                        'massUnit'     => 'lb',
                        'length'       => strval($length),
                        'width'        => strval($width),
                        'height'       => strval($height),
                        'distanceUnit' => 'in',
                    ),
                ),
            ),
            'servicelevelToken' => sanitize_text_field($_POST['service']),
        );
        if (! empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        $api    = new Kiloships_API();
        $result = $api->create_label($data);

        if (is_wp_error($result)) {
            $api_data = $result->get_error_data();
            $payload  = array('message' => $result->get_error_message());
            if (is_array($api_data) && ! empty($api_data['error']['errors'])) {
                $details = array();
                foreach ($api_data['error']['errors'] as $err) {
                    $d = isset($err['detail']) ? $err['detail'] : '';
                    if (! empty($err['source']['parameter'])) {
                        $d = $err['source']['parameter'] . ': ' . $d;
                    }
                    if ($d) {
                        $details[] = $d;
                    }
                }
                if (! empty($details)) {
                    $payload['details'] = $details;
                }
            }
            wp_send_json_error($payload);
        }

        if (isset($result['labelUrl']) && isset($result['trackingNumber'])) {
            // Append to labels array (supports multiple labels per order)
            $labels = $order->get_meta('_kiloships_labels');
            if (!is_array($labels)) {
                $labels = array();
            }
            $labels[] = array(
                'labelUrl'       => $result['labelUrl'],
                'trackingNumber' => $result['trackingNumber'],
                'objectId'       => isset($result['objectId']) ? $result['objectId'] : '',
                'chargeAmount'   => isset($result['chargeAmount']) ? $result['chargeAmount'] : 0,
            );
            $order->update_meta_data('_kiloships_labels', $labels);
            $supplier_id = ! empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
            if ($supplier_id) {
                $order->update_meta_data('_kiloships_supplier_id', $supplier_id);
            }
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(
                    'Kiloships label created. Tracking: %s, Cost: $%s',
                    $result['trackingNumber'],
                    isset($result['chargeAmount']) ? number_format($result['chargeAmount'], 2) : '0.00'
                )
            );

            // Push tracking to WC Shipment Tracking Tool (for customer emails + PayPal sync)
            if (function_exists('wcstt_add_tracking_number')) {
                wcstt_add_tracking_number($order_id, $result['trackingNumber'], 'usps');
            }

            // Save to database for reports
            if (! class_exists('Kiloships_Admin_Reports')) {
                require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
            }

            $from_address = sprintf(
                '%s, %s, %s %s',
                sanitize_text_field($_POST['from_street1']),
                sanitize_text_field($_POST['from_city']),
                strtoupper(sanitize_text_field($_POST['from_state'])),
                sanitize_text_field($_POST['from_zip'])
            );

            $to_address = sprintf(
                '%s, %s, %s %s',
                sanitize_text_field($_POST['to_street1']),
                sanitize_text_field($_POST['to_city']),
                strtoupper(sanitize_text_field($_POST['to_state'])),
                sanitize_text_field($_POST['to_zip'])
            );

            // Save manual address as new supplier if requested
            $save_as_supplier = ! empty($_POST['save_as_supplier']) && $_POST['save_as_supplier'] === '1';
            if ($save_as_supplier && empty($supplier_id) && class_exists('Kiloships_Admin_Suppliers')) {
                $nickname = ! empty($_POST['new_supplier_nickname'])
                    ? sanitize_text_field($_POST['new_supplier_nickname'])
                    : sanitize_text_field($_POST['from_name']);

                $new_id = Kiloships_Admin_Suppliers::save(array(
                    'nickname' => $nickname,
                    'name'     => sanitize_text_field($_POST['from_name']),
                    'street1'  => sanitize_text_field($_POST['from_street1']),
                    'street2'  => sanitize_text_field($_POST['from_street2']),
                    'city'     => sanitize_text_field($_POST['from_city']),
                    'state'    => strtoupper(sanitize_text_field($_POST['from_state'])),
                    'zip'      => sanitize_text_field($_POST['from_zip']),
                ));
                if ($new_id) {
                    $supplier_id = $new_id;
                    $order->update_meta_data('_kiloships_supplier_id', $supplier_id);
                    $order->save();
                }
            }

            Kiloships_Admin_Reports::save_label(array(
                'order_id'        => $order_id,
                'order_number'    => $order->get_order_number(),
                'tracking_number' => $result['trackingNumber'],
                'object_id'       => isset($result['objectId']) ? $result['objectId'] : null,
                'cost'            => isset($result['chargeAmount']) ? $result['chargeAmount'] : 0.00,
                'service_level'   => sanitize_text_field($_POST['service']),
                'from_name'       => sanitize_text_field($_POST['from_name']),
                'from_address'    => $from_address,
                'to_name'         => sanitize_text_field($_POST['to_name']),
                'to_address'      => $to_address,
                'website'         => get_bloginfo('name'),
                'supplier_id'     => $supplier_id,
            ));

            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to create label. Invalid API response.');
        }
    }

    /**
     * Handle AJAX request to cancel label.
     */
    public function cancel_label()
    {
        check_ajax_referer('kiloships_cancel_label', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (empty($tracking_number)) {
            wp_send_json_error('Tracking number is required.');
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            wp_send_json_error('Order not found.');
        }

        $api    = new Kiloships_API();
        $result = $api->cancel_label($tracking_number);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Remove cancelled label from labels array
        $labels = $order->get_meta('_kiloships_labels');
        if (is_array($labels)) {
            $labels = array_values(array_filter($labels, function ($lbl) use ($tracking_number) {
                return !isset($lbl['trackingNumber']) || $lbl['trackingNumber'] !== $tracking_number;
            }));
            $order->update_meta_data('_kiloships_labels', $labels);
        }
        // Also clean up legacy single-label meta if present
        $order->delete_meta_data('_kiloships_label_url');
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                'Kiloships label cancelled. Tracking: %s',
                $tracking_number
            )
        );

        // Remove tracking from WC Shipment Tracking Tool (also cancels PayPal sync)
        if (class_exists('WCSTT_Tracking_Repository')) {
            WCSTT_Tracking_Repository::remove_tracking_item($order_id, $tracking_number);
        }

        // Update database for reports
        if (! class_exists('Kiloships_Admin_Reports')) {
            require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
        }
        Kiloships_Admin_Reports::cancel_label($tracking_number);

        wp_send_json_success(array(
            'message' => 'Label cancelled successfully',
            'data' => $result
        ));
    }

    /**
     * Handle AJAX request to lookup city/state.
     */
    public function lookup_city_state()
    {
        check_ajax_referer('kiloships_create_label', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $zip_code = isset($_POST['zip_code']) ? sanitize_text_field($_POST['zip_code']) : '';

        if (empty($zip_code)) {
            wp_send_json_error('Zip code is required.');
        }

        $api    = new Kiloships_API();
        $result = $api->lookup_city_state($zip_code);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Handle AJAX request to standardize address (From or To).
     */
    public function standardize_address()
    {
        check_ajax_referer('kiloships_create_label', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $street = isset($_POST['streetAddress']) ? sanitize_text_field($_POST['streetAddress']) : '';
        $city   = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
        $state  = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';
        if (empty($street) || empty($city) || empty($state)) {
            wp_send_json_error('Street, city and state are required.');
        }

        $address = array(
            'streetAddress'   => $street,
            'city'            => $city,
            'state'           => strtoupper($state),
            'secondaryAddress' => isset($_POST['secondaryAddress']) ? sanitize_text_field($_POST['secondaryAddress']) : '',
        );
        if (! empty($_POST['ZIPCode'])) {
            $address['ZIPCode'] = sanitize_text_field($_POST['ZIPCode']);
        }

        $api    = new Kiloships_API();
        $result = $api->standardize_address($address);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Handle AJAX request to get tracking info.
     */
    public function get_tracking()
    {
        check_ajax_referer('kiloships_create_label', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        if (empty($tracking_number)) {
            wp_send_json_error('Tracking number is required.');
        }

        $api    = new Kiloships_API();
        $result = $api->get_tracking($tracking_number, 'SUMMARY');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }
}
