<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax\Services;

/**
 * Class RefundTokenService
 * Handles generation and verification of time-based refund codes.
 *
 * @package Ifthenpay\MemberPress\Ajax\Services
 */
final class RefundTokenService {

	/** @var string Secret key for HMAC */
	private string $secret;
	/** @var int Time period for code validity (seconds) */
	private int $period;

	/**
	 * RefundTokenService constructor.
	 *
	 * @param string $secret Secret key
	 * @param int    $period Time period in seconds
	 */
	public function __construct( string $secret = 'secret', int $period = 300 ) {
		$this->secret = $secret;
		$this->period = $period;
	}

	/**
	 * Generate a time-based code for a transaction.
	 *
	 * @param string $txnId Transaction ID
	 * @return string 6-digit code
	 */
	public function generate( string $txn_id ): string {
		$counter = intdiv( time(), $this->period );
		$msg     = $txn_id . ':' . $counter;
		$digest  = hash_hmac( 'sha256', $msg, $this->secret, true );
		$offset  = ord( $digest[ strlen( $digest ) - 1 ] ) & 0x0F;
		$chunk   = substr( $digest, $offset, 4 );
		$num     = unpack( 'N', $chunk )[1] & 0x7fffffff;
		return str_pad( (string) ( $num % 1_000_000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Verify a user-provided code for a transaction.
	 * Accepts codes from previous, current, and next time window.
	 *
	 * @param string $txnId    Transaction ID
	 * @param string $userCode Code entered by user
	 * @return bool True if valid, false otherwise
	 */
	public function verify( string $txn_id, string $user_code ): bool {
		$now_counter = intdiv( time(), $this->period );
		for ( $i = -1; $i <= 1; $i++ ) {
			$msg    = $txn_id . ':' . ( $now_counter + $i );
			$digest = hash_hmac( 'sha256', $msg, $this->secret, true );
			$offset = ord( $digest[ strlen( $digest ) - 1 ] ) & 0x0F;
			$chunk  = substr( $digest, $offset, 4 );
			$num    = unpack( 'N', $chunk )[1] & 0x7fffffff;
			$code   = str_pad( (string) ( $num % 1_000_000 ), 6, '0', STR_PAD_LEFT );
			if ( hash_equals( $code, $user_code ) ) {
				return true;
			}
		}
		return false;
	}
}
