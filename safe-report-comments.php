<?php
/**
 * Plugin Name: Safe Report Comments
 * Plugin Script: safe-report-comments.php
 * Plugin URI: http://wordpress.org/extend/plugins/safe-report-comments/
 * Description: This script gives visitors the possibility to flag/report a comment as inapproriate.
 * After reaching a threshold the comment is moved to moderation. If a comment is approved once by a moderator future reports will be ignored.
 * Version: 0.4.1
 * Author: Thorsten Ott, Daniel Bachhuber, Automattic
 * Author URI: http://automattic.com
 * Text Domain: safe-report-comments
 *
 * @package Safe_Report_Commments
 */

if ( ! class_exists( 'Safe_Report_Comments' ) ) {

	/**
	 * Safe Report Comments
	 */
	class Safe_Report_Comments {

		/**
		 * Plugin prefix
		 *
		 * @var $_plugin_prefix
		 */
		private $_plugin_prefix = 'srcmnt';

		/**
		 * Admin notices
		 *
		 * @var $_admin_notices
		 */
		private $_admin_notices = array();

		/**
		 * Nonce key
		 *
		 * @var $_nonce_key
		 */
		private $_nonce_key     = 'flag_comment_nonce';

		/**
		 * Auto init
		 *
		 * @var $_auto_init
		 */
		private $_auto_init     = true;

		/**
		 * Storage cookie
		 *
		 * @var $_storagecookie
		 */
		private $_storagecookie = 'sfrc_flags';

		/**
		 * Plugin url
		 *
		 * @var $plugin_url
		 */
		public $plugin_url      = false;

		/**
		 * Thank you message
		 *
		 * @var $thank_you_message
		 */
		public $thank_you_message       = '';

		/**
		 * Invalid nonce message
		 *
		 * @var $invalid_nonce_message
		 */
		public $invalid_nonce_message   = '';

		/**
		 * Invalid values message
		 *
		 * @var $invalid_values_message
		 */
		public $invalid_values_message  = '';

		/**
		 * Already flagged message
		 *
		 * @var $already_flagged_message
		 */
		public $already_flagged_message = '';

		/**
		 * Already flagged note
		 *
		 * Displayed instead of the report link when a comment was flagged.
		 *
		 * @var $already_flagged_note
		 */
		public $already_flagged_note    = '';

		/**
		 * Filter vars
		 *
		 * @var $filter_vars
		 */
		public $filter_vars = array(
			'thank_you_message',
			'invalid_nonce_message',
			'invalid_values_message',
			'already_flagged_message',
			'already_flagged_note',
		);

		/**
		 * No cookie grace
		 *
		 * Amount of possible attempts transient hits per comment before a COOKIE enabled
		 * negative check is considered invalid transient hits will be counted up per ip
		 * any time a user flags a comment. This number should be always lower than your
		 * threshold to avoid manipulation.
		 *
		 * @var $no_cookie_grace
		 */
		public $no_cookie_grace    = 3;

		/**
		 * Cookie lifetime
		 *
		 * After this duration a user can report a comment again.
		 *
		 * @var $cookie_lifetime
		 */
		public $cookie_lifetime    = WEEK_IN_SECONDS;

		/**
		 * Transient lifetime
		 *
		 * Cookie fallback.
		 *
		 * @var $transient_lifetime
		 */
		public $transient_lifetime = DAY_IN_SECONDS;

		/**
		 * __construct
		 *
		 * @param bool $auto_init Automatically add the flagging link.
		 */
		public function __construct( $auto_init = true ) {

			$this->_admin_notices = get_transient( $this->_plugin_prefix . '_notices' );
			if ( ! is_array( $this->_admin_notices ) ) {
				$this->_admin_notices = array();
			}
			$this->_admin_notices = array_unique( $this->_admin_notices );
			$this->_auto_init = $auto_init;

			if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && true === DOING_AJAX ) ) {
				add_action( 'init', array( $this, 'frontend_init' ) );
			} elseif ( is_admin() ) {
				add_action( 'admin_init', array( $this, 'backend_init' ) );
			}
			add_action( 'comment_unapproved_to_approved', array( $this, 'mark_comment_moderated' ), 10, 1 );

			$this->thank_you_message       = __( 'Thank you for your feedback. We will look into it.', 'safe-report-comments' );
			$this->invalid_nonce_message   = __( 'It seems you already reported this comment.', 'safe-report-comments' );
			$this->invalid_values_message  = __( 'Cheating huh?', 'safe-report-comments' );
			$this->already_flagged_message = __( 'It seems you already reported this comment.', 'safe-report-comments' );

			// apply some filters to easily alter the frontend messages.
			// add_filter( 'safe_report_comments_thank_you_message', 'alter_message' );
			// this or similar will do the job.
			foreach ( $this->filter_vars as $var ) {
				$this->{$var} = apply_filters( 'safe_report_comments_' . $var , $this->{$var} );
			}
		}

		/**
		 * __destruct
		 */
		public function __destruct() {

		}

		/**
		 * Initialize backend functions
		 * - register_admin_panel
		 * - admin_header
		 */
		public function backend_init() {
			do_action( 'safe_report_comments_backend_init' );

			add_settings_field( $this->_plugin_prefix . '_enabled', __( 'Allow comment flagging', 'safe-report-comments' ), array( $this, 'comment_flag_enable' ), 'discussion', 'default' );
			register_setting( 'discussion', $this->_plugin_prefix . '_enabled' );

			if ( ! $this->is_enabled() ) {
				return;
			}

			add_settings_field( $this->_plugin_prefix . '_threshold', __( 'Flagging threshold', 'safe-report-comments' ), array( $this, 'comment_flag_threshold' ), 'discussion', 'default' );
			register_setting( 'discussion', $this->_plugin_prefix . '_threshold', array( $this, 'check_threshold' ) );
			add_filter( 'manage_edit-comments_columns', array( $this, 'add_comment_reported_column' ) );
			add_action( 'manage_comments_custom_column', array( $this, 'manage_comment_reported_column' ), 10, 2 );

			add_action( 'admin_menu', array( $this, 'register_admin_panel' ) );
			add_action( 'admin_head', array( $this, 'admin_header' ) );
		}

		/**
		 * Initialize frontend functions
		 */
		public function frontend_init() {

			if ( ! $this->is_enabled() ) {
				return;
			}

			if ( ! $this->plugin_url ) {
				$this->plugin_url = plugins_url( false, __FILE__ );
			}

			do_action( 'safe_report_comments_frontend_init' );

			add_action( 'wp_ajax_safe_report_comments_flag_comment', array( $this, 'flag_comment' ) );
			add_action( 'wp_ajax_nopriv_safe_report_comments_flag_comment', array( $this, 'flag_comment' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'action_enqueue_scripts' ) );

			if ( $this->_auto_init ) {
				add_filter( 'comment_reply_link', array( $this, 'add_flagging_link' ) );
			}
			add_action( 'comment_report_abuse_link', array( $this, 'print_flagging_link' ) );

			add_action( 'template_redirect', array( $this, 'add_test_cookie' ) ); // need to do this at template_redirect because is_feed isn't available yet.
		}

		/**
		 * Action enqueue scripts
		 */
		public function action_enqueue_scripts() {

			// Use home_url() if domain mapped to avoid cross-domain issues.
			if ( home_url() != site_url() ) {
				$ajaxurl = home_url( '/wp-admin/admin-ajax.php' );
			} else {
				$ajaxurl = admin_url( 'admin-ajax.php' );
			}

			$ajaxurl = apply_filters( 'safe_report_comments_ajax_url', $ajaxurl );

			wp_enqueue_script( $this->_plugin_prefix . '-ajax-request', $this->plugin_url . '/js/ajax.js', array( 'jquery' ) );
			wp_localize_script( $this->_plugin_prefix . '-ajax-request', 'SafeCommentsAjax', array( 'ajaxurl' => $ajaxurl ) ); // slightly dirty but needed due to possible problems with mapped domains.
		}

		/**
		 * Add test cookie
		 */
		public function add_test_cookie() {
			// Set a cookie now to see if they are supported by the browser.
			// Don't add cookie if it's already set; and don't do it for feeds.
			if ( ! is_feed() && ! isset( $_COOKIE[ TEST_COOKIE ] ) ) {
				@setcookie( TEST_COOKIE, 'WP Cookie check', 0, COOKIEPATH, COOKIE_DOMAIN );
				if ( SITECOOKIEPATH != COOKIEPATH ) {
					@setcookie( TEST_COOKIE, 'WP Cookie check', 0, SITECOOKIEPATH, COOKIE_DOMAIN );
				}
			}
		}

		/**
		 * Add necessary header scripts
		 * Currently only used for admin notices
		 */
		public function admin_header() {
			// print admin notice in case of notice strings given.
			if ( ! empty( $this->_admin_notices ) ) {
					add_action( 'admin_notices' , array( $this, 'print_admin_notice' ) );
			}
?>
<style type="text/css">
.column-comment_reported {
	width: 8em;
}
</style>
<?php

		}

		/**
		 * Add admin error messages
		 *
		 * @param string $message Text of admin notice.
		 */
		protected function add_admin_notice( $message ) {
			$this->_admin_notices[] = $message;
			set_transient( $this->_plugin_prefix . '_notices', $this->_admin_notices, 3600 );
		}

		/**
		 * Print a notification / error msg
		 */
		public function print_admin_notice() {
			?>
			<div id="message" class="updated fade"><h3>Safe Comments:</h3>
			<?php
			foreach ( (array) $this->_admin_notices as $notice ) {
				echo '<p>' . esc_html( $notice ) . '</p>';
			}
			?>
			</div>
			<?php
			$this->_admin_notices = array();
			delete_transient( $this->_plugin_prefix . '_notices' );
		}

		/**
		 * Callback for settings field
		 */
		public function comment_flag_enable() {
			$enabled = $this->is_enabled();
			?>
			<label for="<?php echo esc_attr( $this->_plugin_prefix ); ?>_enabled">
				<input name="<?php echo esc_attr( $this->_plugin_prefix ); ?>_enabled" id="<?php echo esc_attr( $this->_plugin_prefix ); ?>_enabled" type="checkbox" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Allow your visitors to flag a comment as inappropriate.', 'safe-report-comments' ); ?>
			</label>
			<?php
		}

		/**
		 * Callback for settings field
		 */
		public function comment_flag_threshold() {
			$threshold = (int) get_option( $this->_plugin_prefix . '_threshold' );
			?>
			<label for="<?php echo esc_attr( $this->_plugin_prefix ); ?>_threshold">
				<input size="2" name="<?php echo esc_attr( $this->_plugin_prefix ); ?>_threshold" id="<?php echo esc_attr( $this->_plugin_prefix ); ?>_threshold" type="text" value="<?php echo esc_attr( $threshold ); ?>" />
				<?php esc_html_e( 'Amount of user reports needed to send a comment to moderation?', 'safe-report-comments' ); ?>
			</label>
			<?php
		}

		/**
		 * Check if the functionality is enabled or not
		 */
		public function is_enabled() {
			$enabled = get_option( $this->_plugin_prefix . '_enabled' );
			if ( 1 == $enabled ) {
				$enabled = true;
			} else {
				$enabled = false;
			}
			return $enabled;
		}

		/**
		 * Validate threshold, callback for settings field
		 *
		 * @param string $value Submitted threshold value.
		 * @return int Validated threshold value.
		 */
		public function check_threshold( $value ) {
			if ( (int) $value <= 0 || (int) $value > 100 ) {
				$this->add_admin_notice( __( 'Please revise your flagging threshold and enter a number between 1 and 100' ), 'safe-report-comments' );
			}
			return (int) $value;
		}

		/**
		 * Helper function to serialize cookie values
		 *
		 * @param array $value Cookie data.
		 * @return string Serialized cookie data.
		 */
		private function serialize_cookie( $value ) {
			$value = $this->clean_cookie_data( $value );
			return base64_encode( json_encode( $value ) );
		}

		/**
		 * Helper function to unserialize cookie values
		 *
		 * @param string $value Raw cookie data.
		 * @return array Cleansed data.
		 */
		private function unserialize_cookie( $value ) {
			$data = json_decode( base64_decode( $value ) );
			return $this->clean_cookie_data( $data );
		}

		/**
		 * Clean cookie data
		 *
		 * @param array $data Cookie data.
		 * @return array Cleansed data.
		 */
		private function clean_cookie_data( $data ) {
			$clean_data = array();

			if ( ! is_array( $data ) ) {
				$data = array();
			}

			foreach ( $data as $comment_id => $count ) {
				if ( is_numeric( $comment_id ) && is_numeric( $count ) ) {
					$clean_data[ $comment_id ] = $count;
				}
			}

			return $clean_data;
		}

		/**
		 * Mark a comment as being moderated so it will not be autoflagged again
		 * called via comment transient from unapproved to approved
		 *
		 * @param object $comment Comment object.
		 */
		public function mark_comment_moderated( $comment ) {
			if ( isset( $comment->comment_ID ) ) {
				update_comment_meta( $comment->comment_ID, $this->_plugin_prefix . '_moderated', true );
			}
		}

		/**
		 * Check if this comment was flagged by the user before
		 *
		 * @param int $comment_id The comment ID.
		 * @return bool Whether the comment is already flagged or not.
		 */
		public function already_flagged( $comment_id ) {

			// check if cookies are enabled and use cookie store.
			if ( isset( $_COOKIE[ TEST_COOKIE ] ) ) {
				if ( isset( $_COOKIE[ $this->_storagecookie ] ) && isset( $this->_storagecookie ) ) {
					$data = $this->unserialize_cookie(
						sanitize_text_field( wp_unslash(
							$_COOKIE[ $this->_storagecookie ]
						) )
					);
					if ( is_array( $data ) && isset( $data[ $comment_id ] ) ) {
						return true;
					}
				}
			}

			// in case we don't have cookies. fall back to transients, block based on IP/User Agent.
			if (
				isset( $_SERVER['REMOTE_ADDR'] ) &&
				$transient = get_transient( md5( $this->_storagecookie . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) )
			) {
				if (
					// check if no cookie and transient is set.
					 ( ! isset( $_COOKIE[ TEST_COOKIE ] ) && isset( $transient[ $comment_id ] ) ) ||
					// or check if cookies are enabled and comment is not flagged but transients show a relatively high number and assume fraud.
					 ( isset( $_COOKIE[ TEST_COOKIE ] )  && isset( $transient[ $comment_id ] ) && $transient[ $comment_id ] >= $this->no_cookie_grace )
					) {
						return true;
				}
			}
			return false;
		}

		/**
		 * Report a comment and send it to moderation if threshold is reached
		 *
		 * @param int $comment_id The comment ID.
		 */
		public function mark_flagged( $comment_id ) {
			$data = array();
			if ( isset( $_COOKIE[ TEST_COOKIE ] ) ) {
				if ( isset( $_COOKIE[ $this->_storagecookie ] ) ) {
					$data = $this->unserialize_cookie(
						sanitize_text_field( wp_unslash(
							$_COOKIE[ $this->_storagecookie ]
						) )
					);
					if ( ! isset( $data[ $comment_id ] ) ) {
						$data[ $comment_id ] = 0;
					}
					$data[ $comment_id ]++;
					$cookie = $this->serialize_cookie( $data );
					@setcookie( $this->_storagecookie, $cookie, time() + $this->cookie_lifetime, COOKIEPATH, COOKIE_DOMAIN );
					if ( SITECOOKIEPATH != COOKIEPATH ) {
						@setcookie( $this->_storagecookie, $cookie, time() + $this->cookie_lifetime, SITECOOKIEPATH, COOKIE_DOMAIN );
					}
				} else {
					if ( ! isset( $data[ $comment_id ] ) ) {
						$data[ $comment_id ] = 0;
					}
					$data[ $comment_id ]++;
					$cookie = $this->serialize_cookie( $data );
					@setcookie( $this->_storagecookie, $cookie, time() + $this->cookie_lifetime, COOKIEPATH, COOKIE_DOMAIN );
					if ( SITECOOKIEPATH != COOKIEPATH ) {
						@setcookie( $this->_storagecookie, $cookie, time() + $this->cookie_lifetime, SITECOOKIEPATH, COOKIE_DOMAIN );
					}
				}
			}
			// in case we don't have cookies. fall back to transients, block based on IP, shorter timeout to keep mem usage low and don't lock out whole companies.
			$key = md5( $this->_storagecookie . sanitize_text_field( wp_unslash( ( ! empty( $_SERVER['REMOTE_ADDR'] ? $_SERVER['REMOTE_ADDR'] : '' ) ) ) ) );
			$transient = get_transient( $key );
			if ( ! $transient ) {
				set_transient( $key, array( $comment_id => 1 ), $this->transient_lifetime );
			} else {
				$transient[ $comment_id ]++;
				set_transient( $key, $transient, $this->transient_lifetime );
			}

			$threshold = (int) get_option( $this->_plugin_prefix . '_threshold' );
			$current_reports = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
			$current_reports++;
			update_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', $current_reports );

			// we will not flag a comment twice. the moderator is the boss here.
			$already_reported = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
			$already_moderated = get_comment_meta( $comment_id, $this->_plugin_prefix . '_moderated', true );
			if ( true == $already_reported && true == $already_moderated ) {
				// But maybe the boss wants to allow comments to be reflagged.
				if ( ! apply_filters( 'safe_report_comments_allow_moderated_to_be_reflagged', false ) ) {
					return;
				}
			}

			if ( $current_reports >= $threshold ) {
				do_action( 'safe_report_comments_mark_flagged', $comment_id );
				wp_set_comment_status( $comment_id, 'hold' );
			}
		}

		/**
		 * Die() with or without screen based on JS availability
		 *
		 * @param string $message Text string for body.
		 */
		private function cond_die( $message ) {
			if ( isset( $_REQUEST['no_js'] ) && true == (boolean) $_REQUEST['no_js'] ) {
				wp_die( esc_html( $message ), esc_html__( 'Safe Report Comments Notice', 'safe-report-comments' ), array( 'response' => 200 ) );
			} else {
				die( esc_html( $message ) );
			}
		}

		/**
		 * Ajax callback to flag/report a comment
		 */
		public function flag_comment() {
			if ( empty( $_REQUEST['comment_id'] ) || (int) $_REQUEST['comment_id'] != $_REQUEST['comment_id'] ) {
				$this->cond_die( $this->invalid_values_message );
			}

			$comment_id = (int) $_REQUEST['comment_id'];
			if ( $this->already_flagged( $comment_id ) ) {
				$this->cond_die( $this->already_flagged_message );
			}

			if ( isset( $_REQUEST['sc_nonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_REQUEST['sc_nonce'] ) );
			} else {
				$nonce = '';
			}

			// checking if nonces help.
			if ( ! wp_verify_nonce( $nonce, $this->_plugin_prefix . '_' . $this->_nonce_key ) ) {
				$this->cond_die( $nonce . $this->invalid_nonce_message );
			} else {
				$this->mark_flagged( $comment_id );
				$this->cond_die( $this->thank_you_message );
			}

		}

		/**
		 * Print flagging link
		 *
		 * Hooked into comment_report_abuse_link action allowing use via do_action instead of template tag
		 *
		 * @param int    $comment_id The current comment ID.
		 * @param int    $result_id {unknown}.
		 * @param string $text Link text.
		 */
		public function print_flagging_link( $comment_id = '', $result_id = '', $text = false ) {
			$text = $text ?: __( 'Report comment', 'safe-report-comments' );
			echo wp_kses( $this->get_flagging_link( $comment_id, $result_id, $text ) );
		}

		/**
		 * Output Link to report a comment
		 *
		 * @param int    $comment_id The current comment ID.
		 * @param int    $result_id {unknown}.
		 * @param string $text Link text.
		 * @return string The HTML markup for the link.
		 */
		public function get_flagging_link( $comment_id = '', $result_id = '', $text = false ) {
			global $in_comment_loop;
			if ( empty( $comment_id ) && ! $in_comment_loop ) {
				return __( 'Wrong usage of print_flagging_link().', 'safe-report-comments' );
			}
			if ( empty( $comment_id ) ) {
				$comment_id = get_comment_ID();
			} else {
				$comment_id = (int) $comment_id;
				if ( ! get_comment( $comment_id ) ) {
					return __( 'This comment does not exist.', 'safe-report-comments' );
				}
			}
			if ( empty( $result_id ) ) {
				$result_id = 'safe-comments-result-' . $comment_id;
			}

			$result_id = apply_filters( 'safe_report_comments_result_id', $result_id );
			$text = $text ?: __( 'Report comment', 'safe-report-comments' );
			$text = apply_filters( 'safe_report_comments_flagging_link_text', $text );

			$nonce = wp_create_nonce( $this->_plugin_prefix . '_' . $this->_nonce_key );
			$params = array(
							'action'     => 'safe_report_comments_flag_comment',
							'sc_nonce'   => $nonce,
							'comment_id' => $comment_id,
							'result_id'  => $result_id,
							'no_js'      => true,
			);

			if ( $this->already_flagged( $comment_id ) ) {
				return esc_html( $this->already_flagged_note );
			}

			return apply_filters( 'safe_report_comments_flagging_link', sprintf( '<span id="%s"><a class="hide-if-no-js" href="javascript:void(0);" onclick="safe_report_comments_flag_comment( \'%s\', \'%s\', \'%s\');">%s</a></span>',
				esc_attr( $result_id ),
				esc_js( $comment_id ),
				esc_js( $nonce ),
				esc_js( $result_id ),
				esc_html( $text )
			) );

		}

		/**
		 * Callback function to automatically hook in the report link after the comment reply link.
		 * If you want to control the placement on your own define no_autostart_safe_report_comments in your functions.php file and initialize the class
		 * with $safe_report_comments = new Safe_Report_Comments( $auto_init = false );
		 *
		 * @param string $comment_reply_link The HTML markup for the comment reply link.
		 * @return string The new HTML markup for the comment reply link.
		 */
		public function add_flagging_link( $comment_reply_link ) {
			if ( ! preg_match_all( '#^(.*)(<a.+class=["|\']comment-(reply|login)-link["|\'][^>]+>)(.+)(</a>)(.*)$#msiU', $comment_reply_link, $matches ) ) {
				return '<!-- safe-comments add_flagging_link not matching -->' . $comment_reply_link;
			}

			$comment_reply_link = $matches[1][0] . $matches[2][0] . $matches[4][0] . $matches[5][0] . '<span class="safe-comments-report-link">' . $this->get_flagging_link() . '</span>' . $matches[6][0];
			return apply_filters( 'safe_report_comments_comment_reply_link', $comment_reply_link );
		}

		/**
		 * Callback function to add the report counter to comments screen. Remove action manage_edit-comments_columns if not desired
		 *
		 * @param array $comment_columns An array of column headers.
		 * @return array An array of column headers.
		 */
		public function add_comment_reported_column( $comment_columns ) {
			$comment_columns['comment_reported'] = _x( 'Reported', 'column name', 'safe-report-comments' );
			return $comment_columns;
		}

		/**
		 * Callback function to handle custom column. remove action manage_comments_custom_column if not desired
		 *
		 * @param string $column_name The name of the column to display.
		 * @param int    $comment_id The current comment ID.
		 */
		public function manage_comment_reported_column( $column_name, $comment_id ) {
			switch ( $column_name ) {
				case 'comment_reported':
					$reports = 0;
					$already_reported = get_comment_meta( $comment_id, $this->_plugin_prefix . '_reported', true );
					if ( $already_reported > 0 ) {
						$reports = (int) $already_reported;
					}
					echo esc_html( $reports );
					break;
				default:
					break;
			}
		}

	}
}

if ( ! defined( 'no_autostart_safe_report_comments' ) ) {
	$safe_report_comments = new Safe_Report_Comments;
}
