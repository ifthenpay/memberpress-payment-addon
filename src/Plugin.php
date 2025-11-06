<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress;

use Ifthenpay\MemberPress\Ajax\Controller as AjaxController;
use Ifthenpay\MemberPress\Repository\IfthenpayTxnRepository;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

/**
 * Plugin bootstrapper for ifthenpay MemberPress integration.
 *
 * - Ensures DB infrastructure (idempotent)
 * - Registers MemberPress gateway
 * - Loads admin assets and translations
 */
final class Plugin {

	/** @var bool Guard to make boot idempotent */
	private bool $booted = false;

	/** Repo + Ajax controllers (DI-friendly) */
	private IfthenpayTxnRepository $txRepo;
	private AjaxController $ajax;

	public function __construct(
		?IfthenpayTxnRepository $txRepo = null,
		?AjaxController $ajax = null
	) {
		$this->txRepo = $txRepo ?: new IfthenpayTxnRepository();
		$this->ajax   = $ajax ?: new AjaxController();
	}

	/**
	 * Entry point; call from the main plugin file after checking MemberPress is active.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Infra first (idempotent)
		$this->txRepo->maybe_install();

		// Register all hooks
		$this->register_hooks();
	}

	/**
	 * Register all plugin hooks.
	 */
	private function register_hooks(): void {
		// MemberPress gateway discovery
		add_filter( 'mepr-gateway-paths', array( $this, 'add_mepr_gateway_paths' ) );

		// Admin assets for transactions page
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_transactions_assets' ) );

		// AJAX routes (admin)
		add_action( 'wp_ajax_iftp_mepr_refund_send_token', array( $this->ajax, 'send_refund_token' ) );
		add_action( 'wp_ajax_iftp_mepr_refund_verify_token', array( $this->ajax, 'verify_refund_token' ) );
		add_action( 'wp_ajax_iftp_mepr_refund_and_cancel_modal', array( $this->ajax, 'show_refund_and_cancel_modal' ) );
		add_action( 'wp_ajax_iftp_mepr_refund_amount', array( $this->ajax, 'get_refund_amount' ) );
		add_action( 'wp_ajax_iftp_mepr_set_refund_amount', array( $this->ajax, 'set_refund_amount' ) );
	}

	/**
	 * Add our gateway folder to MemberPress gateway discovery.
	 *
	 * @param array<int,string> $paths
	 * @return array<int,string>
	 */
	public function add_mepr_gateway_paths( array $paths ): array {
		$paths[] = IFTP_MP_PATH . '/src/Gateway';
		return $paths;
	}

	/**
	 * Enqueue admin JS and CSS assets for the Transactions page,
	 * passing gateway info and i18n strings.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_transactions_assets( string $hook_suffix ): void {
		if ( empty( $_GET['page'] ) || $_GET['page'] !== 'memberpress-trans' ) {
			return;
		}

		list($gateway_id, $gateway_label) = $this->find_ifthenpay_gateway();

		$script_handle = 'iftp-mp-refund-guard';
		$style_handle  = 'iftp-mp-refund-guard-css';

		// CSS (modal styles etc.)
		wp_enqueue_style( $style_handle, IFTP_MP_CSS_URL . '/refund-guard.css', array(), IFTP_MP_VERSION );

		// JS (modular order)
		wp_enqueue_script( 'iftp-mp-refund-guard-utils', IFTP_MP_JS_URL . '/refund-guard-utils.js', array( 'jquery' ), IFTP_MP_VERSION, true );
		wp_enqueue_script( 'iftp-mp-refund-guard-ajax', IFTP_MP_JS_URL . '/refund-guard-ajax.js', array( 'jquery', 'iftp-mp-refund-guard-utils' ), IFTP_MP_VERSION, true );
		wp_enqueue_script( 'iftp-mp-refund-guard-modals', IFTP_MP_JS_URL . '/refund-guard-modals.js', array( 'jquery', 'iftp-mp-refund-guard-utils' ), IFTP_MP_VERSION, true );
		wp_enqueue_script( 'iftp-mp-refund-guard-core', IFTP_MP_JS_URL . '/refund-guard-core.js', array( 'jquery', 'iftp-mp-refund-guard-modals', 'iftp-mp-refund-guard-utils', 'iftp-mp-refund-guard-ajax' ), IFTP_MP_VERSION, true );
		wp_enqueue_script( $script_handle, IFTP_MP_JS_URL . '/refund-guard.js', array( 'jquery', 'iftp-mp-refund-guard-core' ), IFTP_MP_VERSION, true );

		// Exact text as shown in the "Gateway" column
		$cell_text = $gateway_label ? "{$gateway_label} (ifthenpay | Payment Gateway)" : null;

		// i18n strings
		$i18n = array(
			'title'                  => __( 'Refund verification', 'ifthenpay-payments-for-memberpress' ),
			'prompt'                 => __( 'Please enter the verification code sent to your email', 'ifthenpay-payments-for-memberpress' ),
			'after_submit_notice'    => __( 'After you successfully submit the code, the refund process will start automatically. To confirm the results, refresh this page to see the updated statuses of the refunded transactions.', 'ifthenpay-payments-for-memberpress' ),
			'confirm'                => __( 'Confirm', 'ifthenpay-payments-for-memberpress' ),
			'cancel'                 => __( 'Cancel', 'ifthenpay-payments-for-memberpress' ),
			'error_network'          => __( 'Network error. Please try again.', 'ifthenpay-payments-for-memberpress' ),
			'error_send'             => __( 'Could not send code', 'ifthenpay-payments-for-memberpress' ),
			'error_invalid'          => __( 'Invalid or expired code', 'ifthenpay-payments-for-memberpress' ),
			'error_no_code'          => __( 'No code entered', 'ifthenpay-payments-for-memberpress' ),
			'error_cancelled'        => __( 'Action cancelled.', 'ifthenpay-payments-for-memberpress' ),
			'error_txns'             => __( 'Could not fetch transactions.', 'ifthenpay-payments-for-memberpress' ),
			'error_amount'           => __( 'Could not fetch refund amount.', 'ifthenpay-payments-for-memberpress' ),
			'error_route'            => __( 'Unknown AJAX route.', 'ifthenpay-payments-for-memberpress' ),
			'consent_title'          => __( 'Refund and Cancel Consent', 'ifthenpay-payments-for-memberpress' ),
			'consent_desc'           => __( 'Please review the remaining transactions that will be affected:', 'ifthenpay-payments-for-memberpress' ),
			'consent_none'           => __( 'No transactions found.', 'ifthenpay-payments-for-memberpress' ),
			'consent_ok'             => __( 'Proceed', 'ifthenpay-payments-for-memberpress' ),
			'consent_cancel'         => __( 'Cancel', 'ifthenpay-payments-for-memberpress' ),
			'consent_th_id'          => __( 'ID', 'ifthenpay-payments-for-memberpress' ),
			'consent_th_transaction' => __( 'Transaction', 'ifthenpay-payments-for-memberpress' ),
			'consent_th_starts'      => __( 'Starts', 'ifthenpay-payments-for-memberpress' ),
			'consent_th_ends'        => __( 'Ends', 'ifthenpay-payments-for-memberpress' ),
			'consent_th_amount'      => __( 'Amount', 'ifthenpay-payments-for-memberpress' ),
			'consent_total_label'    => __( 'Total Refunded:', 'ifthenpay-payments-for-memberpress' ),
			'amount_title'           => __( 'Select Refund Amount', 'ifthenpay-payments-for-memberpress' ),
			'amount_desc_single'     => __( 'Enter the amount to refund for this transaction (up to its full amount).', 'ifthenpay-payments-for-memberpress' ),
			'amount_desc_mass'       => __( 'Enter the total amount you wish to refund across the remaining transactions (up to the displayed total).', 'ifthenpay-payments-for-memberpress' ),
			'amount_input_label'     => __( 'Refund amount', 'ifthenpay-payments-for-memberpress' ),
			'amount_confirm'         => __( 'Set Amount', 'ifthenpay-payments-for-memberpress' ),
			'amount_cancel'          => __( 'Cancel', 'ifthenpay-payments-for-memberpress' ),
			'amount_error_empty'     => __( 'Please enter a refund amount.', 'ifthenpay-payments-for-memberpress' ),
			'amount_error_invalid'   => __( 'Enter a valid positive number.', 'ifthenpay-payments-for-memberpress' ),
			'amount_error_exceeds'   => __( 'Amount exceeds the allowed maximum.', 'ifthenpay-payments-for-memberpress' ),
		);

		// Localize everything the JS needs: ajax info + routes + gateway + i18n
		wp_localize_script(
			$script_handle,
			'IFTP_REFUND_GUARD',
			array(
				'ajax'    => array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'iftp_refund_nonce' ),
					'routes' => array(
						'send'       => 'iftp_mepr_refund_send_token',
						'verify'     => 'iftp_mepr_refund_verify_token',
						'txns_modal' => 'iftp_mepr_refund_and_cancel_modal',
						'amount'     => 'iftp_mepr_refund_amount',
						'set_amount' => 'iftp_mepr_set_refund_amount',
					),
				),
				'gateway' => array(
					'id'   => $gateway_id,
					'cell' => $cell_text,
				),
				'i18n'    => $i18n,
				'brand'   => array(
					'logo' => IFTP_MP_IMAGES_URL . '/ifthenpay_symbol.svg',
				),
			)
		);
	}

	/**
	 * Helper to find the ifthenpay gateway id and label from MemberPress options.
	 *
	 * @return array [id, label]
	 */
	private function find_ifthenpay_gateway(): array {
		$mepr_options = get_option( 'mepr_options' );
		if ( ! empty( $mepr_options['integrations'] ) && is_array( $mepr_options['integrations'] ) ) {
			foreach ( $mepr_options['integrations'] as $id => $integration ) {
				if ( ! empty( $integration['gateway'] ) && $integration['gateway'] === 'MeprIfthenpayGateway' ) {
					return array( $id, $integration['label'] ?? 'ifthenpay | Payment Gateway' );
				}
			}
		}
		return array( null, null );
	}
}
