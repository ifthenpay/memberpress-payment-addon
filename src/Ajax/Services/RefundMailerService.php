<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax\Services;

use Ifthenpay\MemberPress\Repository\DTO\IfthenpayTxn;

final class RefundMailerService {

	// Colors / brand
	private string $color_primary    = '#00609c';
	private string $color_secondary  = '#00a7e2';
	private string $color_tertiary   = '#001b2d';
	private string $color_quaternary = '#d5dfe3';
	// Assets
	private string $company_name     = 'ifthenpay';
	private string $company_site_url = 'https://ifthenpay.com/';
	private string $header_logo_url  = IFTP_MP_IMAGES_URL . '/ifthenpay_symbol.svg';
	private string $footer_logo_url  = IFTP_MP_IMAGES_URL . '/ifthenpay_brand.png';
	// Default code expiry (minutes)
	private int $code_expiry_minutes = 5;

	/** Send refund verification email with a confirmation code. */
	public function send_refund_token( \MeprTransaction $mepr_txn, IfthenpayTxn $iftp_txn, string $code ): void {
		$user    = wp_get_current_user();
		$to      = $user && $user->user_email ? $user->user_email : (string) get_option( 'admin_email' );
		$subject = sprintf( '%s | %s %s', $this->company_name, __( 'Refund code for Transaction:', 'ifthenpay-payments-for-memberpress' ), (int) $mepr_txn->rec->id );
		$body    = $this->render_template(
			array(
				'headline'         => esc_html__( 'Enter this code to confirm your refund', 'ifthenpay-payments-for-memberpress' ),
				'code'             => $code,
				'rows'             => array(
					'Transaction' => (string) $mepr_txn->rec->trans_num,
					'Amount'      => (string) $mepr_txn->rec->total,
					'Paid At'     => (string) $iftp_txn->updated_at,
				),
				'show_code'        => true,
				'expiry_minutes'   => $this->code_expiry_minutes,
				'ignore_copy'      => esc_html__( 'If you did not request this, you can ignore this email.', 'ifthenpay-payments-for-memberpress' ),
				'intro_paragraphs' => array(),
			)
		);
		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** Notify admins of a user refund+cancel request (no code box). */
	public function send_cancel_request( \MeprSubscription $sub ): void {
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			return;
		}
		$user         = $sub->user();
		$prd          = $sub->product();
		$amount       = (float) $sub->price;
		$period_label = (int) $sub->period . ' ' . $sub->period_type;
		$headline     = esc_html__( 'Refund & Cancel Request', 'ifthenpay-payments-for-memberpress' );
		$intro        = array( esc_html__( 'A subscription owner has requested an administrator to perform a Refund & Cancel action.', 'ifthenpay-payments-for-memberpress' ) );
		$subject      = sprintf( '%s | %s %s', $this->company_name, __( 'Refund & Cancel Request – Subscription:', 'ifthenpay-payments-for-memberpress' ), (int) $sub->id );
		$admin_url    = add_query_arg( 'subscription', (int) $sub->id, admin_url( 'admin.php?page=memberpress-subscriptions' ) );

		// Structured admin actions block (specific to cancellation request email)
		$admin_actions_html = '
<tr>
	<td style="padding:20px 0 0;">
		<div style="max-width:520px;margin:0 auto;padding:16px 20px;border:1px solid #e2e8f0;border-radius:12px;background:#f9fafb;">
			<div style="font-size:13px;font-weight:600;color:#334155;margin:0 0 8px 0;">' . esc_html__( 'Admin Actions:', 'ifthenpay-payments-for-memberpress' ) . '</div>
			<ol style="margin:0;padding-left:18px;font-size:13px;line-height:1.55;color:#334155;">
				<li>' . sprintf(
				esc_html__( 'Navigate to the requested %s', 'ifthenpay-payments-for-memberpress' ),
				'<a href="' . esc_url( $admin_url ) . '" style="color:' . esc_attr( $this->color_secondary ) . ';text-decoration:none;font-weight:600;">' . esc_html__( 'Subscription #', 'ifthenpay-payments-for-memberpress' ) . (int) $sub->id . '</a>'
			) . '</li>
				<li>' . esc_html__( 'Open the subscription details (View Transactions screen).', 'ifthenpay-payments-for-memberpress' ) . '</li>
				<li>' . esc_html__( 'Perform the "Refund & Cancel" gateway action (mass refund will run).', 'ifthenpay-payments-for-memberpress' ) . '</li>
				<li>' . esc_html__( 'Confirm outcome and notify the user.', 'ifthenpay-payments-for-memberpress' ) . '</li>
			</ol>
		</div>
	</td>
</tr>';

		$body = $this->render_template(
			array(
				'headline'         => $headline,
				'code'             => '',
				'rows'             => array(
					'Subscription ID' => (int) $sub->id,
					'User'            => is_object( $user ) ? sprintf( '%s (%s)', $user->user_login, $user->user_email ) : 'N/A',
					'Product'         => is_object( $prd ) ? (string) get_the_title( $prd->ID ) : 'N/A',
					'Amount / Period' => sprintf( '€%s / %s', number_format( $amount, 2 ), $period_label ),
					'Current Status'  => (string) $sub->status,
				),
				'show_code'        => false,
				'expiry_minutes'   => 0,
				'ignore_copy'      => esc_html__( 'This email was generated automatically.', 'ifthenpay-payments-for-memberpress' ),
				'intro_paragraphs' => $intro,
				'before_rows_html' => $admin_actions_html,
			)
		);
		wp_mail( $admin_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Build the rows HTML table chunk.
	 *
	 * @param array $rows Associative label => value.
	 */
	private function build_rows_html( array $rows ): string {
		$html = '';
		foreach ( $rows as $label => $value ) {
			$label = esc_html( (string) $label );
			$value = esc_html( (string) $value );
			$html .= '<tr>'
				. '<td style="width:180px;padding:6px 10px;background:#f9fafb;color:#4a5568;font-size:13px;border-radius:8px;">' . $label . '</td>'
				. '<td style="padding:6px 10px;font-size:13px;color:#001b2d;">' . $value . '</td>'
				. '</tr>';
		}
		return $html;
	}

	/** Build intro paragraphs HTML. */
	private function build_intro_html( array $intro_paras ): string {
		$intro_html = '';
		foreach ( $intro_paras as $p ) {
			$intro_html .= '<p style="margin:0 0 12px 0;font-size:14px;line-height:1.55;color:#334155;text-align:center;">' . esc_html( (string) $p ) . '</p>';
		}
		return $intro_html;
	}

	/** Build contacts block HTML. */
	private function build_contacts_html(): string {
		$secondary = esc_attr( $this->color_secondary );
		return '<div style="margin-top:6px;">256 245 560 • 808 222 777*</div>'
			. '<div>'
			. '<a href="mailto:suporte@ifthenpay.com" style="color:' . $secondary . ';text-decoration:none;">suporte@ifthenpay.com</a>'
			. '&nbsp;·&nbsp;'
			. '<a href="https://helpdesk.ifthenpay.com/" style="color:' . $secondary . ';text-decoration:none;">https://helpdesk.ifthenpay.com/</a>'
			. '</div>'
			. '<div style="margin-top:8px;opacity:.8;">* Call cost to the national fixed network.</div>';
	}

	/** Build code verification block (optional). */
	private function build_code_block( string $code_safe, bool $show_code ): string {
		if ( ! $show_code || $code_safe === '' ) {
			return '';
		}
		return '<tr><td style="padding:8px 0;text-align:center;">'
			. '<div style="display:inline-block;padding:10px 14px;border:1px solid ' . esc_attr( $this->color_quaternary ) . ';border-radius:10px;background:#f9fafb;">'
			. '<code style="font-size:26px;letter-spacing:6px;font-weight:700;color:' . esc_attr( $this->color_tertiary ) . ';display:inline-block;">' . $code_safe . '</code>'
			. '</div>'
			. '</td></tr>';
	}

	/** Render HTML email. Expects keys: headline, code, rows (assoc), show_code, expiry_minutes, ignore_copy, intro_paragraphs, before_rows_html. */
	private function render_template( array $data ): string {
		// Basic extraction (call sites controlled)
		$headline         = esc_html( (string) $data['headline'] );
		$code_raw         = (string) ( $data['code'] );
		$show_code        = ! empty( $data['show_code'] );
		$expiry_minutes   = (int) $data['expiry_minutes'];
		$ignore_copy      = (string) $data['ignore_copy'];
		$rows             = (array) $data['rows'];
		$intro_paras      = (array) $data['intro_paragraphs'];
		$before_rows_html = (string) ( $data['before_rows_html'] ?? '' );

		// Derived / computed values used by helpers
		$code_safe = esc_html( $code_raw );
		/* translators: %d: The number of minutes before the code expires. */
		$expiry = ( $show_code && $expiry_minutes > 0 ) ? esc_html( sprintf( __( 'This code expires in %d minutes.', 'ifthenpay-payments-for-memberpress' ), $expiry_minutes ) ) : '';
		// Use class properties directly for color palette (avoid local aliases).
		$year        = esc_html( date_i18n( 'Y' ) );
		$header_logo = '<a href="' . esc_url( $this->company_site_url ) . '" style="text-decoration:none;display:inline-block;line-height:0;">'
			. '<img src="' . esc_url( $this->header_logo_url ) . '" alt="' . esc_attr( $this->company_name ) . '" height="28" style="display:block;border:0;height:28px;width:auto;" />'
			. '</a>';
		$footer_logo = '<a href="' . esc_url( $this->company_site_url ) . '" style="text-decoration:none;display:inline-block;line-height:0;">'
			. '<img src="' . esc_url( $this->footer_logo_url ) . '" alt="' . esc_attr( $this->company_name ) . '" height="28" style="display:block;border:0;height:28px;width:auto;" />'
			. '</a>';

		// Build modular chunks
		$rows_html     = $this->build_rows_html( $rows );
		$intro_html    = $this->build_intro_html( $intro_paras );
		$contacts_html = $this->build_contacts_html();
		$code_block    = $this->build_code_block( $code_safe, $show_code );

		// Meta / expiry + ignore message
		$meta_lines_html = '';
		if ( $expiry !== '' ) {
			$meta_lines_html .= '<p style="margin:0;font-size:12px;line-height:1.65;color:#475569;text-align:center;">' . $expiry . '</p>';
		}
		$ignore_html = '';
		if ( $ignore_copy !== '' ) {
			$ignore_html = '<tr><td style="padding-bottom:16px;text-align:center;"><p style="margin:0;font-size:14px;color:#64748b;">' . esc_html( $ignore_copy ) . '</p></td></tr>';
		}

		// Final HTML output
		$tertiary = esc_attr( $this->color_tertiary );
		return '<!doctype html>'
			. '<html lang="en">'
			. '<head>'
			. '<meta charset="UTF-8" />'
			. '<title>' . $headline . '</title>'
			. '<meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '</head>'
			. '<body style="margin:0;padding:0;background:#ffffff;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">'
			. '<tr><td align="center" style="padding:20px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . $tertiary . ';">'
			. '<tr><td style="padding:4px 0 0;text-align:center;">' . $header_logo . '</td></tr>'
			. '<tr><td><h1 style="margin:16px;font-size:28px;line-height:1.2;font-weight:500;text-align:center;">' . $headline . '</h1></td></tr>'
			. '<tr><td style="text-align:center;">' . $intro_html . '</td></tr>'
			. $code_block
			. '<tr><td>' . $meta_lines_html . '</td></tr>'
			. $before_rows_html
			. '<tr><td style="padding:32px 0;display:flex;justify-content:center;"><table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0 8px;">' . $rows_html . '</table></td></tr>'
			. $ignore_html
			. '<tr><td style="padding:32px 0 0;border-top:1px solid #eaeef2;text-align:center;"><div style="margin-bottom:8px;">' . $footer_logo . '</div><div style="font-size:12px;line-height:1.6;color:#334155;">' . $contacts_html . '</div><div style="margin:8px;font-size:12px;color:#6b7280;">© ' . $year . ' ' . $this->company_name . '</div></td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body>'
			. '</html>';
	}
}
