<?php

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

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
	 * @param string $entity The CiviCRM entity the API call is for.
	 * @param string $action The API action of the entity the call is to be performed.
	 * @param array  $params An array of parameters for the API call. This could be selects, wheres, joins, etc.
	 * @param array  $options A combination of API options and/or remote call options.
	 * @param string $api_version The version of the CiviCRM API the call is for.
	 *
	 * @return array
	 */
	public function api_wrapper( $entity, $action, $params, $options=[], $api_version = '3' ) {
		$connection = $this->plugin->admin->setting_get('connection');
		$profiles = $this->get_profiles();

		// Return error if we can't locate the profile.
		if ( !isset( $profiles[$connection] ) ) {
			return [
				'is_error' => 1,
				'error_message' => __('Profile not found', 'civicrm-wp-member-sync'),
				'error_code' => 'profile_not_found',
			];
		}

		// Perform the API call
		try {
			$result = call_user_func( $profiles[$connection]['function'], $connection, $entity, $action, $params, $options, $api_version );
		} catch ( \Exception $e ) {
			// Return error from calling profile function.
			return [
				'is_error' => 1,
				'error_message' => $e->getMessage(),
				'error_code' => $e->getCode(),
			];
		}

		// If we get an error back from the API call, make sure it is set as an error and pass the error along.
		if ( !empty( $result['is_error'] ) ) {
			return [
				'is_error' => 1,
				'error_message' => $result['error_message'],
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
	 *
	 * **Profiles should normally consist of the following properties:**
	 *  - title (required) : The title of the profile
	 *  - function (required) : The function that should be called for the profile. The API wrapper function for the profile.
	 *  - url (required for Curl/CurlAuthX) : If provided, this should be the API3 remote url that will be used for API3 Curl/CurlAuthX calls. \
	 * 		See https://github.com/CiviMRF/CMRF_Abstract_Core/blob/master/CMRF/Connection/AbstractCurlConnection.php#L123
	 *  - urlV4 (required for Curl/CurlAuthX) : If provided, this should be the API4 remote url that will be used for API4 Curl/CurlAuthX calls. \
	 * 		See https://github.com/CiviMRF/CMRF_Abstract_Core/blob/master/CMRF/Connection/AbstractCurlConnection.php#L123
	 *  - connector (required) : type of connection for the call. Options: local, curl, curlauthx
	 *  - site_key (required for Curl/CurlAuthX) : If provided, the site_key used in permission of remote calls.
	 *  - api_key (required for Curl/CurlAuthX) : If provided, the api_key used in permission of remote calls.
	 *  - validated : Whether the profile's connection has been validated.
	 *
	 * @return array
	 */
	public function get_profiles() {
		static $profiles = null;
		if ( is_array( $profiles ) ) {
			return $profiles;
		}

		$profiles = array();

		// Add local api wrapper if CiviCRM is installed with this WP site.
		// This will become the default profile if no other profile is defined through WPCMRF plugin.
		require_once( CIVI_WP_MEMBER_SYNC_PLUGIN_PATH . 'includes/civi-wp-ms-local-civicrm.php' );
		$profiles = Civi_WP_Member_Sync_Local_CiviCRM::loadProfile( $profiles );

		// If the WPCMRF plugin is installed, get it's saved profiles and append to the local install if found.
		if ( function_exists('wpcmrf_get_core') ) {
			$core = \wpcmrf_get_core();
			$wpcmrf_profiles = $core->getConnectionProfiles();

			foreach( $wpcmrf_profiles as $profile ) {
				if (!empty($profile['validated'])) {
					$profile_name = 'wpcmrf_profile_' . $profile['id'];
					$profiles[$profile_name] = [
						'title' => $profile['label'],
						'function' => 'Civi_WP_Member_Sync_CiviCRMAPI\civicrm_wpcmrf_api',
						'url' => $profile['url'],
						'urlV4' => $profile['urlV4'],
						'connector' => $profile['connector'],
						'site_key' => $profile['site_key'],
						'api_key' => $profile['api_key'],
						'validated' => $profile['validated'],
					];
				}
			}
		}

		return $profiles;
	}

	/**
	 * This is the callback function for non-local profiles.
	 *
	 * @param string $profile The name of the profile.
	 * @param string $entity The API entity the call is for.
	 * @param string $action the action for the API entity to be performed.
	 * @param array $options List of options for the API call.
	 * @param int $api_version Which API version should be used for the call.
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

		// This instantiates the CMRF\Wordpress\CORE class and calls the correct connector (curl/curlauthx).
		// Local calls will be handled by Civi_WP_Member_Sync_Local_CiviCRM class.
		$core = \wpcmrf_get_core();
		$call = $core->createCall( $profile_id, $entity, $action, $params, $options, NULL, $api_version );
		$core->executeCall( $call );
		return $call->getReply();
	}

	/**
	 * Checks we have a Connection setting and that the connection exists in the profiles and has been validated.
	 *
	 * @retun bool Whether we have a validated connection.
	 */
	public function check_civicrm_installation() {
		// Get our CiviCRM connection setting.
		$connection = (string) $this->plugin->admin->setting_get( 'connection' );
		$profiles = $this->get_profiles();

		// If we don't have a connection set or the profile for the connection doesn't exist, we have no installation to work with.
		// We also need to make sure the connection was validated.
		if ( empty( $connection ) || !isset($profiles[$connection]) || empty($profiles[$connection]['validated']) ) {
			return false;
		}

		return true;
	}
}
