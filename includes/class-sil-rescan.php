<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-applies the current phrase list to existing content in small batches via
 * WP-Cron, so triggering a rescan on a large site doesn't time out the request.
 */
class SIL_Rescan {

	private static $instance = null;
	const CRON_HOOK    = 'sil_process_rescan_batch';
	const QUEUE_OPTION = 'sil_rescan_queue';
	const STATS_OPTION = 'sil_rescan_stats';
	const BATCH_SIZE   = 20;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );
	}

	public function is_running() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return ! empty( $queue );
	}

	public function get_progress() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$stats = get_option(
			self::STATS_OPTION,
			array(
				'total'     => 0,
				'processed' => 0,
			)
		);
		$stats['remaining'] = count( $queue );
		return $stats;
	}

	/**
	 * Builds the queue of eligible post IDs and schedules the first batch.
	 * Returns true if a rescan was started, false if one was already running.
	 */
	public function start() {
		if ( $this->is_running() ) {
			return false;
		}

		$query = new WP_Query(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$linker  = SIL_Linker::instance();
		$post_ids = array();
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $linker->should_process( $post_id, $post ) ) {
				$post_ids[] = $post_id;
			}
		}

		update_option( self::QUEUE_OPTION, $post_ids, false );
		update_option(
			self::STATS_OPTION,
			array(
				'total'     => count( $post_ids ),
				'processed' => 0,
			),
			false
		);

		if ( ! empty( $post_ids ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return true;
	}

	public function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$batch     = array_splice( $queue, 0, self::BATCH_SIZE );
		$linker    = SIL_Linker::instance();

		foreach ( $batch as $post_id ) {
			$linker->relink_post( $post_id );
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		$stats = get_option(
			self::STATS_OPTION,
			array(
				'total'     => 0,
				'processed' => 0,
			)
		);
		$stats['processed'] += count( $batch );
		update_option( self::STATS_OPTION, $stats, false );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
