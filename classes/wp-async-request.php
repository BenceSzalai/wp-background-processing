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
		 * Prefix is used to create the first part of the unique {@see WP_Async_Request::$identifier}
		 * in {@see WP_Async_Request::__construct()}.
		 *
		 * (default value: 'wp')
		 *
		 * @var string
		 * @access protected
		 */
		protected $prefix = 'wp';

		/**
		 * Action used to create the second part of the unique {@see WP_Async_Request::$identifier}
		 * in {@see WP_Async_Request::__construct()}.
		 *
		 * (default value: 'async_request')
		 *
		 * @var string
		 * @access protected
		 */
		protected $action = 'async_request';

		/**
		 * Unique identifier for the class.
		 *
		 * Serves as the basis for all usages, where a need to uniquely identify the actual implementing class is needed
		 * such as:
		 * - the action tag of the WP AJAX calls
		 * - the prefix of the filters
		 * - url of the REST interface
		 *
		 * Created from {@see WP_Async_Request::$prefix} and {@see WP_Async_Request::$action} in
		 * {@see WP_Async_Request::__construct()}.
		 *
		 * @var mixed
		 * @access protected
		 */
		protected $identifier;

		/**
		 * Contains the Data to be processed.
		 *
		 * (default value: array())
		 *
		 * @var array
		 * @access protected
		 */
		protected $data = array();

		/**
		 * Initiates a new async request.
		 *
		 * - Sets {@see WP_Async_Request::$identifier}
		 * - Registers the required REST route or AJAX calls with WP
		 *
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
		 * Sets the Data to be processed (i.e. sets {@see WP_Async_Request::$data}).
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
		 * Dispatches the async request.
		 *
		 * @return array|WP_Error The response from a {@see wp_remote_post()} call.
		 */
		public function dispatch() {
			$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
			$args = $this->get_post_args();

			return wp_remote_post( esc_url_raw( $url ), $args );
		}

		/**
		 * Gets the query arguments to be used by {@see WP_Async_Request::dispatch()}.
		 *
		 * If the class has a {@see $query_args} attribute, that is returned.
		 *
		 * Otherwise a new set of arguments are generated.
		 *
		 * The generated arguments can also be modified using WP filter with a tag composed as:
		 *
		 * `$this->identifier . '_query_args'`
		 *
		 * @see WP_Async_Request::$identifier
		 *
		 * @return array
		 */
		protected function get_query_args() {
			if ( property_exists( $this, 'query_args' ) ) {
				return $this->query_args;
			}
			
			if( $this->is_rest() ){
				$args = array(
					'_wpnonce'  => wp_create_nonce( 'wp_rest' ),
				);
			}
			else {
				$args = [
					'action' => $this->identifier,
					'nonce'  => wp_create_nonce( $this->identifier ),
				];
			}
			
			/**
			 * Filters the post arguments used during an async request.
			 *
			 * @param array $url
			 */
			return apply_filters( $this->identifier . '_query_args', $args );
		}

		/**
		 * Gets the query URL to be used by {@see WP_Async_Request::dispatch()}.
		 *
		 * If the class has a {@see $query_url} attribute, that is returned.
		 *
		 * Otherwise a new URL is generated.
		 *
		 * The generated URL can also be modified using WP filter with a tag composed as:
		 *
		 * `$this->identifier . '_query_url'`
		 *
		 * @see WP_Async_Request::$identifier
		 *
		 * @return string
		 */
		protected function get_query_url() {
			if ( property_exists( $this, 'query_url' ) ) {
				return $this->query_url;
			}
			
			if( $this->is_rest() ){
				$url = rest_url( 'background_process/v1/' . $this->identifier  );
			}
			else {
				$url = admin_url( 'admin-ajax.php' );
			}

			/**
			 * Filters the post arguments used during an async request.
			 *
			 * @param string $url
			 */
			return apply_filters( $this->identifier . '_query_url', $url );
		}
		
		/**
		 * Gets the POST arguments to be used by {@see WP_Async_Request::dispatch()}.
		 *
		 * If the class has a {@see $post_args} attribute, that is returned.
		 *
		 * Otherwise a new set of arguments are generated.
		 *
		 * The generated arguments can also be modified using WP filter with a tag composed as:
		 *
		 * `$this->identifier . '_post_args'`
		 *
		 * @see WP_Async_Request::$identifier
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
		 * Conditionally handles the current request if the nonce check is passed.
		 *
		 * It also closes the HTTP connection ASAP in order to free up the WebServer to process other requests if needed.
		 *
		 * @uses WP_Async_Request::handle()
		 */
		public function maybe_handle() {
			// Don't lock up other requests while processing.
            $this->close_http_connection();

			$this->check_nonce();

			$this->handle();

			return $this->send_or_die();
		}

		/**
		 * Checks if request is set to use the WordPress REST API instead of AJAX.
		 *
		 * To use the REST API create a {@see $use_rest} attribute in the class and set to boolean `true`.
		 *
		 * @return boolean
		 */
		protected function is_rest() {
			return ( property_exists( $this, 'use_rest' ) && true === $this->use_rest );
		}
		
		/**
		 * If AJAX is used it is equivalent to calling {@see wp_die()}.
		 * If REST is used, it returns a valid REST response using {@see rest_ensure_response()}.
		 *
		 * @return mixed|WP_Error|WP_HTTP_Response|WP_REST_Response|void
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
         *
         * Ripped from \WC_Background_Emailer::close_http_connection()
         *
         * @see https://core.trac.wordpress.org/ticket/41358
         */
        protected function close_http_connection() {
            // Only 1 PHP process can access a session object at a time, close this so the next request isn't kept waiting.
            // @codingStandardsIgnoreStart
            if (session_id()) {
                session_write_close();
            }
            // @codingStandardsIgnoreEnd
	
	        if ( function_exists( 'wc_set_time_limit' ) ) {
	            wc_set_time_limit( 0 );
            }
            else {
		        @set_time_limit( 0 ); // @codingStandardsIgnoreLine
	        }

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
		 * Checks if the nonce is valid, and dies otherwise.
		 *
		 * A wrapper around {@see check_ajax_referer()} WP function to properly handle both AJAX and REST mode.
		 */
		protected function check_nonce() {
			
			$bypass = apply_filters($this->identifier . '_bypass_nonce_verification', false );
			if( $bypass ) {
				return;
			}
			
			$action = $this->identifier;
			$query_arg = 'nonce';

			if ( $this->is_rest() ) {
				$action = 'wp_rest';
				$query_arg = '_wpnonce';
			}
			
			check_ajax_referer( $action, $query_arg ); // Shouldn't we use wp_verify_nonce() for REST instead?
		}

		/**
		 * Handles the actual processing.
		 *
		 * Override this method to perform any actions required
		 * during the async request.
		 */
		abstract protected function handle();

	}
}
