=== Big Screen Bad Movies Integration ===
Contributors: dallensmith
Tags: movies, tmdb, nocodb, custom post type, admin interface
Requires at least: 5.8
Tested up to: # We can update this later, e.g., current WP version
Requires PHP: 7.4
Stable tag: 1.0.22
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates NocoDB movie data with WordPress. Creates an 'Experiment' CPT and a custom admin page for managing experiments and movies.

== Description ==

The Big Screen Bad Movies Integration plugin provides a custom admin interface within WordPress to manage "Experiment" data (movie viewing events). It connects to an external NocoDB database to store detailed movie information and utilizes The Movie Database (TMDb) API for fetching movie details.

Key Features:
*   Registers an "Experiment" Custom Post Type.
*   Custom "Add New Experiment" admin page with fields for event details and multiple movies.
*   Live TMDb search to find and populate movie information.
*   Saves experiment and movie data to WordPress and syncs to a NocoDB database.
*   Settings page for API keys (NocoDB, TMDb) and display defaults.

== Installation ==

1.  Upload the `bigscreenbadmovies-plugin` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to "Experiments" > "Settings" to configure your API keys for NocoDB and TMDb.
4.  Start adding new experiments via the "Experiments" > "Add New Experiment" page.

== Frequently Asked Questions ==

= How do I get API keys? =

*   **NocoDB:** Refer to your NocoDB instance documentation for generating an API token. You'll also need your NocoDB URL, Project ID, and Table Name/ID.
*   **TMDb:** You can apply for a free API key at [https://www.themoviedb.org/documentation/api](https://www.themoviedb.org/documentation/api).

== Screenshots ==

1.  The "Add New Experiment" form. (We can add actual screenshot links/descriptions later)
2.  The plugin settings page.

== Changelog ==

= 1.0.19 - 2025-05-08 =
* Defined `parse_affiliate_links_from_nocodb()` method in `class-bsbm-plugin.php` to handle parsing of affiliate links from NocoDB during import.
* Updated Sync Hub UI and handlers for NocoDB to WordPress and WordPress to NocoDB synchronization.
* Refined API key handling and settings page.
* Continued development of the "Add New Experiment" form with dynamic movie fields and TMDb search.

= 1.0.18 - 2025-05-08 =
* Addressed issue with `sync_wp_experiment_to_nocodb` where `affiliate_links_json` was not correctly prepared.
* Ensured `_bsbm_movies_data_for_nocodb_sync` meta field is correctly populated and used.
* Minor fixes to `handle_save_experiment_form` for data sanitization and NocoDB payload.

= 1.0.17 - 2025-05-07 =
* Implemented the `handle_sync_selected_wp_experiments_to_nocodb` method for the Sync Hub.
* Implemented the `handle_list_wp_experiments_for_sync` method for the Sync Hub.
* Added more robust error handling and user feedback messages in the Sync Hub.

= 1.0.16 - 2025-05-07 =
* Implemented `handle_import_selected_experiments_from_nocodb` in `class-bsbm-plugin.php` for importing NocoDB data into WordPress.
* Implemented `handle_fetch_pending_experiments_from_nocodb` to fetch data from NocoDB.
* Developed the UI for the "Sync Hub" (`render_sync_hub_page`).
* Added admin notices for sync operations.

= 1.0.15 - 2025-05-07 =
* Added `find_existing_nocodb_record` helper method.
* Refined `sync_wp_experiment_to_nocodb` to handle both creating new and updating existing NocoDB records.
* Updated `handle_save_experiment_form` to use the improved sync method.

= 1.0.14 - 2025-05-07 =
* Enhanced `handle_save_experiment_form` to prepare detailed movie data for NocoDB sync.
* Added `_bsbm_movies_data_for_nocodb_sync` post meta to store structured movie data.
* Began work on the `sync_wp_experiment_to_nocodb` method for more robust NocoDB communication.

= 1.0.13 - 2025-05-06 =
* Corrected plugin update checker initialization to prevent multiple instantiations.
* Moved updater initialization to the `run()` method.
* Added `maybe_init_updater` with a static flag.

= 1.0.12 - 2025-05-06 =
* Added `bsbm_event_image_id` to `handle_save_experiment_form`.
* Implemented image preview and removal in the "Add New Experiment" form.
* Enqueued `bsbm-admin-form.js` for the experiment form page.

= 1.0.11 - 2025-05-06 =
* Added image uploader functionality to the "Add New Experiment" form (sidebar).
* Enqueued `wp-mediaelement` and custom JS for the uploader.

= 1.0.10 - 2025-05-05 =
* Added "Event Notes" metabox to the "Add New Experiment" form (sidebar, initially closed).
* Minor layout adjustments in the form.

= 1.0.9 - 2025-05-05 =
* Improved "Add New Experiment" form layout with 2 columns.
* Moved "Publish" and "Event Details" to a sidebar.
* Enhanced movie entry template with more fields and better structure.

= 1.0.8 - 2025-05-04 =
* Added dynamic movie entry fields to "Add New Experiment" form using JavaScript.
* Implemented TMDb movie search functionality within the form.
* Added `bsbm-admin-form.js` and associated REST API endpoint for TMDb search.

= 1.0.7 - 2025-05-03 =
* Created custom "Add New Experiment" admin page (`render_experiment_form_page`).
* Modified CPT admin menu to use this custom page instead of the default `post-new.php`.
* Added basic fields: Title, Event Date, Event Host.

= 1.0.6 - 2025-05-07 =
* Minor update for testing the update process again.

= 1.0.5 =
* Automated release process test.
* Updated BSBM_PLUGIN_VERSION constant.

= 1.0.1 - 2025-05-07 =
*   Test update. Minor adjustments for update checker testing.

= 1.0.0 - 2025-05-07 =
*   Initial release.
*   Features: Experiment CPT, custom admin form for adding experiments, TMDb integration for movie search and details, NocoDB synchronization, settings page.
*   Plugin update checker implemented using YahnisElsts/plugin-update-checker.

== Upgrade Notice ==

= 1.0.0 =
Initial release of the plugin.
