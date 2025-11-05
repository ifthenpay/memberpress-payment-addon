<?php

declare(strict_types=1);

namespace Ifthenpay\MemberPress\Repository;

use Ifthenpay\MemberPress\Repository\DTO\IfthenpayTxn;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You shall not pass!' );
}

/**
 * Database access client for the mepr_ifthenpay_transactions table.
 *
 * Provides CRUD operations and helpers for IfthenpayTxn DTOs.
 */
final class IfthenpayTxnRepository {

	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Cache getter callback.
	 *
	 * @var callable
	 */
	private $cache_get;
	/**
	 * Cache setter callback.
	 *
	 * @var callable
	 */
	private $cache_set;
	/**
	 * Cache delete callback.
	 *
	 * @var callable
	 */
	private $cache_delete;

	private const CACHE_GROUP = 'ifthenpay_txn';

	public function __construct(
		?\wpdb $db = null,
		?callable $cache_get = null,
		?callable $cache_set = null,
		?callable $cache_delete = null
	) {
		$this->db           = $db ?: $GLOBALS['wpdb'];
		$this->cache_get    = $cache_get ?: 'wp_cache_get';
		$this->cache_set    = $cache_set ?: 'wp_cache_set';
		$this->cache_delete = $cache_delete ?: 'wp_cache_delete';
	}

	/**
	 * Ensure the transactions table exists (safe to call every load).
	 * Creates the table if missing.
	 */
	public function maybe_install(): void {
		if ( ! $this->table_exists() ) {
			$this->create_table();
		}
	}

	// ---------- CRUD (DTO-aware) ----------

	/**
	 * Insert a new transaction and return the persisted DTO (with id), or false on failure.
	 *
	 * @param IfthenpayTxn $txn
	 * @return IfthenpayTxn|false
	 */
	public function insert( IfthenpayTxn $txn ) {
		$row = $txn->to_db_array();
		$ok  = (bool) $this->db->insert( $this->table(), $row, $this->formats_for( $row ) );
		if ( ! $ok ) {
			return false;
		}

		$id = (int) $this->db->insert_id;
		( $this->cache_delete )( $this->ck_trans( $txn->trans_num ), self::CACHE_GROUP );

		// Re-read for created_at/updated_at (populated by DB)
		$fresh = $this->get_by_id( $id );
		return $fresh ?? $txn; // fallback with id
	}

	/**
	 * Update columns by trans_num using a patch array.
	 *
	 * @param string $trans_num
	 * @param array  $patch
	 * @return bool True if update succeeded
	 */
	public function update_by_trans_num( string $trans_num, array $patch ): bool {
		// Drop only nulls so we don't coerce NULL to 0 with %d / %s
		$patch = array_filter(
			$patch,
			static function ( $v ) {
				return $v !== null;
			}
		);

		$allowed = $this->allowed();
		$data    = $fmt = array();
		foreach ( $patch as $col => $val ) {
			if ( isset( $allowed[ $col ] ) ) {
				$data[ $col ] = $val;
				$fmt[]        = $allowed[ $col ];
			}
		}
		if ( ! $data ) {
			return false;
		}

		$ok = (bool) $this->db->update(
			$this->table(),
			$data,
			array( 'trans_num' => $trans_num ),
			$fmt,
			array( '%s' )
		);
		if ( $ok ) {
			( $this->cache_delete )( $this->ck_trans( $trans_num ), self::CACHE_GROUP );
		}
		return $ok;
	}

	/**
	 * Update only the state column for a transaction.
	 *
	 * @param string $trans_num
	 * @param string $state
	 * @return bool
	 */
	public function update_state( string $trans_num, string $state ): bool {
		return $this->update_by_trans_num( $trans_num, array( 'state' => $state ) );
	}

	/**
	 * Update only the redirect_url column for a transaction.
	 *
	 * @param string      $trans_num
	 * @param string|null $url
	 * @return bool
	 */
	public function update_redirect_url( string $trans_num, ?string $url ): bool {
		return $this->update_by_trans_num( $trans_num, array( 'redirect_url' => $url ) );
	}

	/**
	 * Fetch a transaction by trans_num (cached).
	 *
	 * @param string $trans_num
	 * @return IfthenpayTxn|null
	 */
	public function get_one_by_trans_num( string $trans_num ): ?IfthenpayTxn {
		$ck     = $this->ck_trans( $trans_num );
		$cached = ( $this->cache_get )( $ck, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM `{$this->table()}` WHERE trans_num = %s LIMIT 1", $trans_num )
		);

		$dto = $row ? IfthenpayTxn::from_db_row( $row ) : null;
		( $this->cache_set )( $ck, $dto ?: false, self::CACHE_GROUP );
		return $dto;
	}

	/**
	 * Fetch a transaction by ID (no cache).
	 *
	 * @param int $id
	 * @return IfthenpayTxn|null
	 */
	public function get_by_id( int $id ): ?IfthenpayTxn {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM `{$this->table()}` WHERE id = %d LIMIT 1", $id )
		);
		return $row ? IfthenpayTxn::from_db_row( $row ) : null;
	}

	/**
	 * Combined flexible listing by user_id and/or sub_id with optional state filter.
	 * At least one of $user_id or $sub_id must be provided; otherwise returns an empty array.
	 * Results ordered by id DESC with limit/offset paging.
	 *
	 * @param int|null    $user_id User identifier to filter or null to ignore.
	 * @param int|null    $sub_id  Subscription identifier to filter or null to ignore.
	 * @param int         $limit   Max rows (defaults to 20).
	 * @param int         $offset  Offset for paging (defaults to 0).
	 * @param string|null $state   Optional state filter.
	 * @return IfthenpayTxn[]
	 */
	public function list_by_user_or_sub( ?int $user_id = null, ?int $sub_id = null, int $limit = 20, int $offset = 0, ?string $state = null ): array {
		// $limit <= 0 means no LIMIT/OFFSET (return all matching rows). Use cautiously.
		$wheres = array();
		$params = array();

		if ( null !== $user_id ) {
			$wheres[] = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( null !== $sub_id ) {
			$wheres[] = 'sub_id = %d';
			$params[] = $sub_id;
		}
		if ( null !== $state ) {
			$wheres[] = 'state = %s';
			$params[] = $state;
		}

		// Require at least one primary filter to avoid unbounded table scans.
		if ( ! $wheres ) {
			return array();
		}

		if ( $limit > 0 ) {
			$sql      = "SELECT * FROM `{$this->table()}` WHERE " . implode( ' AND ', $wheres ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
			$prepared = $this->db->prepare( $sql, $params );
		} else {
			$sql      = "SELECT * FROM `{$this->table()}` WHERE " . implode( ' AND ', $wheres ) . ' ORDER BY id DESC';
			$prepared = $this->db->prepare( $sql, $params );
		}
		$rows = $this->db->get_results( $prepared ) ?: array();
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = IfthenpayTxn::from_db_row( $r );
		}
		return $out;
	}

	/**
	 * Delete a transaction by trans_num.
	 *
	 * @param string $trans_num
	 * @return bool True if deleted
	 */
	public function delete_by_trans_num( string $trans_num ): bool {
		$deleted = (bool) $this->db->delete( $this->table(), array( 'trans_num' => $trans_num ), array( '%s' ) );
		if ( $deleted ) {
			( $this->cache_delete )( $this->ck_trans( $trans_num ), self::CACHE_GROUP );
		}
		return $deleted;
	}

	/**
	 * Count transactions by state.
	 *
	 * @param string $state
	 * @return int
	 */
	public function count_by_state( string $state ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM `{$this->table()}` WHERE state = %s", $state )
		);
	}

	// ---------- Schema ----------

	/**
	 * Create the transactions table if it does not exist.
	 */
	public function create_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$this->table()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

			trans_num    VARCHAR(100) NOT NULL,
			user_id      BIGINT UNSIGNED NOT NULL,
			product_id   BIGINT UNSIGNED NOT NULL,
			sub_id       BIGINT UNSIGNED DEFAULT NULL,
			amount       DECIMAL(18,2) NOT NULL DEFAULT 0,
			gateway_key  VARCHAR(100) NOT NULL,
			redirect_url VARCHAR(255) DEFAULT NULL,
			pay_method   VARCHAR(50)  DEFAULT NULL,
			request_id   VARCHAR(50)  DEFAULT NULL,
			state        VARCHAR(40)  NOT NULL DEFAULT 'PENDING',

			PRIMARY KEY (id),
			UNIQUE KEY trans_num (trans_num),
			KEY user_id (user_id),
			KEY product_id (product_id),
			KEY sub_id (sub_id),
			KEY state (state)
		) " . $this->db->get_charset_collate() . ';';

		dbDelta( $sql );
		( $this->cache_delete )( $this->ck_exists(), self::CACHE_GROUP );
	}

	/**
	 * Check if the transactions table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		$ck     = $this->ck_exists();
		$cached = ( $this->cache_get )( $ck, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$like   = $this->db->esc_like( $this->table() );
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $this->table();
		( $this->cache_set )( $ck, $exists, self::CACHE_GROUP );
		return $exists;
	}

	// ---------- Internals ----------

	/**
	 * Get the table name for transactions.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'mepr_ifthenpay_transactions';
	}

	/**
	 * Get allowed columns and their formats for DB operations.
	 *
	 * @return array
	 */
	private function allowed(): array {
		return array(
			'trans_num'    => '%s',
			'user_id'      => '%d',
			'product_id'   => '%d',
			'sub_id'       => '%d',
			'amount'       => '%f',
			'gateway_key'  => '%s',
			'redirect_url' => '%s',
			'pay_method'   => '%s',
			'request_id'   => '%s',
			'state'        => '%s',
		);
	}

	/**
	 * Get DB format array for a given row.
	 *
	 * @param array $row
	 * @return array
	 */
	private function formats_for( array $row ): array {
		$formats = array();
		$allowed = $this->allowed();
		foreach ( $row as $col => $_ ) {
			if ( isset( $allowed[ $col ] ) ) {
				$formats[] = $allowed[ $col ];
			}
		}
		return $formats;
	}

	/**
	 * Get cache key for a transaction.
	 *
	 * @param string $trans_num
	 * @return string
	 */
	private function ck_trans( string $trans_num ): string {
		return 'trans_' . $trans_num;
	}

	/**
	 * Get cache key for table existence.
	 *
	 * @return string
	 */
	private function ck_exists(): string {
		return 'exists_' . $this->table();
	}
}
