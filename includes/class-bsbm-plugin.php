<?php

/**
 * The core plugin class for BSBM Integration (Admin Page Approach).
 *
 * @since      1.0.0
 * @package    Bsbm_Integration
 * @subpackage Bsbm_Integration/includes
 * @author     # Your Name Here
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die( 'Silence is golden.' );
}

class BigScreenBadMovies_Plugin {

	protected $plugin_name;
	protected $version;
	private $options = null;
	private $tmdb_api_base_url = 'https://api.themoviedb.org/3';
	private static $updater_initialized = false; // New static flag

	public function __construct() {
		if ( defined( 'BSBM_PLUGIN_VERSION' ) ) { $this->version = BSBM_PLUGIN_VERSION; } else { $this->version = '1.0.0'; }
		$this->plugin_name = 'bsbm-integration';
		$this->define_hooks();
		// $this->init_updater(); // REMOVED from constructor
	}

	private function define_hooks() {
		add_action( 'init', array( $this, 'register_experiment_cpt' ), 0 );
		// Admin Menu and Settings
		add_action( 'admin_menu', array( $this, 'modify_experiment_cpt_menu' ), 1 ); // Changed priority
		add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
		// Hook to handle form submission from our custom admin page
		add_action( 'admin_post_bsbm_save_experiment', array( $this, 'handle_save_experiment_form' ) );
        
        // Hooks for the new Sync Hub
        add_action( 'admin_post_bsbm_fetch_pending_experiments_from_nocodb', array( $this, 'handle_fetch_pending_experiments_from_nocodb' ) );
        add_action( 'admin_post_bsbm_import_selected_experiments_from_nocodb', array( $this, 'handle_import_selected_experiments_from_nocodb' ) );
        add_action( 'admin_post_bsbm_list_wp_experiments_for_sync', array( $this, 'handle_list_wp_experiments_for_sync' ) );
        add_action( 'admin_post_bsbm_sync_selected_wp_experiments_to_nocodb', array( $this, 'handle_sync_selected_wp_experiments_to_nocodb' ) );

        // Hook to enqueue scripts/styles needed for the custom admin form page & potentially Sync Hub
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_form_assets' ) );
		// REST API Hooks
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	private function get_plugin_options() {
		if ( $this->options === null ) {
			$this->options = get_option( 'bsbm_settings' );
			if ( ! is_array( $this->options ) ) { $this->options = array(); }
		}
		return $this->options;
	}

	// --- CPT Registration ---
	public function register_experiment_cpt() {
		$labels = array(
			'name'                     => _x( 'Experiments', 'Post type general name', 'bsbm-integration' ),
			'singular_name'            => _x( 'Experiment', 'Post type singular name', 'bsbm-integration' ),
			'add_new'                  => _x( 'Add New', 'Experiment', 'bsbm-integration' ),
			'add_new_item'             => __( 'Add New Experiment', 'bsbm-integration' ),
			'edit_item'                => __( 'Edit Experiment', 'bsbm-integration' ),
			'new_item'                 => __( 'New Experiment', 'bsbm-integration' ),
			'view_item'                => __( 'View Experiment', 'bsbm-integration' ),
			'view_items'               => __( 'View Experiments', 'bsbm-integration' ),
			'search_items'             => __( 'Search Experiments', 'bsbm-integration' ),
			'not_found'                => __( 'No experiments found.', 'bsbm-integration' ),
			'not_found_in_trash'       => __( 'No experiments found in Trash.', 'bsbm-integration' ),
			'parent_item_colon'        => '',
			'all_items'                => __( 'All Experiments', 'bsbm-integration' ),
			'archives'                 => __( 'Experiment Archives', 'bsbm-integration' ),
			'attributes'               => __( 'Experiment Attributes', 'bsbm-integration' ),
			'insert_into_item'         => __( 'Insert into experiment', 'bsbm-integration' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this experiment', 'bsbm-integration' ),
			'featured_image'           => __( 'Experiment Image', 'bsbm-integration' ),
			'set_featured_image'       => __( 'Set experiment image', 'bsbm-integration' ),
			'remove_featured_image'    => __( 'Remove experiment image', 'bsbm-integration' ),
			'use_featured_image'       => __( 'Use as experiment image', 'bsbm-integration' ),
			'menu_name'                => __( 'Experiments', 'bsbm-integration' ),
			'filter_items_list'        => __( 'Filter experiments list', 'bsbm-integration' ),
			'items_list_navigation'    => __( 'Experiments list navigation', 'bsbm-integration' ),
			'items_list'               => __( 'Experiments list', 'bsbm-integration' ),
			'name_admin_bar'           => _x( 'Experiment', 'Add New on Toolbar', 'bsbm-integration' ),
		);
		$args = array(
			'labels'                => $labels,
			'public'                => true,
			'has_archive'           => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'menu_position'         => 20,
			'menu_icon'             => 'dashicons-video-alt3',
			'hierarchical'          => false,
			'publicly_queryable'    => true,
			'exclude_from_search'   => false,
			'supports'              => array( 'title', 'thumbnail', 'revisions' ),
			'rewrite'               => array( 'slug' => 'experiment', 'with_front' => false ),
			'show_in_rest'          => true,
			'rest_base'             => 'experiments',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		);
		register_post_type( 'bsbm_experiment', $args );
	}

    /**
     * Modifies the 'bsbm_experiment' CPT admin menu.
     * Adds our custom form page, settings page, and reorders.
     *
     * @since 1.0.0
     */
    public function modify_experiment_cpt_menu() {
        // 1. Remove the default "Add New" - this is usually post-new.php?post_type=bsbm_experiment
        remove_submenu_page( 'edit.php?post_type=bsbm_experiment', 'post-new.php?post_type=bsbm_experiment' );

        // 2. Add our custom "Add New Experiment" form page (desired top position)
        add_submenu_page(
            'edit.php?post_type=bsbm_experiment',       // Parent slug
            __( 'Add New Experiment', 'bsbm-integration' ), // Page title
            __( 'Add New Experiment', 'bsbm-integration' ), // Menu title
            'edit_posts',                               // Capability
            'bsbm-add-experiment-form',                 // Menu slug
            array( $this, 'render_experiment_form_page' ), // Callback
            1                                           // Position (1 for top under "All Items")
        );

        // 3. Add the new Sync Experiments page (replacing the old NocoDB Sync)
        add_submenu_page(
            'edit.php?post_type=bsbm_experiment',       // Parent slug
            __( 'Sync Experiments', 'bsbm-integration' ), // Page title
            __( 'Sync Hub', 'bsbm-integration' ),             // Menu title - Changed for brevity
            'manage_options',                           // Capability
            'bsbm-sync-hub',                    // Menu slug - Changed to reflect new purpose
            array( $this, 'render_sync_hub_page' ), // Callback - New render function
            2                                           // Position
        );

        // 4. Add the Settings page under the "Experiments" menu
        add_submenu_page(
            'edit.php?post_type=bsbm_experiment',       // Parent slug
            __( 'BSBM Settings', 'bsbm-integration' ),    // Page title
            __( 'Settings', 'bsbm-integration' ),         // Menu title
            'manage_options',                           // Capability
            'bsbm-integration-settings',                // Menu slug
            array( $this, 'render_settings_page' ),      // Callback
            10                                          // Position
        );
        // Remove the old NocoDB Sync (Legacy) page if it exists and is no longer needed.
        // If you had a 'bsbm-nocodb-sync' slug for it:
        remove_submenu_page( 'edit.php?post_type=bsbm_experiment', 'bsbm-nocodb-sync' );
    }


	// --- Settings Page (Now under "Experiments" menu) ---
	public function add_plugin_admin_menu() {
        // This function is no longer strictly needed as settings are moved under "Experiments"
        // by modify_experiment_cpt_menu().
    }
	public function register_plugin_settings() {
		register_setting('bsbm_option_group', 'bsbm_settings', array( $this, 'sanitize_settings' ));
		add_settings_section('bsbm_api_section', __('API Credentials', 'bsbm-integration'), array( $this, 'render_api_section_intro' ), 'bsbm-integration-settings'); // Changed page slug
		add_settings_field('nocodb_url', __('NocoDB URL', 'bsbm-integration'), array($this, 'render_nocodb_url_field'), 'bsbm-integration-settings', 'bsbm_api_section');
		add_settings_field('nocodb_project_id', __('NocoDB Project ID', 'bsbm-integration'), array($this, 'render_nocodb_project_id_field'), 'bsbm-integration-settings', 'bsbm_api_section');
		add_settings_field('nocodb_table_id', __('NocoDB Table Name/ID', 'bsbm-integration'), array($this, 'render_nocodb_table_id_field'), 'bsbm-integration-settings', 'bsbm_api_section');
		add_settings_field('nocodb_token', __('NocoDB API Token', 'bsbm-integration'), array($this, 'render_nocodb_token_field'), 'bsbm-integration-settings', 'bsbm_api_section');
		add_settings_field('tmdb_api_key', __('TMDb API Key', 'bsbm-integration'), array($this, 'render_tmdb_api_key_field'), 'bsbm-integration-settings', 'bsbm_api_section');

		add_settings_section('bsbm_display_section', __('Global Display Defaults (for Shortcodes/Templates)', 'bsbm-integration'), array( $this, 'render_display_section_intro' ), 'bsbm-integration-settings'); // Changed page slug
		$display_options = [
			'show_poster' => __('Movie Poster', 'bsbm-integration'), 'show_backdrop' => __('Movie Backdrop', 'bsbm-integration'),
			'show_overview' => __('Overview/Synopsis', 'bsbm-integration'), 'show_tagline' => __('Tagline', 'bsbm-integration'),
			'show_genres' => __('Genres', 'bsbm-integration'), 'show_director' => __('Director(s)', 'bsbm-integration'),
			'show_cast' => __('Main Cast', 'bsbm-integration'), 'show_rating' => __('TMDb Rating', 'bsbm-integration'),
			'show_runtime' => __('Runtime', 'bsbm-integration'), 'show_release_date' => __('Release Date', 'bsbm-integration'),
			'show_budget' => __('Budget', 'bsbm-integration'), 'show_revenue' => __('Box Office Revenue', 'bsbm-integration'),
			'show_trailer' => __('Trailer Link/Embed', 'bsbm-integration'), 'show_imdb_link' => __('IMDb Link', 'bsbm-integration'),
			'show_tmdb_link' => __('TMDb Link', 'bsbm-integration'), 'show_event_notes' => __('Event Notes', 'bsbm-integration'),
			'show_affiliate_links' => __('Affiliate Links', 'bsbm-integration'),
		];
		foreach ( $display_options as $field_id => $field_title ) { add_settings_field( $field_id, $field_title, array( $this, 'render_display_checkbox_field' ), 'bsbm-integration-settings', 'bsbm_display_section', array( 'id' => $field_id, 'label' => $field_title ) ); }
	}
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'You do not have sufficient permissions to access this page.', 'bsbm-integration' ) ); }
		$this->options = $this->get_plugin_options();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'bsbm_option_group' ); do_settings_sections( 'bsbm-integration-settings' ); submit_button( __( 'Save Settings', 'bsbm-integration' ) ); ?>
			</form>
		</div>
		<?php
	}
	public function sanitize_settings( $input ) {
		$sanitized_input = array(); $current_options = $this->get_plugin_options();
		$sanitized_input['nocodb_url'] = isset( $input['nocodb_url'] ) ? esc_url_raw( trim( $input['nocodb_url'] ) ) : '';
		$sanitized_input['nocodb_project_id'] = isset( $input['nocodb_project_id'] ) ? sanitize_text_field( trim( $input['nocodb_project_id'] ) ) : '';
		$sanitized_input['nocodb_table_id'] = isset( $input['nocodb_table_id'] ) ? sanitize_text_field( trim( $input['nocodb_table_id'] ) ) : '';
		$sanitized_input['nocodb_token'] = ( isset( $input['nocodb_token'] ) && !empty(trim($input['nocodb_token'])) ) ? sanitize_text_field( trim( $input['nocodb_token'] ) ) : ($current_options['nocodb_token'] ?? '');
		$sanitized_input['tmdb_api_key'] = ( isset( $input['tmdb_api_key'] ) && !empty(trim($input['tmdb_api_key'])) ) ? sanitize_text_field( trim( $input['tmdb_api_key'] ) ) : ($current_options['tmdb_api_key'] ?? '');
		$checkbox_options = ['show_poster', 'show_backdrop', 'show_overview', 'show_tagline', 'show_genres', 'show_director', 'show_cast', 'show_rating', 'show_runtime', 'show_release_date', 'show_budget', 'show_revenue', 'show_trailer', 'show_imdb_link', 'show_tmdb_link', 'show_event_notes', 'show_affiliate_links'];
		foreach ($checkbox_options as $option) { $sanitized_input[$option] = isset( $input[$option] ) ? 1 : 0; }
		return $sanitized_input;
	}
	public function render_api_section_intro() { echo '<p>' . esc_html__( 'Enter the API credentials required to connect to NocoDB and TMDb.', 'bsbm-integration' ) . '</p>'; }
	public function render_display_section_intro() { echo '<p>' . esc_html__( 'Select which movie details should be displayed by default (e.g., via shortcodes or custom templates).', 'bsbm-integration' ) . '</p>'; }
	public function render_nocodb_url_field() { $options = $this->get_plugin_options(); $value = $options['nocodb_url'] ?? ''; printf('<input type="url" id="nocodb_url" name="bsbm_settings[nocodb_url]" value="%s" class="regular-text" placeholder="https://nocodb.yourdomain.com" />', esc_attr( $value )); echo '<p class="description">' . esc_html__( 'Enter the base URL of your NocoDB instance.', 'bsbm-integration') . '</p>'; }
	public function render_nocodb_project_id_field() { $options = $this->get_plugin_options(); $value = $options['nocodb_project_id'] ?? ''; printf('<input type="text" id="nocodb_project_id" name="bsbm_settings[nocodb_project_id]" value="%s" class="regular-text" placeholder="e.g., p935e12auzxp046" />', esc_attr( $value )); echo '<p class="description">' . esc_html__( 'Enter your NocoDB Project ID.', 'bsbm-integration') . '</p>'; }
	public function render_nocodb_table_id_field() { $options = $this->get_plugin_options(); $value = $options['nocodb_table_id'] ?? ''; printf('<input type="text" id="nocodb_table_id" name="bsbm_settings[nocodb_table_id]" value="%s" class="regular-text" placeholder="e.g., Movies or maa7halyw09bfgf" />', esc_attr( $value )); echo '<p class="description">' . esc_html__( 'Enter your NocoDB Table Name or Table ID.', 'bsbm-integration') . '</p>'; }
	public function render_nocodb_token_field() { $options = $this->get_plugin_options(); echo '<input type="password" id="nocodb_token" name="bsbm_settings[nocodb_token]" value="" class="regular-text" placeholder="' . esc_attr__('Enter new token to update', 'bsbm-integration') . '" autocomplete="new-password" />'; echo '<p class="description">' . ( !empty($options['nocodb_token']) ? esc_html__( 'Token is currently set. Enter a new token only if you need to change it.', 'bsbm-integration') : esc_html__( 'Enter the API token generated in NocoDB.', 'bsbm-integration') ) . '</p>'; }
	public function render_tmdb_api_key_field() { $options = $this->get_plugin_options(); echo '<input type="password" id="tmdb_api_key" name="bsbm_settings[tmdb_api_key]" value="" class="regular-text" placeholder="' . esc_attr__('Enter new key to update', 'bsbm-integration') . '" autocomplete="new-password" />'; echo '<p class="description">' . ( !empty($options['tmdb_api_key']) ? esc_html__( 'Key is currently set. Enter a new key only if you need to change it.', 'bsbm-integration') : esc_html__( 'Enter your API Key (v3 auth) for The Movie Database (TMDb).', 'bsbm-integration') ) . '</p>'; }
	public function render_display_checkbox_field( $args ) { $options = $this->get_plugin_options(); $option_id = $args['id']; $label_text = $args['label'] ?? ucfirst( str_replace( '_', ' ', $option_id ) ); $checked = isset( $options[$option_id] ) && $options[$option_id] ? 'checked' : ''; printf('<label for="%1$s"><input type="checkbox" id="%1$s" name="bsbm_settings[%1$s]" value="1" %2$s /> %3$s</label>', esc_attr( $option_id ), $checked, esc_html( $label_text ) ); }


    // --- Custom Admin Page for Experiment Form ---
    public function render_experiment_form_page() {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( __( 'Sorry, you are not allowed to access this page.', 'bsbm-integration' ) ); }
        $current_user = wp_get_current_user(); $default_host_id = $current_user->ID;
        $all_users = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name' ) );
        $latest_experiment_query = new WP_Query(array( 'post_type' => 'bsbm_experiment', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC' ));
        $next_experiment_number = 1;
        if ($latest_experiment_query->have_posts()) {
            $latest_post_title = $latest_experiment_query->posts[0]->post_title;
            if (preg_match('/Experiment\s*#(\d+)/i', $latest_post_title, $matches)) { $next_experiment_number = intval($matches[1]) + 1; }
            else { $next_experiment_number = $latest_experiment_query->found_posts + 1; }
        } wp_reset_postdata(); $default_title = "Experiment #" . $next_experiment_number;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Add New Experiment', 'bsbm-integration' ); ?></h1>
            <?php if ( isset( $_GET['message'] ) ) : ?> <div id="message" class="<?php echo $_GET['message'] === 'success' ? 'updated' : 'error'; ?> notice is-dismissible"><p><?php echo $_GET['message'] === 'success' ? esc_html__( 'Experiment saved successfully!', 'bsbm-integration' ) : esc_html__( 'Error saving experiment. Please check data and logs.', 'bsbm-integration' ); ?></p></div> <?php endif; ?>
            <form id="bsbm-experiment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bsbm_save_experiment">
                <?php wp_nonce_field( 'bsbm_save_experiment_nonce', 'bsbm_experiment_nonce' ); ?>
                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content" style="position: relative;">
                            <div id="titlediv">
                                <div id="titlewrap">
                                    <label class="screen-reader-text" id="title-prompt-text" for="bsbm_post_title"><?php esc_html_e( 'Experiment Title', 'bsbm-integration' ); ?></label>
                                    <input type="text" name="bsbm_post_title" size="30" value="<?php echo esc_attr($default_title); ?>" id="bsbm_post_title" spellcheck="true" autocomplete="off" required style="width: 100%; padding: 3px 8px; font-size: 1.7em; line-height: 100%; height: 1.7em; margin-bottom: 15px;"/>
                                </div>
                            </div>
                            <div id="bsbm_movies_watched_box" class="postbox">
                                <button type="button" class="handlediv button-link" aria-expanded="true"><span class="screen-reader-text"><?php esc_html_e('Toggle panel: Movies Watched', 'bsbm-integration'); ?></span><span class="toggle-indicator" aria-hidden="true"></span></button>
                                <h2 class="hndle"><span><?php esc_html_e('Movies Watched', 'bsbm-integration'); ?></span></h2>
                                <div class="inside">
                                    <div id="bsbm-movies-repeater"></div>
                                    <button type="button" id="bsbm-add-movie-button" class="button button-secondary"><?php esc_html_e( '+ Add Movie', 'bsbm-integration' ); ?></button>
                                </div>
                            </div>
                        </div>
                        <div id="postbox-container-1" class="postbox-container">
                            <div id="submitdiv" class="postbox">
                                <button type="button" class="handlediv button-link" aria-expanded="true"><span class="screen-reader-text"><?php esc_html_e('Toggle panel: Publish', 'bsbm-integration'); ?></span><span class="toggle-indicator" aria-hidden="true"></span></button>
                                <h2 class="hndle"><span><?php esc_html_e('Publish', 'bsbm-integration'); ?></span></h2>
                                <div class="inside">
                                    <div class="submitbox" id="submitpost">
                                        <div id="misc-publishing-actions">
                                            <div class="misc-pub-section misc-pub-event-date"> <label for="bsbm_event_date"><?php esc_html_e( 'Event Date:', 'bsbm-integration' ); ?></label> <input type="datetime-local" name="bsbm_event_date" id="bsbm_event_date" value="<?php echo esc_attr(current_time('Y-m-d\TH:i')); ?>" required style="width: 100%;"> </div>
                                            <div class="misc-pub-section misc-pub-event-host"> <label for="bsbm_event_host_select"><?php esc_html_e( 'Event Host:', 'bsbm-integration' ); ?></label> <select name="bsbm_event_host_select" id="bsbm_event_host_select" style="width: 100%;"> <?php foreach ( $all_users as $user ) : ?> <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user->ID, $default_host_id ); ?>> <?php echo esc_html( $user->display_name ); ?> </option> <?php endforeach; ?> <option value="other"><?php esc_html_e( 'Other...', 'bsbm-integration' ); ?></option> </select> <input type="text" name="bsbm_event_host_custom" id="bsbm_event_host_custom" value="" class="regular-text" style="display:none; margin-top: 5px; width: 100%;" placeholder="<?php esc_attr_e( 'Enter custom host name', 'bsbm-integration' ); ?>"> </div>
                                        </div>
                                        <div id="major-publishing-actions"> <?php submit_button( __( 'Save Experiment', 'bsbm-integration' ), 'primary large', 'publish', false, array('style' => 'width: 100%; text-align: center;') ); ?> </div> <div class="clear"></div>
                                    </div>
                                </div>
                            </div>
                            <div id="bsbm_experiment_image" class="postbox">
                                <button type="button" class="handlediv button-link" aria-expanded="true"><span class="screen-reader-text"><?php esc_html_e('Toggle panel: Experiment Image', 'bsbm-integration'); ?></span><span class="toggle-indicator" aria-hidden="true"></span></button>
                                <h2 class="hndle"><span><?php esc_html_e('Experiment Image', 'bsbm-integration'); ?></span></h2>
                                <div class="inside"> <div class="bsbm-image-uploader"> <img src="" alt="Image Preview" style="max-width: 100%; height: auto; display: none; border: 1px solid #ddd; padding: 5px; margin-bottom: 5px;" class="bsbm-image-preview"> <input type="hidden" name="bsbm_event_image_id" class="bsbm-image-id" value=""> <button type="button" class="button bsbm-upload-button"><?php esc_html_e( 'Upload/Select Image', 'bsbm-integration' ); ?></button> <button type="button" class="button bsbm-remove-button" style="display: none; margin-left: 5px;"><?php esc_html_e( 'Remove Image', 'bsbm-integration' ); ?></button> </div> </div>
                            </div>
                             <div id="bsbm_event_notes_box_sidebar" class="postbox closed"> <?php // Moved here and set to closed ?>
                                <button type="button" class="handlediv button-link" aria-expanded="false"><span class="screen-reader-text"><?php esc_html_e('Toggle panel: Event Notes', 'bsbm-integration'); ?></span><span class="toggle-indicator" aria-hidden="true"></span></button>
                                <h2 class="hndle"><span><?php esc_html_e('Event Notes', 'bsbm-integration'); ?></span></h2>
                                <div class="inside">
                                     <textarea name="bsbm_event_notes" id="bsbm_event_notes" rows="3" class="large-text" style="width: 100%;"></textarea>
                                </div>
                            </div>
                        </div></div><br class="clear">
                </div></form>
        </div>
        <script type="text/template" id="bsbm-movie-entry-template">
            <div class="bsbm-movie-entry-section postbox closed" style="margin-bottom: 20px; background: #fdfdfd;">
                <button type="button" class="handlediv button-link" aria-expanded="false"><span class="screen-reader-text"><?php esc_html_e('Toggle panel: Movie', 'bsbm-integration'); ?></span><span class="toggle-indicator" aria-hidden="true"></span></button>
                <div class="bsbm-movie-actions" style="position: absolute; top: 5px; right: 5px; z-index: 10;">
                     <button type="button" class="button-link button-link-delete bsbm-remove-movie-button" style="padding: 5px; cursor: pointer;">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Remove', 'bsbm-integration' ); ?>
                    </button>
                </div>
                <h2 class="hndle" style="cursor: move; padding-right: 80px;"><span><?php esc_html_e( 'Movie', 'bsbm-integration' ); ?> <span class="movie-title-display-hndle"></span></span></h2>
                <div class="inside" style="padding: 12px;">
                    <input type="hidden" name="movies[][tmdb_id]" class="movie-tmdb-id">
                    <div class="bsbm-movie-search-area" style="margin-bottom: 15px;">
                        <label for="movie-search-{{INDEX}}" style="font-weight: bold;"><?php esc_html_e( 'Search TMDb:', 'bsbm-integration' ); ?></label><br>
                        <input type="text" id="movie-search-{{INDEX}}" class="regular-text bsbm-movie-search" placeholder="<?php esc_attr_e( 'Type title...', 'bsbm-integration' ); ?>" style="width: calc(100% - 30px); margin-right: 5px;">
                        <span class="spinner" style="float: none; vertical-align: middle;"></span>
                        <ul class="bsbm-search-results" style="list-style: none; margin: 5px 0 0 0; padding: 0; border: 1px solid #ccc; max-height: 150px; overflow-y: auto; background: #fff; display: none; position: absolute; z-index: 100; width: calc(100% - 30px);"></ul>
                    </div>
                    <hr>
                    <div class="bsbm-movie-details-columns" style="display: flex; flex-wrap: wrap; gap: 20px;">
                        <div class="bsbm-movie-col1" style="flex: 1 1 220px; min-width: 200px;">
                            <p><label><strong><?php esc_html_e( 'Title:', 'bsbm-integration' ); ?></strong></label><br><input type="text" name="movies[][title]" class="regular-text movie-title" style="width:100%;"></p>
                            <p><label><strong><?php esc_html_e( 'Year:', 'bsbm-integration' ); ?></strong></label><br><input type="number" name="movies[][year]" class="small-text movie-year"></p>
                            <p><label><strong><?php esc_html_e( 'Poster URL:', 'bsbm-integration' ); ?></strong></label><br><input type="url" name="movies[][poster_url]" class="regular-text movie-poster-url" style="width:100%;"><br><img src="" class="movie-poster-preview" style="max-width: 120px; height: auto; margin-top: 5px; display: none; border: 1px solid #ddd;"></p>
                            <p><label><strong><?php esc_html_e( 'Director:', 'bsbm-integration' ); ?></strong></label><br><input type="text" name="movies[][director]" class="regular-text movie-director" style="width:100%;"></p>
                        </div>
                        <div class="bsbm-movie-col2" style="flex: 2 1 300px; min-width: 280px;">
                            <p><label><strong><?php esc_html_e( 'Overview:', 'bsbm-integration' ); ?></strong></label><br><textarea name="movies[][overview]" rows="4" class="large-text movie-overview" style="width:100%;"></textarea></p>
                            <p><label><strong><?php esc_html_e( 'Cast:', 'bsbm-integration' ); ?></strong></label><br><textarea name="movies[][cast]" rows="2" class="large-text movie-cast" placeholder="<?php esc_attr_e( 'Comma-separated', 'bsbm-integration' ); ?>" style="width:100%;"></textarea></p>
                            <p><label><strong><?php esc_html_e( 'Genres:', 'bsbm-integration' ); ?></strong></label><br><input type="text" name="movies[][genres]" class="regular-text movie-genres" placeholder="<?php esc_attr_e( 'Comma-separated', 'bsbm-integration' ); ?>" style="width:100%;"></p>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <p style="flex: 1;"><label><strong><?php esc_html_e( 'Rating:', 'bsbm-integration' ); ?></strong></label><br><input type="number" step="0.1" name="movies[][rating]" class="small-text movie-rating"></p>
                                <p style="flex: 1;"><label><strong><?php esc_html_e( 'Runtime (min):', 'bsbm-integration' ); ?></strong></label><br><input type="number" name="movies[][runtime]" class="small-text movie-runtime"></p>
                            </div>
                            <p><label><strong><?php esc_html_e( 'IMDb ID:', 'bsbm-integration' ); ?></strong></label><br><input type="text" name="movies[][imdb_id]" class="regular-text movie-imdb-id"></p>
                            <p><label><strong><?php esc_html_e( 'Trailer URL:', 'bsbm-integration' ); ?></strong></label><br><input type="url" name="movies[][trailer_url]" class="regular-text movie-trailer-url" style="width:100%;"></p>
                        </div>
                    </div>
                    <div style="flex-basis: 100%; margin-top: 15px;"><hr></div>
                    <div style="flex-basis: 100%;">
                        <h4><?php esc_html_e( 'Affiliate Links', 'bsbm-integration' ); ?></h4>
                        <div class="bsbm-affiliate-links-area"></div>
                        <button type="button" class="button button-secondary bsbm-add-affiliate-link-button"><?php esc_html_e( '+ Add Affiliate Link', 'bsbm-integration' ); ?></button>
                    </div>
                </div>
            </div>
        </script>
        <?php
    }

    public function handle_save_experiment_form() {
        if ( ! isset( $_POST['bsbm_experiment_nonce'] ) || ! wp_verify_nonce( $_POST['bsbm_experiment_nonce'], 'bsbm_save_experiment_nonce' ) ) { wp_die( __( 'Security check failed.', 'bsbm-integration' ) ); }
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( __( 'You do not have permission to save experiments.', 'bsbm-integration' ) ); }

        $post_title     = isset( $_POST['bsbm_post_title'] ) ? sanitize_text_field( $_POST['bsbm_post_title'] ) : '';
        $event_date_str = isset( $_POST['bsbm_event_date'] ) ? sanitize_text_field( $_POST['bsbm_event_date'] ) : '';
        $event_host_select = isset( $_POST['bsbm_event_host_select'] ) ? sanitize_text_field( $_POST['bsbm_event_host_select'] ) : '';
        $event_host_custom = isset( $_POST['bsbm_event_host_custom'] ) ? sanitize_text_field( $_POST['bsbm_event_host_custom'] ) : '';
        $event_host = ( $event_host_select === 'other' && !empty($event_host_custom) ) ? $event_host_custom : '';
        if (empty($event_host) && $event_host_select !== 'other') { $user = get_user_by('ID', $event_host_select); if ($user) { $event_host = $user->display_name; } }
        $event_notes    = isset( $_POST['bsbm_event_notes'] ) ? sanitize_textarea_field( $_POST['bsbm_event_notes'] ) : '';
        $image_id       = isset( $_POST['bsbm_event_image_id'] ) ? absint( $_POST['bsbm_event_image_id'] ) : 0;
        $movies_input   = isset( $_POST['movies'] ) && is_array( $_POST['movies'] ) ? $_POST['movies'] : array();

        if ( empty($post_title) || empty($event_date_str) ) { wp_safe_redirect( admin_url( 'edit.php?post_type=bsbm_experiment&page=bsbm-add-experiment-form&message=error_validation' ) ); exit; }
        $event_timestamp = strtotime( $event_date_str );
        $event_date_gmt = $event_timestamp ? gmdate( 'Y-m-d H:i:s', $event_timestamp ) : gmdate( 'Y-m-d H:i:s' );
        $event_date_local = $event_timestamp ? get_date_from_gmt($event_date_gmt, 'Y-m-d H:i:s') : current_time('mysql');
        
        // Prepare post data, but don't set meta_input yet for _bsbm_movies_data_for_nocodb_sync
        $post_data = [ 'post_title' => $post_title, 'post_status' => 'publish', 'post_type' => 'bsbm_experiment', 'post_date' => $event_date_local, 'post_date_gmt'=> $event_date_gmt ];
        
        // Check if this is an update or new post by looking for an existing post ID passed in the form
        // This part needs to be added if you plan to edit experiments via this form.
        // For now, assuming it's always a new post from this specific form.
        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) { wp_die( __( 'Error saving experiment post:', 'bsbm-integration' ) . ' ' . $post_id->get_error_message() ); }
        
        update_post_meta( $post_id, '_bsbm_event_host', $event_host ); 
        update_post_meta( $post_id, '_bsbm_event_notes', $event_notes );
        if ( $image_id > 0 ) { set_post_thumbnail( $post_id, $image_id ); } else { delete_post_thumbnail( $post_id ); }

        // Prepare $movies_data_for_nocodb_sync from $movies_input
        $movies_data_for_nocodb_sync = array();
        foreach ( $movies_input as $movie_data_raw ) {
            $sanitized_movie_data = [];
            foreach ($movie_data_raw as $key => $value) {
                if ($key === 'overview' || $key === 'cast') { $sanitized_movie_data[$key] = sanitize_textarea_field($value); }
                elseif ($key === 'poster_url' || $key === 'trailer_url') { $sanitized_movie_data[$key] = esc_url_raw($value); }
                elseif ($key === 'affiliate_links' && is_array($value)) { 
                    $sanitized_movie_data[$key] = array_map(function($link){ 
                        return ['name' => sanitize_text_field($link['name'] ?? ''), 'url' => esc_url_raw($link['url'] ?? '')]; 
                    }, $value); 
                }
                elseif (in_array($key, ['tmdb_id', 'year', 'runtime', 'budget', 'revenue'])) { $sanitized_movie_data[$key] = absint($value); }
                elseif ($key === 'rating') { $sanitized_movie_data[$key] = floatval($value); }
                else { $sanitized_movie_data[$key] = sanitize_text_field($value); }
            }
            // Ensure essential fields for NocoDB sync are present
            $movies_data_for_nocodb_sync[] = [
                'movie_tmdb_id' => absint($sanitized_movie_data['tmdb_id'] ?? 0),
                'movie_title' => $sanitized_movie_data['title'] ?? '',
                'movie_year' => absint($sanitized_movie_data['year'] ?? 0),
                'movie_overview' => $sanitized_movie_data['overview'] ?? '',
                'movie_poster_url' => $sanitized_movie_data['poster_url'] ?? '',
                'movie_director' => $sanitized_movie_data['director'] ?? '',
                'movie_cast' => $sanitized_movie_data['cast'] ?? '',
                'movie_genres' => $sanitized_movie_data['genres'] ?? '',
                'movie_tmdb_rating' => !empty($sanitized_movie_data['rating']) ? floatval($sanitized_movie_data['rating']) : null,
                'movie_trailer_url' => $sanitized_movie_data['trailer_url'] ?? '',
                'movie_imdb_id' => $sanitized_movie_data['imdb_id'] ?? '',
                'movie_runtime' => !empty($sanitized_movie_data['runtime']) ? absint($sanitized_movie_data['runtime']) : null,
                'affiliate_links' => $sanitized_movie_data['affiliate_links'] ?? [],
                // Add any other fields that sync_wp_experiment_to_nocodb expects from this structure
            ];
        }
        // Save the structured movie data to post meta for the new sync function
        update_post_meta( $post_id, '_bsbm_movies_data_for_nocodb_sync', $movies_data_for_nocodb_sync );

        // Mark as needing sync (or clear old sync time)
        delete_post_meta( $post_id, '_bsbm_last_synced_to_nocodb' ); 

        // Call the new sync function
        $sync_success = $this->sync_wp_experiment_to_nocodb( $post_id );

        $redirect_url = admin_url( 'edit.php?post_type=bsbm_experiment&page=bsbm-add-experiment-form&post_id=' . $post_id );
        if ($sync_success) {
            $redirect_url = add_query_arg( 'message', 'success', $redirect_url );
        } else {
            $redirect_url = add_query_arg( 'message', 'error_sync', $redirect_url ); // A new error message for partial/failed sync
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

    // --- NocoDB Helper ---
    private function find_existing_nocodb_record( $exp_num, $tmdb_id, $api_base_url, $api_token ) {
        // Construct the query URL for NocoDB API
        // This assumes your NocoDB table has columns named 'experiment_number' and 'movie_tmdb_id'
        // Adjust column names if they are different in your NocoDB table.
        $query_filter = sprintf("(experiment_number,eq,%d)~and(movie_tmdb_id,eq,%d)", $exp_num, $tmdb_id);
        $request_url = sprintf('%s/records?where=%s&limit=1',
            rtrim($api_base_url, '/'),
            rawurlencode($query_filter) // Ensure the filter string is URL encoded
        );

        $args = [
            'method'  => 'GET',
            'headers' => [
                'xc-token'     => $api_token,
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'timeout' => 15,
        ];

        $response = wp_remote_request( $request_url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( "BSBM Plugin: Error finding NocoDB record (exp_num: {$exp_num}, tmdb_id: {$tmdb_id}). WP_Error: " . $response->get_error_message() );
            return null;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $response_code !== 200 ) {
            error_log( "BSBM Plugin: Error finding NocoDB record (exp_num: {$exp_num}, tmdb_id: {$tmdb_id}). Status: {$response_code}. Body: " . $response_body );
            return null;
        }

        $data = json_decode( $response_body, true );

        // NocoDB API v1/v2 typically returns records under a "list" key.
        // If your NocoDB API version or specific table view returns data differently, this may need adjustment.
        if ( isset($data['list']) && is_array($data['list']) && !empty($data['list']) ) {
            // Assuming the record ID field in NocoDB is 'Id' (case-sensitive)
            if ( isset( $data['list'][0]['Id'] ) ) {
                return $data['list'][0]['Id'];
            }
        }
        
        // No record found or unexpected response structure
        return null;
    }

    // --- Method to Sync a Single WP Experiment to NocoDB ---
    public function sync_wp_experiment_to_nocodb( $post_id ) {
        if ( ! $post_id ) return false;

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bsbm_experiment' ) return false;

        $settings = $this->get_plugin_options();
        $nocodb_url = trim( $settings['nocodb_url'] ?? '' );
        $nocodb_project_id = trim( $settings['nocodb_project_id'] ?? '' );
        $nocodb_table_id = trim( $settings['nocodb_table_id'] ?? '' );
        $nocodb_token = trim( $settings['nocodb_token'] ?? '' );

        if ( empty( $nocodb_url ) || empty( $nocodb_project_id ) || empty( $nocodb_table_id ) || empty( $nocodb_token ) ) {
            error_log( 'BSBM Plugin: NocoDB settings incomplete, skipping NocoDB sync for post ID: ' . $post_id );
            return false;
        }

        $nocodb_base_api_url = sprintf('%s/api/v1/db/data/v1/%s/%s', rtrim($nocodb_url, '/'), $nocodb_project_id, $nocodb_table_id);

        // Fetch experiment data from post and meta
        $post_title = $post->post_title;
        $experiment_number = 0;
        if ( preg_match( '/Experiment\s*#(\d+)/i', $post_title, $matches ) ) {
            $experiment_number = intval( $matches[1] );
        }
        // If experiment number is not in title, try to get it from meta (if you store it separately)
        // For now, we assume it's primarily derived from title for consistency with form submission

        $event_date_gmt = $post->post_date_gmt;
        $event_host = get_post_meta( $post_id, '_bsbm_event_host', true );
        $event_notes = get_post_meta( $post_id, '_bsbm_event_notes', true );
        $image_id = get_post_thumbnail_id( $post_id );
        $event_image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';
        $post_url = get_permalink( $post_id );

        // IMPORTANT: Fetch movie data. This depends on how you store it.
        // Assuming it's stored in a meta field like '_bsbm_movies_data' as an array of movie arrays
        // This was the structure used in the NocoDB->WP import logic.
        // If your "Add New Experiment" form saves it differently, adjust here.
        $movies_data_from_wp = get_post_meta( $post_id, '_bsbm_movies_data_for_nocodb_sync', true ); // Or whatever meta key you use
        if ( !is_array( $movies_data_from_wp ) ) $movies_data_from_wp = array();

        $sync_successful_for_all_movies = true;

        foreach ( $movies_data_from_wp as $movie_data ) {
            $movie_tmdb_id = absint( $movie_data['movie_tmdb_id'] ?? 0 );
            if ( empty( $movie_tmdb_id ) || $movie_tmdb_id <= 0 ) {
                error_log("BSBM Sync: Skipping movie with invalid TMDB ID for post {$post_id}");
                continue;
            }

            // Prepare payload similar to handle_save_experiment_form
            $payload = [
                'experiment_number' => $experiment_number,
                'event_date' => $event_date_gmt,
                'event_host' => $event_host,
                'event_notes' => $event_notes,
                'post_url' => $post_url,
                'event_image_wp_id' => $image_id ?: null,
                'event_image' => $event_image_url ?: null,
                // Movie specific fields from $movie_data
                'movie_title' => sanitize_text_field($movie_data['movie_title'] ?? ''),
                'movie_tmdb_id' => $movie_tmdb_id,
                'movie_year' => absint($movie_data['movie_year'] ?? 0),
                'movie_overview' => sanitize_textarea_field($movie_data['movie_overview'] ?? ''),
                'movie_poster' => esc_url_raw($movie_data['movie_poster_url'] ?? ($movie_data['movie_poster'] ?? '') ), // Check both possible keys
                'movie_director' => sanitize_text_field($movie_data['movie_director'] ?? ''),
                'movie_cast' => sanitize_text_field($movie_data['movie_cast'] ?? ''), // Assuming comma separated string
                'movie_genres' => sanitize_text_field($movie_data['movie_genres'] ?? ''), // Assuming comma separated string
                'movie_tmdb_rating' => !empty($movie_data['movie_tmdb_rating']) ? floatval( $movie_data['movie_tmdb_rating'] ) : (!empty($movie_data['rating']) ? floatval($movie_data['rating']) : null),
                'movie_trailer' => esc_url_raw($movie_data['movie_trailer_url'] ?? ($movie_data['trailer_url'] ?? '') ),
                'movie_imdb_id' => sanitize_text_field($movie_data['movie_imdb_id'] ?? ($movie_data['imdb_id'] ?? '') ),
                'movie_runtime' => !empty($movie_data['movie_runtime']) ? absint($movie_data['movie_runtime']) : (!empty($movie_data['runtime']) ? absint($movie_data['runtime']) : null),
                'affiliate_links_json'  => wp_json_encode($movie_data['affiliate_links'] ?? []),
                // Add any other fields you sync from the form
            ];
            $payload = array_filter($payload, function($value) { return $value !== null; });

            $existing_record_id = $this->find_existing_nocodb_record( $experiment_number, $movie_tmdb_id, $nocodb_base_api_url, $nocodb_token );
            $http_method = $existing_record_id ? 'PATCH' : 'POST';
            $request_url = $existing_record_id ? $nocodb_base_api_url . '/' . $existing_record_id : $nocodb_base_api_url;
            $args = ['method' => $http_method, 'headers' => ['xc-token' => $nocodb_token, 'Content-Type' => 'application/json; charset=utf-8'], 'body' => wp_json_encode( $payload ), 'timeout' => 15, 'data_format' => 'body'];
            $response = wp_remote_request( $request_url, $args );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
                error_log( "BSBM Plugin Error: Failed {$http_method} NocoDB. Post {$post_id}, Movie {$movie_tmdb_id}. Error: " . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response) . ' - ' . wp_remote_retrieve_body($response)) );
                $sync_successful_for_all_movies = false;
            }
        }

        if ($sync_successful_for_all_movies) {
            update_post_meta( $post_id, '_bsbm_last_synced_to_nocodb', current_time( 'mysql', 1 ) ); // Store GMT time
            return true;
        }
        return false;
    }

	// --- Other Class Methods ---
	public function get_plugin_name() { return $this->plugin_name; }
	public function get_version() { return $this->version; }

	public function run() {
		// $this->maybe_init_updater(); // Temporarily commented out to deal with PucReadmeParser error
		/* ... any other run logic ... */
	}

	/**
	 * Initialize the plugin update checker, ensuring it only runs once.
	 *
	 * @since 1.0.0 (Modified in 1.0.13+)
	 */
	private function maybe_init_updater() {
		if ( self::$updater_initialized ) {
			return;
		}

		if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			// Optionally log: error_log('BSBM Plugin: Plugin Update Checker library (PucFactory) not found.');
			return; // Library not loaded
		}

		$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/dallensmith/Wordpress-plugins/', // GitHub repository URL.
			BSBM_PLUGIN_PATH . 'bigscreenbadmovies-plugin.php', // Main plugin file.
			'bigscreenbadmovies-plugin' // Plugin slug.
		);

		// Set the branch to check for updates.
		$myUpdateChecker->setBranch('main');

		// Optional: If your repository is private, uncomment the following line and add your Personal Access Token.
		// $myUpdateChecker->setAuthentication('YOUR_GITHUB_PAT');
		
		self::$updater_initialized = true;
	}

	// The old init_updater() method is now removed as its logic is in maybe_init_updater()

    private function fetch_nocodb_record_by_id($record_id) {
        $options = $this->get_plugin_options();
        $nocodb_url = trailingslashit( $options['nocodb_url'] ?? '' );
        $project_id = $options['nocodb_project_id'] ?? '';
        $table_name = $options['nocodb_table_id'] ?? '';
        $token = $options['nocodb_token'] ?? '';

        if ( empty( $nocodb_url ) || empty( $project_id ) || empty( $table_name ) || empty( $token ) || empty($record_id) ) {
            error_log('BSBM DEBUG: NocoDB settings or record ID incomplete for fetching single record by ID.');
            return null;
        }

        // NocoDB API URL for fetching a single record by its ID
        $api_url = sprintf(
            '%sapi/v1/db/data/v1/%s/%s/%s',
            rtrim($nocodb_url, '/'), // Ensure no double slash if $nocodb_url has it
            $project_id,
            $table_name,
            rawurlencode($record_id) // Ensure record ID is URL encoded if it contains special characters
        );
        error_log('BSBM DEBUG: Fetching NocoDB record by ID. API URL: ' . $api_url);

        $response = wp_remote_get( $api_url, array(
            'headers' => array( 'xc-token' => $token ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log('BSBM DEBUG: fetch_nocodb_record_by_id WP_Error: ' . $response->get_error_message());
            return null;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $response_code !== 200 ) {
            error_log('BSBM DEBUG: fetch_nocodb_record_by_id Non-200 response. Code: ' . $response_code . ' Body: ' . substr($body, 0, 500));
            return null;
        }
        
        $data = json_decode( $body, true );

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('BSBM DEBUG: fetch_nocodb_record_by_id JSON decode error: ' . json_last_error_msg() . '. Body was: ' . substr($body, 0, 500));
            return null;
        }
        return $data; // This should be the single record object/array
    }

    // --- New Sync Experiments Page ---
    // Renamed from render_sync_experiments_page to render_sync_hub_page
    public function render_sync_hub_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'bsbm-integration' ) );
        }

        // Get transient data if any
        $pending_experiments_from_nocodb = get_transient( 'bsbm_pending_experiments_from_nocodb' );
        $wp_experiments_to_sync = get_transient( 'bsbm_wp_experiments_to_sync_list' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php 
            // Consolidated message handling
            if ( isset( $_GET['message'] ) ) {
                $message_type = 'info'; // Default
                $message_text = '';

                switch ( $_GET['message'] ) {
                    case 'fetch_from_nocodb_error':
                        $message_type = 'error';
                        $message_text = __('Error fetching data from NocoDB. Please check settings and NocoDB availability.', 'bsbm-integration');
                        break;
                    case 'no_new_experiments_from_nocodb':
                        $message_type = 'info';
                        $message_text = __('No new experiments found in NocoDB to import.', 'bsbm-integration');
                        break;
                    case 'fetched_from_nocodb': // Added this case based on handle_fetch_pending_experiments_from_nocodb
                        $message_type = 'success';
                        $message_text = __('Successfully fetched pending experiments from NocoDB. Review and select experiments to import below.', 'bsbm-integration');
                        break;
                    case 'imported_to_wp':
                        $message_type = 'success';
                        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                        $message_text = sprintf( _n( '%s experiment imported successfully to WordPress.', '%s experiments imported successfully to WordPress.', $count, 'bsbm-integration' ), $count );
                        break;
                    case 'nothing_to_import_to_wp':
                        $message_type = 'warning';
                        $message_text = __('No experiments were selected to import to WordPress.', 'bsbm-integration');
                        break;
                    
                    // WP -> NocoDB messages
                    case 'listed_for_nocodb_sync':
                        $message_type = 'success';
                        $message_text = __('Found WordPress experiments that may need syncing to NocoDB. Review and select experiments to sync below.', 'bsbm-integration');
                        break;
                    case 'synced_to_nocodb':
                        $message_type = 'success';
                        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                        $message_text = sprintf( _n( '%s WordPress experiment synced successfully to NocoDB.', '%s WordPress experiments synced successfully to NocoDB.', $count, 'bsbm-integration' ), $count );
                        break;
                    case 'nothing_to_sync_to_nocodb':
                        $message_type = 'warning';
                        $message_text = __('No WordPress experiments were selected to sync to NocoDB.', 'bsbm-integration');
                        break;
                    case 'no_wp_experiments_to_sync':
                        $message_type = 'info';
                        $message_text = __('No WordPress experiments currently need to be synced to NocoDB.', 'bsbm-integration');
                        break;
                    case 'error_sync_to_nocodb':
                        $message_type = 'error';
                        $synced_count = isset($_GET['synced_count']) ? intval($_GET['synced_count']) : 0;
                        $failed_count = isset($_GET['failed_count']) ? intval($_GET['failed_count']) : 0;
                        $message_text = sprintf( __('Sync to NocoDB complete. %s succeeded, %s failed. Check error logs for details on failures.', 'bsbm-integration'), $synced_count, $failed_count);
                        if ($failed_count === 0 && $synced_count === 0) { // Should not happen if this message is used
                            $message_text = __('An unspecified error occurred during sync to NocoDB.', 'bsbm-integration');
                        }
                        break;
                }
                if ( !empty($message_text) ) {
                    echo '<div id="message" class="' . esc_attr($message_type) . ' notice is-dismissible"><p>' . esc_html($message_text) . '</p></div>';
                }
            }
            ?>

            <div id="col-container" class="wp-clearfix">
                <div id="col-left" style="width: 49%; float: left; margin-right: 2%;">
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('NocoDB to WordPress Sync', 'bsbm-integration'); ?></span></h2>
                        <div class="inside">
                            <p><?php _e('Fetch new experiments from NocoDB that are not yet on this site.', 'bsbm-integration'); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="bsbm_fetch_pending_experiments_from_nocodb">
                                <?php wp_nonce_field( 'bsbm_fetch_pending_experiments_from_nocodb_nonce', 'bsbm_fetch_nocodb_nonce' ); ?>
                                <?php submit_button( __( 'Fetch New from NocoDB', 'bsbm-integration' ), 'primary', 'fetch_from_nocodb_submit', false ); ?>
                            </form>

                            <?php if ( ! empty( $pending_experiments_from_nocodb ) ) : ?>
                                <h3><?php _e( 'Review & Import from NocoDB', 'bsbm-integration' ); ?></h3>
                                <p><?php _e( 'Select experiments to import into WordPress.', 'bsbm-integration' ); ?></p>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="bsbm_import_selected_experiments_from_nocodb">
                                    <?php wp_nonce_field( 'bsbm_import_selected_experiments_from_nocodb_nonce', 'bsbm_import_nocodb_nonce' ); ?>
                                    
                                    <table class="wp-list-table widefat fixed striped">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="manage-column column-cb check-column"><input type="checkbox" id="select-all-nocodb-experiments" checked="checked"></th>
                                                <th scope="col"><?php _e( 'Experiment Title', 'bsbm-integration' ); ?></th>
                                                <th scope="col"><?php _e( 'Event Date', 'bsbm-integration' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $pending_experiments_from_nocodb as $index => $exp ) : ?>
                                                <tr>
                                                    <th scope="row" class="check-column">
                                                        <input type="checkbox" name="selected_experiments_from_nocodb[]" value="<?php echo esc_attr( $exp['id_from_nocodb'] ); ?>" checked="checked">
                                                    </th>
                                                    <td><?php echo esc_html( $exp['title'] ?? 'N/A' ); ?></td>
                                                    <td><?php echo esc_html( $exp['event_date'] ? date_i18n( get_option('date_format'), strtotime($exp['event_date']) ) : 'N/A' ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php submit_button( __( 'Import Selected to WordPress', 'bsbm-integration' ), 'secondary', 'import_to_wp_submit', false, array('style' => 'margin-top:10px;') ); ?>
                                </form>
                            <?php elseif ( isset( $_GET['message'] ) && $_GET['message'] === 'fetched_from_nocodb' ) : ?>
                                <p><?php _e( 'All fetched experiments have been processed or there were none to display.', 'bsbm-integration' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="col-right" style="width: 49%; float: right;">
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('WordPress to NocoDB Sync', 'bsbm-integration'); ?></span></h2>
                        <div class="inside">
                            <p><?php _e('Find and sync WordPress experiments that have been updated or are new and not yet in NocoDB.', 'bsbm-integration'); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="bsbm_list_wp_experiments_for_sync">
                                <?php wp_nonce_field( 'bsbm_list_wp_experiments_for_sync_nonce', 'bsbm_list_wp_nonce' ); ?>
                                <?php submit_button( __( 'Find WP Experiments to Sync', 'bsbm-integration' ), 'primary', 'list_wp_to_sync_submit', false ); ?>
                            </form>

                            <?php if ( ! empty( $wp_experiments_to_sync ) ) : ?>
                                <h3><?php _e( 'Review & Sync to NocoDB', 'bsbm-integration' ); ?></h3>
                                <p><?php _e( 'Select WordPress experiments to sync/update in NocoDB.', 'bsbm-integration' ); ?></p>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="bsbm_sync_selected_wp_experiments_to_nocodb">
                                    <?php wp_nonce_field( 'bsbm_sync_selected_wp_experiments_to_nocodb_nonce', 'bsbm_sync_wp_nonce' ); ?>
                                    
                                    <table class="wp-list-table widefat fixed striped">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="manage-column column-cb check-column"><input type="checkbox" id="select-all-wp-experiments" checked="checked"></th>
                                                <th scope="col"><?php _e( 'Experiment Title (WP)', 'bsbm-integration' ); ?></th>
                                                <th scope="col"><?php _e( 'Last Modified (WP)', 'bsbm-integration' ); ?></th>
                                                <th scope="col"><?php _e( 'Sync Status', 'bsbm-integration' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $wp_experiments_to_sync as $exp_to_sync ) : ?>
                                                <tr>
                                                    <th scope="row" class="check-column">
                                                        <input type="checkbox" name="selected_wp_experiments_to_sync[]" value="<?php echo esc_attr( $exp_to_sync['id'] ); ?>" checked="checked">
                                                    </th>
                                                    <td>
                                                        <a href="<?php echo esc_url(get_edit_post_link($exp_to_sync['id'])); ?>" target="_blank">
                                                            <?php echo esc_html( $exp_to_sync['title'] ?? 'N/A' ); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo esc_html( $exp_to_sync['modified_date'] ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($exp_to_sync['modified_date']) ) : 'N/A' ); ?></td>
                                                    <td><?php echo esc_html( $exp_to_sync['sync_status'] ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php submit_button( __( 'Push Selected to NocoDB', 'bsbm-integration' ), 'secondary', 'sync_to_nocodb_submit', false, array('style' => 'margin-top:10px;') ); ?>
                                </form>
                            <?php elseif ( isset( $_GET['message'] ) && $_GET['message'] === 'listed_for_nocodb_sync' ) : ?>
                                 <p><?php _e( 'All listed experiments have been processed or there were none to display.', 'bsbm-integration' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div> <!-- /col-container -->

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#select-all-nocodb-experiments').on('click', function() {
                        var checkboxes = $(this).closest('table').find(':checkbox[name="selected_experiments_from_nocodb[]"]');
                        checkboxes.prop('checked', $(this).prop('checked'));
                    });
                    $('#select-all-wp-experiments').on('click', function() {
                        var checkboxes = $(this).closest('table').find(':checkbox[name="selected_wp_experiments_to_sync[]"]');
                        checkboxes.prop('checked', $(this).prop('checked'));
                    });
                });
            </script>
            <div style="clear:both;"></div>
        </div>
        <?php
        // Clear transients after displaying them
        if ( ! empty( $pending_experiments_from_nocodb ) && !isset($_GET['preserve_transient_nocodb'])) { // Add a query param to preserve if debugging
            delete_transient( 'bsbm_pending_experiments_from_nocodb' );
        }
        if ( ! empty( $wp_experiments_to_sync ) && !isset($_GET['preserve_transient_wp'])) { // Add a query param to preserve if debugging
            delete_transient( 'bsbm_wp_experiments_to_sync_list' );
        }
    }

    // Renamed from handle_fetch_pending_experiments to align with new structure
    public function handle_fetch_pending_experiments_from_nocodb() {
        error_log('BSBM DEBUG: Entered handle_fetch_pending_experiments_from_nocodb'); 
        if ( ! isset( $_POST['bsbm_fetch_nocodb_nonce'] ) || ! wp_verify_nonce( $_POST['bsbm_fetch_nocodb_nonce'], 'bsbm_fetch_pending_experiments_from_nocodb_nonce' ) ) {
            error_log('BSBM DEBUG: Nonce check failed in handle_fetch_pending_experiments_from_nocodb');
            wp_die( __( 'Security check failed.', 'bsbm-integration' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('BSBM DEBUG: Permission check failed in handle_fetch_pending_experiments_from_nocodb');
            wp_die( __( 'You do not have sufficient permissions.', 'bsbm-integration' ) );
        }

        error_log('BSBM DEBUG: Nonce and permission checks passed.');

        $options = $this->get_plugin_options();
        $nocodb_url = trailingslashit( $options['nocodb_url'] ?? '' );
        $project_id = $options['nocodb_project_id'] ?? '';
        $table_name = $options['nocodb_table_id'] ?? ''; // Assuming this is table name or ID
        $token = $options['nocodb_token'] ?? '';

        error_log('BSBM DEBUG: Options fetched: URL: ' . $nocodb_url . ', ProjectID: ' . $project_id . ', Table: ' . $table_name . ', Token Set: ' . !empty($token));

        if ( empty( $nocodb_url ) || empty( $project_id ) || empty( $table_name ) || empty( $token ) ) {
            error_log('BSBM DEBUG: NocoDB settings incomplete. Redirecting.');
            wp_redirect( add_query_arg( 'message', 'fetch_from_nocodb_error', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        // Corrected API URL structure for NocoDB v1 to list records
        $api_url = sprintf(
            '%sapi/v1/db/data/v1/%s/%s?limit=1000&shuffle=0&offset=0', // Changed /noco/ to /v1/ and removed /records segment before query
            $nocodb_url,
            $project_id,
            $table_name
        );
        error_log('BSBM DEBUG: NocoDB API URL (Corrected): ' . $api_url);

        $response = wp_remote_get( $api_url, array(
            'headers' => array( 'xc-token' => $token ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log('BSBM DEBUG: wp_remote_get WP_Error: ' . $response->get_error_message());
            wp_redirect( add_query_arg( 'message', 'fetch_from_nocodb_error', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        error_log('BSBM DEBUG: wp_remote_get response code: ' . $response_code);

        if ( $response_code !== 200 ) {
            error_log('BSBM DEBUG: Non-200 response. Body: ' . wp_remote_retrieve_body( $response ));
            wp_redirect( add_query_arg( 'message', 'fetch_from_nocodb_error', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        $body = wp_remote_retrieve_body( $response );
        error_log('BSBM DEBUG: Successfully retrieved body from NocoDB.');
        $data = json_decode( $body, true );

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('BSBM DEBUG: JSON decode error: ' . json_last_error_msg() . '. Body was: ' . substr($body, 0, 500)); // Log first 500 chars of body
            wp_redirect( add_query_arg( 'message', 'fetch_from_nocodb_error', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }
        error_log('BSBM DEBUG: JSON decoded successfully.');

        $nocodb_experiments = isset($data['list']) && is_array($data['list']) ? $data['list'] : [];
        error_log('BSBM DEBUG: Count of experiments from NocoDB list: ' . count($nocodb_experiments));

        if ( !empty($nocodb_experiments) ) {
            $first_record_keys = array_keys($nocodb_experiments[0]);
            error_log('BSBM DEBUG: Keys of the first NocoDB record: ' . implode(', ', $first_record_keys)); 
        }

        global $wpdb;
        $existing_nocodb_ids_results = $wpdb->get_col( $wpdb->prepare( 
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", 
            '_bsbm_nocodb_id' 
        ) );
        $existing_nocodb_ids = array_map('strval', $existing_nocodb_ids_results);
        error_log('BSBM DEBUG: Found ' . count($existing_nocodb_ids) . ' existing NocoDB IDs in WordPress.');

        $pending_experiments = array();
        foreach ( $nocodb_experiments as $exp_data ) {
            $nocodb_id = strval($exp_data['Id'] ?? ($exp_data['id'] ?? null));
            $title = $exp_data['movie_title'] ?? 'Untitled Experiment';       // Use 'movie_title'
            $event_date_str = $exp_data['event_date'] ?? null;                // Use 'event_date'

            if ( empty($nocodb_id) ) continue;

            if ( ! in_array( $nocodb_id, $existing_nocodb_ids ) ) {
                $pending_experiments[] = array(
                    'id_from_nocodb' => $nocodb_id,
                    'title' => $title,
                    'event_date' => $event_date_str
                    // 'full_data' => $exp_data // Intentionally removed
                );
            }
        }
        error_log('BSBM DEBUG: Found ' . count($pending_experiments) . ' pending experiments to list (summary only).');

        if ( empty( $pending_experiments ) ) {
            error_log('BSBM DEBUG: No new experiments found in NocoDB. Redirecting.');
            wp_redirect( add_query_arg( 'message', 'no_new_experiments_from_nocodb', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        set_transient( 'bsbm_pending_experiments_from_nocodb', $pending_experiments, HOUR_IN_SECONDS );
        error_log('BSBM DEBUG: Pending experiments set in transient. Redirecting to fetched_from_nocodb.');
        wp_redirect( add_query_arg( 'message', 'fetched_from_nocodb', menu_page_url( 'bsbm-sync-hub', false ) ) );
        exit;
    }

    // Renamed from handle_import_selected_experiments
    public function handle_import_selected_experiments_from_nocodb() {
        if ( ! isset( $_POST['bsbm_import_nocodb_nonce'] ) || ! wp_verify_nonce( $_POST['bsbm_import_nocodb_nonce'], 'bsbm_import_selected_experiments_from_nocodb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'bsbm-integration' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions.', 'bsbm-integration' ) );
        }

        $selected_ids = $_POST['selected_experiments_from_nocodb'] ?? array();
        if ( empty( $selected_ids ) ) {
            wp_redirect( add_query_arg( 'message', 'nothing_to_import_to_wp', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        $all_pending_experiments = get_transient( 'bsbm_pending_experiments_from_nocodb' );
        if ( empty( $all_pending_experiments ) ) {
            wp_redirect( add_query_arg( 'message', 'fetch_from_nocodb_error', menu_page_url( 'bsbm-sync-hub', false ) ) ); 
            exit;
        }

        $imported_count = 0;
        $remaining_pending_experiments = $all_pending_experiments;

        foreach ( $all_pending_experiments as $key => $exp_to_import ) {
            if ( in_array( strval($exp_to_import['id_from_nocodb']), $selected_ids ) ) {
                $nocodb_data = $this->fetch_nocodb_record_by_id($exp_to_import['id_from_nocodb']); // Fetch full data by ID

                if (empty($nocodb_data)) {
                    error_log("BSBM Import: Failed to fetch full data for NocoDB ID: " . $exp_to_import['id_from_nocodb']);
                    continue;
                }

                // --- Prepare Post Data ---
                // IMPORTANT: Adjust field names from $nocodb_data to match your NocoDB column names
                $post_title = $nocodb_data['movie_title'] ?? // Use NocoDB movie_title
                              ($nocodb_data['experiment_number'] ? "Experiment #" . $nocodb_data['experiment_number'] : // Fallback to experiment number
                              ('Untitled Experiment from NocoDB ' . ($exp_to_import['id_from_nocodb'] ?? 'UnknownID'))); // Absolute fallback

                $post_content = $nocodb_data['movie_overview'] ?? ($nocodb_data['event_notes'] ?? ''); // Use movie_overview or event_notes for content
                $event_date_str = $exp_to_import['event_date'] ?? ($nocodb_data['event_date'] ?? null);
                
                $event_timestamp = false;
                if ($event_date_str) {
                    // Try to parse various common date formats, including ISO 8601 from NocoDB
                    $parsed_time = strtotime($event_date_str);
                    if ($parsed_time === false) {
                        // Attempt to parse if it's a date like "YYYYMMDDHHMMSS" or "YYYYMMDD"
                        if (preg_match('/^(\\d{4})(\\d{2})(\\d{2})(\\d{2})?(\\d{2})?(\\d{2})?$/', $event_date_str, $matches)) {
                            $iso_date = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                            if (isset($matches[4]) && isset($matches[5]) && isset($matches[6])) {
                                $iso_date .= ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                            } else {
                                $iso_date .= ' 22:00:00'; // Default time if only date part is present
                            }
                            $parsed_time = strtotime($iso_date);
                        }
                    }
                    $event_timestamp = $parsed_time;
                }


                if ( $event_timestamp === false ) {
                    $post_date = current_time( 'mysql' );
                    $post_date_gmt = current_time( 'mysql', 1 );
                    error_log("BSBM Import: Could not parse event date: \"$event_date_str\" for NocoDB ID: " . $exp_to_import['id_from_nocodb'] . ". Using current time.");
                } else {
                    $post_date = date( 'Y-m-d H:i:s', $event_timestamp ); // Local time
                    $post_date_gmt = gmdate( 'Y-m-d H:i:s', $event_timestamp ); // GMT
                }
                
                // Prepare movies data for the _bsbm_movies_data_for_nocodb_sync meta field
                // This requires that your NocoDB fetch brings all movies for an experiment if they are separate rows,
                // or that $nocodb_data (if it's a single row per experiment) contains an array/JSON of movies.
                // For simplicity, assuming $nocodb_data is one experiment, and it might have movie details directly
                // or you might need to adjust the fetch logic to group movies per experiment_number from NocoDB.
                // The current `handle_fetch_pending_experiments_from_nocodb` fetches individual rows.
                // We need to ensure that when we select an "experiment" to import, we get all its associated movie rows.
                // This part needs careful review based on how NocoDB data is structured and fetched.
                // For now, this import will create ONE WP post per selected NocoDB row.
                // This is simpler to implement initially but might not be the final desired UX if NocoDB has one row per movie.
                // We will store the NocoDB row ID.

                $meta_input = array(
                    '_bsbm_nocodb_id' => $exp_to_import['id_from_nocodb'], // Store NocoDB's own record ID
                    '_bsbm_experiment_number' => $nocodb_data['experiment_number'] ?? null,
                    '_bsbm_event_host' => $nocodb_data['event_host'] ?? null,
                    '_bsbm_event_notes' => $nocodb_data['event_notes'] ?? null, // Specific event notes
                    '_bsbm_post_url_from_nocodb' => $nocodb_data['post_url'] ?? null, // If you have post_url from NocoDB
                    '_bsbm_event_location_from_nocodb' => $nocodb_data['event_location'] ?? null,
                );

                // If NocoDB stores movies as a JSON string in a field (e.g., 'movies_json')
                $movies_for_sync_meta = array();
                // Assuming each NocoDB row IS a movie, and we want to populate the sync meta for this single movie.
                // The structure of _bsbm_movies_data_for_nocodb_sync should be an array of movie arrays.
                // So, we create an array containing one movie, mapped from $nocodb_data.
                
                $current_movie_data_for_sync = [
                    'movie_tmdb_id' => absint($nocodb_data['movie_tmdb_id'] ?? 0),
                    'movie_title' => sanitize_text_field($nocodb_data['movie_title'] ?? ''),
                    'movie_year' => absint($nocodb_data['movie_year'] ?? 0),
                    'movie_overview' => sanitize_textarea_field($nocodb_data['movie_overview'] ?? ''),
                    'movie_poster_url' => esc_url_raw($nocodb_data['movie_poster'] ?? ''), // NocoDB key 'movie_poster'
                    'movie_director' => sanitize_text_field($nocodb_data['movie_director'] ?? ''),
                    'movie_cast' => sanitize_text_field($nocodb_data['movie_actors'] ?? ''), // NocoDB key 'movie_actors'
                    'movie_genres' => sanitize_text_field($nocodb_data['movie_genres'] ?? ''),
                    'movie_tmdb_rating' => !empty($nocodb_data['movie_tmdb_rating']) ? floatval($nocodb_data['movie_tmdb_rating']) : null,
                    'movie_trailer_url' => esc_url_raw($nocodb_data['movie_trailer'] ?? ''), // NocoDB key 'movie_trailer'
                    'movie_imdb_id' => sanitize_text_field($nocodb_data['movie_imdb_id'] ?? ''),
                    'movie_runtime' => !empty($nocodb_data['movie_runtime']) ? absint($nocodb_data['movie_runtime']) : null,
                    'affiliate_links' => $this->parse_affiliate_links_from_nocodb($nocodb_data['affiliate_links_override'] ?? ''), // Assuming this field might exist or be added
                    // Add other fields from NocoDB to this structure as needed
                    'movie_original_title' => sanitize_text_field($nocodb_data['movie_original_title'] ?? ''),
                    'movie_release_date' => sanitize_text_field($nocodb_data['movie_release_date'] ?? ''),
                    'movie_tagline' => sanitize_text_field($nocodb_data['movie_tagline'] ?? ''),
                    'movie_content_rating' => sanitize_text_field($nocodb_data['movie_content_rating'] ?? ''),
                    'movie_writers' => sanitize_text_field($nocodb_data['movie_writers'] ?? ''),
                    'movie_studio' => sanitize_text_field($nocodb_data['movie_studio'] ?? ''),
                    'movie_country' => sanitize_text_field($nocodb_data['movie_country'] ?? ''),
                    'movie_language' => sanitize_text_field($nocodb_data['movie_language'] ?? ''),
                    'movie_budget' => !empty($nocodb_data['movie_budget']) ? floatval($nocodb_data['movie_budget']) : null,
                    'movie_box_office' => !empty($nocodb_data['movie_box_office']) ? floatval($nocodb_data['movie_box_office']) : null,
                    'movie_backdrop_url' => esc_url_raw($nocodb_data['movie_backdrop'] ?? ''), // NocoDB key 'movie_backdrop'
                    'movie_tmdb_votes' => !empty($nocodb_data['movie_tmdb_votes']) ? intval($nocodb_data['movie_tmdb_votes']) : null,
                    'movie_imdb_rating' => !empty($nocodb_data['movie_imdb_rating']) ? floatval($nocodb_data['movie_imdb_rating']) : null,
                    'movie_imdb_votes' => !empty($nocodb_data['movie_imdb_votes']) ? intval($nocodb_data['movie_imdb_votes']) : null,
                    'movie_tmdb_url' => esc_url_raw($nocodb_data['movie_tmdb_url'] ?? ''),
                    'movie_imdb_url' => esc_url_raw($nocodb_data['movie_imdb_url'] ?? ''),
                ];
                $movies_for_sync_meta[] = array_filter($current_movie_data_for_sync, function($value) { return $value !== null && $value !== ''; });


                if (!empty($movies_for_sync_meta)) {
                    $meta_input['_bsbm_movies_data_for_nocodb_sync'] = $movies_for_sync_meta;
                }


                $post_arr = array(
                    'post_type'    => 'bsbm_experiment',
                    'post_title'   => sanitize_text_field( $post_title ),
                    'post_content' => wp_kses_post( $post_content ), // Use if you have a main content field
                    'post_status'  => 'publish',
                    'post_date'    => $post_date,
                    'post_date_gmt'=> $post_date_gmt,
                    'meta_input'   => $meta_input,
                );

                $post_id = wp_insert_post( $post_arr, true ); 

                if ( ! is_wp_error( $post_id ) ) {
                    $imported_count++;
                    unset( $remaining_pending_experiments[$key] );

                    // Sideload image if URL is present in NocoDB data and post doesn't have a thumbnail
                    $poster_url_to_sideload = $nocodb_data['custom_event_poster_url'] ?? ($nocodb_data['EventPosterURL'] ?? null); // Adjust field name
                    if ( !empty($poster_url_to_sideload) && !has_post_thumbnail($post_id) ) {
                        if (!function_exists('media_sideload_image')) {
                            require_once(ABSPATH . 'wp-admin/includes/media.php');
                            require_once(ABSPATH . 'wp-admin/includes/file.php');
                            require_once(ABSPATH . 'wp-admin/includes/image.php');
                        }
                        $image_sideload_id = media_sideload_image(esc_url_raw($poster_url_to_sideload), $post_id, $post_title, 'id');
                        if (!is_wp_error($image_sideload_id)) {
                            set_post_thumbnail($post_id, $image_sideload_id);
                        } else {
                            error_log("BSBM Import: Error sideloading image for post #{$post_id} from URL {$poster_url_to_sideload}: " . $image_sideload_id->get_error_message());
                        }
                    }
                     // Mark as synced from NocoDB (so it doesn't show up as "needing sync to NocoDB" immediately)
                    update_post_meta($post_id, '_bsbm_last_synced_to_nocodb', current_time('mysql', 1));

                } else {
                    error_log("BSBM Import: Failed to import experiment (NocoDB ID: " . $exp_to_import['id_from_nocodb'] . "): " . $post_id->get_error_message());
                }
            }
        }

        if ( empty( $remaining_pending_experiments ) ) {
            delete_transient( 'bsbm_pending_experiments_from_nocodb' );
        } else {
            set_transient( 'bsbm_pending_experiments_from_nocodb', array_values($remaining_pending_experiments), HOUR_IN_SECONDS );
        }

        wp_redirect( add_query_arg( array('message' => 'imported_to_wp', 'count' => $imported_count), menu_page_url( 'bsbm-sync-hub', false ) ) );
        exit;
    }

    // --- WP to NocoDB Sync Handlers ---
    public function handle_list_wp_experiments_for_sync() {
        error_log('BSBM DEBUG: Attempting to enter handle_list_wp_experiments_for_sync'); // DEBUG LINE
        if ( ! isset( $_POST['bsbm_list_wp_nonce'] ) || ! wp_verify_nonce( $_POST['bsbm_list_wp_nonce'], 'bsbm_list_wp_experiments_for_sync_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'bsbm-integration' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions.', 'bsbm-integration' ) );
        }

        $args = array(
            'post_type' => 'bsbm_experiment',
            'posts_per_page' => -1,
            'post_status' => 'publish', // Or any status you want to sync
            'orderby' => 'modified',
            'order' => 'DESC',
        );
        $query = new WP_Query( $args );
        $experiments_to_list = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $last_synced_gmt = get_post_meta( $post_id, '_bsbm_last_synced_to_nocodb', true );
                $post_modified_gmt = get_post_modified_time('Y-m-d H:i:s', true, $post_id);
                
                $needs_sync = false;
                $sync_status = 'Up to date';

                if ( empty($last_synced_gmt) ) {
                    $needs_sync = true;
                    $sync_status = 'Never synced';
                } elseif ( $post_modified_gmt && strtotime($post_modified_gmt) > strtotime($last_synced_gmt) ) {
                    $needs_sync = true;
                    $sync_status = 'Modified since last sync';
                }

                if ( $needs_sync ) {
                    $experiments_to_list[] = array(
                        'id' => $post_id,
                        'title' => get_the_title(),
                        'modified_date' => get_post_modified_time('Y-m-d H:i:s', false, $post_id), // Local time for display
                        'sync_status' => $sync_status,
                    );
                }
            }
            wp_reset_postdata();
        }

        if ( empty( $experiments_to_list ) ) {
            wp_redirect( add_query_arg( 'message', 'no_wp_experiments_to_sync', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        set_transient( 'bsbm_wp_experiments_to_sync_list', $experiments_to_list, HOUR_IN_SECONDS );
        wp_redirect( add_query_arg( 'message', 'listed_for_nocodb_sync', menu_page_url( 'bsbm-sync-hub', false ) ) );
        exit;
    }

    public function handle_sync_selected_wp_experiments_to_nocodb() {
        if ( ! isset( $_POST['bsbm_sync_wp_nonce'] ) || ! wp_verify_nonce( $_POST['bsbm_sync_wp_nonce'], 'bsbm_sync_selected_wp_experiments_to_nocodb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'bsbm-integration' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions.', 'bsbm-integration' ) );
        }

        $selected_post_ids = $_POST['selected_wp_experiments_to_sync'] ?? array();
        if ( empty( $selected_post_ids ) ) {
            wp_redirect( add_query_arg( 'message', 'nothing_to_sync_to_nocodb', menu_page_url( 'bsbm-sync-hub', false ) ) );
            exit;
        }

        $synced_count = 0;
        $failed_count = 0;

        foreach ( $selected_post_ids as $post_id ) {
            $post_id = absint($post_id);
            if ( $post_id > 0 ) {
                // Before syncing, ensure the `_bsbm_movies_data_for_nocodb_sync` meta is up-to-date
                // if it's not already being perfectly maintained by the 'Add/Edit Experiment' form.
                // This might involve re-fetching movie details from TMDb if your primary movie data storage
                // in WP is different from what `sync_wp_experiment_to_nocodb` expects.
                // For now, assuming `_bsbm_movies_data_for_nocodb_sync` is the canonical source for syncing.

                if ( $this->sync_wp_experiment_to_nocodb( $post_id ) ) {
                    $synced_count++;
                } else {
                    $failed_count++;
                }
            }
        }
        
        // Clear the transient as the action is done
        delete_transient('bsbm_wp_experiments_to_sync_list');

        if ($failed_count > 0) {
             wp_redirect( add_query_arg( array('message' => 'error_sync_to_nocodb', 'synced_count' => $synced_count, 'failed_count' => $failed_count), menu_page_url( 'bsbm-sync-hub', false ) ) );
        } else {
             wp_redirect( add_query_arg( array('message' => 'synced_to_nocodb', 'count' => $synced_count), menu_page_url( 'bsbm-sync-hub', false ) ) );
        }
        exit;
    }
    
    private function parse_affiliate_links_from_nocodb($links_json_or_text) {
        if (empty($links_json_or_text)) return [];
        $decoded = json_decode($links_json_or_text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_map(function($link){
                return [
                    'name' => sanitize_text_field($link['name'] ?? ''),
                    'url' => esc_url_raw($link['url'] ?? '')
                ];
            }, $decoded);
        }
        // Fallback for simple text if not JSON (e.g. "Name1:URL1, Name2:URL2") - very basic
        $links = [];
        $pairs = explode(',', $links_json_or_text);
        foreach($pairs as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2) {
                $links[] = ['name' => sanitize_text_field(trim($parts[0])), 'url' => esc_url_raw(trim($parts[1]))];
            }
        }
        return $links;
    }

    // --- REST API Endpoints ---
    public function register_rest_routes() {
        // Register custom REST API routes here
    }

    // Placeholder for admin scripts/styles enqueue to prevent fatal error
    public function enqueue_admin_form_assets($hook_suffix) {
        // Only load on our specific admin pages
        $screen = get_current_screen();
        // Check if $screen is null before accessing its properties
        if ($screen && (strpos($screen->id, 'bsbm-add-experiment-form') !== false || strpos($screen->id, 'bsbm-sync-hub') !== false) ) {
            // Assuming BSBM_PLUGIN_URL is defined in your main plugin file correctly
            if (defined('BSBM_PLUGIN_URL')) {
                 wp_enqueue_script('bsbm-admin-form-script', BSBM_PLUGIN_URL . 'admin/js/bsbm-admin-form.js', array('jquery', 'wp-util', 'media-editor'), BSBM_PLUGIN_VERSION, true); // Added 'media-editor', ensured 'wp-util'
                 
                 $rest_url = get_rest_url(null, 'bsbm/v1/'); 
                 $nonce = wp_create_nonce('wp_rest');

                 wp_localize_script('bsbm-admin-form-script', 'bsbmAdminData', array( // CORRECTED to bsbmAdminData
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => $nonce, 
                    'rest_url' => $rest_url, 
                    'text_add_movie' => __('+ Add Movie', 'bsbm-integration'),
                    'text_remove_movie' => __('Remove', 'bsbm-integration'),
                    'text_movie_title_placeholder' => __('Movie', 'bsbm-integration'),
                    'tmdb_api_key' => $this->get_plugin_options()['tmdb_api_key'] ?? '',
                    'tmdb_base_url' => $this->tmdb_api_base_url,
                    'pageNow' => $screen->id, 
                    'text' => [ 
                        'no_results' => __('No results found.', 'bsbm-integration'),
                        'error_fetching' => __('Error fetching results.', 'bsbm-integration'),
                        'error_details' => __('Error: Invalid movie details received.', 'bsbm-integration'),
                        'platform_name' => __('Platform Name', 'bsbm-integration'),
                        'affiliate_url' => __('Affiliate URL', 'bsbm-integration'),
                        'remove_link' => __('Remove Link', 'bsbm-integration'),
                    ]
                 ));
            }
        }
    }

} // End class BigScreenBadMovies_Plugin