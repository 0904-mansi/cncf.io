<?php
/**
 * Speakers Contact class that allows for bulk email of Speakers.
 *
 * @category Components
 * @package  WordPress
 * @author   Fuerza
 * @license  https://www.gnu.org/licenses/gpl-3.0.txt GNU/GPLv3
 * @link     https://cncf.io
 * @since    1.0.0
 */

namespace Fuerza;

use Fuerza_Utils;

/**
 * Speakers Contact class.
 */
class Speakers_Contact {

	/**
	 * Class instance.
	 *
	 * @var object $instance
	 */
	protected static $instance = null;

	/**
	 * Form ID.
	 *
	 * @var int $form_id
	 */
	protected $form_id         = 2;

	/**
	 * Select Speakers.
	 *
	 * @var string $select_speakers
	 */
	protected $select_speakers = 'speakers_select';

	/**
	 * Speakers limit.
	 *
	 * @var int $speakers_limit
	 */
	protected $speakers_limit  = 50;

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'gform_pre_render', array( $this, 'on_pre_render' ) );
		add_filter( 'gform_pre_validation', array( $this, 'populate_speakers_select' ) );
		add_filter( 'gform_pre_submission_filter', array( $this, 'populate_speakers_select' ) );
		add_filter( 'gform_admin_pre_render', array( $this, 'populate_speakers_select' ) );
		add_filter( 'gform_field_validation', array( $this, 'validate_max_speakers' ), 10, 4 );
		add_filter( 'gform_form_not_found_message', array( $this, 'change_not_found_message' ), 10, 2 );
		add_action( 'gform_after_submission', array( $this, 'after_form_submission' ), 10, 2 );
		add_action( 'fz_send_email_to_speakers', array( $this, 'send_email_to_speakers' ), 10, 2 );
		add_action( 'gform_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_C6CCC525DF', array( $this, 'handle_ajax_is_allowed_role' ) );
	}

	/**
	 * Pre-render.
	 *
	 * @param object $form Form object.
	 */
	public function on_pre_render( $form ) {
		if ( $form['id'] !== $this->form_id ) {
			return $form;
		}

		if ( is_user_logged_in() ) {
			$is_allowed = $this->is_allowed_user();
			if ( ! $is_allowed ) {
				return null;
			}
		}

		$this->populate_speakers_select( $form );

		return $form;
	}

	/**
	 * Change not found message.
	 *
	 * @param string $message Message.
	 * @param int    $form_id Form ID.
	 */
	public function change_not_found_message( $message, $form_id ) {
		if ( intval( $form_id ) !== $this->form_id ) {
			return $message;
		}

		return "Sorry. You don't have permission to view this form.";
	}

	/**
	 * Enqueue Scripts.
	 *
	 * @param object $form form.
	 */
	public function enqueue_scripts( $form ) {
		if ( $this->form_id !== $form['id'] ) {
			return;
		}

		wp_enqueue_script(
			'select2',
			get_stylesheet_directory_uri() . '/js/vendor/select2.min.js',
			array( 'jquery' ),
			true
		);

		wp_enqueue_script(
			'select2-position-fixer',
			get_stylesheet_directory_uri() . '/js/vendor/select2-position-fixer.js',
			array( 'jquery', 'select2' ),
			true
		);

		wp_enqueue_script(
			'speakers-contact-js',
			get_stylesheet_directory_uri() . '/js/speakers-contact.js',
			array( 'jquery', 'select2', 'select2-position-fixer' ),
			true,
			true
		);
	}

	/**
	 * Validate max speakers.
	 *
	 * @param int    $result Result.
	 * @param int    $value Value.
	 * @param object $form Form.
	 * @param string $field Field.
	 */
	public function validate_max_speakers( $result, $value, $form, $field ) {
		if ( $form['id'] !== $this->form_id || $field->inputName !== $this->select_speakers ) { //phpcs:ignore
			return $result;
		}

		if ( ! empty( $value ) ) {
			$count = count( $value );
			if ( $count > $this->speakers_limit ) {
				$result['is_valid'] = false;
				$result['message']  = "The maximum amount that can be selected is 50. You have selected {$count}.";
			}
		}

		return $result;
	}

	/**
	 * Populate speakers select box.
	 *
	 * @param object $form Form.
	 */
	public function populate_speakers_select( $form ) {
		if ( $form['id'] !== $this->form_id ) {
			return $form;
		}

		foreach ( $form['fields'] as &$field ) {
			if ( $field->inputName !== $this->select_speakers ) { //phpcs:ignore
				continue;
			}

			$speakers     = $this->fetch_speakers_ids();
			$all_speakers = $this->fetch_all_speakers();
			$choices      = array();

			foreach ( $all_speakers as $speaker ) {
				$choices[] = array(
					'text'       => $speaker->display_name,
					'value'      => $speaker->ID,
					'isSelected' => $speakers['has_filters'] ? in_array( $speaker->ID, $speakers['items'] ) : false,
				);
			}

			$field->placeholder = 'Select a speaker';
			$field->choices     = $choices;
		}

		return $form;
	}

	/**
	 * After Form Submission
	 *
	 * @param obejct $entry Entry.
	 * @param object $form Form.
	 */
	public function after_form_submission( $entry, $form ) {
		if ( $form['id'] !== $this->form_id ) {
			return;
		}

		$name             = rgar( $entry, 5 );
		$email            = rgar( $entry, 3 );
		$speakers_ids     = rgar( $entry, 2 );
		$subject          = rgar( $entry, 6 );
		$message          = rgar( $entry, 7 );
		$start_date       = rgar( $entry, 8 );
		$end_date         = rgar( $entry, 9 );
		$travel_funding   = rgar( $entry, 11 );
		$attendees_number = rgar( $entry, 12 );
		$language         = rgar( $entry, 13 );

		$speakers = trim( $speakers_ids, '][' );
		$speakers = explode( ',', $speakers );
		$speakers = array_map(
			function( $item ) {
				return str_replace( '"', '', $item );
			},
			$speakers,
		); //phpcs:ignore

		$params = compact( 'name', 'email', 'start_date', 'end_date', 'subject', 'message', 'language', 'travel_funding', 'attendees_number' );

		$this->send_email_to_moderator( $speakers, $params );

		wp_schedule_single_event( time(), 'fz_send_email_to_speakers', array( $speakers, $params ) );
	}

	/**
	 * Send email.
	 *
	 * @param array  $speakers speakers.
	 * @param object $params Params.
	 */
	public function send_email_to_speakers( $speakers, $params ) {
		foreach ( $speakers as $speaker_id ) {
			$speaker  = get_userdata( $speaker_id );
			$headers  = array( 'Content-type: text/html; charset=UTF-8' );
			$template = $this->email_template_to_speakers(
				'You have a new request to speak',
				$params
			);

			wp_mail( $speaker->user_email, 'CNCF - You have a new request to speak', $template, $headers );
		}
	}

	/**
	 * Sends email to moderator.
	 *
	 * @param array  $speakers speakers.
	 * @param object $params Params.
	 */
	public function send_email_to_moderator( $speakers, $params ) {
		$moderator_email        = 'speakers-bureau-messages@lists.cncf.io';
		$headers                = array( 'Content-type: text/html; charset=UTF-8' );
		$params['is_moderator'] = true;
		$params['speakers']     = array_map(
			function( $speaker_id ) {
				$speaker = get_userdata( $speaker_id );
				return array(
					'name'  => $speaker->display_name,
					'email' => $speaker->user_email,
				);
			},
			$speakers,
		);

		$template = $this->email_template_to_speakers(
			'You have a new request to speak',
			$params
		);

		wp_mail( $moderator_email, 'CNCF - You have a new request to speak', $template, $headers );
	}

	/**
	 * Fetch Speakers IDs.
	 */
	public function fetch_speakers_ids() {
		$params          = $this->get_base_params( array( 'fields' => 'ID' ) );
		$current_filters = array();
		$search_filters  = Speakers::get_filter_keys();

		foreach ( $search_filters as $filter_name ) {
			$value = 'project' === $filter_name ? Fuerza_Utils::get( '_project' ) : Fuerza_Utils::get( $filter_name );

			if ( ! empty( $value ) ) {
				$current_filters[ $filter_name ] = $value;
			}
		}

		$has_filters = ! empty( $current_filters );
		$items       = array();

		if ( $has_filters ) {
			$query_args  = Speakers::build_query_args( $current_filters, $params );
			$users_query = new \WP_User_Query( $query_args );
			$items = $users_query->get_results();
		}

		return compact( 'has_filters', 'items' );
	}

	/**
	 * Fetch all speakers.
	 */
	public function fetch_all_speakers() {
		$params      = $this->get_base_params();
		$users_query = new \WP_User_Query( $params );
		$data        = $users_query->get_results();

		return $data;
	}

	/**
	 * Get base params.
	 *
	 * @param array $args Args.
	 */
	private function get_base_params( $args = array() ) {
		$per_page     = 500;
		$default_args = array(
			'number'     => $per_page,
			'meta_key'   => 'last_name',
			'order'      => 'asc',
			'orderby'    => 'meta_value',
			'paged'      => 1,
			'role__in'   => array( 'um_speaker' ),
			'meta_query' => Speakers::get_avatar_query_args(),
		);

		$default_args['meta_query'][] = array(
			array(
				'key'   => 'account_status',
				'value' => 'approved',
			),
		);

		return wp_parse_args( $args, $default_args );
	}

	/**
	 * Is user allowed.
	 */
	private function is_allowed_user() {
		$allowed_roles = array( 'administrator', 'um_member' );
		$user          = wp_get_current_user();
		$is_allowed    = false;

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $allowed_roles ) ) {
				$is_allowed = true;
			}
		}

		return $is_allowed;
	}

	/**
	 * Email template to speakers.
	 *
	 * @param string $title Title.
	 * @param object $params Params.
	 */
	public function email_template_to_speakers( $title, $params ) {
		ob_start();

		$this->email_template_header( $title );

		?>
			<tbody>
				<tr>
					<td style="padding:20px 20px 0;">
						<div style="color:#484848;font-family:Arial;font-size:14px;line-height:150%;text-align:left;">
							<p>A CNCF Member has sent you a speaking request from the CNCF Speakers Bureau:</p>
						</div>
					</td>
				</tr>
				<tr>
					<td style="padding:0 20px;">
						<div style="color:#484848;font-family:Arial;font-size:14px;line-height:150%;text-align:left">
							<p><span style="font-weight:700">Name: </span><?php echo esc_html( $params['name'] ); ?></p>
							<p><span style="font-weight:700">Email: </span><a style="text-decoration: underline;color:#484848;" href="mailto:<?php echo esc_attr( $params['email'] ); ?>"><?php echo esc_attr( $params['email'] ); ?></a></p>
							<p><span style="font-weight:700">Subject: </span><?php echo esc_html( $params['subject'] ); ?></p>
							<?php if ( ! empty( $params['start_date'] ) ) : ?>
							<p><span style="font-weight:700">Event Start Date: </span><?php echo esc_html( $params['start_date'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $params['end_date'] ) ) : ?>
							<p><span style="font-weight:700">Event End Date: </span><?php echo esc_html( $params['end_date'] ); ?></p>
							<?php endif; ?>
							<p><span style="font-weight:700">Expected Number of Attendees: </span><?php echo esc_html( $params['attendees_number'] ); ?></p>
							<p><span style="font-weight:700">Main Language at Event: </span><?php echo esc_html( $params['language'] ); ?></p>
							<p><span style="font-weight:700">Travel Funding Will be Provided? </span><?php echo esc_html( $params['travel_funding'] ); ?></p>
							<p><?php echo esc_html( $params['message'] ); ?></p>

							<?php if ( isset( $params['is_moderator'] ) && $params['is_moderator'] ) : ?>
							<p>
								<span style="font-weight:700">Speakers: </span>
								<ul>
									<?php
									foreach ( $params['speakers'] as $speaker_info ) :
										printf( '<li>%s - %s</li>', esc_html( $speaker_info['name'] ), esc_attr( $speaker_info['email'] ) );
									endforeach;
									?>
								</ul>
							</p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			</tbody>
		<?php

		$this->email_template_footer();

		return ob_get_clean();
	}

	/**
	 * Email template header.
	 *
	 * @param string $title Title.
	 */
	public function email_template_header( $title ) {
		?>
		<html>
			<head>
				<title><?php echo esc_html( $title ); ?></title>
			</head>
			<body style="padding:0; margin:0;">
				<table width="100%" border="0" cellpadding="0" cellspacing="0">
					<thead>
						<tr>
							<td bgcolor="#252960" height="100" style="text-align: center"><img width="200" src="<?php echo esc_url( get_stylesheet_directory_uri() ) . '/images/cncf-white-logo.png'; ?>" alt=""></td>
						</tr>
					</thead>
		<?php
	}

	/**
	 * Email template footer.
	 */
	public function email_template_footer() {
		?>
					<tfoot>
						<tr>
							<td style="padding:0 20px;">
								<div style="border-bottom:1px solid #dddddd;"></div>
							</td>
						</tr>
					</tfoot>
				</table>
			</body>
		</html>
		<?php
	}

	/**
	 * Handle ajax is allowed.
	 */
	public function handle_ajax_is_allowed_role() {
		if ( ! Fuerza_Utils::is_request_ajax() ) {
			wp_send_json_error( array( 'message' => 'Invalid request' ), 500 );
		}

		wp_send_json_success(
			array(
				'isAllowed' => $this->is_allowed_user(),
				'info'      => md5( get_current_user_id() ),
			)
		);
	}

	/**
	 * Get instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

Speakers_Contact::get_instance();
