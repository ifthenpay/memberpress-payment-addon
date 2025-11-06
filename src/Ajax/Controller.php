<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax;

use MeprTransaction;
use Ifthenpay\MemberPress\Api\IfthenpayHelper;
use Ifthenpay\MemberPress\Ajax\DTO\RefundRequest;
use Ifthenpay\MemberPress\Ajax\DTO\TransactionRequest;
use Ifthenpay\MemberPress\Repository\DTO\IfthenpayTxn;
use Ifthenpay\MemberPress\Ajax\Services\RefundTokenService;
use Ifthenpay\MemberPress\Ajax\Services\RefundMailerService;
use Ifthenpay\MemberPress\Repository\IfthenpayTxnRepository;
use Ifthenpay\MemberPress\Ajax\Services\RefundPermissionService;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

/**
 * AJAX controller for refund-related endpoints (token send/verify, modal data).
 */
final class Controller {

	/** @var string Nonce action for AJAX security */
	private const NONCE_ACTION = 'iftp_refund_nonce';

	/** @var RefundMailerService */
	private RefundMailerService $mailer;
	/** @var RefundTokenService */
	private RefundTokenService $token;
	/** @var RefundPermissionService */
	private RefundPermissionService $guard;
	/** @var IfthenpayTxnRepository */
	private IfthenpayTxnRepository $repo;

	/** Constructor: instantiates services. */
	public function __construct() {
		$this->mailer = new RefundMailerService();
		$this->token  = new RefundTokenService();
		$this->guard  = new RefundPermissionService( self::NONCE_ACTION );
		$this->repo   = new IfthenpayTxnRepository();
	}

	/** Send refund verification code via email. */
	public function send_refund_token(): void {
		// Permission check
		$this->guard->verify_admin();

		// Request parsing & validation
		$req = RefundRequest::from_post( array( 'trans_num', 'trans_id' ) );
		if ( empty( $req->trans_num ) ) {
			wp_send_json_error( __( 'Missing transaction number.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Transaction lookup
		$mepr_txn = new MeprTransaction( $req->trans_id );
		$iftp_txn = $this->repo->get_one_by_trans_num( $req->trans_num );

		// Integrity: ensure requested trans_num matches the loaded MemberPress transaction.
		if ( (string) $mepr_txn->trans_num !== (string) $req->trans_num ) {
			wp_send_json_error( __( 'Transaction mismatch.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Prevent issuing a refund token for the currently active billing period
		if ( IfthenpayHelper::is_current_active_period( $mepr_txn ) ) {
			wp_send_json_error( __( 'The current active period cannot be refunded yet.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Payment method validation
		$allowed_methods = array( 'MBWAY', 'CCARD', 'GOOGLE', 'APPLE' );
		if ( ! in_array( $iftp_txn->pay_method, $allowed_methods, true ) ) {
				/* translators: %s: The payment method name that does not support refunds. */
				wp_send_json_error(
					sprintf(
						__( 'Refunds are not supported for this payment method: %s.', 'ifthenpay-payments-for-memberpress' ),
						$iftp_txn->pay_method
					),
					400
				);
		}

		// Token generation & email sending
		$code = $this->token->generate( $req->trans_num );
		$this->mailer->send_refund_token( $mepr_txn, $iftp_txn, $code );

		// Success response
		wp_send_json_success(
			array(
				'message'   => __( 'Verification code sent.', 'ifthenpay-payments-for-memberpress' ),
				'trans_num' => $req->trans_num,
			),
			200
		);
	}

	/** Verify a previously sent refund code. */
	public function verify_refund_token(): void {
		// Permission check
		$this->guard->verify_admin();

		// Request parsing & validation
		$req = RefundRequest::from_post( array( 'trans_num', 'code' ) );
		if ( empty( $req->trans_num ) || empty( $req->code ) ) {
			wp_send_json_error( __( 'Missing data.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Token verification
		$valid = $this->token->verify( $req->trans_num, $req->code );
		if ( ! $valid ) {
			wp_send_json_error( __( 'Invalid or expired code.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Success response
		wp_send_json_success(
			array(
				'message'   => __( 'Code verified.', 'ifthenpay-payments-for-memberpress' ),
				'trans_num' => $req->trans_num,
			),
			200
		);
	}

	/** Return refund/cancel modal data: eligible transactions + total. */
	public function show_refund_and_cancel_modal(): void {
		// Permission check
		$this->guard->verify_admin();

		// Parse and validate request
		$req = RefundRequest::from_post( array( 'trans_num', 'trans_id' ) );
		if ( empty( $req->trans_num ) || empty( $req->trans_id ) ) {
			wp_send_json_error( __( 'Missing transaction number or transaction ID.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}

		// Load transaction, subscription, and user
		$current_txn = new MeprTransaction( $req->trans_id );
		$sub         = $current_txn->subscription();
		$user        = $current_txn->user();

		// Fetch eligible paid transactions
		$paid_txns = $this->repo->list_by_user_or_sub( (int) $user->ID, (int) $sub->id, 0, 0, IfthenpayTxn::STATE_PAID );

		// Filter and build payload
		$pairs   = IfthenpayHelper::filter_future_period_pairs( $paid_txns );
		$payload = $this->build_refund_modal_payload( $pairs );

		wp_send_json_success( $payload, 200 );
	}

	/** Fetch single refund base amount (cap) for a transaction. */
	public function get_refund_amount(): void {
		// Permission check
		$this->guard->verify_admin();

		$req = RefundRequest::from_post( array( 'trans_num', 'trans_id' ) );
		if ( empty( $req->trans_num ) || empty( $req->trans_id ) ) {
			wp_send_json_error( __( 'Missing transaction number or transaction ID.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		$mepr_txn = new MeprTransaction( $req->trans_id );
		if ( (int) $mepr_txn->id <= 0 ) {
			wp_send_json_error( __( 'Transaction not found.', 'ifthenpay-payments-for-memberpress' ), 404 );
		}
		// Basic integrity: ensure trans_num matches
		if ( (string) $mepr_txn->trans_num !== (string) $req->trans_num ) {
			wp_send_json_error( __( 'Transaction mismatch.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		// Use MeprTransaction amount as cap (float)
		$amount_raw = (float) $mepr_txn->amount;
		if ( $amount_raw <= 0 ) {
			wp_send_json_error( __( 'Invalid amount.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		$formatted = '€' . IfthenpayHelper::format_amount( $amount_raw, 2 );
		wp_send_json_success(
			array(
				'amount'    => $amount_raw,
				'formatted' => $formatted,
				'trans_num' => $req->trans_num,
				'trans_id'  => $req->trans_id,
			),
			200
		);
	}

	/** Validate and temporarily store the user-chosen refund amount (single or mass).
	 * Context rules:
	 * - single: cap is the specific transaction amount
	 * - mass: cap is provided by frontend (sum of remaining future transactions already validated via modal payload)
	 */
	public function set_refund_amount(): void {
		// Permission check
		$this->guard->verify_admin();

		// Expect trans_num, trans_id (optional for mass) and chosen_amount
		$req = RefundRequest::from_post( array( 'trans_num', 'trans_id', 'chosen_amount', 'context', 'cap' ) );
		if ( empty( $req->trans_num ) || ! isset( $_POST['chosen_amount'] ) ) {
			wp_send_json_error( __( 'Missing transaction number or amount.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		$context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'single';

		$raw_input  = (string) $_POST['chosen_amount'];
		$normalized = str_replace( ',', '.', $raw_input );
		if ( ! is_numeric( $normalized ) ) {
			wp_send_json_error( __( 'Amount must be numeric.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		$amount = (float) $normalized;
		if ( $amount <= 0 ) {
			wp_send_json_error( __( 'Amount must be greater than zero.', 'ifthenpay-payments-for-memberpress' ), 400 );
		}
		// Align with frontend: truncate/round to 2 decimals (frontend enforces precision)
		$amount = round( $amount, 2 );

		if ( 'single' === $context ) {
			// Cap = actual transaction amount (trans_id must be present)
			if ( empty( $req->trans_id ) ) {
				wp_send_json_error( __( 'Missing transaction ID for single refund.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
			$mepr_txn = new MeprTransaction( $req->trans_id );
			if ( (int) $mepr_txn->id <= 0 ) {
				wp_send_json_error( __( 'Transaction not found.', 'ifthenpay-payments-for-memberpress' ), 404 );
			}
			$cap = (float) $mepr_txn->amount;
			if ( $amount > $cap ) {
				wp_send_json_error( __( 'Chosen amount exceeds transaction amount.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
		} else { // mass
			// Frontend supplies cap (sum of eligible transactions) for display & validation redundancy
			if ( ! isset( $_POST['cap'] ) ) {
				wp_send_json_error( __( 'Missing cap for mass refund.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
			$cap_raw = str_replace( ',', '.', (string) $_POST['cap'] );
			if ( ! is_numeric( $cap_raw ) ) {
				wp_send_json_error( __( 'Invalid cap value.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
			$cap = (float) $cap_raw;
			if ( $cap <= 0 ) {
				wp_send_json_error( __( 'Invalid cap value.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
			if ( $amount > $cap ) {
				wp_send_json_error( __( 'Chosen amount exceeds allowed total.', 'ifthenpay-payments-for-memberpress' ), 400 );
			}
		}

		// Store temporarily keyed by trans_num + current admin user ID
		$current_user = wp_get_current_user();
		// Simplified transient key name & reduced TTL (only needed briefly before final refund trigger)
		$key         = 'iftp_refund_amt_' . $current_user->ID;
		$ttl_seconds = MINUTE_IN_SECONDS; // Valid for ~1 minute (consumed immediately in final refund action)
		$payload     = array(
			'amount'    => $amount,
			'stored_at' => time(),
			'admin_id'  => $current_user->ID,
			'trans_num' => $req->trans_num,
			'trans_id'  => $req->trans_id,
			'context'   => $context,
			'cap'       => isset( $cap ) ? $cap : null,
		);
		set_transient( $key, $payload, $ttl_seconds );

		wp_send_json_success(
			array(
				'message'   => __( 'Refund amount stored.', 'ifthenpay-payments-for-memberpress' ),
				'amount'    => $amount,
				'cap'       => isset( $cap ) ? $cap : null,
				'context'   => $context,
				'trans_num' => $req->trans_num,
				'trans_id'  => $req->trans_id,
			),
			200
		);
	}

	/** Build modal payload with filtered transactions and formatted total.
	 *
	 * @param array<int,IfthenpayTxn> $remaining_txns
	 * @return array{txns:array<int,\stdClass>,total_amount:string}
	 */
	private function build_refund_modal_payload( array $future_pairs ): array {
		$txns  = array();
		$total = 0.0;
		foreach ( $future_pairs as $trans_num => $pair ) {
			$mepr_txn = $pair['mepr'];
			$txns[]   = new TransactionRequest(
				(int) $mepr_txn->id,
				(string) $mepr_txn->trans_num,
				(float) $mepr_txn->amount,
				(string) $mepr_txn->created_at,
				(string) $mepr_txn->expires_at
			);
			$total   += (float) $mepr_txn->amount;
		}
		return array(
			'txns'         => $txns,
			'total_amount' => '€' . IfthenpayHelper::format_amount( $total, 2 ),
		);
	}
}
