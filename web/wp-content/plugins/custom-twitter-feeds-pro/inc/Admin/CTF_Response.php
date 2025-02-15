<?php
/**
 * Class CTF_Response
 *
 * Sends back ajax response to client end
 *
 * @since 2.0
 */
namespace TwitterFeed\Admin;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class CTF_Response {
	/**
	 * Response constructor.
	 *
	 * @param $is_success
	 * @param $data
	 *
	 * @throws \Exception
	 */
	public function __construct( $is_success, $data ) {
		$is_success ? wp_send_json_success( $data ) : wp_send_json_error( $data );
		wp_die();
	}
}
