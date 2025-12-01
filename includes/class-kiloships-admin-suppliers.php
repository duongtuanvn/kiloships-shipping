<?php

/**
 * Kiloships Admin - Suppliers Management Tab.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Admin_Suppliers
{

    /**
     * Register suppliers tab settings.
     */
    public static function register_settings()
    {
        register_setting('kiloships_suppliers_options', 'kiloships_suppliers');
    }

    /**
     * Initialize hooks for AJAX.
     */
    public static function init_hooks()
    {
        add_action('wp_ajax_kiloships_admin_lookup_city_state', array(__CLASS__, 'ajax_lookup_city_state'));
    }

    /**
     * Render suppliers management tab content.
     */
    public static function render()
    {
        $suppliers = get_option('kiloships_suppliers', array());
        if (!is_array($suppliers)) {
            $suppliers = array();
        }
        ?>

        <style>
            .ks-admin-lookup-wrapper {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .ks-admin-lookup-wrapper input {
                flex: 1;
            }
            .ks-admin-lookup-btn {
                white-space: nowrap;
            }
        </style>

        <p class="description">Save frequently used recipient addresses for quick selection when creating labels.</p>

        <form method="post" action="options.php">
            <?php settings_fields('kiloships_suppliers_options'); ?>

            <div id="kiloships-suppliers-manager">
                <div id="suppliers-list">
                    <?php
                    if (!empty($suppliers)) {
                        foreach ($suppliers as $index => $supplier) {
                            self::render_supplier_row($index, $supplier);
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button" id="add-supplier">Add New Supplier</button>
            </div>

            <?php submit_button(); ?>
        </form>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var supplierIndex = <?php echo count($suppliers); ?>;
                var stateOptions = `<?php
                    $states = array('AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FM' => 'Federated States of Micronesia', 'FL' => 'Florida', 'GA' => 'Georgia', 'GU' => 'Guam', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MH' => 'Marshall Islands', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MP' => 'Northern Mariana Islands', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PW' => 'Palau', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VI' => 'Virgin Islands', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
                    echo '<option value="">Select State</option>';
                    foreach ($states as $code => $name) {
                        echo '<option value="' . esc_attr($code) . '">' . esc_html($code . ' - ' . $name) . '</option>';
                    }
                ?>`;

                $('#add-supplier').on('click', function() {
                    var row = `
                        <div class="supplier-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h4 style="margin: 0;">Supplier #${supplierIndex + 1}</h4>
                                <button type="button" class="button button-link-delete remove-supplier" style="color: #a00;">Remove</button>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label>Name</label>
                                    <input type="text" name="kiloships_suppliers[${supplierIndex}][name]" class="regular-text" />
                                </div>
                                <div>
                                    <label>Street 1</label>
                                    <input type="text" name="kiloships_suppliers[${supplierIndex}][street1]" class="regular-text" />
                                </div>
                                <div>
                                    <label>Street 2</label>
                                    <input type="text" name="kiloships_suppliers[${supplierIndex}][street2]" class="regular-text" />
                                </div>
                                <div>
                                    <label>ZIP Code</label>
                                    <div class="ks-admin-lookup-wrapper">
                                        <input type="text" name="kiloships_suppliers[${supplierIndex}][zip]" class="regular-text ks-supplier-zip" data-index="${supplierIndex}" placeholder="e.g., 12345" />
                                        <button type="button" class="button ks-admin-lookup-btn ks-supplier-lookup" data-index="${supplierIndex}">Lookup</button>
                                    </div>
                                </div>
                                <div>
                                    <label>City</label>
                                    <input type="text" name="kiloships_suppliers[${supplierIndex}][city]" class="regular-text ks-supplier-city" data-index="${supplierIndex}" />
                                </div>
                                <div>
                                    <label>State</label>
                                    <select name="kiloships_suppliers[${supplierIndex}][state]" class="regular-text ks-supplier-state" data-index="${supplierIndex}">
                                        ${stateOptions}
                                    </select>
                                </div>
                            </div>
                        </div>
                    `;
                    $('#suppliers-list').append(row);
                    supplierIndex++;
                });

                $(document).on('click', '.remove-supplier', function() {
                    if (confirm('Are you sure you want to remove this supplier?')) {
                        $(this).closest('.supplier-row').remove();
                    }
                });

                // ZIP Lookup handler
                $(document).on('click', '.ks-supplier-lookup', function() {
                    var $btn = $(this);
                    var index = $btn.data('index');
                    var $zip = $('.ks-supplier-zip[data-index="' + index + '"]');
                    var $city = $('.ks-supplier-city[data-index="' + index + '"]');
                    var $state = $('.ks-supplier-state[data-index="' + index + '"]');
                    var zipCode = $zip.val().trim();

                    if (!zipCode) {
                        alert('Please enter a ZIP Code');
                        return;
                    }

                    if (!/^\d{5}(-\d{4})?$/.test(zipCode)) {
                        alert('ZIP Code must be 5 or 9 digits (e.g., 12345 or 12345-6789)');
                        return;
                    }

                    $btn.prop('disabled', true).text('Loading...');

                    $.post(ajaxurl, {
                        action: 'kiloships_admin_lookup_city_state',
                        zip_code: zipCode,
                        nonce: '<?php echo wp_create_nonce('kiloships_admin_settings'); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false).text('Lookup');
                        if (response.success) {
                            $city.val(response.data.city);
                            $state.val(response.data.state.toUpperCase());
                            alert('City and State updated successfully!');
                        } else {
                            alert('Error: ' + response.data);
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false).text('Lookup');
                        alert('Network error. Please try again.');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Render a single supplier row.
     *
     * @param int   $index    Supplier index.
     * @param array $supplier Supplier data.
     */
    private static function render_supplier_row($index, $supplier)
    {
        $states = array('AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FM' => 'Federated States of Micronesia', 'FL' => 'Florida', 'GA' => 'Georgia', 'GU' => 'Guam', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MH' => 'Marshall Islands', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MP' => 'Northern Mariana Islands', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PW' => 'Palau', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VI' => 'Virgin Islands', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
        ?>
        <div class="supplier-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h4 style="margin: 0;">Supplier #<?php echo ($index + 1); ?></h4>
                <button type="button" class="button button-link-delete remove-supplier" style="color: #a00;">Remove</button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                    <label>Name</label>
                    <input type="text" name="kiloships_suppliers[<?php echo $index; ?>][name]" value="<?php echo esc_attr($supplier['name'] ?? ''); ?>" class="regular-text" />
                </div>
                <div>
                    <label>Street 1</label>
                    <input type="text" name="kiloships_suppliers[<?php echo $index; ?>][street1]" value="<?php echo esc_attr($supplier['street1'] ?? ''); ?>" class="regular-text" />
                </div>
                <div>
                    <label>Street 2</label>
                    <input type="text" name="kiloships_suppliers[<?php echo $index; ?>][street2]" value="<?php echo esc_attr($supplier['street2'] ?? ''); ?>" class="regular-text" />
                </div>
                <div>
                    <label>ZIP Code</label>
                    <div class="ks-admin-lookup-wrapper">
                        <input type="text" name="kiloships_suppliers[<?php echo $index; ?>][zip]" value="<?php echo esc_attr($supplier['zip'] ?? ''); ?>" class="regular-text ks-supplier-zip" data-index="<?php echo $index; ?>" placeholder="e.g., 12345" />
                        <button type="button" class="button ks-admin-lookup-btn ks-supplier-lookup" data-index="<?php echo $index; ?>">Lookup</button>
                    </div>
                </div>
                <div>
                    <label>City</label>
                    <input type="text" name="kiloships_suppliers[<?php echo $index; ?>][city]" value="<?php echo esc_attr($supplier['city'] ?? ''); ?>" class="regular-text ks-supplier-city" data-index="<?php echo $index; ?>" />
                </div>
                <div>
                    <label>State</label>
                    <select name="kiloships_suppliers[<?php echo $index; ?>][state]" class="regular-text ks-supplier-state" data-index="<?php echo $index; ?>">
                        <option value="">Select State</option>
                        <?php
                        foreach ($states as $code => $name) {
                            $selected = (isset($supplier['state']) && $supplier['state'] === $code) ? 'selected' : '';
                            echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($code . ' - ' . $name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to lookup city/state.
     */
    public static function ajax_lookup_city_state()
    {
        check_ajax_referer('kiloships_admin_settings', 'nonce');

        if (! current_user_can('manage_options')) {
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
}
