<?php

/**
 * Kiloships API Handler.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Kiloships_API
{

    /**
     * API Base URL.
     *
     * @var string
     */
    private $api_url = 'https://kiloships.com/api';

    /**
     * API Key.
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->api_key = get_option('kiloships_api_key');
    }

    /**
     * Create a shipping label.
     *
     * @param array $data Shipment data.
     * @return array|WP_Error Response data or error.
     */
    public function create_label($data)
    {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Kiloships API Key is missing. Please configure it in Settings > Kiloships Shipping.');
        }

        $response = wp_remote_post(
            $this->api_url . '/shipping-labels/domestic',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($data),
                'timeout' => 45,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Network error: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code >= 400) {
            $error_message = 'API Error';

            if (isset($data['message'])) {
                $error_message = $data['message'];
            } elseif (isset($data['error']['message'])) {
                $error_message = $data['error']['message'];
            } elseif (isset($data['error']['errors']) && is_array($data['error']['errors'])) {
                $errors = array();
                foreach ($data['error']['errors'] as $err) {
                    if (isset($err['detail'])) {
                        $errors[] = $err['detail'];
                    }
                }
                if (!empty($errors)) {
                    $error_message = implode('; ', $errors);
                }
            }

            if ($http_code === 401) {
                $error_message = 'Unauthorized: Invalid API Key';
            } elseif ($http_code === 402) {
                $error_message = 'Insufficient Balance: Please add funds to your Kiloships account';
            } elseif ($http_code === 429) {
                $error_message = 'Rate limit exceeded: Please try again later';
            }

            return new WP_Error('api_error', $error_message, $data);
        }

        if (!isset($data['labelUrl']) || !isset($data['trackingNumber'])) {
            return new WP_Error('api_error', 'Invalid API response: Missing label URL or tracking number', $data);
        }

        return $data;
    }

    /**
     * Cancel a shipping label.
     *
     * @param string $tracking_number Tracking number.
     * @return array|WP_Error Response data or error.
     */
    public function cancel_label($tracking_number)
    {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Kiloships API Key is missing. Please configure it in Settings > Kiloships Shipping.');
        }

        if (empty($tracking_number)) {
            return new WP_Error('missing_tracking', 'Tracking number is required.');
        }

        $response = wp_remote_request(
            $this->api_url . '/shipping-labels/domestic/' . urlencode($tracking_number),
            array(
                'method'  => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Network error: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code >= 400) {
            $error_message = 'API Error';

            if (isset($data['message'])) {
                $error_message = $data['message'];
            } elseif (isset($data['error']['message'])) {
                $error_message = $data['error']['message'];
            }

            if ($http_code === 401) {
                $error_message = 'Unauthorized: Invalid API Key';
            } elseif ($http_code === 404) {
                $error_message = 'Label not found or already cancelled';
            } elseif ($http_code === 429) {
                $error_message = 'Rate limit exceeded: Please try again later';
            }

            return new WP_Error('api_error', $error_message, $data);
        }

        return $data;
    }

    /**
     * Get organization balance.
     *
     * @return float|WP_Error Balance or error.
     */
    public function get_balance()
    {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Kiloships API Key is missing.');
        }

        $response = wp_remote_get(
            $this->api_url . '/organizations/balance',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['currentBalance'])) {
            return $data['currentBalance'];
        }

        return new WP_Error('api_error', 'Could not retrieve balance.', $data);
    }

    /**
     * Lookup City/State by Zip Code.
     *
     * @param string $zip_code Zip Code.
     * @return array|WP_Error Response data or error.
     */
    public function lookup_city_state($zip_code)
    {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Kiloships API Key is missing.');
        }

        $response = wp_remote_get(
            $this->api_url . '/addresses/city-state?zipCode=' . urlencode($zip_code),
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['city']) && isset($data['state'])) {
            return $data;
        }

        return new WP_Error('api_error', 'Could not lookup city/state.', $data);
    }

    /**
     * Standardize Address.
     *
     * @param array $address Address data.
     * @return array|WP_Error Response data or error.
     */
    public function standardize_address($address)
    {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'Kiloships API Key is missing.');
        }

        $response = wp_remote_post(
            $this->api_url . '/addresses/address',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($address),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['address'])) {
            return $data['address'];
        }

        return new WP_Error('api_error', 'Could not standardize address.', $data);
    }
}
