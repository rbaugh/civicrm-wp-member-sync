<?php
class Civi_WP_Member_Sync_CiviCRMAPI {

	/**
	 * Plugin object.
	 *
	 * @since 0.1
	 * @access public
	 * @var Civi_WP_Member_Sync
	 */
	public $plugin;

	/**
	 * Constructor.
	 *
	 * @since 0.1
	 *
	 * @param object $plugin The plugin object.
	 */
	public function __construct( $plugin ) {

		// Store reference to plugin.
		$this->plugin = $plugin;

	}

	/**
	 * A wrapper function that calls CiviCRM API based on a configured profile.
	 * The profile could be a local instance of CiviCRM or a remote instance.
	 *
	 * @param string $profile The profile to use for the call. A null value will get the first avaialble profile.
	 * @param string $entity The CiviCRM entity the API call is for.
	 * @param string $action The API action of the entity the call is to be performed.
	 * @param array  $params An array of parameters for the API call. This could be selects, wheres, joins, etc.
	 * @param array  $options A combination of API options and/or remote call options.
	 * @param string $api_version The version of the CiviCRM API the call is for.
	 *
	 * @return array
	 */
	public function api_wrapper( $profile, $entity, $action, $params, $options=[], $api_version = '3' ) {
		$profiles = $this->get_profiles();

		if ( empty($profile) ) {
			$profile = array_key_first($profiles);
		}

		if ( !isset( $profiles[$profile] ) ) {
			return [
				'is_error' => 1,
				'error_message' => __('Profile not found', 'civicrm-wp-member-sync'),
				'error_code' => 'profile_not_found',
			];
		}

		if ( isset( $profiles[$profile]['file'] ) ) {
			require_once( $profiles[$profile]['file'] );
		}

		// Perform the API call
		try {
			$result = call_user_func( $profiles[$profile]['function'], $profile, $entity, $action, $params, $options, $api_version );
		} catch ( \Exception $e ) {
			$this->plugin->log_error( [ $e->getMessage() ] );
		}

		if ( !empty( $result['is_error'] ) ) {
			return [
				'is_error' => 1,
				'error_message' => __( $result['error_message'], 'civicrm-wp-member-sync' ),
				'error_code' => $result['error_code'],
			];
		}

		if ( isset( $result['values'] ) ) {
			return $result['values'];
		}

		return $result;
	}

	/**
	 * Returns a list of possible profiles
	 * @return array
	 */
	public function get_profiles() {
		static $profiles = null;
		if ( is_array( $profiles ) ) {
			return $profiles;
		}

		$profiles = array();

		// Local CiviCRM connection
		require_once( CIVI_WP_MEMBER_SYNC_PLUGIN_PATH . 'includes/civi-wp-ms-local-civicrm.php' );
		$profiles = Civi_WP_Member_Sync_Local_CiviCRM::loadProfile( $profiles );

		if ( function_exists('wpcmrf_get_core') ) {
			$core = \wpcmrf_get_core();
			$wpcmrf_profiles = $core->getConnectionProfiles();

			foreach( $wpcmrf_profiles as $profile ) {
				$profile_name = 'wpcmrf_profile_'.$profile['id'];
				$profiles[$profile_name] = [
					'title' => $profile['label'],
					'function' => 'Civi_WP_Member_Sync_CiviCRMAPI\civicrm_wpcmrf_api',
					'url' => $profile['url'],
					'connector' => $profile['connector'],
					'site_key' => $profile['site_key'],
					'api_key' => $profile['api_key'],
				];
			}
		}

		return $profiles;
	}

	/**
	 * Calls wpcmrf_api() for the given profile (i.e. wpcmrf connection)
	 */
	public static function civicrm_wpcmrf_api( $profile, $entity, $action, $params, $options = [], $api_version = '3' ) {
		$profile_id = substr( $profile, 15 );

		/**
		 * TODO: Prevent caching of specific error codes.
		 *
		 * This is likely something that needs to happen in CMRF plugin.
		 * See https://github.com/CiviMRF/CMRF_Abstract_Core/issues/24
		 *
		 */
		// If options isn't already set, set it to a default value.
		$options['cache'] ??= '15 minutes';

		$core = \wpcmrf_get_core();
		$call = $core->createCall( $profile_id, $entity, $action, $params, $options, NULL, $api_version );
		$core->executeCall( $call );
		return $call->getReply();
	}

	/**
	 * Checks we can connect to CiviCRM. A low-permission API call is sufficient to establish whether or not the
	 * connection is possible. Even if the connection is rejected due to insufficient permissions, if we get a
	 * valid response, we can confirm the installation exists.
	 */
	public function check_civicrm_installation( $profile = null ) {
		// If no profile has been provided, get the profile id for the local CiviCRM installation.
		if ( is_null( $profile ) ) {
			$profiles = $this->get_profiles();
			$profile = array_key_first( $profiles );
		}

		// Prevent multiple installation checks that slow down processing
		static $installation;

		if ( $installation !== null ) {
			return [
				'is_error' => $installation ? 0 : 1,
				'message'  => $installation
					? "$profile CiviCRM installation is accessible."
					: "$profile CiviCRM installation is not accessible.",
			];
		}

		// Try a low-permission API call (Contact.getfields - only requires access CiviCRM permission)
		$result = $this->api_wrapper( $profile, 'Contact', 'getfields', [], [] );
		$error_message = strtolower( $result['error_message'] ?? '' );
		$has_perm_err  = strpos( $error_message, 'insufficient permission' ) !== false;

		if ( empty( $result['is_error'] ) || $has_perm_err ) {
			// Can establish a connection.
			// If Contact.getfields failed, it may have just been a permission issue, but at least we can confirm the installation exists.
			$installation = true;
			return [
				'is_error' => 0,
				'message'  => "$profile CiviCRM installation is accessible." . ( $has_perm_err ? ' But user has insufficient permissions.' : '' ),
			];
		}

		// Installation probably unreachable
		$installation = false;
		return [
			'is_error' => 1,
			'message'  => "$profile CiviCRM installation could not be accessed. " . ($result['error_message'] ?? 'Unknown error'),
		];
	}
}
