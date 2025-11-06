<?php

use Ifthenpay\MemberPress\Api\IfthenpayClient;
use Ifthenpay\MemberPress\Api\IfthenpayHelper;
use Ifthenpay\MemberPress\Repository\DTO\IfthenpayTxn;
use Ifthenpay\MemberPress\Repository\IfthenpayTxnRepository;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

class MeprIfthenpayGateway extends MeprBaseRealGateway {

	// Constants representing refund status codes:
	const REFUND_STATUS_SUCCESS  = 1;
	const REFUND_STATUS_FAILED   = 0;
	const REFUND_STATUS_NO_FUNDS = -1;
	// Debounce cooldown for owner refund+cancel requests (in seconds)
	const CANCEL_REQUEST_COOLDOWN = 24 * HOUR_IN_SECONDS;

	/**
	 * The ifthenpay API client instance.
	 *
	 * @var IfthenpayClient|null
	 */
	private ?IfthenpayClient $client = null;

	/**
	 * The repository for ifthenpay transactions.
	 *
	 * @var IfthenpayTxnRepository|null
	 */
	private ?IfthenpayTxnRepository $repo = null;

	public $name;
	public $desc;
	public $icon;
	public $key;

	public function __construct() {
		$this->name = 'ifthenpay | Payment Gateway';
		$this->desc = __( 'Pay securely with your preferred method — including cards, MB WAY, Multibanco, Payshop, and more. Fast, safe, and protected by ifthenpay.', 'ifthenpay-payments-for-memberpress' );
		$this->icon = IFTP_MP_IMAGES_URL . '/ifthenpay_brand.png';
		$this->key  = 'ifthenpay';

		$this->capabilities = array(
			'process-payments',
			'process-refunds',
			'create-subscriptions',
			'cancel-subscriptions',
			'update-subscriptions',
			'multiple-subscriptions',
			'subscription-trial-payment',
			// 'suspend-subscriptions',
			// 'resume-subscriptions',
			// 'send-cc-expirations',
		);
		$this->notifiers     = array(
			'whk'   => 'webhook_handler',
			'renew' => 'renew_handler',
		); // notify_url for ifthenpay webhooks
		$this->message_pages = array();

		$this->set_defaults(); // also initializes services (if creds exist)
	}

	public function load( $settings ) {
		$this->settings = (object) $settings;
		$this->set_defaults(); // re-init services based on saved creds
	}

	protected function set_defaults() {
		$defaults = array(
			'gateway'        => 'MeprIfthenpayGateway',
			'id'             => $this->generate_id(),
			'label'          => '',
			'use_label'      => true,
			'icon'           => IFTP_MP_IMAGES_URL . '/ifthenpay_gateway.png',
			'use_icon'       => true,
			'use_desc'       => true,
			'force_ssl'      => false,
			'debug'          => false,
			'test_mode'      => true,
			'backoffice_key' => '',
			'api_token'      => '',
		);

		$this->settings  = (object) array_merge( $defaults, (array) ( $this->settings ?? array() ) );
		$this->id        = $this->settings->id;
		$this->label     = $this->settings->label;
		$this->use_label = $this->settings->use_label;
		$this->use_icon  = $this->settings->use_icon;
		$this->use_desc  = $this->use_desc ?? $this->settings->use_desc;

		// Initialize services if credentials exist
		$this->client = ( $this->settings->backoffice_key !== '' && $this->settings->api_token !== '' )
			? new IfthenpayClient( $this->settings->backoffice_key, $this->settings->api_token )
			: null;

		$this->repo = ( $this->settings->backoffice_key !== '' && $this->settings->api_token !== '' )
			? new IfthenpayTxnRepository()
			: null;
	}

	/** Displays the options form for the ifthenpay payment gateway integration. */
	public function display_options_form() {
		$mepr_options = MeprOptions::fetch();
		$ns           = $mepr_options->integrations_str;
		$id           = $this->id;

		$backoffice_key = (string) ( $this->settings->backoffice_key ?? '' );
		$api_token      = (string) ( $this->settings->api_token ?? '' );
		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th><label for="ifthenpay-backoffice-key"><?php _e( 'Backoffice Key', 'ifthenpay-payments-for-memberpress' ); ?></label></th>
					<td>
						<input id="ifthenpay-backoffice-key" type="text" class="regular-text"
							name="<?php echo esc_attr( "{$ns}[{$id}][backoffice_key]" ); ?>"
							value="<?php echo esc_attr( $backoffice_key ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th><label for="ifthenpay-api-token"><?php _e( 'API Token', 'ifthenpay-payments-for-memberpress' ); ?></label></th>
					<td>
						<input id="ifthenpay-api-token" type="text" class="regular-text"
							name="<?php echo esc_attr( "{$ns}[{$id}][api_token]" ); ?>"
							value="<?php echo esc_attr( $api_token ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Webhook URL', 'ifthenpay-payments-for-memberpress' ); ?></th>
					<td><?php echo MeprAppHelper::clipboard_input( $this->notify_url( 'whk' ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Validates the submitted options form for the ifthenpay payment gateway.
	 *
	 * Ensures Backoffice Key and API Token are present, checks credentials via API,
	 * and activates the callback URL. Appends error messages to $errors if validation fails.
	 *
	 * @param array $errors Array of error messages.
	 * @return array Updated error messages.
	 */
	public function validate_options_form( $errors ) {
		$mepr_options = MeprOptions::fetch();
		$req          = $_REQUEST[ $mepr_options->integrations_str ][ $this->id ];

		$backoffice_key = (string) $req['backoffice_key'];
		$api_token      = (string) $req['api_token'];

		// Basic validation
		if ( $backoffice_key === '' || $api_token === '' ) {
			$errors[] = __( 'Backoffice Key and API Token are required.', 'ifthenpay-payments-for-memberpress' );
			return $errors;
		}

		try {
			$client  = new IfthenpayClient( $backoffice_key, $api_token );
			$profile = IfthenpayHelper::format_profile( $client->get_data() );

			// Basic sanity checks on ifthenpay integration form
			if (
				empty( $profile['backofficeKey'] ) || empty( $profile['gatewayKey'] ) || empty( $profile['accountKeys'] ) ||
				strcasecmp( $profile['backofficeKey'], $backoffice_key ) !== 0
			) {
				$errors[] = __( 'Invalid MemberPress profile configuration.', 'ifthenpay-payments-for-memberpress' );
				return $errors;
			}

			// Activate webhook URL for ifthenpay IPNs
			$callback = $client->activate_callback_by_gateway_context( $profile['gatewayKey'], $this->notify_url( 'whk' ) );
			if ( ! $callback ) {
				$errors[] = __( 'Failed to activate ifthenpay callback.', 'ifthenpay-payments-for-memberpress' );
			}
		} catch ( \Throwable $e ) {
			$errors[] = $e->getMessage();
		}

		return $errors;
	}

	/* Methods triggered by checkout process */

	public function process_payment( $txn ) {
		$usr          = $txn->user();
		$prd          = $txn->product();
		$sub          = $txn->subscription();
		$mepr_options = MeprOptions::fetch();

		if ( $this->client === null || $this->repo === null ) {
			throw new MeprGatewayException( __( 'ifthenpay is not configured. Save Backoffice Key and API Token first.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( ! $prd->is_one_time_payment() ) {
			throw new MeprGatewayException( __( 'This gateway currently supports one-time payments only.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( $mepr_options->currency_code !== 'EUR' ) {
			throw new MeprGatewayException( __( 'This gateway only supports EUR currency.', 'ifthenpay-payments-for-memberpress' ) );
		}

		try {
			// 1) Pay-by-Link
			$profile = IfthenpayHelper::format_profile( $this->client->get_data() );
			$payload = IfthenpayHelper::build_pay_by_link_payload( $profile, $txn, $this->notify_url( 'whk' ) );
			$url     = $this->client->generate_pay_by_link( $profile['gatewayKey'], $payload );

			// 2) Store MP transaction
			$txn->store();

			// 3) Insert local pending record
			$this->repo->insert(
				new IfthenpayTxn(
					id: null,
					trans_num: (string) $txn->trans_num,
					user_id: (int) $usr->ID,
					product_id: (int) $prd->ID,
					sub_id: ( $sub instanceof MeprSubscription && isset( $sub->id ) ) ? (int) $sub->id : null, // No cast on the null branch.
					amount: $txn->amount,
					gateway_key: (string) $profile['gatewayKey'],
					redirect_url: $url,
					pay_method: null,  // Set later on "ifthenpay callback".
					request_id: null,  // Set later on "ifthenpay callback".
					state: IfthenpayTxn::STATE_PENDING
				)
			);
			return MeprUtils::wp_redirect( $url );
		} catch ( \Throwable $e ) {
			// translators: %s is the underlying exception message from ifthenpay API during payment processing.
			throw new MeprGatewayException( sprintf( __( 'ifthenpay | Payment Gateway: %s', 'ifthenpay-payments-for-memberpress' ), $e->getMessage() ) );
		}
	}

	public function process_trial_payment( $txn ) {
		// Get the subscription to access trial amount
		$sub = $txn->subscription();

		// Prepare the $txn for the process_payment method
		$txn->set_subtotal( $sub->trial_amount );
		$txn->status = MeprTransaction::$pending_str;

		// Attempt processing the payment here
		return $this->process_payment( $txn );
	}

	public function process_create_subscription( $txn ) {
		$usr          = $txn->user();
		$prd          = $txn->product();
		$sub          = $txn->subscription();
		$mepr_options = MeprOptions::fetch();

		if ( $this->client === null || $this->repo === null ) {
			throw new MeprGatewayException( __( 'ifthenpay is not configured. Save Backoffice Key and API Token first.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( $mepr_options->currency_code !== 'EUR' ) {
			throw new MeprGatewayException( __( 'This gateway only supports EUR currency.', 'ifthenpay-payments-for-memberpress' ) );
		}

		try {
			// 1) Pay-by-Link
			$profile = IfthenpayHelper::format_profile( $this->client->get_data() );
			$payload = IfthenpayHelper::build_pay_by_link_payload( $profile, $txn, $this->notify_url( 'whk' ) );
			$pbl_url = $this->client->generate_pay_by_link( $profile['gatewayKey'], $payload );

			// 2) Store MP transaction
			$txn->store();

			// 3) Insert local pending record
			$this->repo->insert(
				new IfthenpayTxn(
					id: null,
					trans_num: (string) $txn->trans_num,
					user_id: (int) $usr->ID,
					product_id: (int) $prd->ID,
					sub_id: ( $sub instanceof MeprSubscription && isset( $sub->id ) ) ? (int) $sub->id : null, // No cast on the null branch.
					amount: $txn->amount,
					gateway_key: (string) $profile['gatewayKey'],
					redirect_url: $pbl_url,
					pay_method: null,  // Set later on "ifthenpay callback".
					request_id: null,  // Set later on "ifthenpay callback".
					state: IfthenpayTxn::STATE_PENDING
				)
			);
			return MeprUtils::wp_redirect( $pbl_url );
		} catch ( \Throwable $e ) {
			// translators: %s is the underlying exception message from ifthenpay API during subscription creation payment link generation.
			throw new MeprGatewayException( sprintf( __( 'ifthenpay | Payment Gateway: %s', 'ifthenpay-payments-for-memberpress' ), $e->getMessage() ) );
		}
	}

	public function display_payment_page( $txn ) {
		$usr          = $txn->user();
		$prd          = $txn->product();
		$sub          = $txn->subscription();
		$mepr_options = MeprOptions::fetch();

		if ( $this->client === null || $this->repo === null ) {
			throw new MeprGatewayException( __( 'ifthenpay is not configured. Save Backoffice Key and API Token first.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( $mepr_options->currency_code !== 'EUR' ) {
			throw new MeprGatewayException( __( 'This gateway only supports EUR currency.', 'ifthenpay-payments-for-memberpress' ) );
		}

		try {
			// 1) Pay-by-Link
			$profile = IfthenpayHelper::format_profile( $this->client->get_data() );
			$payload = IfthenpayHelper::build_pay_by_link_payload( $profile, $txn, $this->notify_url( 'whk' ) );
			$pbl_url = $this->client->generate_pay_by_link( $profile['gatewayKey'], $payload );

			// 2) Store MP transaction
			$txn->store();

			// 3) Insert local pending record
			$this->repo->insert(
				new IfthenpayTxn(
					id: null,
					trans_num: (string) $txn->trans_num,
					user_id: (int) $usr->ID,
					product_id: (int) $prd->ID,
					sub_id: ( $sub instanceof MeprSubscription && isset( $sub->id ) ) ? (int) $sub->id : null, // No cast on the null branch.
					amount: $txn->amount,
					gateway_key: (string) $profile['gatewayKey'],
					redirect_url: $pbl_url,
					pay_method: null,  // Set later on "ifthenpay callback".
					request_id: null,  // Set later on "ifthenpay callback".
					state: IfthenpayTxn::STATE_PENDING
				)
			);
			return MeprUtils::wp_redirect( $pbl_url );
		} catch ( \Throwable $e ) {
			// translators: %s is the underlying exception message from ifthenpay API during display payment page link generation.
			throw new MeprGatewayException( sprintf( __( 'ifthenpay | Payment Gateway: %s', 'ifthenpay-payments-for-memberpress' ), $e->getMessage() ) );
		}
	}

	/**
	 * Displays the update account form for a subscription, allowing users to renew by paying for the next period.
	 *
	 * Shows subscription and billing details, or error messages if the gateway is not configured or the subscription is invalid.
	 *
	 * @param int    $subscription_id Subscription ID to update.
	 * @param array  $errors Optional. Error messages to display.
	 * @param string $message Optional. Success or info message to display.
	 * @return void
	 */
	public function display_update_account_form( $subscription_id, $errors = array(), $message = '' ) {
		if ( $this->client === null || $this->repo === null ) {
			$errors[] = __( 'The payment gateway is not configured. Please contact support.', 'ifthenpay-payments-for-memberpress' );
		}

		$sub = new MeprSubscription( (int) $subscription_id );
		if ( (int) $sub->id <= 0 ) {
			$errors[] = __( 'Invalid subscription.', 'ifthenpay-payments-for-memberpress' );
		}

		if ( ! empty( $errors ) ) {
			MeprView::render( '/shared/errors', get_defined_vars() );
			return;
		}

		$prd    = $sub->product();
		$amount = (float) $sub->price;
		['anchor_at' => $anchor_at, 'new_end_at' => $new_end_at, 'preserving' => $preserving] = $this->compute_anchor_and_end( $sub );

		$pbl_link = add_query_arg(
			array(
				'action'   => 'renew',
				'sub'      => (int) $sub->id,
				'_wpnonce' => wp_create_nonce( 'iftp_renew_' . (int) $sub->id ),
			),
			$this->notify_url( 'renew' )
		);

		$period_label = (int) $sub->period . ' ' . $sub->period_type;
		?>
		<section class="ifp-renew-card" style="max-width:720px;margin:32px auto 0 auto;padding:0;border:0;">
			<?php if ( ! empty( $message ) ) : ?>
				<div class="mepr-success" style="margin-bottom:24px;padding:10px 16px;border-radius:8px;font-size:14px;">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>
			<div style="border:1px solid #e5e7eb;border-radius:16px;padding:32px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.06);">
				<header style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
					<h3 style="margin:0;font-size:22px;line-height:1.3;font-weight:600;letter-spacing:-0.5px;">
						<?php echo esc_html( get_the_title( $prd->ID ) ); ?>
					</h3>
					<?php if ( $preserving ) : ?>
						<span style="font-size:12px;padding:5px 12px;border-radius:999px;background:#ecfdf5;color:#065f46;font-weight:500;">
							<?php esc_html_e( 'No time lost', 'ifthenpay-payments-for-memberpress' ); ?>
						</span>
					<?php endif; ?>
				</header>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px 32px;margin-bottom:24px;">
					<div>
						<div style="font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Price / period', 'ifthenpay-payments-for-memberpress' ); ?></div>
						<strong style="font-size:14px;vertical-align:middle;"><?php echo wp_kses_post( MeprAppHelper::format_currency( $amount ) ); ?></strong>
						<span style="color:#6b7280;font-size:12px;vertical-align:middle;"> / <?php echo esc_html( $period_label ); ?></span>
					</div>
					<div>
						<div style="font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Starts', 'ifthenpay-payments-for-memberpress' ); ?></div>
						<strong style="font-size:14px;vertical-align:middle;">
							<?php echo esc_html( MeprAppHelper::format_date( $anchor_at ) ); ?>
						</strong>
						<?php if ( ! $preserving ) : ?>
							<span style="color:#6b7280;font-size:12px;vertical-align:middle;margin-left:6px;">
								<?php esc_html_e( '(now)', 'ifthenpay-payments-for-memberpress' ); ?>
							</span>
						<?php endif; ?>
					</div>
					<div>
						<div style="font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Billing', 'ifthenpay-payments-for-memberpress' ); ?></div>
						<span style="font-size:14px;color:#374151;">
							<b>
								<?php
								printf(
									/* translators: 1: formatted amount, 2: period label like "2 weeks" */
									esc_html__( '%1$s charged each %2$s.', 'ifthenpay-payments-for-memberpress' ),
									MeprAppHelper::format_currency( $amount ),
									esc_html( $period_label )
								);
								?>
							</b>
						</span>
					</div>
					<div>
						<div style="font-size:12px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Ends', 'ifthenpay-payments-for-memberpress' ); ?></div>
						<strong style="font-size:14px;">
							<?php echo esc_html( MeprAppHelper::format_date( $new_end_at ) ); ?>
						</strong>
					</div>
				</div>
				<div style="display:flex;flex-direction:column;align-items:center;gap:6px;margin-top:8px;">
					<button
						type="button"
						class="button button-primary"
						style="display:inline-block;font-size:16px;padding:7px 24px;border-radius:6px;font-weight:500;min-width:180px;background:#00609c;color:#fff;border:none;box-shadow:0 2px 8px rgba(0,0,0,.07);outline:none;transition:background 0.2s,box-shadow 0.2s, border-color 0.2s;cursor:pointer;letter-spacing:0.01em;"
						aria-label="<?php esc_attr_e( 'Pay securely to renew your subscription', 'ifthenpay-payments-for-memberpress' ); ?>"
						onclick="window.location.href='<?php echo esc_url( $pbl_link ); ?>'"
						onmouseover="this.style.background='#00a7e2';this.style.color='#fff';"
						onmouseout="this.style.background='#00609c';this.style.color='#fff';">
						<?php esc_html_e( 'Pay securely to renew', 'ifthenpay-payments-for-memberpress' ); ?>
					</button>
					<p class="description" style="margin:0;color:#6b7280;font-size:12px;text-align:center;">
						<?php esc_html_e( 'After payment you’ll be returned automatically. If not, come back and refresh.', 'ifthenpay-payments-for-memberpress' ); ?>
					</p>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Computes the anchor and end dates for a given subscription.
	 *
	 * Determines the anchor date (the starting point for the next subscription period)
	 * and the new end date based on the subscription's period type (days, weeks, months, years).
	 * Also indicates whether the current expiration is being preserved.
	 *
	 * @param MeprSubscription $sub The subscription object to compute dates for.
	 * @return array {
	 *     @type string  $anchor_at   The anchor date in MySQL datetime format.
	 *     @type string  $new_end_at  The new end date in MySQL datetime format (set to 23:59:59).
	 *     @type bool    $preserving  Whether the expiration is being preserved (true) or reset (false).
	 * }
	 */
	private function compute_anchor_and_end( MeprSubscription $sub ): array {
		$now_ts     = time();
		$exp_txn    = $sub->expiring_txn();
		$exp_ts     = ( $exp_txn instanceof MeprTransaction && ! empty( $exp_txn->expires_at ) ) ? strtotime( $exp_txn->expires_at ) : 0;
		$anchor_ts  = ( $exp_ts > $now_ts ) ? $exp_ts : $now_ts;
		$anchor_at  = MeprUtils::ts_to_mysql_date( $anchor_ts );
		$preserving = ( $exp_ts > $now_ts );

		switch ( $sub->period_type ) {
			case 'weeks':
				$end_ts = $anchor_ts + MeprUtils::weeks( (int) $sub->period );
				break;
			case 'months':
				$end_ts = $anchor_ts + MeprUtils::months( (int) $sub->period, $anchor_ts, false, gmdate( 'j', strtotime( $sub->renewal_base_date ) ) );
				break;
			case 'years':
				$end_ts = $anchor_ts + MeprUtils::years( (int) $sub->period, $anchor_ts );
				break;
			default:
				$end_ts = $anchor_ts + MeprUtils::days( (int) $sub->period );
		}
		$new_end_at = MeprUtils::ts_to_mysql_date( $end_ts, 'Y-m-d 23:59:59' );
		return compact( 'anchor_at', 'new_end_at', 'preserving' );
	}

	/**
	 * Handles renewal requests: validates method, nonce, and subscription ID, then creates and finalizes the renewal transaction.
	 *
	 * @return void
	 */
	public function renew_handler(): void {
		// 1. Request method must be GET.
		if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) {
			MeprUtils::exit_with_status( 405, __( 'Method Not Allowed', 'ifthenpay-payments-for-memberpress' ) );
		}
		// 2. Basic param + nonce validation.
		$sub_id = isset( $_GET['sub'] ) ? (int) $_GET['sub'] : 0;
		$nonce  = $_GET['_wpnonce'] ?? '';
		if ( $sub_id <= 0 || ! wp_verify_nonce( $nonce, 'iftp_renew_' . $sub_id ) ) {
			MeprUtils::exit_with_status( 400, __( 'Invalid request.', 'ifthenpay-payments-for-memberpress' ) );
		}
		// 3. Delegate creation & finalization.
		$txn     = $this->process_update_subscription( $sub_id );
		$pbl_url = $this->record_update_subscription( $txn );
		MeprUtils::wp_redirect( $pbl_url );
	}

	public function process_update_subscription( $subscription_id ): MeprTransaction {
		$sub = new MeprSubscription( (int) $subscription_id );
		if ( (int) $sub->id <= 0 ) {
			MeprUtils::exit_with_status( 400, __( 'Invalid subscription.', 'ifthenpay-payments-for-memberpress' ) );
		}
		$usr = $sub->user();
		if ( ! current_user_can( 'manage_options' ) && (int) get_current_user_id() !== (int) $usr->ID ) {
			MeprUtils::exit_with_status( 403, __( 'Not allowed.', 'ifthenpay-payments-for-memberpress' ) );
		}

		$prd    = $sub->product();
		$amount = (float) $sub->price;
		['anchor_at' => $anchor_at, 'new_end_at' => $new_end_at] = $this->compute_anchor_and_end( $sub );

		$txn                  = new MeprTransaction();
		$txn->user_id         = (int) $usr->ID;
		$txn->product_id      = (int) $prd->ID;
		$txn->subscription_id = (int) $sub->id;
		$txn->gateway         = $this->id;
		$txn->status          = MeprTransaction::$pending_str;
		$txn->txn_type        = MeprTransaction::$payment_str;
		$txn->created_at      = $anchor_at; // keep anchor alignment
		$txn->expires_at      = $new_end_at;
		$txn->set_subtotal( $amount );
		$txn->store();
		return $txn;
	}

	public function record_update_subscription( MeprTransaction $txn = null ): string {
		if ( ! $txn instanceof MeprTransaction ) {
			MeprUtils::exit_with_status( 500, __( 'No pending transaction to finalize.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( $this->client === null || $this->repo === null ) {
			MeprUtils::exit_with_status( 500, __( 'Gateway not configured.', 'ifthenpay-payments-for-memberpress' ) );
		}
		try {
			$profile = IfthenpayHelper::format_profile( $this->client->get_data() );
			$payload = IfthenpayHelper::build_pay_by_link_payload( $profile, $txn, $this->notify_url( 'whk' ) );
			$pbl_url = $this->client->generate_pay_by_link( $profile['gatewayKey'], $payload );

			$usr = $txn->user();
			$prd = $txn->product();
			$sub = $txn->subscription();

			$this->repo->insert(
				new IfthenpayTxn(
					id: null,
					trans_num: (string) $txn->trans_num,
					user_id: (int) $usr->ID,
					product_id: (int) $prd->ID,
					sub_id: (int) $sub->id,
					amount: (float) $txn->amount,
					gateway_key: (string) $profile['gatewayKey'],
					redirect_url: $pbl_url,
					pay_method: null,
					request_id: null,
					state: IfthenpayTxn::STATE_PENDING
				)
			);
			return $pbl_url;
		} catch ( \Throwable $e ) {
			/* translators: %s: The error message returned when creating a payment link fails. */
			MeprUtils::exit_with_status( 502, sprintf( __( 'Could not create payment link: %s', 'ifthenpay-payments-for-memberpress' ), $e->getMessage() ) );
		}
	}

	/**
	 * Handle ifthenpay webhook GET requests ("Callback").
	 *
	 * Expects an empty body and specific query params and delegates to:
	 * - record_payment_failure() when 'status' and 'ref' are present.
	 * - record_payment() when 'ref','apk','val','mtd','req' are present.
	 *
	 * May exit with an HTTP status via MeprUtils::exit_with_status().
	 *
	 * @return mixed|null
	 */
	public function webhook_handler() {
		// Validate request method and body
		if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
			MeprUtils::exit_with_status( 405, __( 'Method Not Allowed', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( file_get_contents( 'php://input' ) !== '' ) {
			MeprUtils::exit_with_status( 401, __( 'Unauthorized', 'ifthenpay-payments-for-memberpress' ) );
		}

		// Handle different notification types
		if ( isset( $_GET['status'] ) && isset( $_GET['ref'] ) ) {
			return $this->record_payment_failure();
		}
		if ( isset( $_GET['ref'] ) && isset( $_GET['apk'] ) && isset( $_GET['val'] ) && isset( $_GET['mtd'] ) && isset( $_GET['req'] ) ) {
			return $this->record_payment();
		}

		// Unknown notification
		MeprUtils::exit_with_status( 400, __( 'Bad Request', 'ifthenpay-payments-for-memberpress' ) );
	}

	/**
	 * Validate webhook query params against the IfthenpayTxn DB record.
	 *
	 * @param IfthenpayTxn $iftp_txn
	 * @return bool
	 */
	private function is_valid_webhook_query( IfthenpayTxn $iftp_txn ): bool {
		// Validate amount
		if ( (float) $iftp_txn->amount !== (float) $_GET['val'] ) {
			return false;
		}

		// Validate gateway_key (reverse base64 of apk)
		$decoded = trim( base64_decode( $_GET['apk'] ) );
		if ( $iftp_txn->gateway_key !== $decoded ) {
			return false;
		}

		// Validate pay_method (mtd)
		$allowed_methods = array( 'MB', 'MBWAY', 'PAYSHOP', 'CCARD', 'COFIDIS', 'GOOGLE', 'APPLE' );
		if ( ! in_array( $_GET['mtd'], $allowed_methods, true ) && ! is_numeric( $_GET['mtd'] ) ) {
			return false;
		}

		return true;
	}

	public function record_payment_failure() {
		// Retrieve both local and MemberPress transactions
		$iftp_txn = $this->repo->get_one_by_trans_num( $_GET['ref'] );
		$mepr_txn = $this->get_mepr_transaction_by_trans_num( $_GET['ref'] );

		// Basic validation (single gate, includes the only allowed status values)
		if ( ! $iftp_txn || ! $mepr_txn || ( $_GET['status'] !== 'cancelled' && $_GET['status'] !== 'error' ) ) {
			MeprUtils::exit_with_status( 404, __( 'Not Found', 'ifthenpay-payments-for-memberpress' ) );
		}

		// Idempotency: if already finalized as failed both sides, short-circuit
		if (
			$mepr_txn->status === MeprTransaction::$failed_str &&
			( $iftp_txn->state === IfthenpayTxn::STATE_CANCELLED || $iftp_txn->state === IfthenpayTxn::STATE_FAILED )
		) {
			return $mepr_txn;
		}

		// Decide local state with a simple boolean
		$is_cancelled = ( $_GET['status'] === 'cancelled' );
		$new_state    = $is_cancelled ? IfthenpayTxn::STATE_CANCELLED : IfthenpayTxn::STATE_FAILED;

		// Update local record
		$iftp_txn = $iftp_txn->with_state( $new_state );
		$this->repo->update_by_trans_num( $iftp_txn->trans_num, $iftp_txn->to_db_array() );

		// Update MemberPress transaction to failed
		$mepr_txn->status = MeprTransaction::$failed_str;
		$mepr_txn->store();

		MeprUtils::send_failed_txn_notices( $mepr_txn );

		$opts = MeprOptions::fetch();
		return MeprUtils::wp_redirect( IfthenpayHelper::page_url( $opts->account_page_id ) );
	}

	public function record_payment() {
		// Retrieve both local and MemberPress transactions
		$iftp_txn = $this->repo->get_one_by_trans_num( $_GET['ref'] );
		$mepr_txn = $this->get_mepr_transaction_by_trans_num( $_GET['ref'] );

		// Basic validation
		if ( ! $iftp_txn || ! $mepr_txn || ! $this->is_valid_webhook_query( $iftp_txn ) ) {
			MeprUtils::exit_with_status( 404, __( 'Not Found', 'ifthenpay-payments-for-memberpress' ) );
		}
		// Just short circuit if the txn has already completed
		if ( ( $mepr_txn->status == MeprTransaction::$complete_str ) && ( $iftp_txn->state == IfthenpayTxn::STATE_PAID ) ) {
			return $mepr_txn;
		}

		// Update local record with payment details
		$iftp_txn = $iftp_txn
			->with_state( IfthenpayTxn::STATE_PAID )
			->with_pay_method( $_GET['mtd'] )
			->with_request_id( $_GET['req'] );
		$this->repo->update_by_trans_num( $iftp_txn->trans_num, $iftp_txn->to_db_array() );

		// Update MemberPress transaction and handle subscription logic
		$mepr_txn->status = MeprTransaction::$complete_str;
		$mepr_txn->store();
		$sub         = $mepr_txn->subscription();
		$sub->status = MeprSubscription::$active_str;
		$sub->store();

		$event_txn = $mepr_txn->maybe_cancel_old_sub();
		$prd       = $mepr_txn->product();

		if ( $prd->period_type == 'lifetime' ) {
			if ( $mepr_txn->is_upgrade() ) {
				$this->upgraded_sub( $mepr_txn, $event_txn );
			} elseif ( $mepr_txn->is_downgrade() ) {
				$this->downgraded_sub( $mepr_txn, $event_txn );
			} else {
				$this->new_sub( $mepr_txn );
			}

			MeprUtils::send_signup_notices( $mepr_txn );
		}

		MeprUtils::send_transaction_receipt_notices( $mepr_txn );
		return $mepr_txn;
	}




	/* Refunds and cancellations */

	public function process_refund( MeprTransaction $mepr_txn, IfthenpayTxn $iftp_txn = null, bool $skip_gateway_check = false, bool $skip_state_check = false ): void {
		if ( ! $skip_gateway_check && ( $this->repo === null || $this->client === null ) ) {
			throw new MeprGatewayException( __( 'Payment gateway not configured.', 'ifthenpay-payments-for-memberpress' ) );
		}
		// Allow caller to provide already-fetched gateway txn to avoid duplicate queries
		if ( ! $iftp_txn ) {
			$iftp_txn = $this->repo->get_one_by_trans_num( $mepr_txn->trans_num );
		}

		$this->validate_refund_pair( $mepr_txn, $iftp_txn, $skip_state_check );

		$store = $this->get_refund_balance_store();
		if ( ! empty( $store ) && $store['context'] === 'mass' ) {
			// Mass initial call handling
			$mass_balance = (float) $store['amount'];
			if ( $mass_balance <= 0 ) {
				$this->record_local_zero_refund( $mepr_txn, $iftp_txn );
				return;
			}
			$refund_amount = min( (float) $iftp_txn->amount, $mass_balance );
			$this->perform_gateway_refund( $mepr_txn, $iftp_txn, $refund_amount );
			$mass_balance -= $refund_amount;
			// Preserve transient with zero balance so cancellation loop can distinguish "mass flow exhausted" from "no mass flow chosen".
			if ( $mass_balance > 0 ) {
				$this->update_refund_balance_store( $mass_balance, 'mass' );
			} else {
				$this->update_refund_balance_store( 0.0, 'mass' ); // sentinel: mass context exhausted
			}
			return;
		}

		// Single flow (or no transient) handling
		$refund_amount = (float) $iftp_txn->amount;
		if ( ! empty( $store ) && $store['context'] === 'single' && isset( $store['trans_num'] ) && (string) $store['trans_num'] === (string) $mepr_txn->trans_num ) {
			$refund_amount = (float) $store['amount'];
		}
		$this->perform_gateway_refund( $mepr_txn, $iftp_txn, $refund_amount );
		if ( ! empty( $store ) && $store['context'] === 'single' ) {
			$remaining = (float) $store['amount'] - $refund_amount; // normally zero
			if ( $remaining > 0 ) {
				$this->update_refund_balance_store( $remaining, 'single', $mepr_txn->trans_num );
			} else {
				$this->clear_refund_balance_store();
			}
		}
	}

	public function record_refund( MeprTransaction $mepr_txn = null, IfthenpayTxn $iftp_txn = null ): void {
		if ( ! ( $mepr_txn instanceof MeprTransaction ) || ! ( $iftp_txn instanceof IfthenpayTxn ) ) {
			return; // Maintain parent signature contract; silently ignore invalid.
		}
		// Always ensure state set to REFUNDED
		$iftp_txn = $iftp_txn->with_state( IfthenpayTxn::STATE_REFUNDED );
		$this->repo->update_by_trans_num( $iftp_txn->trans_num, $iftp_txn->to_db_array() );

		// Persist refunded amount on MemberPress transaction (partial or zero for balance-exhausted mass cases)
		if ( method_exists( $mepr_txn, 'set_subtotal' ) ) {
			$mepr_txn->set_subtotal( (float) $iftp_txn->amount );
		} else {
			// Fallback: direct property assignment if setter not available
			$mepr_txn->amount = (float) $iftp_txn->amount;
		}
		$mepr_txn->status = MeprTransaction::$refunded_str;
		$mepr_txn->store();

		// Send refund notices AFTER amount & status persisted
		MeprUtils::send_refunded_txn_notices( $mepr_txn );
	}

	public function process_cancel_subscription( $sub_id ): void {
		$sub = new MeprSubscription( (int) $sub_id );
		if ( (int) $sub->id <= 0 ) {
			throw new MeprGatewayException( __( 'Invalid subscription.', 'ifthenpay-payments-for-memberpress' ) );
		}
		$user = $sub->user();

		// Owner request path – notify admins via mailer service and short-circuit without performing refunds.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( (int) get_current_user_id() > 0 && (int) get_current_user_id() === (int) $user->ID ) {
				$this->notify_admin_cancel_request( $sub );
				throw new MeprGatewayException( __( 'Your refund and cancellation request has been sent to the site administrator. Only site administrators may perform mass cancellations via the gateway.', 'ifthenpay-payments-for-memberpress' ) );
			}
			throw new MeprGatewayException( __( 'Not allowed to cancel this subscription. Only site administrators may perform mass cancellations via the gateway.', 'ifthenpay-payments-for-memberpress' ) );
		}

		// Admin path (perform actual refunds + cancellation)
		if ( $this->repo === null || $this->client === null ) {
			throw new MeprGatewayException( __( 'Payment gateway not configured.', 'ifthenpay-payments-for-memberpress' ) );
		}
		$errors       = array(); // trans_num => error message
		$paid_txns    = $this->repo->list_by_user_or_sub( (int) $user->ID, (int) $sub->id, 0, 0, IfthenpayTxn::STATE_PAID );
		$future_pairs = IfthenpayHelper::filter_future_period_pairs( $paid_txns );

		// Retrieve mass refund balance (transient context 'mass') if present
		$store        = $this->get_refund_balance_store();
		$mass_balance = ( ! empty( $store ) && $store['context'] === 'mass' ) ? (float) $store['amount'] : null; // null => no mass flow; 0.0 => exhausted balance sentinel

		foreach ( $future_pairs as $trans_num => $pair ) {
			$mepr_txn = $pair['mepr'];
			$iftp_txn = $pair['iftp'];
			try {
				$this->validate_refund_pair( $mepr_txn, $iftp_txn, true ); // skip state check as already filtered

				if ( $mass_balance === null ) {
					// No balance tracking -> full refund per transaction
					$this->perform_gateway_refund( $mepr_txn, $iftp_txn, (float) $iftp_txn->amount );
					continue;
				}

				if ( $mass_balance > 0 ) {
					$refund_amount = min( (float) $iftp_txn->amount, $mass_balance );
					$this->perform_gateway_refund( $mepr_txn, $iftp_txn, $refund_amount );
					$mass_balance -= $refund_amount;
					if ( $mass_balance > 0 ) {
						$this->update_refund_balance_store( $mass_balance, 'mass' );
					} else {
						$this->update_refund_balance_store( 0.0, 'mass' ); // exhausted sentinel retained
					}
				} else {
					// Balance exhausted: local-only zero refund
					$this->record_local_zero_refund( $mepr_txn, $iftp_txn );
				}
			} catch ( MeprGatewayException $e ) {
				$errors[ $trans_num ] = $e->getMessage();
			} catch ( \Throwable $t ) {
				$errors[ $trans_num ] = $t->getMessage();
			}
		}

		if ( ! empty( $errors ) ) {
			/* translators: %d: The number of refund errors that occurred during cancellation. */
			$lines[] = sprintf( __( 'Cancellation failed: %d refund error(s) occurred.', 'ifthenpay-payments-for-memberpress' ), count( $errors ) );
			$lines[] = __( 'Details:', 'ifthenpay-payments-for-memberpress' );
			foreach ( $errors as $tn => $msg ) {
				$lines[] = ' • ' . $tn . ' → ' . $msg;
			}
			$lines[] = __( 'Please retry or contact support if the issue persists.', 'ifthenpay-payments-for-memberpress' );
			throw new MeprGatewayException( implode( "\n", $lines ) );
		}
		// No errors -> mark subscription cancelled & send notices
		$this->record_cancel_subscription( $sub );
	}

	public function record_cancel_subscription( MeprSubscription $sub = null ): void {
		// Mark subscription as cancelled
		$sub->status = MeprSubscription::$cancelled_str;
		$sub->store();

		// Trigger any limit reached actions
		$sub->limit_reached_actions();

		// Send cancellation notices
		MeprUtils::send_cancelled_sub_notices( $sub );
	}

	/**
	 * Creates an admin notification for a subscription owner's Refund & Cancel request.
	 * Uses a transient to prevent duplicate emails within a short window.
	 *
	 * @param MeprSubscription $sub
	 * @return void
	 */
	private function notify_admin_cancel_request( MeprSubscription $sub ): void {
		// Debounce repeat requests using a transient to avoid admin spam (locked cooldown).
		$cache_key = 'iftp_cancel_req_sub_' . (int) $sub->id;
		if ( get_transient( $cache_key ) ) {
			return; // Already requested within cooldown window.
		}
		set_transient( $cache_key, time(), self::CANCEL_REQUEST_COOLDOWN );

		if ( class_exists( 'Ifthenpay\\MemberPress\\Ajax\\Services\\RefundMailerService' ) ) {
			$mailer = new Ifthenpay\MemberPress\Ajax\Services\RefundMailerService();
			$mailer->send_cancel_request( $sub );
		}
	}

	/**
	 * Internal gateway refund executor supporting partial amounts.
	 * Handles remote call (stubbed) then recording local state.
	 *
	 * @param MeprTransaction $mepr_txn
	 * @param IfthenpayTxn    $iftp_txn
	 * @param float           $refund_amount Amount to refund (<= original txn amount)
	 * @throws MeprGatewayException
	 * @return void
	 */
	private function perform_gateway_refund( MeprTransaction $mepr_txn, IfthenpayTxn $iftp_txn, float $refund_amount ): void {
		if ( $refund_amount <= 0 || $refund_amount > (float) $iftp_txn->amount ) {
			throw new MeprGatewayException( __( 'Invalid refund amount.', 'ifthenpay-payments-for-memberpress' ) );
		}
		try {
			$payload  = array(
				'backofficekey' => $this->settings->backoffice_key,
				'requestId'     => $iftp_txn->request_id,
				'amount'        => (string) $refund_amount,
			);
			$response = $this->client->request_refund( (object) $payload );
			switch ( $response['Code'] ) {
				case self::REFUND_STATUS_SUCCESS:
					if ( $refund_amount < (float) $iftp_txn->amount ) {
						// Persist partial amount adjustment prior to marking refunded
						$iftp_txn = $iftp_txn->with_amount( (float) $refund_amount );
						$this->repo->update_by_trans_num( $iftp_txn->trans_num, $iftp_txn->to_db_array() );
					}
					$this->record_refund( $mepr_txn, $iftp_txn );
					return;
				case self::REFUND_STATUS_FAILED:
					throw new MeprGatewayException( __( 'Payment/s could not be refunded.', 'ifthenpay-payments-for-memberpress' ) );
				case self::REFUND_STATUS_NO_FUNDS:
					throw new MeprGatewayException( __( 'Insufficient funds. The balance is the sum of all available funds that have not yet been transferred to the customer\'s account, meaning all payments made from 8:00 PM the previous day until the time of the refund.', 'ifthenpay-payments-for-memberpress' ) );
				default:
					throw new MeprGatewayException( __( 'Unexpected refund response.', 'ifthenpay-payments-for-memberpress' ) );
			}
		} catch ( MeprGatewayException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// translators: %s is the underlying exception message from ifthenpay API during refund processing.
			throw new MeprGatewayException( sprintf( __( 'ifthenpay | Payment Gateway: %s', 'ifthenpay-payments-for-memberpress' ), $e->getMessage() ) );
		}
	}

	/**
	 * Validates a MemberPress/ifthenpay transaction pair before refund.
	 *
	 * @param MeprTransaction $mepr_txn
	 * @param IfthenpayTxn    $iftp_txn
	 * @throws MeprGatewayException
	 * @return void
	 */
	private function validate_refund_pair( $mepr_txn, $iftp_txn, bool $skip_state_check = false ): void {
		if ( ! ( $mepr_txn instanceof MeprTransaction ) || ! ( $iftp_txn instanceof IfthenpayTxn ) ) {
			throw new MeprGatewayException( __( 'Refund target not found.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( ! $skip_state_check && $iftp_txn->state !== IfthenpayTxn::STATE_PAID ) {
			throw new MeprGatewayException( __( 'Transaction is not in a refundable state.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( $mepr_txn->status === MeprTransaction::$refunded_str ) {
			throw new MeprGatewayException( __( 'Already refunded.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( method_exists( $mepr_txn, 'is_expired' ) && $mepr_txn->is_expired() ) {
			throw new MeprGatewayException( __( 'Expired transaction.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( empty( $iftp_txn->request_id ) ) {
			// A paid transaction must have a request_id set by webhook; treat missing as an integrity error
			throw new MeprGatewayException( __( 'Missing gateway request ID.', 'ifthenpay-payments-for-memberpress' ) );
		}
		if ( (float) $mepr_txn->amount !== (float) $iftp_txn->amount ) {
			throw new MeprGatewayException( __( 'Amount mismatch.', 'ifthenpay-payments-for-memberpress' ) );
		}
	}

	/**
	 * Retrieve the current refund balance store (single or mass) for the logged-in admin.
	 * Adds a private _key element when valid.
	 *
	 * @return array Empty if none or invalid; otherwise ['context'=>'single|mass','amount'=>float,'trans_num'?,'stored_at'=>int,'_key'=>string]
	 */
	private function get_refund_balance_store(): array {
		$current_user = wp_get_current_user();
		if ( ! ( $current_user instanceof WP_User ) ) {
			return array();
		}
		$key   = 'iftp_refund_amt_' . $current_user->ID;
		$value = get_transient( $key );
		if ( ! is_array( $value ) || ! isset( $value['context'], $value['amount'] ) ) {
			return array();
		}
		$value['_key'] = $key; // internal use only
		return $value;
	}

	/**
	 * Update (or create) the refund balance store with a remaining amount.
	 *
	 * @param float       $amount Remaining amount.
	 * @param string      $context 'single' or 'mass'.
	 * @param string|null $trans_num Required for single context.
	 * @return void
	 */
	private function update_refund_balance_store( float $amount, string $context, string $trans_num = null ): void {
		$store = $this->get_refund_balance_store();
		$key   = $store['_key'] ?? null;
		if ( ! $key ) {
			$current_user = wp_get_current_user();
			if ( ! ( $current_user instanceof WP_User ) ) {
				return;
			}
			$key = 'iftp_refund_amt_' . $current_user->ID;
		}
		$data = array(
			'context'   => $context,
			'amount'    => (float) $amount,
			'stored_at' => time(),
		);
		if ( $context === 'single' && $trans_num !== null ) {
			$data['trans_num'] = (string) $trans_num;
		}
		set_transient( $key, $data, MINUTE_IN_SECONDS );
	}

	/**
	 * Clear the refund balance store transient for current user (if any).
	 *
	 * @return void
	 */
	private function clear_refund_balance_store(): void {
		$store = $this->get_refund_balance_store();
		if ( empty( $store['_key'] ) ) {
			return;
		}
		delete_transient( $store['_key'] );
	}

	/**
	 * Record a local-only zero refund (no remote gateway refund performed).
	 * Ensures both local gateway txn and MemberPress transaction reflect amount 0
	 * before sending refund notices.
	 *
	 * @param MeprTransaction $mepr_txn
	 * @param IfthenpayTxn    $iftp_txn Original paid gateway txn
	 * @return void
	 */
	private function record_local_zero_refund( MeprTransaction $mepr_txn, IfthenpayTxn $iftp_txn ): void {
		// Adjust local gateway record to amount 0 & refunded state
		$zero_gateway = $iftp_txn->with_amount( 0.0 )->with_state( IfthenpayTxn::STATE_REFUNDED );
		$this->repo->update_by_trans_num( $zero_gateway->trans_num, $zero_gateway->to_db_array() );

		// Update MemberPress transaction monetary fields explicitly
		if ( method_exists( $mepr_txn, 'set_subtotal' ) ) {
			$mepr_txn->set_subtotal( 0.0 );
		}
		// Also set common amount property defensively (depending on MP internals)
		$mepr_txn->amount = 0.0;
		$mepr_txn->status = MeprTransaction::$refunded_str;
		$mepr_txn->store();

		MeprUtils::send_refunded_txn_notices( $mepr_txn );
	}

	/**
	 * Encapsulate logic for retrieving and hydrating a MemberPress transaction by trans_num.
	 *
	 * @param string $trans_num
	 * @return MeprTransaction
	 */
	private function get_mepr_transaction_by_trans_num( string $trans_num ): MeprTransaction {
		$mepr_txn_rec = MeprTransaction::get_one_by_trans_num( $trans_num );
		return new MeprTransaction( $mepr_txn_rec->id );
	}


	/** Required abstracts (no-ops for features not used yet) */
	public function process_signup_form( $txn ) {}
	public function record_trial_payment( $txn ) {}
	public function record_create_subscription() {}
	public function display_payment_form( $amount, $user, $product_id, $txn_id ) {
		die( 'display payment form' );
	}
	public function validate_payment_form( $errors ) {
		return $errors;
	}
	public function enqueue_payment_form_scripts() {}
	public function process_suspend_subscription( $subscription_id ) {
		die( 'process suspend subscription' );
	}
	public function record_suspend_subscription() {}
	public function process_resume_subscription( $subscription_id ) {
		die( 'process resume subscription' );
	}
	public function record_resume_subscription() {}
	public function record_subscription_payment() {}
	public function process_update_account_form( $subscription_id ) {
		die( 'process update account form' );
	}
	public function validate_update_account_form( $errors = array() ) {
		return $errors;
	}

	public function is_test_mode() {
		return ( isset( $this->settings->test_mode ) and $this->settings->test_mode );
	}
	public function force_ssl() {
		return ( isset( $this->settings->force_ssl ) and ( $this->settings->force_ssl == 'on' or $this->settings->force_ssl == true ) );
	}
}
