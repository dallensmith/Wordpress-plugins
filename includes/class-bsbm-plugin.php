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
        // Hook to enqueue scripts/styles needed for the custom admin form page
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

        // 3. WordPress adds "All Experiments" (edit.php?post_type=bsbm_experiment) automatically, usually at position 5 or 10.
        // We want our "Add New Experiment" above "All Experiments".

        // 4. Add the Settings page under the "Experiments" menu
        add_submenu_page(
            'edit.php?post_type=bsbm_experiment',       // Parent slug
            __( 'BSBM Settings', 'bsbm-integration' ),    // Page title
            __( 'Settings', 'bsbm-integration' ),         // Menu title
            'manage_options',                           // Capability
            'bsbm-integration-settings',                // Menu slug
            array( $this, 'render_settings_page' ),      // Callback
            10                                          // Position (after "All Experiments")
        );
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
        $post_data = [ 'post_title' => $post_title, 'post_status' => 'publish', 'post_type' => 'bsbm_experiment', 'post_date' => $event_date_local, 'post_date_gmt'=> $event_date_gmt ];
        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) { wp_die( __( 'Error saving experiment post:', 'bsbm-integration' ) . ' ' . $post_id->get_error_message() ); }
        update_post_meta( $post_id, '_bsbm_event_host', $event_host ); update_post_meta( $post_id, '_bsbm_event_notes', $event_notes );
        if ( $image_id > 0 ) { set_post_thumbnail( $post_id, $image_id ); } else { delete_post_thumbnail( $post_id ); }

		$settings = $this->get_plugin_options();
		$nocodb_url = trim( $settings['nocodb_url'] ?? '' ); $nocodb_project_id = trim( $settings['nocodb_project_id'] ?? '' );
		$nocodb_table_id = trim( $settings['nocodb_table_id'] ?? '' ); $nocodb_token = trim( $settings['nocodb_token'] ?? '' );

		if ( !empty( $nocodb_url ) && !empty( $nocodb_project_id ) && !empty( $nocodb_table_id ) && !empty( $nocodb_token ) ) {
			$nocodb_base_api_url = sprintf('%s/api/v1/db/data/v1/%s/%s', rtrim($nocodb_url, '/'), $nocodb_project_id, $nocodb_table_id);
            $experiment_number = 0; if ( preg_match( '/Experiment\s*#(\d+)/i', $post_title, $matches ) ) { $experiment_number = intval( $matches[1] ); }
            $post_url = get_permalink( $post_id ); $event_image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

            foreach ( $movies_input as $movie_data_raw ) {
                $sanitized_movie_data = [];
                foreach ($movie_data_raw as $key => $value) {
                    if ($key === 'overview' || $key === 'cast') { $sanitized_movie_data[$key] = sanitize_textarea_field($value); }
                    elseif ($key === 'poster_url' || $key === 'trailer_url') { $sanitized_movie_data[$key] = esc_url_raw($value); }
                    elseif ($key === 'affiliate_links' && is_array($value)) { $sanitized_movie_data[$key] = array_map(function($link){ return ['name' => sanitize_text_field($link['name'] ?? ''), 'url' => esc_url_raw($link['url'] ?? '')]; }, $value); }
                    elseif (in_array($key, ['tmdb_id', 'year', 'runtime', 'budget', 'revenue'])) { $sanitized_movie_data[$key] = absint($value); }
                    elseif ($key === 'rating') { $sanitized_movie_data[$key] = floatval($value); }
                    else { $sanitized_movie_data[$key] = sanitize_text_field($value); }
                }
                $movie_tmdb_id = absint($sanitized_movie_data['tmdb_id'] ?? 0);
                if ( empty( $movie_tmdb_id ) || $movie_tmdb_id <= 0 ) { continue; }
                $payload = [
                    'experiment_number' => $experiment_number, 'event_date' => $event_date_gmt, 'event_host' => $event_host, 'event_notes' => $event_notes, 'post_url' => $post_url,
                    'event_image_wp_id' => $image_id ?: null, 'event_image' => $event_image_url ?: null,
                    'movie_title' => $sanitized_movie_data['title'] ?? '', 'movie_tmdb_id' => $movie_tmdb_id, 'movie_year' => absint($sanitized_movie_data['year'] ?? 0),
                    'movie_overview' => $sanitized_movie_data['overview'] ?? '', 'movie_poster' => $sanitized_movie_data['poster_url'] ?: null,
                    'movie_director' => $sanitized_movie_data['director'] ?? '', 'movie_cast' => $sanitized_movie_data['cast'] ?? '', 'movie_genres' => $sanitized_movie_data['genres'] ?? '',
                    'movie_tmdb_rating' => !empty($sanitized_movie_data['rating']) ? floatval( $sanitized_movie_data['rating'] ) : null,
                    'movie_trailer' => $sanitized_movie_data['trailer_url'] ?: null, 'movie_imdb_id' => $sanitized_movie_data['imdb_id'] ?? '',
                    'movie_runtime' => !empty($sanitized_movie_data['runtime']) ? absint($sanitized_movie_data['runtime']) : null,
                    'affiliate_links_json'  => wp_json_encode($sanitized_movie_data['affiliate_links'] ?? []),
                ];
                $payload = array_filter($payload, function($value) { return $value !== null; });
                $existing_record_id = $this->find_existing_nocodb_record( $experiment_number, $movie_tmdb_id, $nocodb_base_api_url, $nocodb_token );
                $http_method = $existing_record_id ? 'PATCH' : 'POST';
                $request_url = $existing_record_id ? $nocodb_base_api_url . '/' . $existing_record_id : $nocodb_base_api_url;
                $args = ['method' => $http_method, 'headers' => ['xc-token' => $nocodb_token, 'Content-Type' => 'application/json; charset=utf-8'], 'body' => wp_json_encode( $payload ), 'timeout' => 15, 'data_format' => 'body'];
                $response = wp_remote_request( $request_url, $args );
                if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) { error_log( "BSBM Plugin Error: Failed {$http_method} NocoDB. Post {$post_id}, Movie {$movie_tmdb_id}. Error: " . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)) ); }
            }
		} else { error_log( 'BSBM Plugin: NocoDB settings incomplete, skipping NocoDB sync for post ID: ' . $post_id ); }
        wp_safe_redirect( admin_url( 'edit.php?post_type=bsbm_experiment&page=bsbm-add-experiment-form&message=success&post_id=' . $post_id ) );
        exit;
    }

    public function enqueue_admin_form_assets( $hook_suffix ) {
        if ( 'bsbm_experiment_page_bsbm-add-experiment-form' !== $hook_suffix && strpos($hook_suffix, 'bsbm-add-experiment-form') === false ) { return; }
        wp_enqueue_media(); wp_enqueue_script('jquery'); wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( $this->plugin_name . '-admin-form-script', BSBM_PLUGIN_URL . 'admin/js/bsbm-admin-form.js', ['jquery', 'jquery-ui-sortable', 'postbox'], $this->version, true );
        wp_localize_script( $this->plugin_name . '-admin-form-script', 'bsbmAdminData', [
            'rest_url' => esc_url_raw( rest_url( 'bsbm/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'pageNow' => $hook_suffix,
            'text' => [ 'remove_movie' => __( 'Remove Movie', 'bsbm-integration' ), 'add_affiliate_link' => __( '+ Add Affiliate Link', 'bsbm-integration' ), 'remove_link' => __( 'Remove Link', 'bsbm-integration' ), 'platform_name' => __( 'Platform Name', 'bsbm-integration' ), 'affiliate_url' => __( 'Affiliate URL', 'bsbm-integration' ), 'no_results' => __('No results found.', 'bsbm-integration'), 'error_fetching' => __('Error fetching results.', 'bsbm-integration'), 'error_details' => __('Error fetching movie details.', 'bsbm-integration') ],
        ]);
        wp_enqueue_style( 'common' ); wp_enqueue_style( 'wp-admin' ); wp_enqueue_style( 'dashboard' );
    }

    // --- REST API Callbacks ---
	public function register_rest_routes() { /* ... Same as before ... */ }
	public function rest_permission_check( WP_REST_Request $request ) { /* ... Same as before ... */ }
	public function handle_tmdb_search_request( WP_REST_Request $request ) { /* ... Same as before ... */ }
	public function handle_tmdb_details_request( WP_REST_Request $request ) { /* ... Same as before ... */ }

    // --- NocoDB Helper ---
    private function find_existing_nocodb_record( $exp_num, $tmdb_id, $api_base_url, $api_token ) { /* ... Same as before ... */ }

	// --- Other Class Methods ---
	public function get_plugin_name() { return $this->plugin_name; }
	public function get_version() { return $this->version; }

	public function run() {
		$this->maybe_init_updater(); // Initialize updater here
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

} // End class BigScreenBadMovies_Plugin