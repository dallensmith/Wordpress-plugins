=== Big Screen Bad Movies Integration ===
Contributors: dallensmith
Tags: movies, tmdb, nocodb, custom post type, admin interface
Requires at least: 5.8
Tested up to: # We can update this later, e.g., current WP version
Requires PHP: 7.4
Stable tag: 1.0.1
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

= 1.0.1 - 2025-05-07 =
*   Test update. Minor adjustments for update checker testing.

= 1.0.0 - 2025-05-07 =
*   Initial release.
*   Features: Experiment CPT, custom admin form for adding experiments, TMDb integration for movie search and details, NocoDB synchronization, settings page.
*   Plugin update checker implemented using YahnisElsts/plugin-update-checker.

== Upgrade Notice ==

= 1.0.0 =
Initial release of the plugin.
