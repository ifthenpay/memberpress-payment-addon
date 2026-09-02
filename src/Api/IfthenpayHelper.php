<?php

namespace Ifthenpay\MemberPress\Api;

use MeprOptions;
use MeprTransaction;

/**
 * Helper for ifthenpay MemberPress integration: payload building and profile formatting.
 */
final class IfthenpayHelper {

	/**
	 * Format a profile array by decoding JSON fields (jsonData, metadata, paymentData, shippingData).
	 *
	 * Decodes each field if it exists and contains valid JSON, replacing the string with the decoded array.
	 *
	 * @param array $profile Raw profile array from API.
	 * @return array Profile with decoded fields (if present and valid JSON).
	 */
	public static function format_profile( array $profile ): array {
		$jsonFields = array( 'jsonData', 'metadata', 'paymentData', 'shippingData' );

		foreach ( $jsonFields as $field ) {
			if ( ! isset( $profile[ $field ] ) || ! is_string( $profile[ $field ] ) ) {
				continue;
			}

			$decoded = json_decode( $profile[ $field ], true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				$profile[ $field ] = $decoded;
			}
		}

		return $profile;
	}

	/**
	 * Create payload object for ifthenpay API from profile and transaction.
	 *
	 * @param array           $profile Profile data from ifthenpay integrations API.
	 * @param MeprTransaction $txn MemberPress transaction.
	 * @return object Payload for ifthenpay API.
	 * @throws \InvalidArgumentException If required data is missing.
	 */
	public static function build_pay_by_link_payload( array $profile, MeprTransaction $txn, string $whk_url ): object {
		$opts = MeprOptions::fetch();
		if (
			empty( $profile['gatewayDescription'] ) ||
			! isset( $profile['accountKeys'], $profile['paymentData'] ) ||
			empty( $txn->id ) || empty( $txn->amount ) ||
			empty( $opts->thankyou_page_id ) || empty( $opts->account_page_id )
		) {
			throw new \InvalidArgumentException( 'Missing required data for payload.' );
		}

		return (object) array(
			'id'              => (string) $txn->trans_num,
			'amount'          => (string) $txn->amount,
			'description'     => isset( $profile['gatewayDescription'] ) && $profile['gatewayDescription'] !== ''
				// translators: 1: internal transaction ID, 2: gateway description string from profile.
				? sprintf( __( 'Transaction #%1$s – %2$s', 'ifthenpay-payments-for-memberpress' ), (string) $txn->id, (string) $profile['gatewayDescription'] )
				// translators: %s: internal transaction ID.
				: sprintf( __( 'Transaction #%s', 'ifthenpay-payments-for-memberpress' ), (string) $txn->id ),
			'lang'            => self::map_wp_locale_to_lang( get_locale() ),
			'expiredate'      => isset( $profile['expiryDays'] ) && $profile['expiryDays'] !== null ? self::compute_expire_ymd( (int) $profile['expiryDays'] ) : '',
			'accounts'        => (string) $profile['accountKeys'],
			'selected_method' => (string) $profile['paymentData']['defaultPaymentMethod'],
			'otp'			  => true,
			'success_url'     => self::page_url( $opts->thankyou_page_id, array( 'trans_num' => (string) $txn->trans_num ) ),
			'cancel_url'      => self::append_query( $whk_url, 'status=cancelled&ref=' . (string) $txn->trans_num ),
			'error_url'       => self::append_query( $whk_url, 'status=error&ref=' . (string) $txn->trans_num ),
		);
	}

	/**
	 * Get a WordPress page URL, optionally with query args.
	 *
	 * @param int|null $page_id
	 * @param array    $args
	 * @return string
	 */
	public static function page_url( $page_id, array $args = array() ): string {
		$url = $page_id ? get_permalink( (int) $page_id ) : home_url( '/' );
		return ! empty( $args ) ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Append a query string to a base URL, choosing the correct separator.
	 *
	 * Trims trailing "?", "&" and whitespace from the base URL first, so a
	 * base that already ends with a separator (or is repeatedly appended to)
	 * doesn't produce a doubled or dangling one. The separator is then chosen
	 * based on whether the base URL already carries a real query string.
	 *
	 * @param string $base  Base URL, with or without an existing query string.
	 * @param string $query Query string to append (without a leading separator).
	 * @return string Base URL with $query appended using "?" or "&" as needed.
	 */
	public static function append_query( string $base, string $query ): string {
		$base = rtrim( $base, "?& \t\n\r\0\x0B" );
		$sep  = wp_parse_url( $base, PHP_URL_QUERY ) ? '&' : '?';
		return $base . $sep . $query;
	}

	/**
	 * Map WP locale to ifthenpay language code.
	 *
	 * @param string $locale
	 * @return string
	 */
	private static function map_wp_locale_to_lang( string $locale ): string {
		return match ( strtolower( substr( $locale, 0, 2 ) ) ) {
			'pt', 'es', 'fr' => strtolower( substr( $locale, 0, 2 ) ),
			default => 'en'
		};
	}

	/**
	 * Compute expiry date in Ymd (UTC) for a given number of days.
	 * - 0 days: tomorrow's date (expires tomorrow at 00:00)
	 * - n days: date n+1 days from now (valid for n days, expires after n+1 days)
	 *
	 * @param int $days Number of days.
	 * @return string Ymd date (UTC).
	 */
	private static function compute_expire_ymd( int $days ): string {
		if ( $days === 0 ) {
			return gmdate( 'Ymd', time() + 86400 );
		}
		return gmdate( 'Ymd', time() + ( $days + 1 ) * 86400 );
	}

	/**
	 * Format a numeric amount with space as thousands separator and dot as decimal separator.
	 *
	 * Examples:
	 *  - 1001189.82 => "1 001 189.82"
	 *  - 1000 => "1 000.00" (when decimals = 2)
	 *
	 * @param int|float|string $amount Numeric value or numeric string.
	 * @param int              $decimals Number of decimal places to display (default 2).
	 * @return string Formatted amount. If non-numeric input is provided, it's cast to string and returned.
	 */
	public static function format_amount( $amount, int $decimals = 2 ): string {
		if ( ! is_numeric( $amount ) ) {
			return (string) $amount;
		}

		$decimals = max( 0, $decimals );
		$negative = (float) $amount < 0;
		$value    = abs( (float) $amount );

		// Use a regular space as thousands separator to match the example (can be changed to non-breaking if needed).
		$formatted = number_format( $value, $decimals, '.', ' ' );

		return $negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Filter an array of IfthenpayTxn records down to future periods only and pair them with their MemberPress transactions.
	 *
	 * Rules:
	 *  - Skip records with empty trans_num.
	 *  - Skip if the MemberPress transaction is expired (is_expired()).
	 *  - Skip if "now" is within [created_at, expires_at] (current active period).
	 *
	 * Returned shape: array of arrays, each inner array has keys:
	 *  - 'mepr' => MeprTransaction
	 *  - 'iftp' => IfthenpayTxn
	 *
	 * @param array<int,object> $iftp_txns Array of IfthenpayTxn-like objects (must expose ->trans_num).
	 * @return array<int,array{mepr:MeprTransaction,iftp:object}>
	 */
	public static function filter_future_period_pairs( array $iftp_txns ): array {
		$pairs  = array(); // trans_num => ['mepr'=>MeprTransaction,'iftp'=>IfthenpayTxn]
		$now_ts = time();
		foreach ( $iftp_txns as $tx ) {
			if ( ! is_object( $tx ) || empty( $tx->trans_num ) ) {
				continue;
			}
			$rec = \MeprTransaction::get_one_by_trans_num( $tx->trans_num );
			if ( ! $rec || empty( $rec->id ) ) {
				continue;
			}
			$mepr_txn = new \MeprTransaction( $rec->id );
			if ( method_exists( $mepr_txn, 'is_expired' ) && $mepr_txn->is_expired() ) {
				continue;
			}
			if ( self::is_current_active_period( $mepr_txn, $now_ts ) ) {
				continue; // Skip the current active period
			}
			$pairs[ (string) $tx->trans_num ] = array(
				'mepr' => $mepr_txn,
				'iftp' => $tx,
			);
		}
		return $pairs;
	}

	/**
	 * Determine if a MemberPress transaction represents the current active billing period.
	 *
	 * A period is considered active if the current timestamp falls within the inclusive
	 * range of its creation and expiration times: created_at <= now <= expires_at.
	 * Gracefully handles missing or unparsable dates by returning false.

	 * @param MeprTransaction $mepr_txn Transaction whose period is being evaluated.
	 * @param int|null        $now_ts Optional UNIX timestamp to use (primarily for testability); defaults to time().
	 * @return bool True if the transaction period is currently active; false otherwise.
	 */
	public static function is_current_active_period( MeprTransaction $mepr_txn, ?int $now_ts = null ): bool {
		$now_ts     = $now_ts ?? time();
		$created_ts = strtotime( (string) $mepr_txn->created_at );
		$expires_ts = strtotime( (string) $mepr_txn->expires_at );
		if ( ! $created_ts || ! $expires_ts ) {
			return false; // Missing bounds means we can't assert active window
		}
		return ( $created_ts <= $now_ts && $now_ts <= $expires_ts );
	}
}
