<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Ajax\DTO;

use Ifthenpay\MemberPress\Api\IfthenpayHelper;

/** Transaction DTO for refund/cancel modal. */
final class TransactionRequest implements \JsonSerializable {

	/** Internal numeric ID */
	public int $id;
	/** MemberPress transaction number */
	public string $transaction;
	/** Numeric amount */
	public float $amount;
	/** Creation timestamp */
	public string $starts;
	/** Expiration timestamp */
	public string $ends;
	/** Currency symbol */
	private string $currencySymbol;

	/**
	 * @param int    $id Internal ID
	 * @param string $transaction Transaction number
	 * @param float  $amount Raw amount
	 * @param string $starts Created time
	 * @param string $ends Expiry time
	 * @param string $currencySymbol Currency symbol (default €)
	 */
	public function __construct( int $id, string $transaction, float $amount, string $starts, string $ends, string $currencySymbol = '€' ) {
		$this->id             = $id;
		$this->transaction    = $transaction;
		$this->amount         = $amount;
		$this->starts         = $starts;
		$this->ends           = $ends;
		$this->currencySymbol = $currencySymbol;
	}

	/** Formatted amount */
	public function formatted(): string {
		return $this->currencySymbol . IfthenpayHelper::format_amount( $this->amount, 2 );
	}

	/** JsonSerializable hook (id, transaction, amount, starts, ends). */
	public function jsonSerialize(): array {
		return array(
			'id'          => $this->id,
			'transaction' => $this->transaction,
			'amount'      => $this->formatted(),
			'starts'      => $this->starts,
			'ends'        => $this->ends,
		);
	}
}
