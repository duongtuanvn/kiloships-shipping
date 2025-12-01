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
    }

    /**
     * Add meta box to order page.
     */
    public function add_meta_box()
    {
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
     * @param WP_Post $post Order post object.
     */
    public function render_meta_box($post)
    {
        $order = wc_get_order($post->ID);
        if (! $order) {
            return;
        }

        $label_url       = $order->get_meta('_kiloships_label_url');
        $tracking_number = $order->get_meta('_kiloships_tracking_number');
        $object_id       = $order->get_meta('_kiloships_object_id');
        $charge_amount   = $order->get_meta('_kiloships_charge_amount');

        if ($label_url) {
            echo '<div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 15px; margin-bottom: 10px;">';
            echo '<h4 style="margin-top: 0; color: #0073aa;">Label Created Successfully</h4>';
            echo '<p style="margin: 5px 0;"><strong>Tracking Number:</strong> ' . esc_html($tracking_number) . '</p>';
            if ($object_id) {
                echo '<p style="margin: 5px 0;"><strong>Label ID:</strong> ' . esc_html($object_id) . '</p>';
            }
            if ($charge_amount) {
                echo '<p style="margin: 5px 0;"><strong>Cost:</strong> $' . number_format(floatval($charge_amount), 2) . '</p>';
            }
            echo '<div style="margin-top: 15px; display: flex; gap: 10px;">';
            echo '<a href="' . esc_url($label_url) . '" class="button button-primary" target="_blank">Download Label PDF</a>';
            echo '<button type="button" class="button button-secondary kiloships-cancel-label" data-tracking="' . esc_attr($tracking_number) . '" data-order-id="' . esc_attr($order->get_id()) . '">Cancel Label</button>';
            echo '</div>';
            echo '<div id="kiloships-cancel-message" style="margin-top: 10px;"></div>';
            echo '</div>';

            // Add JavaScript for cancel functionality
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.kiloships-cancel-label').on('click', function() {
                        var $btn = $(this);
                        var trackingNumber = $btn.data('tracking');
                        var orderId = $btn.data('order-id');
                        var $msg = $('#kiloships-cancel-message');

                        if (!confirm('Are you sure you want to cancel this shipping label?\n\nTracking: ' + trackingNumber + '\n\nThis action cannot be undone and may result in a refund.')) {
                            return;
                        }

                        $btn.prop('disabled', true).text('Cancelling...');
                        $msg.removeClass('error success').text('Processing cancellation...').show();

                        $.post(ajaxurl, {
                            action: 'kiloships_cancel_label',
                            tracking_number: trackingNumber,
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('kiloships_cancel_label'); ?>'
                        }, function(response) {
                            if (response.success) {
                                $msg.css({
                                    'padding': '10px',
                                    'background': '#d4edda',
                                    'color': '#155724',
                                    'border': '1px solid #c3e6cb',
                                    'border-radius': '4px'
                                }).text('Label cancelled successfully! Reloading...');
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                $btn.prop('disabled', false).text('Cancel Label');
                                $msg.css({
                                    'padding': '10px',
                                    'background': '#f8d7da',
                                    'color': '#721c24',
                                    'border': '1px solid #f5c6cb',
                                    'border-radius': '4px'
                                }).text('Error: ' + response.data);
                            }
                        }).fail(function(xhr, status, error) {
                            $btn.prop('disabled', false).text('Cancel Label');
                            $msg.css({
                                'padding': '10px',
                                'background': '#f8d7da',
                                'color': '#721c24',
                                'border': '1px solid #f5c6cb',
                                'border-radius': '4px'
                            }).text('Network error: ' + error);
                        });
                    });
                });
            </script>
            <?php
        } else {
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
                .kiloships-container {
                    background: #fff;
                    padding: 15px;
                }

                .kiloships-tabs {
                    display: flex;
                    border-bottom: 2px solid #ddd;
                    margin-bottom: 20px;
                    gap: 5px;
                }

                .kiloships-tab {
                    padding: 10px 20px;
                    cursor: pointer;
                    background: #f5f5f5;
                    border: 1px solid #ddd;
                    border-bottom: none;
                    border-radius: 4px 4px 0 0;
                    transition: all 0.3s ease;
                    font-weight: 500;
                }

                .kiloships-tab:hover {
                    background: #e9e9e9;
                }

                .kiloships-tab.active {
                    background: #fff;
                    border-bottom: 2px solid #fff;
                    margin-bottom: -2px;
                    color: #2271b1;
                    border-color: #2271b1 #2271b1 #fff #2271b1;
                }

                .kiloships-panel {
                    display: none;
                    animation: fadeIn 0.3s;
                }

                .kiloships-panel.active {
                    display: block;
                }

                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }

                .kiloships-form-group {
                    margin-bottom: 15px;
                }

                .kiloships-form-group label {
                    display: block;
                    margin-bottom: 5px;
                    font-weight: 600;
                    color: #333;
                }

                .kiloships-form-group input,
                .kiloships-form-group select {
                    width: 100%;
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-sizing: border-box;
                    font-size: 14px;
                }

                .kiloships-form-group input:focus,
                .kiloships-form-group select:focus {
                    border-color: #2271b1;
                    outline: none;
                    box-shadow: 0 0 0 1px #2271b1;
                }

                .kiloships-form-group input[readonly],
                .kiloships-form-group select[disabled] {
                    background-color: #f5f5f5;
                    cursor: not-allowed;
                    opacity: 0.7;
                }

                #ks_from_country,
                #ks_to_country {
                    background-color: #f5f5f5;
                    cursor: not-allowed;
                }

                .kiloships-row {
                    display: flex;
                    gap: 15px;
                    margin-bottom: 0;
                }

                .kiloships-col {
                    flex: 1;
                }

                .ks-lookup-wrapper {
                    display: flex;
                    gap: 8px;
                }

                .ks-lookup-wrapper input {
                    flex: 1;
                }

                .ks-lookup-btn {
                    white-space: nowrap;
                    min-width: 80px;
                }

                #kiloships-message {
                    padding: 10px;
                    border-radius: 4px;
                    font-weight: 500;
                }

                #kiloships-message.error {
                    background: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                }

                #kiloships-message.success {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }

                #kiloships-create-label {
                    width: 100%;
                    padding: 12px;
                    font-size: 16px;
                    font-weight: 600;
                }

                #kiloships-create-label:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
            </style>

            <div class="kiloships-container">
                <div class="kiloships-tabs">
                    <div class="kiloships-tab active" data-target="parcel">Parcel</div>
                    <div class="kiloships-tab" data-target="from">From</div>
                    <div class="kiloships-tab" data-target="to">To</div>
                    <div class="kiloships-tab" data-target="options">Options</div>
                </div>

                <div id="kiloships-panel-parcel" class="kiloships-panel active">
                    <div class="kiloships-form-group">
                        <label>Weight (lb)</label>
                        <input type="number" id="ks_weight" value="<?php echo esc_attr($total_weight); ?>" step="0.1">
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group">
                            <label>Length (in)</label>
                            <input type="number" id="ks_length" value="10" step="0.1">
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Width (in)</label>
                            <input type="number" id="ks_width" value="6" step="0.1">
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Height (in)</label>
                            <input type="number" id="ks_height" value="4" step="0.1">
                        </div>
                    </div>
                </div>

                <div id="kiloships-panel-from" class="kiloships-panel">
                    <div class="kiloships-form-group" style="background: #f0f6fc; padding: 12px; border-radius: 4px; margin-bottom: 15px;">
                        <label style="color: #2271b1;">Quick Select Supplier</label>
                        <select id="ks_supplier_select" style="margin-bottom: 5px;">
                            <option value="">-- Manual Entry --</option>
                            <?php
                            $suppliers = get_option('kiloships_suppliers', array());
                            if (is_array($suppliers) && !empty($suppliers)) {
                                foreach ($suppliers as $index => $supplier) {
                                    if (isset($supplier['name']) && !empty($supplier['name'])) {
                                        echo '<option value="' . esc_attr($index) . '">' . esc_html($supplier['name']) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">Select a saved supplier or enter manually below. <a href="<?php echo admin_url('options-general.php?page=kiloships-shipping'); ?>" target="_blank">Manage Suppliers</a></small>
                    </div>
                    <div class="kiloships-form-group"><label>Name</label><input type="text" id="ks_from_name" value="<?php echo esc_attr($from['name']); ?>"></div>
                    <div class="kiloships-form-group"><label>Street 1</label><input type="text" id="ks_from_street1" value="<?php echo esc_attr($from['street1']); ?>"></div>
                    <div class="kiloships-form-group"><label>Street 2</label><input type="text" id="ks_from_street2" value="<?php echo esc_attr($from['street2']); ?>"></div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>Zip</label>
                            <div class="ks-lookup-wrapper">
                                <input type="text" id="ks_from_zip" value="<?php echo esc_attr($from['zip']); ?>">
                                <button type="button" class="button ks-lookup-btn" data-target="from">Lookup</button>
                            </div>
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Country</label>
                            <select id="ks_from_country">
                                <option value="US" selected>US - United States</option>
                            </select>
                        </div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>City</label><input type="text" id="ks_from_city" value="<?php echo esc_attr($from['city']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>State (2-letter code)</label>
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
                </div>

                <div id="kiloships-panel-to" class="kiloships-panel">
                    <div class="kiloships-form-group"><label>Name</label><input type="text" id="ks_to_name" value="<?php echo esc_attr($to['name']); ?>"></div>
                    <div class="kiloships-form-group"><label>Street 1</label><input type="text" id="ks_to_street1" value="<?php echo esc_attr($to['street1']); ?>"></div>
                    <div class="kiloships-form-group"><label>Street 2</label><input type="text" id="ks_to_street2" value="<?php echo esc_attr($to['street2']); ?>"></div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>Zip</label>
                            <div class="ks-lookup-wrapper">
                                <input type="text" id="ks_to_zip" value="<?php echo esc_attr($to['zip']); ?>">
                                <button type="button" class="button ks-lookup-btn" data-target="to">Lookup</button>
                            </div>
                        </div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>Country</label>
                            <select id="ks_to_country">
                                <option value="US" selected>US - United States</option>
                            </select>
                        </div>
                    </div>
                    <div class="kiloships-row">
                        <div class="kiloships-col kiloships-form-group"><label>City</label><input type="text" id="ks_to_city" value="<?php echo esc_attr($to['city']); ?>"></div>
                        <div class="kiloships-col kiloships-form-group">
                            <label>State (2-letter code)</label>
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
                </div>

                <div id="kiloships-panel-options" class="kiloships-panel">
                    <div class="kiloships-form-group">
                        <label>Service Level</label>
                        <select id="ks_service">
                            <option value="usps_ground_advantage">USPS Ground Advantage</option>
                            <option value="usps_priority">USPS Priority Mail</option>
                            <option value="usps_priority_express">USPS Priority Mail Express</option>
                            <option value="usps_media_mail">USPS Media Mail</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 10px;">
                    <button type="button" id="kiloships-create-label" class="button button-primary" style="width: 100%;">Create Label</button>
                    <div id="kiloships-message" style="margin-top: 10px;"></div>
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

                    // Supplier data
                    var suppliersData = <?php echo json_encode(get_option('kiloships_suppliers', array())); ?>;

                    // Supplier selection handler
                    $('#ks_supplier_select').on('change', function() {
                        var selectedIndex = $(this).val();

                        if (selectedIndex === '') {
                            // Manual entry - don't clear fields (keep default settings)
                            return;
                        }

                        // Populate fields with selected supplier data
                        var supplier = suppliersData[selectedIndex];
                        if (supplier) {
                            $('#ks_from_name').val(supplier.name || '');
                            $('#ks_from_street1').val(supplier.street1 || '');
                            $('#ks_from_street2').val(supplier.street2 || '');
                            $('#ks_from_city').val(supplier.city || '');
                            $('#ks_from_state').val(supplier.state || '');
                            $('#ks_from_zip').val(supplier.zip || '');
                        }
                    });

                    // Helper function to show messages
                    function showMessage(message, type) {
                        var $msg = $('#kiloships-message');
                        $msg.removeClass('error success').addClass(type).text(message).show();
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

                    // Create Label
                    $('#kiloships-create-label').on('click', function() {
                        var $btn = $(this);
                        var $msg = $('#kiloships-message');

                        // Validate form
                        var errors = validateForm();
                        if (errors.length > 0) {
                            showMessage('Please fix the following errors:\n• ' + errors.join('\n• '), 'error');
                            return;
                        }

                        $btn.prop('disabled', true).text('Creating Label...');
                        $msg.removeClass('error success').text('');

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
                            service: $('#ks_service').val()
                        };

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                showMessage('Label created successfully! Reloading page...', 'success');
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                $btn.prop('disabled', false).text('Create Label');
                                showMessage('Error: ' + response.data, 'error');
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

        // Retrieve data from POST
        $data = array(
            'shipment' => array(
                'async' => false,
                'addressTo' => array(
                    'name'    => sanitize_text_field($_POST['to_name']),
                    'street1' => sanitize_text_field($_POST['to_street1']),
                    'street2' => sanitize_text_field($_POST['to_street2']),
                    'city'    => sanitize_text_field($_POST['to_city']),
                    'state'   => strtoupper(sanitize_text_field($_POST['to_state'])),
                    'zip'     => sanitize_text_field($_POST['to_zip']),
                    'country' => 'US',
                ),
                'addressFrom' => array(
                    'name'    => sanitize_text_field($_POST['from_name']),
                    'street1' => sanitize_text_field($_POST['from_street1']),
                    'street2' => sanitize_text_field($_POST['from_street2']),
                    'city'    => sanitize_text_field($_POST['from_city']),
                    'state'   => strtoupper(sanitize_text_field($_POST['from_state'])),
                    'zip'     => sanitize_text_field($_POST['from_zip']),
                    'country' => 'US',
                ),
                'parcels' => array(
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

        $api    = new Kiloships_API();
        $result = $api->create_label($data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        if (isset($result['labelUrl']) && isset($result['trackingNumber'])) {
            $order->update_meta_data('_kiloships_label_url', $result['labelUrl']);
            $order->update_meta_data('_kiloships_tracking_number', $result['trackingNumber']);
            $order->update_meta_data('_kiloships_object_id', isset($result['objectId']) ? $result['objectId'] : '');
            $order->update_meta_data('_kiloships_charge_amount', isset($result['chargeAmount']) ? $result['chargeAmount'] : 0);
            $order->save();

            // Add order note
            $order->add_order_note(
                sprintf(
                    'Kiloships label created. Tracking: %s, Cost: $%s',
                    $result['trackingNumber'],
                    isset($result['chargeAmount']) ? number_format($result['chargeAmount'], 2) : '0.00'
                )
            );

            // Save to database for reports
            if (! class_exists('Kiloships_Admin_Reports')) {
                require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-admin-reports.php';
            }

            $from_address = sprintf('%s, %s, %s %s',
                sanitize_text_field($_POST['from_street1']),
                sanitize_text_field($_POST['from_city']),
                strtoupper(sanitize_text_field($_POST['from_state'])),
                sanitize_text_field($_POST['from_zip'])
            );

            $to_address = sprintf('%s, %s, %s %s',
                sanitize_text_field($_POST['to_street1']),
                sanitize_text_field($_POST['to_city']),
                strtoupper(sanitize_text_field($_POST['to_state'])),
                sanitize_text_field($_POST['to_zip'])
            );

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

        // Clear label data from order meta
        $order->delete_meta_data('_kiloships_label_url');
        $order->delete_meta_data('_kiloships_tracking_number');
        $order->delete_meta_data('_kiloships_object_id');
        $order->delete_meta_data('_kiloships_charge_amount');
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                'Kiloships label cancelled. Tracking: %s',
                $tracking_number
            )
        );

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
}
