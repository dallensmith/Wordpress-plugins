<?php
/**
 * Plugin Name:       Big Screen Bad Movies Integration
 * Plugin URI:        # Update with your plugin's URL if you have one
 * Description:       Integrates NocoDB movie data with WordPress. Creates an 'Experiment' CPT and a custom admin page for managing experiments and movies.
 * Version: 1.0.19
 * Author:            # Zasderq/Dallensmith
 * Author URI:        # https://bigscreenbadmovies.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bsbm-integration
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die( 'Silence is golden.' );
}

/**
 * Define constants for the plugin.
 */
define( 'BSBM_PLUGIN_VERSION', '1.0.19' );
define( 'BSBM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSBM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


/**
 * The core plugin class that handles initialization and hooks.
 */
require_once BSBM_PLUGIN_PATH . 'includes/class-bsbm-plugin.php';

/**
 * Load the plugin update checker library.
 */
require_once BSBM_PLUGIN_PATH . 'includes/plugin-update-checker-5.5/plugin-update-checker.php';

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function bsbm_run_plugin() {
	if ( class_exists( 'BigScreenBadMovies_Plugin' ) ) {
		$plugin = new BigScreenBadMovies_Plugin();
		$plugin->run();
	} else {
		add_action( 'admin_notices', function() {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Big Screen Bad Movies Integration plugin error: Core class file not found.', 'bsbm-integration' ); ?></p>
			</div>
			<?php
		});
	}
}
bsbm_run_plugin();

/**
 * Activation hook.
 *
 * @since 1.0.0
 */
function bsbm_activate_plugin() {
	// Ensure the CPT is registered before flushing.
	if ( class_exists( 'BigScreenBadMovies_Plugin' ) ) {
		$temp_plugin_instance = new BigScreenBadMovies_Plugin();
		$temp_plugin_instance->register_experiment_cpt(); // Call the CPT registration method
	}
	flush_rewrite_rules(); // Flush rules for the new CPT

	// Set default options if they don't exist
	if ( false === get_option( 'bsbm_settings' ) ) {
		$default_options = array(
			'nocodb_url' => '',
			'nocodb_project_id' => '', // Added
			'nocodb_table_id' => '',   // Added
			'nocodb_token' => '',
			'tmdb_api_key' => '',
			// Set default display options (1 = true/checked, 0 = false/unchecked)
			'show_poster'          => 1, 'show_backdrop'        => 0, 'show_overview'        => 1,
			'show_tagline'         => 1, 'show_genres'          => 1, 'show_director'        => 1,
			'show_cast'            => 1, 'show_rating'          => 1, 'show_runtime'         => 1,
			'show_release_date'    => 1, 'show_budget'          => 0, 'show_revenue'         => 0,
			'show_trailer'         => 1, 'show_imdb_link'       => 1, 'show_tmdb_link'       => 1,
			'show_event_notes'     => 1, 'show_affiliate_links' => 1,
		);
		add_option( 'bsbm_settings', $default_options );
	}
}
register_activation_hook( __FILE__, 'bsbm_activate_plugin' );

/**
 * Deactivation hook.
 *
 * @since 1.0.0
 */
function bsbm_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bsbm_deactivate_plugin' );
