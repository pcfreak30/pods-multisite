<?php
/**
 * Class Pods_Multisite_Sync
 */

! defined( 'PODS_MULTISITE_DIR' ) and die();

class Pods_Multisite_Sync {

	private $option = 'multisite_sync_to_sites';

	private $tab = 'pods-multisite';

	/**
	 * @var Pods_Multisite_Sync
	 */
	private static $instance;

	/**
	 * @return Pods_Multisite_Sync
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Pods_Multisite_Sync constructor.
	 */
	private function __construct() {
		// @todo Non ajax method
		add_action( 'pods_admin_ajax_success_save_pod', array( $this, 'sync_pod' ) );

		add_filter( 'pods_admin_setup_edit_tabs', array( $this, 'pod_settings_tab' ) );
		add_filter( 'pods_admin_setup_edit_options', array( $this, 'pod_settings_options' ) );
	}

	/**
	 * @param mixed $pod (optional) a Pod name or array data
	 */
	public function sync_pod( $pod ) {

		$api = pods_api();
		$pod = $api->load_pod( $pod );

		if ( empty( $pod['options'][ $this->option ] ) || ! is_array( $pod['options'][ $this->option ] ) ) {
			return;
		}

		unset( $pod['id'] );
		foreach ( $pod['fields'] as $name => $field ) {
			unset( $pod['fields'][ $name ]['id'] );
		}

		$pod_name = $pod['name'];
		$pod_old_name = false;
		if ( isset( $pod['options']['old_name'] ) && $pod['options']['old_name'] != $pod['name'] ) {
			$pod_old_name = $pod['options']['old_name'];
		}

		// Get the relationships
		$rel = array();
		foreach ( $pod['fields'] as $name => $field ) {
			if ( 'pick' == $field['type'] && ! empty( $field['sister_id'] && ! empty( $field['pick_val'] ) ) ) {
				// Load sister field by ID, no pod param needed since the ID is always unique
				$rel[ $name ] = array(
					'field' => $api->load_field( array( 'id' => $field['sister_id'] ), false ),
					'pod' => $field['pick_val']
				);
			}
		}

		// Get the current site ID before it gets overwritten by the loop (switch_to_blog)
		$site_id = get_current_blog_id();

		foreach( $pod['options'][ $this->option ] as $site ) {

			// Do not run sync if it's the current site + validate site value to a number (site id)
			if ( ! is_numeric( $site ) || $site_id == (int) $site ) {
				continue;
			}

			/**
			 * Switch to the remote site
			 * All API calls will get the remote data from here
			 */
			switch_to_blog( (int) $site );

			// The Pod info to be synced, this will overwrite existing data on the other sites
			$store_pod = $pod;

			$remote_pod = $api->load_pod( $pod_name, false );
			if ( ! $remote_pod && $pod_old_name ) {
				$remote_pod = $api->load_pod( $pod_old_name, false );
			}

			if ( false !== $remote_pod ) {
				// The remote Pod already exists, use it's ID.
				$store_pod['id'] = $remote_pod['id'];

				/**
				 * Loop through all fields
				 * If the field exists in the remote Pod, then overwrite it's ID (post id)
				 * Also checks the old_name as a fallback for when a field name is changed
				 */
				foreach ( $store_pod['fields'] as $field ) {
					// The remote Pod already exists, use it's ID.
					$store_pod['fields'][ $name ]['pod_id'] = $remote_pod['id'];

					$name = $field['name'];
					$old_name = false;
					if ( ! empty( $field['options']['old_name'] ) && $field['options']['old_name'] != $name ) {
						$old_name = $field['options']['old_name'];
					}

					if ( isset( $remote_pod['fields'][ $name ] ) ) {
						$store_pod['fields'][ $name ]['id'] = $remote_pod['fields'][ $name ]['id'];
					}
					elseif ( $old_name && isset( $remote_pod['fields'][ $old_name ] ) ) {
						$store_pod['fields'][ $name ]['id'] = $remote_pod['fields'][ $old_name ]['id'];
					}
				}
			}

			// Sync the relationship field data
			foreach ( $rel as $name => $field ) {
				if ( false != $field['field'] ) {
					// Fetch by name and pod, we don't know the ID
					$sister_params = array(
						'pod' => $field['pod'],
						'name' => $field['field']['name']
					);
					$sister = $api->load_field( $sister_params, false );
					if ( false != $sister ) {
						$store_pod['fields'][ $name ]['sister_id'] = $sister['id'];
					} else {
						// No valid sister field found in other site
						$store_pod['fields'][ $name ]['sister_id'] = false;
					}
				}
			}

			//wp_send_json( array( 'pod' => $pod, 'store_pod' => $store_pod, 'remote_pod' => $remote_pod ) );

			$api->save_pod( $store_pod );
		}

		restore_current_blog();
	}


	/**
	 * The multisite option tab.
	 *
	 * @since  0.1
	 * @param  array $tabs
	 * @return array
	 */
	public function pod_settings_tab( $tabs ) {
		$tabs[ $this->tab ] = __( 'Multisite', 'pods-multisite' );
		return $tabs;
	}

	/**
	 * The mutisite options
	 *
	 * @since  0.1
	 * @param  array $options
	 * @return array
	 */
	public function pod_settings_options( $options ) {

		$options[ $this->tab ] = array(
			$this->option => array(
				'label' => __( 'Sync this Pod with other sites', 'pods-multisite' ),
				'help' => __( 'This overwrites the the remote Pod data', 'pods-multisite' ),
				'description' => __( 'Sync is not bi-directional by default. If you want to sync both ways you need to check the current site as well', 'pods-multisite' ),
				'type' => 'pick',
				'default' => '',
				'pick_object' => 'site',
				'pick_format_type' => 'multi'
			)
		);

		return $options;

	}
}