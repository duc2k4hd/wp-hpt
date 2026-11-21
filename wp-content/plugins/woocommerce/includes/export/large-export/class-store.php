<?php
/**
 * Durable database storage for large product export jobs.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

defined( 'ABSPATH' ) || exit;

/**
 * Persists export state using optimistic updates and per-job lease tokens.
 */
final class Store {

	const SCHEMA_VERSION = 1;
	const LEASE_SECONDS  = 300;
	const MAX_ATTEMPTS   = 5;

	const RUNNABLE = array( 'pending', 'queued', 'running', 'pausing', 'cancelling', 'finalizing', 'verifying' );

	/**
	 * Install the site-local job table on first use.
	 */
	public function install(): void {
		global $wpdb;
		if ( self::SCHEMA_VERSION === (int) get_option( 'woocommerce_large_export_schema_version' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id varchar(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				request_key char(64) DEFAULT NULL,
				status varchar(16) NOT NULL,
				resume_status varchar(16) NOT NULL DEFAULT '',
				config longtext NOT NULL,
				checkpoint longtext NOT NULL,
				logs text NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 0,
				lease_token varchar(36) NOT NULL DEFAULT '',
				lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
				heartbeat_at bigint(20) unsigned NOT NULL DEFAULT 0,
				available_at bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at bigint(20) unsigned NOT NULL,
				started_at bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at bigint(20) unsigned NOT NULL,
				completed_at bigint(20) unsigned NOT NULL DEFAULT 0,
				attempts int(10) unsigned NOT NULL DEFAULT 0,
				last_error text NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_key (request_key),
				KEY owner_jobs (user_id,created_at),
				KEY queue (status,available_at,lease_until),
				KEY retention (status,updated_at)
			) $collate;"
		);

		$required = array( 'id', 'user_id', 'request_key', 'status', 'resume_status', 'config', 'checkpoint', 'logs', 'revision', 'lease_token', 'lease_until', 'heartbeat_at', 'available_at', 'created_at', 'started_at', 'updated_at', 'completed_at', 'attempts', 'last_error' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
		if ( $wpdb->last_error || array_diff( $required, $columns ) ) {
			throw new \RuntimeException( 'The large export job table could not be installed.' );
		}
		update_option( 'woocommerce_large_export_schema_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Create a job, or return the job for a previously submitted request token.
	 *
	 * @param int   $user User ID.
	 * @param array $config Validated export configuration.
	 * @return array
	 */
	public function create( int $user, array $config ): array {
		global $wpdb;
		$this->install();
		$config['schema_version'] = self::SCHEMA_VERSION;
		$request_key              = null;
		if ( isset( $config['request_id'] ) ) {
			if ( ! is_string( $config['request_id'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/D', $config['request_id'] ) ) {
				throw new \InvalidArgumentException( 'Invalid export request ID.' );
			}
			$request_key = hash( 'sha256', $user . ':' . $config['request_id'] );
			unset( $config['request_id'] );
		}

		$now = time();
		$id  = wp_generate_uuid4();
		$row = array(
			'id'           => $id,
			'user_id'      => $user,
			'request_key'  => $request_key,
			'status'       => 'queued',
			'config'       => $this->encode( $config ),
			'checkpoint'   => '{}',
			'logs'         => $this->encode(
				array(
					array(
						'at'     => $now,
						'status' => 'queued',
					),
				)
			),
			'available_at' => $now,
			'created_at'   => $now,
			'updated_at'   => $now,
			'last_error'   => '',
		);
		// A duplicate request token is expected when a browser repeats a Start request.
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$inserted = $wpdb->insert( $this->table(), $row, array_values( $this->formats( $row ) ) );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
		if ( false === $inserted && $request_key ) {
			$table = $this->table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE request_key = %s AND user_id = %d", $request_key, $user ) );
			if ( $existing ) {
				return $this->required( $existing );
			}
		}
		if ( 1 !== $inserted ) {
			throw new \RuntimeException( 'The large export job could not be created.' );
		}
		return $this->required( $id );
	}

	/**
	 * Read one job.
	 *
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		global $wpdb;
		if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $id ) ) {
			return null;
		}
		$this->install();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %s", $id ), ARRAY_A );
		$this->check_read();
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * List a user's newest jobs in bounded pages for the admin screen.
	 *
	 * @param int $user User ID.
	 * @return array
	 */
	public function recent( int $user ): array {
		global $wpdb;
		$this->install();
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 20", $user ), ARRAY_A );
		$this->check_read();
		return array_map( array( $this, 'decode' ), $rows );
	}

	/**
	 * Return a bounded list of ready job IDs, including expired leases.
	 *
	 * @return string[]
	 */
	public function due(): array {
		global $wpdb;
		$this->install();
		$table = $this->table();
		$now   = time();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier and fixed status literals.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status IN ('pending','queued','running','pausing','cancelling','finalizing','verifying') AND available_at <= %d AND lease_until <= %d ORDER BY updated_at ASC, created_at ASC, id ASC LIMIT 32", $now, $now ) );
		$this->check_read();
		return $rows;
	}

	/**
	 * Claim runnable work using an atomic compare-and-swap.
	 *
	 * @param string $id Job ID.
	 * @return array|null Claimed job, or null if another worker owns it.
	 */
	public function claim( string $id ): ?array {
		$job = $this->get( $id );
		$now = time();
		if ( ! $job || ! in_array( $job['status'], self::RUNNABLE, true ) || $job['available_at'] > $now || $job['lease_until'] > $now ) {
			return null;
		}

		$changes = array(
			'lease_token'  => wp_generate_uuid4(),
			'lease_until'  => $now + self::LEASE_SECONDS,
			'heartbeat_at' => $now,
		);
		if ( '' !== $job['lease_token'] ) {
			// A fatal error or killed process cannot call failure(). Charge abandoned
			// leases against the same bounded retry budget instead of looping forever.
			$changes['attempts']   = $job['attempts'] + 1;
			$changes['last_error'] = 'The previous worker lease expired before it was released.';
			if ( $changes['attempts'] >= self::MAX_ATTEMPTS ) {
				$changes['status']        = 'failed';
				$changes['resume_status'] = $job['status'];
				$changes['lease_token']   = '';
				$changes['lease_until']   = 0;
				$this->update( $job, $changes );
				return null;
			}
		}
		if ( in_array( $job['status'], array( 'pending', 'queued' ), true ) ) {
			$changes['status'] = 'running';
		}
		if ( ! $job['started_at'] ) {
			$changes['started_at'] = $now;
		}
		if ( ! $this->update( $job, $changes ) ) {
			return null;
		}
		// A simultaneous operator update can already appear in the returned job.
		$claimed = $this->required( $id );
		return $changes['lease_token'] === $claimed['lease_token'] ? $claimed : null;
	}

	/**
	 * Persist a checkpoint and renew the lease. A control request wins a race
	 * with a normal worker state transition; completed work is still recorded.
	 *
	 * @param array $job     Claimed job containing the worker's lease token.
	 * @param array $changes State or checkpoint fields.
	 */
	public function save( array $job, array $changes ): void {
		$fields = array( 'checkpoint', 'status', 'available_at', 'attempts', 'last_error' );
		if ( array_diff( array_keys( $changes ), $fields ) ) {
			throw new \InvalidArgumentException( 'Unsupported large export checkpoint fields.' );
		}
		if ( isset( $changes['checkpoint'] ) && ! is_array( $changes['checkpoint'] ) ) {
			throw new \InvalidArgumentException( 'Invalid large export checkpoint.' );
		}
		if ( isset( $changes['checkpoint'] ) ) {
			$changes['checkpoint'] = $this->encode( $changes['checkpoint'] );
		}
		if ( isset( $changes['last_error'] ) ) {
			$changes['last_error'] = $this->error_text( $changes['last_error'] );
		}

		$this->mutate_owned(
			$job,
			function ( array $current ) use ( $changes ): array {
				$now           = time();
				$state         = $current['status'];
				$worker_states = array(
					'running'    => array( 'queued', 'finalizing', 'failed' ),
					'finalizing' => array( 'verifying', 'failed' ),
					'verifying'  => array( 'completed', 'failed' ),
					'pausing'    => array( 'paused', 'failed' ),
					'cancelling' => array( 'cancelled', 'failed' ),
				);
				if ( isset( $changes['status'] ) && $changes['status'] !== $state ) {
					if ( ! in_array( $changes['status'], $worker_states[ $state ] ?? array(), true ) ) {
						if ( in_array( $state, array( 'pausing', 'cancelling' ), true ) ) {
							unset( $changes['status'] );
						} else {
							throw new \LogicException( 'Invalid large export worker state change.' );
						}
					}
					if ( isset( $changes['status'] ) && 'completed' === $changes['status'] ) {
						$changes['completed_at'] = $now;
					}
					if ( isset( $changes['status'] ) && 'failed' === $changes['status'] ) {
						$changes['resume_status'] = $state;
					}
				}
				$changes['heartbeat_at'] = $now;
				$changes['lease_until']  = $now + self::LEASE_SECONDS;
				return $changes;
			}
		);
	}

	/**
	 * Release a lease only when it still belongs to this worker.
	 *
	 * @param array $job Claimed job containing the lease token.
	 */
	public function release( array $job ): void {
		global $wpdb;
		if ( empty( $job['lease_token'] ) ) {
			return;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
		$released = $wpdb->query( $wpdb->prepare( "UPDATE $table SET lease_token = '', lease_until = 0, revision = revision + 1 WHERE id = %s AND lease_token = %s", $job['id'], $job['lease_token'] ) );
		if ( false === $released ) {
			throw new \RuntimeException( 'The large export lease could not be released.' );
		}
	}

	/**
	 * Apply one operator command. Ownership is checked by the caller.
	 *
	 * @param string $id     Job ID.
	 * @param string $action Pause, resume, cancel, retry or expire.
	 * @return array
	 */
	public function control( string $id, string $action ): array {
		for ( $try = 0; $try < 5; ++$try ) {
			$job     = $this->required( $id );
			$changes = $this->control_changes( $job, $action );
			if ( ! $changes ) {
				return $job;
			}
			if ( $this->update( $job, $changes ) ) {
				return $this->required( $id );
			}
		}
		throw new \RuntimeException( 'The large export state changed repeatedly. Please try again.' );
	}

	/**
	 * Record a bounded automatic retry, preserving its phase and checkpoint.
	 *
	 * @param array  $job   Claimed job containing its lease token.
	 * @param string $error Safe diagnostic suitable for the export UI.
	 */
	public function failure( array $job, string $error ): void {
		$this->mutate_owned(
			$job,
			function ( array $current ) use ( $error ): array {
				$attempts = $current['attempts'] + 1;
				$state    = $current['status'];
				$changes  = array(
					'attempts'     => $attempts,
					'last_error'   => $this->error_text( $error ),
					'available_at' => time() + min( 900, 15 * ( 2 ** min( 6, $attempts - 1 ) ) ),
				);
				if ( $attempts >= self::MAX_ATTEMPTS && in_array( $state, array( 'running', 'finalizing', 'verifying', 'pausing', 'cancelling' ), true ) ) {
					$changes['status']        = 'failed';
					$changes['resume_status'] = $state;
				}
				return $changes;
			}
		);
	}

	/**
	 * List old terminal jobs ready for a retention pass.
	 *
	 * @return string[]
	 */
	public function expires(): array {
		global $wpdb;
		$this->install();
		$table  = $this->table();
		$cutoff = $this->expiry_cutoff();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Site-local table identifier.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE (status IN ('completed','cancelled','failed') AND updated_at < %d AND lease_until <= %d) OR (status = 'expired' AND resume_status = 'purging') ORDER BY updated_at ASC LIMIT 32", $cutoff, time() ) );
		$this->check_read();
		return $rows;
	}

	/**
	 * Resolve a requested state change using the latest row revision.
	 *
	 * @param array  $job    Current job.
	 * @param string $action Requested action.
	 * @return array
	 */
	private function control_changes( array $job, string $action ): array {
		$state  = $job['status'];
		$active = array( 'pending', 'queued', 'running', 'finalizing', 'verifying' );
		$now    = time();
		switch ( $action ) {
			case 'pause':
				if ( in_array( $state, array( 'pausing', 'paused' ), true ) ) {
					return array();
				}
				if ( in_array( $state, $active, true ) ) {
					return array(
						'status'        => '' === $job['lease_token'] ? 'paused' : 'pausing',
						'resume_status' => $state,
						'available_at'  => $now,
					);
				}
				break;
			case 'resume':
				if ( in_array( $state, array( 'paused', 'pausing' ), true ) ) {
					return array(
						'status'       => $job['resume_status'] ? $job['resume_status'] : 'queued',
						'available_at' => $now,
					);
				}
				break;
			case 'cancel':
				if ( in_array( $state, array( 'cancelling', 'cancelled' ), true ) ) {
					return array();
				}
				if ( in_array( $state, self::RUNNABLE, true ) || in_array( $state, array( 'paused', 'failed' ), true ) ) {
					return array(
						'status'       => 'cancelling',
						'available_at' => $now,
						'attempts'     => 0,
					);
				}
				break;
			case 'retry':
				if ( 'failed' === $state ) {
					return array(
						'status'       => $job['resume_status'] ? $job['resume_status'] : 'queued',
						'attempts'     => 0,
						'last_error'   => '',
						'available_at' => $now,
					);
				}
				break;
			case 'expire':
				if ( 'expired' === $state ) {
					return array();
				}
				if ( in_array( $state, array( 'completed', 'cancelled', 'failed' ), true ) && $job['updated_at'] < $this->expiry_cutoff() && $job['lease_until'] <= $now ) {
					return array(
						'status'        => 'expired',
						'resume_status' => 'purging',
						'last_error'    => '',
					);
				}
				break;
			case 'purged':
				if ( 'expired' === $state ) {
					return 'purging' === $job['resume_status'] ? array( 'resume_status' => 'purged' ) : array();
				}
				break;
		}
		throw new \InvalidArgumentException( 'This action is unavailable for the current export job state.' );
	}

	/**
	 * Run a worker-owned update after rebasing over any operator update.
	 *
	 * @param array    $job     Claimed job.
	 * @param callable $changes Update callback.
	 */
	private function mutate_owned( array $job, callable $changes ): void {
		for ( $try = 0; $try < 5; ++$try ) {
			$current = $this->required( $job['id'] );
			if ( empty( $job['lease_token'] ) || $job['lease_token'] !== $current['lease_token'] ) {
				throw new \RuntimeException( 'The large export worker no longer holds its lease.' );
			}
			if ( $this->update( $current, $changes( $current ) ) ) {
				return;
			}
		}
		throw new \RuntimeException( 'The large export checkpoint could not be committed.' );
	}

	/**
	 * Update a row only if it still matches the observed revision.
	 *
	 * @param array $job     Observed row.
	 * @param array $changes Fields to update.
	 * @return bool
	 */
	private function update( array $job, array $changes ): bool {
		global $wpdb;
		$now                   = time();
		$changes['updated_at'] = $now;
		$changes['revision']   = $job['revision'] + 1;
		if ( ( isset( $changes['status'] ) && $changes['status'] !== $job['status'] ) || ! empty( $changes['last_error'] ) ) {
			$entry = array(
				'at'     => $now,
				'status' => $changes['status'] ?? $job['status'],
			);
			if ( ! empty( $changes['last_error'] ) ) {
				$entry['message'] = $changes['last_error'];
			}
			$changes['logs'] = $this->encode( array_slice( array_merge( $job['logs'], array( $entry ) ), -20 ) );
		}
		$result = $wpdb->update(
			$this->table(),
			$changes,
			array(
				'id'          => $job['id'],
				'revision'    => $job['revision'],
				'lease_token' => $job['lease_token'],
			),
			array_values( $this->formats( $changes ) ),
			array( '%s', '%d', '%s' )
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'The large export job state could not be updated.' );
		}
		return 1 === $result;
	}

	/**
	 * Read a job or surface a missing row.
	 *
	 * @param string $id Job ID.
	 * @return array
	 */
	private function required( string $id ): array {
		$job = $this->get( $id );
		if ( null === $job ) {
			throw new \InvalidArgumentException( 'The large export job does not exist.' );
		}
		return $job;
	}

	/**
	 * Decode database rows and restore numeric field types.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function decode( array $row ): array {
		foreach ( array( 'config', 'checkpoint', 'logs' ) as $field ) {
			$row[ $field ] = json_decode( $row[ $field ], true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $row[ $field ] ) ) {
				throw new \RuntimeException( 'Invalid large export state JSON.' );
			}
		}
		if ( self::SCHEMA_VERSION !== ( $row['config']['schema_version'] ?? null ) ) {
			throw new \RuntimeException( 'Unsupported large export configuration version.' );
		}
		foreach ( $this->formats( $row ) as $column => $format ) {
			if ( '%d' === $format ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		unset( $row['request_key'] );
		return $row;
	}

	/**
	 * Map job columns to the types expected by wpdb.
	 *
	 * @param array $fields Fields in query order.
	 * @return array
	 */
	private function formats( array $fields ): array {
		$formats = array();
		foreach ( $fields as $column => $value ) {
			$formats[ $column ] = in_array( $column, array( 'user_id', 'revision', 'attempts', 'lease_until' ), true ) || substr( $column, -3 ) === '_at' ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Encode a JSON field or throw before writing invalid state.
	 *
	 * @param array $data State to encode.
	 * @return string
	 */
	private function encode( array $data ): string {
		return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Invalid input must throw instead of being coerced.
	}

	/**
	 * Limit diagnostic size and keep error text unformatted.
	 *
	 * @param string $message Safe diagnostic text.
	 * @return string
	 */
	private function error_text( string $message ): string {
		return wp_html_excerpt( sanitize_text_field( $message ), 500 );
	}

	/**
	 * Return the oldest retained terminal status timestamp.
	 *
	 * @return int
	 */
	private function expiry_cutoff(): int {
		return time() - max( DAY_IN_SECONDS, (int) apply_filters( 'woocommerce_large_export_retention_seconds', 7 * DAY_IN_SECONDS ) );
	}

	/**
	 * Fail fast if wpdb could not read the job table.
	 */
	private function check_read(): void {
		global $wpdb;
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'The large export job table could not be read.' );
		}
	}

	/**
	 * Return the current WordPress site's job table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_large_export_jobs';
	}
}
