<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Repository\DTO;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

/**
 * Data Transfer Object (DTO) for rows in the mepr_ifthenpay_transactions table.
 *
 * Immutable. Use withers to update fields. Only construction, serialization, and simple helpers allowed.
 *
 * @property-read int|null    $id           Auto-increment PK (null before insert)
 * @property-read string      $trans_num    Unique transaction number
 * @property-read int         $user_id      WordPress user ID
 * @property-read int         $product_id   MemberPress product ID
 * @property-read int|null    $sub_id       MemberPress subscription ID (nullable)
 * @property-read float       $amount       Transaction amount (EUR)
 * @property-read string      $gateway_key  Ifthenpay client gateway key
 * @property-read string|null $redirect_url Payment redirect URL
 * @property-read string|null $pay_method   Payment method (MB, MBWAY, etc)
 * @property-read string|null $request_id   Unique request ID from webhook
 * @property-read string      $state        Transaction state (PENDING, PAID, etc)
 * @property-read string|null $created_at   MySQL DATETIME string
 * @property-read string|null $updated_at   MySQL DATETIME string
 */
final class IfthenpayTxn {

	// ----- States -----
	public const STATE_PENDING   = 'PENDING';
	public const STATE_PAID      = 'PAID';
	public const STATE_FAILED    = 'FAILED';
	public const STATE_CANCELLED = 'CANCELLED';
	public const STATE_REFUNDED  = 'REFUNDED';

	/** @var int|null Auto-increment PK (null before insert) */
	public ?int $id;
	/** @var string Unique transaction number */
	public string $trans_num;
	/** @var int WordPress user ID */
	public int $user_id;
	/** @var int MemberPress product ID */
	public int $product_id;
	/** @var int|null MemberPress subscription ID (nullable) */
	public ?int $sub_id;
	/** @var float Transaction amount (EUR) */
	public float $amount;
	/** @var string Ifthenpay client gateway key */
	public string $gateway_key;
	/** @var string|null Payment redirect URL */
	public ?string $redirect_url;
	/** @var string|null Payment method (MB, MBWAY, etc) */
	public ?string $pay_method;
	/** @var string|null Unique request ID from webhook */
	public ?string $request_id;
	/** @var string Transaction state (PENDING, PAID, etc) */
	public string $state;
	/** @var string|null MySQL DATETIME string */
	public ?string $created_at;
	/** @var string|null MySQL DATETIME string */
	public ?string $updated_at;

	public function __construct(
		?int $id,
		string $trans_num,
		int $user_id,
		int $product_id,
		?int $sub_id,
		float $amount,
		string $gateway_key,
		?string $redirect_url,
		?string $pay_method,
		?string $request_id,
		string $state = self::STATE_PENDING,
		?string $created_at = null,
		?string $updated_at = null
	) {
		$this->id           = $id;
		$this->trans_num    = $trans_num;
		$this->user_id      = $user_id;
		$this->product_id   = $product_id;
		$this->sub_id       = $sub_id;
		$this->amount       = $amount;
		$this->gateway_key  = $gateway_key;
		$this->redirect_url = $redirect_url;
		$this->pay_method   = $pay_method;
		$this->request_id   = $request_id;
		$this->state        = $state;
		$this->created_at   = $created_at;
		$this->updated_at   = $updated_at;
	}

	/**
	 * Create a DTO from a DB row object.
	 *
	 * @param object $row DB row object
	 * @return self
	 */
	public static function from_db_row( object $row ): self {
		return new self(
			isset( $row->id ) ? (int) $row->id : null,
			(string) $row->trans_num,
			(int) $row->user_id,
			(int) $row->product_id,
			isset( $row->sub_id ) ? (int) $row->sub_id : null,
			(float) $row->amount,
			(string) $row->gateway_key,
			isset( $row->redirect_url ) ? (string) $row->redirect_url : null,
			isset( $row->pay_method ) ? (string) $row->pay_method : null,
			isset( $row->request_id ) ? (string) $row->request_id : null,
			(string) $row->state,
			isset( $row->created_at ) ? (string) $row->created_at : null,
			isset( $row->updated_at ) ? (string) $row->updated_at : null
		);
	}

	/**
	 * Convert DTO to associative array for DB insert/update.
	 *
	 * @return array
	 */
	public function to_db_array(): array {
		$row = array(
			'trans_num'    => $this->trans_num,
			'user_id'      => $this->user_id,
			'product_id'   => $this->product_id,
			'sub_id'       => $this->sub_id,      // may be NULL
			'amount'       => $this->amount,
			'gateway_key'  => $this->gateway_key,
			'redirect_url' => $this->redirect_url,
			'pay_method'   => $this->pay_method,
			'request_id'   => $this->request_id,
			'state'        => $this->state,
		);

		// Keep legit 0, drop only actual NULL so MySQL stores NULL (not coerced 0).
		return array_filter(
			$row,
			static function ( $v ) {
				return $v !== null;
			}
		);
	}

	// ----- Immutability helpers -----

	/**
	 * Return a new instance with updated trans_num.
	 *
	 * @param string $trans_num
	 * @return self
	 */
	public function with_trans_num( string $trans_num ): self {
		$clone            = clone $this;
		$clone->trans_num = $trans_num;
		return $clone;
	}

	/**
	 * Return a new instance with updated user_id.
	 *
	 * @param int $user_id
	 * @return self
	 */
	public function with_user_id( int $user_id ): self {
		$clone          = clone $this;
		$clone->user_id = $user_id;
		return $clone;
	}

	/**
	 * Return a new instance with updated product_id.
	 *
	 * @param int $product_id
	 * @return self
	 */
	public function with_product_id( int $product_id ): self {
		$clone             = clone $this;
		$clone->product_id = $product_id;
		return $clone;
	}

	/**
	 * Return a new instance with updated amount.
	 *
	 * @param float $amount
	 * @return self
	 */
	public function with_amount( float $amount ): self {
		$clone         = clone $this;
		$clone->amount = $amount;
		return $clone;
	}

	/**
	 * Return a new instance with updated gateway_key.
	 *
	 * @param string $gateway_key
	 * @return self
	 */
	public function with_gateway_key( string $gateway_key ): self {
		$clone              = clone $this;
		$clone->gateway_key = $gateway_key;
		return $clone;
	}

	/**
	 * Return a new instance with updated state.
	 *
	 * @param string $state
	 * @return self
	 */
	public function with_state( string $state ): self {
		$clone        = clone $this;
		$clone->state = $state;
		return $clone;
	}

	/**
	 * Return a new instance with updated redirect_url.
	 *
	 * @param string|null $url
	 * @return self
	 */
	public function with_redirect_url( ?string $url ): self {
		$clone               = clone $this;
		$clone->redirect_url = $url;
		return $clone;
	}

	/**
	 * Return a new instance with updated request_id.
	 *
	 * @param string|null $request_id
	 * @return self
	 */
	public function with_request_id( ?string $request_id ): self {
		$clone             = clone $this;
		$clone->request_id = $request_id;
		return $clone;
	}

	/**
	 * Return a new instance with updated pay_method.
	 *
	 * @param string|null $pay_method
	 * @return self
	 */
	public function with_pay_method( ?string $pay_method ): self {
		$clone             = clone $this;
		$clone->pay_method = $pay_method;
		return $clone;
	}

	/**
	 * Return a new instance with updated sub_id (subscription ID).
	 *
	 * @param int|null $sub_id
	 * @return self
	 */
	public function with_sub_id( ?int $sub_id ): self {
		$clone         = clone $this;
		$clone->sub_id = $sub_id;
		return $clone;
	}
}
