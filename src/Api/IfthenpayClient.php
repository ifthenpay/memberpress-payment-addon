<?php

namespace ifthenpay\MemberPress\Api;

/**
 * ifthenpay API client for MemberPress integration.
 * Handles profile fetch and pay-by-link generation.
 */
final class IfthenpayClient {

	/**
	 * Base URL for ifthenpay API
	 */
	private const BASE = 'https://api.ifthenpay.com';

	/**
	 * @var string ifthenpay backoffice key
	 */
	private string $backoffice_key;

	/**
	 * @var string ifthenpay API token
	 */
	private string $api_token;

	/**
	 * @var bool Use sandbox environment
	 */
	private bool $sandbox;

	/**
	 * Constructor
	 *
	 * @param string $backoffice_key
	 * @param string $api_token
	 * @param bool   $sandbox
	 */
	public function __construct( string $backoffice_key, string $api_token, bool $sandbox = false ) {
		$this->backoffice_key = $backoffice_key;
		$this->api_token      = $api_token;
		$this->sandbox        = $sandbox;
	}

	/**
	 * Fetch ifthenpay profile data for MemberPress.
	 *
	 * @return array Raw profile data on success.
	 * @throws \RuntimeException on transport/HTTP/JSON errors or if profile not found.
	 */
	public function get_data(): array {
		$url = self::BASE . '/v2/cmsintegration/'
			. ( $this->sandbox ? 'sandbox/' : '' )
			. 'get/' . rawurlencode( $this->api_token ) . '/memberpress';

		$data = $this->decode_or_fail(
			wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array(),
					'body'    => null,
				)
			),
			'ifthenpay | Payment Gateway: Failed retrieving profile'
		);
		return $data;
	}

	/**
	 * Generate a pay-by-link using the ifthenpay API.
	 *
	 * @param string $gateway_key The gateway key for ifthenpay.
	 * @param object $payload The payload object to send (must be stdClass or similar).
	 * @return string Redirect URL on success.
	 * @throws \RuntimeException on transport/HTTP/JSON errors or invalid API response.
	 */
	public function generate_pay_by_link( string $gateway_key, object $payload ): string {
		$url = self::BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key );

		$data = $this->decode_or_fail(
			wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			),
			'Failed to generate pay-by-link'
		);

		if ( ! isset( $data['PinCode'] ) && ! isset( $data['PinpayUrl'] ) && ! isset( $data['RedirectUrl'] ) ) {
			throw new \RuntimeException( 'Invalid response from ifthenpay: missing payment link.' );
		}
		return $data['RedirectUrl'];
	}

	/**
	 * Activates the callback for a specific gateway context.
	 *
	 * This method sends a POST request to the ifthenpay API to activate a callback for the provided gateway key.
	 * The payload includes:
	 *  - apKey: The base64-encoded gateway key.
	 *  - chave: The raw gateway key.
	 *  - urlCb: The callback URL template, which contains placeholders for order and payment details.
	 *
	 * The API is expected to respond with HTTP 200 and a plain text body:
	 *  - "OK" indicates successful activation.
	 *  - "INVALID" or any other response indicates failure.
	 *
	 * @param string $gateway_key The ifthenpay Gateway Key.
	 * @param string $wbk_url The base callback URL template with placeholders.
	 * @return bool Returns true if activation is successful ("OK"), false otherwise.
	 * @throws \RuntimeException on transport/HTTP/JSON errors or invalid API response.
	 */
	public function activate_callback_by_gateway_context( string $gateway_key, string $wbk_url ): bool {
		$url = self::BASE . '/endpoint/callback/activation/?cms=memberpress';

		$payload = array(
			'apKey' => base64_encode( $gateway_key ),
			'chave' => $gateway_key,
			'urlCb' => IfthenpayHelper::append_query(
				$wbk_url,
				'ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]'
			),
		);

		$res = $this->decode_or_fail(
			wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			),
			'Failed to activate callback'
		);

		return (string) $res['data'] === 'OK';
	}

	/**
	 * Sends a refund request to the ifthenpay API.
	 *
	 * @param object $payload The payload containing refund details.
	 * @return array The decoded response data from the API.
	 * @throws Exception If the API request fails or the response cannot be decoded.
	 */
	public function request_refund( object $payload ): array {
		$url = self::BASE . '/v2/payments/refund';

		$data = $this->decode_or_fail(
			wp_remote_post(
				$url,
				array(
					'timeout' => 25,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			),
			'Failed to request refund'
		);

		return $data;
	}

	/**
	 * Decode a WordPress HTTP response or throw on error.
	 *
	 * @param mixed  $resp WP HTTP response
	 * @param string $context Error context for exception
	 * @return array Decoded response data
	 * @throws \RuntimeException on error
	 */
	private function decode_or_fail( $resp, string $context ): array {
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		$base_error = sprintf( '%s | HTTP Error %d | Response: %s', $context, $code, trim( mb_substr( $body, 0, 300 ) ) );

		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( sprintf( '%s | WP Error: %s', $base_error, $resp->get_error_message() ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( $base_error );
		}

		$data = json_decode( $body, true );
		if ( is_null( $data ) && json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( sprintf( '%s | Invalid JSON response', $base_error ) );
		}

		return is_array( $data ) ? $data : array( 'data' => $data );
	}
}
