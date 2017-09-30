<?php
/**
 * WP Async Request
 *
 * @package WP-Background-Processing
 */

if ( ! class_exists( 'WP_Async_Request' ) ) {

	/**
	 * Abstract WP_Async_Request class.
	 *
	 * @abstract
	 */
	abstract class WP_Async_Request {

		/**
		 * Prefix
		 *
		 * (default value: 'wp')
		 *
		 * @var string
		 * @access protected
		 */
		protected $prefix = 'wp';

		/**
		 * Action
		 *
		 * (default value: 'async_request')
		 *
		 * @var string
		 * @access protected
		 */
		protected $action = 'async_request';

		/**
		 * Identifier
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $identifier;

		/**
		 * Data
		 *
		 * (default value: array())
		 *
		 * @var array
		 * @access protected
		 */
		protected $data = array();

		/**
		 * Initiate new async request
		 */
		public function __construct() {
			$this->identifier = $this->prefix . '_' . $this->action;

			// Use REST API for requests.
			if ( $this->is_rest() ) {
				add_action(
					'rest_api_init', function () {
						register_rest_route(
							'background_process/v1', $this->identifier, array(
								'methods'    => 'POST',
								'callback' => array( $this, 'maybe_handle' ),
							)
						);
					}
				);
			} // Use AJAX API
			else {
				add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
				add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
			}

		}

		/**
		 * Set data used during the request
		 *
		 * @param array $data Data.
		 *
		 * @return $this
		 */
		public function data( $data ) {
			$this->data = $data;

			return $this;
		}

		/**
		 * Dispatch the async request
		 *
		 * @return array|WP_Error
		 */
		public function dispatch() {
			$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
			$args = $this->get_post_args();

			return wp_remote_post( esc_url_raw( $url ), $args );
		}

		/**
		 * Get query args
		 *
		 * @return array
		 */
		protected function get_query_args() {
			if ( property_exists( $this, 'query_args' ) ) {
				return $this->query_args;
			}
			
			if( $this->is_rest() ){
				return array(
					'_wpnonce'  => wp_create_nonce( 'wp_rest' ),
				);
			}

			$args = array(
				'action' => $this->identifier,
				'nonce'  => wp_create_nonce( $this->identifier ),
			);

			/**
			 * Filters the post arguments used during an async request.
			 *
			 * @param array $url
			 */
			return apply_filters( $this->identifier . '_query_args', $args );
		}

		/**
		 * Get query URL
		 *
		 * @return string
		 */
		protected function get_query_url() {
			if ( property_exists( $this, 'query_url' ) ) {
				return $this->query_url;
			}
			
			if( $this->is_rest() ){
				return rest_url( 'background_process/v1/' . $this->identifier  );
			}

			$url = admin_url( 'admin-ajax.php' );

			/**
			 * Filters the post arguments used during an async request.
			 *
			 * @param string $url
			 */
			return apply_filters( $this->identifier . '_query_url', $url );
		}

		/**
		 * Get post args
		 *
		 * @return array
		 */
		protected function get_post_args() {
			if ( property_exists( $this, 'post_args' ) ) {
				return $this->post_args;
			}

			$args = array(
				'timeout'   => 5,
				'blocking'  => false,
				'body'      => $this->data,
				'cookies'   => $_COOKIE,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			);
			
			if( $this->is_rest() ){
				unset( $args['blocking'] );
			}
			
			/**
			 * Filters the post arguments used during an async request.
			 *
			 * @param array $args
			 */
			$args = apply_filters( $this->identifier . '_post_args', $args );
			
			return $args;
		}

		/**
		 * Maybe handle
		 *
		 * Check for correct nonce and pass to handler.
		 */
		public function maybe_handle() {
			// Don't lock up other requests while processing.
            $this->close_http_connection();

			$this->check_nonce();

			$this->handle();

			return $this->send_or_die();
		}

		/**
		 * Is REST.
		 *
		 * Checks if request is set to use the WordPress REST API instead of AJAX.
		 *
		 * @return boolean
		 */
		protected function is_rest() {
			return ( property_exists( $this, 'use_rest' ) && true === $this->use_rest );
		}

		/**
		 * Send or die
		 *
		 * @return (WP_Error|WP_HTTP_Response|mixed)
		 */
		protected function send_or_die() {
			// If using REST API, return a response.
			if ( $this->is_rest() ) {
				return rest_ensure_response(
					array(
						'success' => true,
					)
				);
			}

			// Because WP AJAX will only work if the page dies.
			wp_die();
		}

        /**
         * Finishes replying to the client, but keeps the process running for further (async) code execution.
         * Ripped from \WC_Background_Emailer::close_http_connection()
         * @see https://core.trac.wordpress.org/ticket/41358
         */
        protected function close_http_connection()
        {
            // Only 1 PHP process can access a session object at a time, close this so the next request isn't kept waiting.
            // @codingStandardsIgnoreStart
            if (session_id()) {
                session_write_close();
            }
            // @codingStandardsIgnoreEnd

            wc_set_time_limit(0);

            // fastcgi_finish_request is the cleanest way to send the response and keep the script running, but not every server has it.
            if (is_callable('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // Fallback: send headers and flush buffers.
                if (!headers_sent()) {
                    header('Connection: close');
                }
                @ob_end_flush(); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
                flush();
            }
        }

		/**
		 * Check Nonce.
		 *
		 * Check if nonce is valid, else die.
		 */
		protected function check_nonce() {
			$action = $this->identifier;
			$query_arg = 'nonce';

			if ( $this->is_rest() ) {
				$action = 'wp_rest';
				$query_arg = '_wpnonce';
			}

			check_ajax_referer( $action, $query_arg );
		}

		/**
		 * Handle
		 *
		 * Override this method to perform any actions required
		 * during the async request.
		 */
		abstract protected function handle();

	}
}
