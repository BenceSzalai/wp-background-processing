<?php
/**
 * WP Background Process
 *
 * @package WP-Background-Processing
 */

if ( ! class_exists( 'WP_Background_Process' ) ) {

	/**
	 * Abstract WP_Background_Process class.
	 *
	 * @abstract
	 * @extends WP_Async_Request
	 */
	abstract class WP_Background_Process extends WP_Async_Request {
		
		/**
		 * Action used to create the second part of the unique {@see WP_Async_Request::$identifier}
		 * in {@see WP_Async_Request::__construct()}.
		 *
		 * (default value: 'background_process')
		 *
		 * @var string
		 * @access protected
		 */
		protected $action = 'background_process';

		/**
		 * Start time of current process as a Unix timestamp.
		 *
		 * Set in {@see WP_Background_Process::lock_process()}.
		 *
		 * (default value: 0)
		 *
		 * @var int
		 * @access protected
		 */
		protected $start_time = 0;
		
		/**
		 * Unique identifier for the WP based cron health check.
		 *
		 * Created from {@see WP_Async_Request::$identifier} in {@see WP_Async_Request::__construct()}.
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $cron_hook_identifier;

		/**
		 * Unique identifier for the WP based cron health check interval.
		 *
		 * Created from {@see WP_Async_Request::$identifier} in {@see WP_Async_Request::__construct()}.
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $cron_interval_identifier;
		
		/**
		 * The Batch (an array) that contains the Items being processed.
		 *
		 * @var array
		 */
		protected $data = array();
		
		/**
		 * Initiates a new background process
		 *
		 * - Sets {@see WP_Async_Request::$cron_hook_identifier}
		 * - Sets {@see WP_Async_Request::$cron_interval_identifier}
		 * - Registers the cron health check with WP.
		 */
		public function __construct() {
			parent::__construct();

			$this->cron_hook_identifier     = $this->identifier . '_cron';
			$this->cron_interval_identifier = $this->identifier . '_cron_interval';

			add_action( $this->cron_hook_identifier, array( $this, 'handle_cron_healthcheck' ) );
			add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) );
		}
		
		/**
		 * Dispatches the async request and schedules a health check.
		 *
		 * @uses schedule_event()
		 *
		 * @return array|WP_Error The response from a {@see wp_remote_post()} call.
		 */
		public function dispatch() {
			// Schedule the cron healthcheck.
			$this->schedule_event();

			// Perform remote post.
			return parent::dispatch();
		}

		/**
		 * Adds an Item to the Batch stored in the class
		 * (i.e. adds an item as a new array element to the the {@see WP_Background_Process::$data}) array.
		 *
		 * @param array $data The Item to be added.
		 *
		 * @return $this
		 */
		public function push_to_queue( $data ) {
			$this->data[] = $data;

			return $this;
		}

		/**
		 * Saves the current Batch ({@see WP_Background_Process::$data}) in the db Queue as a new Batch.
		 *
		 * Note: This does not updates the actual Batch in the Queue, but adds as a new Batch at the end.
		 *
		 * @return $this
		 */
		public function save() {
			$key = $this->generate_key();

			if ( ! empty( $this->data ) ) {
				update_site_option( $key, $this->data );
			}

			return $this;
		}
		
		/**
		 * Updates a Batch in the db Queue with the current Batch ({@see WP_Background_Process::$data}).
		 *
		 * @param string $key  The key that identifies the Batch.
		 *                     (The name of the option used with {@see update_site_option()}.
		 * @param array  $data The Batch to be saved.
		 *
		 * @return $this
		 */
		public function update( $key, $data ) {
			if ( ! empty( $data ) ) {
				update_site_option( $key, $data );
			}

			return $this;
		}

		/**
		 * Deletes a Batch from the db Queue.
		 *
		 * @param string $key The key that identifies the Batch.
		 *                    (The name of the option used with {@see delete_site_option()}.
		 *
		 * @return $this
		 */
		public function delete( $key ) {
			delete_site_option( $key );

			return $this;
		}

		/**
		 * Generates a key to be used to save a new Batch in the db Queue.
		 *
		 * Generates a unique key based on microtime. Batches are
		 * given a unique key so that they can be merged upon save.
		 *
		 * @param int $length The length of the key to generate.
		 *                    Should be substantially bigger than the length of the {@see WP_Async_Request::$identifier}!
		 *
		 * @return string
		 */
		protected function generate_key( $length = 64 ) {
			// ToDo: ensure a suitable minimum length based on the length of $this->identifier
			$unique  = md5( microtime() . rand() );
			$prepend = $this->identifier . '_batch_';

			return substr( $prepend . $unique, 0, $length );
		}

		/**
		 * Conditionally handles the current request if all of these conditions are met:
		 * - the nonce check is passed,
		 * - there is at least one Batch in the db Queue,
		 * - the process is not running already,
		 *
		 * It also closes the HTTP connection ASAP in order to free up the WebServer to process other requests if needed.
		 *
		 * @uses WP_Background_Process::handle()
		 */
		public function maybe_handle() {
			// Don't lock up other requests while processing.
			$this->close_http_connection();

			if ( $this->is_process_running() ) {
				// Background process already running.
				return $this->send_or_die();
			}

			if ( $this->is_queue_empty() ) {
				// No data to process.
				return $this->send_or_die();
			}
			
			$this->check_nonce();

			$this->handle();

			return $this->send_or_die();
		}

		/**
		 * Checks if there are any Batches in the db Queue.
		 *
		 * @return bool
		 */
		protected function is_queue_empty() {
			global $wpdb;

			$table  = $wpdb->options;
			$column = 'option_name';

			if ( is_multisite() ) {
				$table  = $wpdb->sitemeta;
				$column = 'meta_key';
			}

			$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s ",
					$key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

			return ( $count > 0 ) ? false : true;
		}

		/**
		 * Checks if there is a Process Lock in place,
		 * i.e. checks if another instance of the current process running already.
		 *
		 * This implementation is based on WP site Transients.
		 * Override with more reliable approach if needed.
		 *
		 * @see lock_process()
		 * @see unlock_process()
		 *
		 * @return boolean
		 *
		 */
		protected function is_process_running() {
			if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
				// Process already running.
				return true;
			}

			return false;
		}

		/**
		 * Sets the Process Lock.
		 * Locks the process so that multiple instances can't run simultaneously.
		 *
		 * This implementation is based on WP site Transients.
		 * Override with more reliable approach if needed, but the lock duration
		 * should be greater than that used in the {@see time_exceeded()} method.
		 *
		 * @see is_process_running()
		 * @see unlock_process()
		 */
		protected function lock_process() {
			$this->start_time = time(); // Set start time of current process.

			$lock_duration = ( property_exists( $this, 'queue_lock_time' ) ) ? $this->queue_lock_time : 60; // 1 minute
			$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', $lock_duration );

			set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
		}

		/**
		 * Removes the Process Lock.
		 *
		 * Unlock the process so that other instances can spawn.
		 *
		 * This implementation is based on WP site Transients.
		 * Override with more reliable approach if needed.
		 *
		 * @see is_process_running()
		 * @see lock_process()
		 *
		 * @return $this
		 */
		protected function unlock_process() {
			delete_site_transient( $this->identifier . '_process_lock' );

			return $this;
		}

		/**
		 * Gets the next (i.e. the first) Batch from the db Queue.
		 *
		 * @return stdClass Batch object where the `key` attribute is the Batch key and the `data` attribute is the actual
		 *                  data of the Batch containing the Items to be processed.
		 */
		protected function get_batch() {
			global $wpdb;

			$table        = $wpdb->options;
			$column       = 'option_name';
			$key_column   = 'option_id';
			$value_column = 'option_value';

			if ( is_multisite() ) {
				$table        = $wpdb->sitemeta;
				$column       = 'meta_key';
				$key_column   = 'meta_id';
				$value_column = 'meta_value';
			}

			$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$query = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$key_column} ASC LIMIT 1",
					$key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

			$batch       = new stdClass();
			$batch->key  = $query->$column;
			$batch->data = maybe_unserialize( $query->$value_column );

			return $batch;
		}
		
		/**
		 * Handles the actual processing of the next Batch from the Queue.
		 *
		 * It passes each Item in the Batch to the task handler ({@see task()}),
		 * while remaining within server memory and time limit constraints.
		 *
		 * When the constraints are reached, but there are Batches still to be processed
		 * it calls {@see dispatch()} to continue processing in a new request.
		 *
		 */
		protected function handle() {
			$this->lock_process();

			do {
				$batch = $this->get_batch();

				foreach ( $batch->data as $key => $value ) {
					$task = $this->task( $value );

					if ( false !== $task ) {
						$batch->data[ $key ] = $task;
					} else {
						unset( $batch->data[ $key ] );
					}

					if ( $this->time_exceeded() || $this->memory_exceeded() ) {
						// Batch limits reached.
						break;
					}
				}

				// Update or delete current batch.
				if ( ! empty( $batch->data ) ) {
					$this->update( $batch->key, $batch->data );
				} else {
					$this->delete( $batch->key );
				}
			} while ( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() );

			$this->unlock_process();

			// Start next batch or complete process.
			if ( ! $this->is_queue_empty() ) {
				$this->dispatch();
			} else {
				$this->complete();
			}

			return $this->send_or_die();
		}

		/**
		 * Checks if memory limits are exceeded.
		 *
		 * Can be used to ensures the processing never exceeds 90% of the maximum available memory.
		 *
		 * @return bool
		 */
		protected function memory_exceeded() {
			$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
			$current_memory = memory_get_usage( true );
			$return         = false;

			if ( $current_memory >= $memory_limit ) {
				$return = true;
			}

			return apply_filters( $this->identifier . '_memory_exceeded', $return );
		}

		/**
		 * Gets the memory limit in bytes.
		 *
		 * @return int
		 */
		protected function get_memory_limit() {
			if ( function_exists( 'ini_get' ) ) {
				$memory_limit = ini_get( 'memory_limit' );
			} else {
				// Sensible default.
				$memory_limit = '128M';
			}

			if ( ! $memory_limit || - 1 === intval( $memory_limit ) ) {
				// Unlimited, set to 32GB.
				$memory_limit = '32000M';
			}

			return $this->convert_shorthand_to_bytes( $memory_limit );
		}

		/**
		 * Converts a shorthand byte value to an integer byte value.
		 *
		 * @param string $value A (PHP ini) byte value, either shorthand or ordinary.
		 *
		 * @return int An integer byte value.
		 */
		protected function convert_shorthand_to_bytes( $value ) {
			$value = strtolower( trim( $value ) );
			$bytes = (int) $value;

			if ( false !== strpos( $value, 'g' ) ) {
				$bytes *= 1024 * 1024 * 1024;
			} elseif ( false !== strpos( $value, 'm' ) ) {
				$bytes *= 1024 * 1024;
			} elseif ( false !== strpos( $value, 'k' ) ) {
				$bytes *= 1024;
			}

			// Deal with large (float) values which run into the maximum integer size.
			return min( $bytes, PHP_INT_MAX );
		}
		
		/**
		 * Checks if time limits are exceeded.
		 *
		 * Can be used to ensures the processing never exceeds a sensible execution time limit.
		 *
		 * A timeout limit of 30s is common on shared hosting.
		 *
		 * Note: This check always returns false if WP_CLI is being used for the current request!
		 *
		 * @return bool
		 */
		protected function time_exceeded() {
			$finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
			$return = false;

			if ( time() >= $finish ) {
				$return = true;
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return false;
			}

			return apply_filters( $this->identifier . '_time_exceeded', $return );
		}

		/**
		 * This method is called when the processing of all Items in all Batches are completed.
		 *
		 * Override if applicable, but ensure that the default actions are performed, or call parent::complete().
		 */
		protected function complete() {
			// Unschedule the cron healthcheck.
			$this->clear_scheduled_event();
		}

		/**
		 * Schedules a cron health check with WP Cron.
		 *
		 * @param mixed $schedules Schedules.
		 *
		 * @return mixed
		 */
		public function schedule_cron_healthcheck( $schedules ) {
			$interval = apply_filters( $this->identifier . '_cron_interval', 5 );

			if ( property_exists( $this, 'cron_interval' ) ) {
				$interval = apply_filters( $this->identifier . '_cron_interval', $this->cron_interval );
			}

			// Adds every 5 minutes to the existing schedules.
			$schedules[ $this->identifier . '_cron_interval' ] = array(
				'interval' => MINUTE_IN_SECONDS * $interval,
				/* translators: %d: cron interval */
				'display'  => sprintf( __( 'Every %d Minutes' ), $interval ),
			);

			return $schedules;
		}

		/**
		 * The callback method for the health check scheduled with WP Cron.
		 *
		 * It restarts the background process if not already running and the queue is not empty.
		 */
		public function handle_cron_healthcheck() {
			if ( $this->is_process_running() ) {
				// Background process already running.
				exit;
			}

			if ( $this->is_queue_empty() ) {
				// No data to process.
				$this->clear_scheduled_event();
				exit;
			}

			$this->handle();

			exit;
		}

		/**
		 * Schedules the next health check with WP Cron if there is no such schedule in place already.
		 *
		 * @uses wp_schedule_event()
		 */
		protected function schedule_event() {
			if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
				wp_schedule_event( time(), $this->cron_interval_identifier, $this->cron_hook_identifier );
			}
		}

		/**
		 * Removes the scheduled health check from WP Cron
		 *
		 * @uses wp_unschedule_event()
		 */
		protected function clear_scheduled_event() {
			$timestamp = wp_next_scheduled( $this->cron_hook_identifier );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $this->cron_hook_identifier );
			}
		}

		/**
		 * Cancels a running process.
		 *
		 * It removes all Batches from the db Queue and clears the scheduled health checks.
		 */
		public function cancel_process() {
			while ( ! $this->is_queue_empty() ) {
				$batch = $this->get_batch();
				$this->delete( $batch->key );
			}
			
			// ToDo: handle a case, when the Batches are deted from the db Queue, but there is a processing running in parallel, which may write the actual Batch back to the db at the end of it's own run! Probably need to modify update() to check and only update if option is still there.
			
			$this->clear_scheduled_event();
		}

		/**
		 * Processes an Item.
		 *
		 * Override this method to perform any actions required on each
		 * queue Item. Return the modified Item for further processing
		 * in the next pass through. Or, return false to remove the
		 * Item from the queue.
		 *
		 * @param mixed $item Queue Item to process.
		 *
		 * @return mixed
		 */
		abstract protected function task( $item );

	}
}
