<?php

// All functions are Wordpress-specific.
defined( 'ABSPATH' ) or die( 'No direct access' );

class Civi_WP_Member_Sync_Local_CiviCRM {

	/**
	 * Handles calling the local CiviCRM instance API functions.
	 *
	 * @param string $profile The profile from the wrapper call. Not used but part of the wrapper.
	 * @param string $entity The CiviCRM entity the API call is for.
	 * @param string $action The API action of the entity the call is to be performed.
	 * @param array  $params An array of parameters for the API call. This could be selects, wheres, joins, etc.
	 * @param array  $options Options for the API call.
	 * @param string $api_version The version of the CiviCRM API the call is for.
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function api( $profile, $entity, $action, $params, $options = [], $api_version = '3' ) {
		$contract_errors = [];
		if ( empty( $entity ) ) {
			$contract_errors[] = sprintf( __( "'%s' is required" ), '$entity' );
		}
		if ( empty( $action ) ) {
			$contract_errors[] = sprintf( __( "'%s' is required" ), '$action' );
		}
		if ( ! is_array( $params ) ) {
			$contract_errors = sprintf( __( "'%s' must be an array" ), '$params' );
		}

		if(!empty($contract_errors)){
			throw new \Exception( implode( '\r\n', $contract_errors ) );
		}

		if ( ! civi_wp()->initialize() ) {
			return [ 'error' => 'CiviCRM not Initialized', 'is_error' => '1' ];
		}

		/*
		 * Copied from CiviCRM invoke function as there is a problem with timezones
		 * when the local connection is used.
		 *
		 * CRM-12523
		 * WordPress has it's own timezone calculations
		 * CiviCRM relies on the php default timezone which WP
		 * overrides with UTC in wp-settings.php
		 */
		$wpBaseTimezone = date_default_timezone_get();
		$wpUserTimezone = get_option( 'timezone_string' );
		if ( $wpUserTimezone ) {
			date_default_timezone_set( $wpUserTimezone );
			\CRM_Core_Config::singleton()->userSystem->setMySQLTimeZone();
		}

		try {
			switch($api_version) {
				case '3':
					if ( ! empty( $options ) ) {
						$params['options'] = $options;
					}
					$result = civicrm_api3( $entity, $action, $params );
					break;
				case '4':
					$index = null;
					if ( isset($options['index']) ) {
						$index = $options['index'];
						unset( $options['index'] );
					}
					$result = civicrm_api4( $entity, $action, $params, $index )->getArrayCopy();
					break;
			}

			return $result;
		}
		catch ( CRM_Core_Exception $e ) {
			throw new \Exception($e->getMessage(), $e->getCode(), $e);
		}
		finally {
			/*
			 * Reset the timezone back the original setting.
			 */
			if ( $wpBaseTimezone ) {
				date_default_timezone_set( $wpBaseTimezone );
			}
		}
	}

	/**
	 * Load local CiviCRM Profile.
	 * Only when CiviCRM is installed.
	 *
	 * @param $profiles
	 *
	 * @return array
	 */
	public static function loadProfile( $profiles ) {
		if ( function_exists( 'civi_wp' ) ) {
			$profiles['_local_civi_'] = [
				'title'    => __( 'Local CiviCRM' ),
				'function' => [ 'Civi_WP_Member_Sync_Local_CiviCRM', 'api' ],
			];
		}

		return $profiles;
	}

}
