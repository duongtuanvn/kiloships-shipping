<?php

/**
 * Kiloships Admin - API Configuration Tab.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_Admin_API
{

    /**
     * Register API tab settings.
     */
    public static function register_settings()
    {
        register_setting('kiloships_api_options', 'kiloships_api_key');
        register_setting('kiloships_api_options', 'kiloships_from_name');
        register_setting('kiloships_api_options', 'kiloships_from_street1');
        register_setting('kiloships_api_options', 'kiloships_from_street2');
        register_setting('kiloships_api_options', 'kiloships_from_city');
        register_setting('kiloships_api_options', 'kiloships_from_state');
        register_setting('kiloships_api_options', 'kiloships_from_zip');
        register_setting('kiloships_api_options', 'kiloships_from_country');
    }

    /**
     * Render API configuration tab content.
     */
    public static function render()
    {
        $api_key = get_option('kiloships_api_key');
        $balance = '';
        $status  = '';

        if ($api_key) {
            if (! class_exists('Kiloships_API')) {
                require_once KILOSHIPS_PLUGIN_DIR . 'includes/class-kiloships-api.php';
            }
            $api     = new Kiloships_API();
            $balance = $api->get_balance();

            if (is_wp_error($balance)) {
                $status = '<span style="color: red;">Connection Failed: ' . $balance->get_error_message() . '</span>';
                $balance = '';
            } else {
                $status  = '<span style="color: green;">Connected</span>';
                $balance = '<span style="font-size: 1.2em; font-weight: bold;">$' . number_format($balance, 2) . '</span>';
            }
        }

        // Get US states array
        $states = array('AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AS' => 'American Samoa', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FM' => 'Federated States of Micronesia', 'FL' => 'Florida', 'GA' => 'Georgia', 'GU' => 'Guam', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MH' => 'Marshall Islands', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MP' => 'Northern Mariana Islands', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PW' => 'Palau', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VI' => 'Virgin Islands', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
        ?>

        <?php if ($api_key) : ?>
            <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                <h2>Account Status</h2>
                <p><strong>Connection:</strong> <?php echo $status; ?></p>
                <?php if ($balance !== '') : ?>
                    <p><strong>Current Balance:</strong> <?php echo $balance; ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('kiloships_api_options'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="password" name="kiloships_api_key" value="<?php echo esc_attr(get_option('kiloships_api_key')); ?>" class="regular-text" />
                        <p class="description">Your Kiloships API Key from <a href="https://kiloships.com" target="_blank">kiloships.com</a></p>
                    </td>
                </tr>
            </table>

            <h2>Default From Address</h2>
            <p class="description">This address will be pre-filled when creating labels.</p>

            <table class="form-table">
                <tr>
                    <th scope="row">Name</th>
                    <td>
                        <input type="text" name="kiloships_from_name" value="<?php echo esc_attr(get_option('kiloships_from_name')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Street 1</th>
                    <td>
                        <input type="text" name="kiloships_from_street1" value="<?php echo esc_attr(get_option('kiloships_from_street1')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Street 2</th>
                    <td>
                        <input type="text" name="kiloships_from_street2" value="<?php echo esc_attr(get_option('kiloships_from_street2')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">ZIP Code</th>
                    <td>
                        <input type="text" name="kiloships_from_zip" value="<?php echo esc_attr(get_option('kiloships_from_zip')); ?>" class="regular-text" placeholder="e.g., 12345" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">City</th>
                    <td>
                        <input type="text" name="kiloships_from_city" value="<?php echo esc_attr(get_option('kiloships_from_city')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">State</th>
                    <td>
                        <select name="kiloships_from_state" class="regular-text">
                            <option value="">Select State</option>
                            <?php
                            $selected_state = get_option('kiloships_from_state');
                            foreach ($states as $code => $name) {
                                $selected = ($selected_state === $code) ? 'selected' : '';
                                echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($code . ' - ' . $name) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Country</th>
                    <td>
                        <select name="kiloships_from_country" class="regular-text">
                            <option value="US" selected>US - United States</option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }
}
