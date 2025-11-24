<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax\Services;

/**
 * Class RefundPermissionService
 * Handles permission checks for refund AJAX actions.
 *
 * @package Ifthenpay\MemberPress\Ajax\Services
 */
final class RefundPermissionService {

	/** @var string Nonce action for AJAX security */
	private string $nonceAction;

	/**
	 * RefundPermissionService constructor.
	 *
	 * @param string $nonceAction Nonce action name
	 */
	public function __construct( string $nonceAction ) {
		$this->nonceAction = $nonceAction;
	}

	/**
	 * Verify current user is admin and nonce is valid.
	 *
	 * @return void
	 */
	public function verify_admin(): void {
		// Verify nonce for AJAX security
		check_ajax_referer( $this->nonceAction );
		// Check if user has admin capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'ifthenpay-payments-for-memberpress' ), 403 );
		}
	}
}
