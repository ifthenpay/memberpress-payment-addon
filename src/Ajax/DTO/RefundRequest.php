<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax\DTO;

/**
 * Class RefundRequest
 * DTO for refund AJAX requests.
 *
 * @package Ifthenpay\MemberPress\Ajax\DTO
 */
final class RefundRequest {

	/** @var string Transaction number */
	public string $trans_num = '';
	/** @var string Transaction ID */
	public string $trans_id = '';
	/** @var string Verification code */
	public string $code = '';

	/**
	 * Create RefundRequest from $_POST data.
	 *
	 * @param array $fields List of expected fields
	 * @return self
	 */
	public static function from_post( array $fields ): self {
		$req = new self();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$req->$field = sanitize_text_field( (string) $_POST[ $field ] );
			}
		}
		return $req;
	}
}
