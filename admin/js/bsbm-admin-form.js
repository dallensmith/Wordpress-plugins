/**
 * JavaScript for the BSBM Custom Admin Experiment Form Page.
 */
(function ($) {
	'use strict';

	function debounce(func, wait) {
		let timeout;
		return function executedFunction(...args) {
			const later = () => {
				clearTimeout(timeout);
				func(...args);
			};
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
		};
	}

	function addMovieEntry(container) {
		const movieIndex = container.children('.bsbm-movie-entry-section').length;
		let templateHtml = $('#bsbm-movie-entry-template').html();
        if (!templateHtml) {
            console.error('BSBM Error: Movie entry template not found!');
            alert('BSBM Error: Movie entry template not found! Please ensure the PHP template is on the page.');
            return;
        }
        // Replace placeholder for unique IDs/names in the template
        templateHtml = templateHtml.replace(/{{INDEX}}/g, movieIndex);

		const newEntry = $(templateHtml);
        // Ensure input names are correctly indexed for PHP array processing
        newEntry.find('input, textarea, select').each(function() {
            const name = $(this).attr('name');
            if (name && name.includes('movies[][]')) { // Check if it's a movies array field
                $(this).attr('name', name.replace('movies[][]', `movies[${movieIndex}]`));
            }
        });
		container.append(newEntry);
        // Manually trigger WordPress's postbox toggle handling for the new element
        if (typeof postboxes !== 'undefined' && typeof postboxes.add_postbox_toggles === 'function') {
            // pagenow is a global WP variable on admin pages. bsbmAdminData.pageNow is a fallback.
            postboxes.add_postbox_toggles(bsbmAdminData.pageNow || window.pagenow || '', newEntry);
        }
	}

	function handleTmdbSearch(inputField) {
		const searchTerm = $(inputField).val();
		const resultsList = $(inputField).siblings('.bsbm-search-results');
		const spinner = $(inputField).siblings('.spinner');
		const movieSection = $(inputField).closest('.bsbm-movie-entry-section');

		resultsList.empty().hide(); // Clear previous results
		if (!searchTerm || searchTerm.length < 3) {
            spinner.removeClass('is-active');
            return; // Don't search if term is too short
        }
		spinner.addClass('is-active');

		$.ajax({
			url: bsbmAdminData.rest_url + 'tmdb/search', // Use localized URL
			method: 'GET',
			beforeSend: function (xhr) {
				// Set nonce header for authentication
				xhr.setRequestHeader('X-WP-Nonce', bsbmAdminData.nonce);
			},
			data: {
				query: searchTerm,
				// year: optionalYear // Could add year input later
			},
			success: function (response) {
				spinner.removeClass('is-active');
				if (response && Array.isArray(response) && response.length > 0) {
					response.forEach(function (movie) {
						const year = movie.release_date ? `(${movie.release_date.substring(0, 4)})` : '';
						const listItem = $(
                            `<li style="display: flex; align-items: center; padding: 5px; border-bottom: 1px solid #eee; cursor: pointer;">
                                ${movie.poster_path ? `<img src="${movie.poster_path}" alt="" style="width: 30px; height: auto; margin-right: 8px;">` : '<div style="width: 30px; height: 45px; margin-right: 8px; background: #eee; display: inline-block;"></div>'}
                                <span>${movie.title} ${year}</span>
                            </li>`
                        );
						listItem.on('click', function () {
							fetchAndFillMovieDetails(movie.id, movieSection);
							resultsList.empty().hide();
							$(inputField).val(''); // Clear search field
						});
						resultsList.append(listItem);
					});
					resultsList.show();
				} else {
					resultsList.append($('<li>').text(bsbmAdminData.text.no_results || 'No results found.')).show();
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				spinner.removeClass('is-active');
				console.error("TMDb Search AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
				resultsList.append($('<li>').text(bsbmAdminData.text.error_fetching || 'Error fetching results.')).show();
			}
		});
	}

	function fetchAndFillMovieDetails(tmdbId, movieSection) {
		const spinner = movieSection.find('.bsbm-movie-search').siblings('.spinner');
		spinner.addClass('is-active');

		$.ajax({
			url: bsbmAdminData.rest_url + 'tmdb/details/' + tmdbId,
			method: 'GET',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', bsbmAdminData.nonce);
			},
			success: function (details) {
				spinner.removeClass('is-active');
				if (details && typeof details === 'object') {
					// Fill the fields within the specific movieSection
					movieSection.find('.movie-tmdb-id').val(details.tmdb_id || '');
					movieSection.find('.movie-title').val(details.title || '');
					movieSection.find('.movie-year').val(details.year || '');
					movieSection.find('.movie-overview').val(details.overview || '');
					movieSection.find('.movie-poster-url').val(details.poster_url || '');
					movieSection.find('.movie-poster-preview').attr('src', details.poster_url || '').toggle(!!details.poster_url);
					movieSection.find('.movie-director').val(details.director || '');
					movieSection.find('.movie-cast').val(details.cast || '');
					movieSection.find('.movie-genres').val(details.genres || '');
					movieSection.find('.movie-rating').val(details.rating || '');
					movieSection.find('.movie-runtime').val(details.runtime || '');
					movieSection.find('.movie-imdb-id').val(details.imdb_id || '');
					movieSection.find('.movie-trailer-url').val(details.trailer_url || '');
                    // Update the section title display in the handle
					movieSection.find('.movie-title-display-hndle').text(': ' + (details.title || ''));
				} else {
                    console.error("Invalid details received:", details);
                    alert(bsbmAdminData.text.error_details || 'Error: Invalid movie details received.');
                }
			},
			error: function (jqXHR, textStatus, errorThrown) {
				spinner.removeClass('is-active');
				console.error("TMDb Details AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
				alert(bsbmAdminData.text.error_details || 'Error fetching movie details.');
			}
		});
	}

    function addAffiliateLinkRow(button) {
        const area = $(button).siblings('.bsbm-affiliate-links-area');
        const movieSection = $(button).closest('.bsbm-movie-entry-section');
        // Find the index of the current movie section relative to its siblings
        const movieIndex = movieSection.parent().children('.bsbm-movie-entry-section').index(movieSection);
        const linkIndex = area.find('.bsbm-affiliate-link-row').length;
        const rowHtml = `
            <div class="bsbm-affiliate-link-row" style="display: flex; gap: 5px; margin-bottom: 5px;">
                <input type="text" name="movies[${movieIndex}][affiliate_links][${linkIndex}][name]" placeholder="${bsbmAdminData.text.platform_name || 'Platform Name'}" style="flex: 1;" class="regular-text">
                <input type="url" name="movies[${movieIndex}][affiliate_links][${linkIndex}][url]" placeholder="${bsbmAdminData.text.affiliate_url || 'Affiliate URL'}" style="flex: 2;" class="regular-text">
                <button type="button" class="button button-link-delete bsbm-remove-affiliate-link-button" title="${bsbmAdminData.text.remove_link || 'Remove Link'}">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        `;
        area.append(rowHtml);
    }

	// Document Ready
	$(function () {
		const moviesContainer = $('#bsbm-movies-repeater');

        // Event Host Dropdown Logic
        $('#bsbm_event_host_select').on('change', function() {
            if ($(this).val() === 'other') {
                $('#bsbm_event_host_custom').show().focus();
            } else {
                $('#bsbm_event_host_custom').hide().val('');
            }
        }).trigger('change'); // Trigger on load to set initial state

		// Add initial movie entry if container is empty
		if (moviesContainer.children('.bsbm-movie-entry-section').length === 0) {
			addMovieEntry(moviesContainer);
		}

		$('#bsbm-add-movie-button').on('click', function () { addMovieEntry(moviesContainer); });
		moviesContainer.on('click', '.bsbm-remove-movie-button', function () { $(this).closest('.bsbm-movie-entry-section').remove(); });
		const debouncedSearchHandler = debounce(function(event) { handleTmdbSearch(event.target); }, 500);
		moviesContainer.on('input', '.bsbm-movie-search', debouncedSearchHandler);
        moviesContainer.on('click', '.bsbm-add-affiliate-link-button', function() { addAffiliateLinkRow(this); });
        moviesContainer.on('click', '.bsbm-remove-affiliate-link-button', function() { $(this).closest('.bsbm-affiliate-link-row').remove(); });

		// Media Uploader
        let mediaFrame;
        // Use event delegation for upload button
        $('#bsbm-experiment-form').on('click', '.bsbm-upload-button', function(event) {
            event.preventDefault();
            const $button = $(this); const $preview = $button.siblings('.bsbm-image-preview'); const $idInput = $button.siblings('.bsbm-image-id'); const $removeButton = $button.siblings('.bsbm-remove-button');
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({ title: 'Select or Upload Image', library: { type: 'image' }, button: { text: 'Use this image' }, multiple: false });
            mediaFrame.on('select', function() {
                const attachment = mediaFrame.state().get('selection').first().toJSON();
                $idInput.val(attachment.id); $preview.attr('src', attachment.sizes?.medium?.url || attachment.sizes?.thumbnail?.url || attachment.url).show();
                $removeButton.show(); $button.hide();
            });
            mediaFrame.open();
        });
        $('#bsbm-experiment-form').on('click', '.bsbm-remove-button', function(event) {
            event.preventDefault();
            const $button = $(this); const $preview = $button.siblings('.bsbm-image-preview'); const $idInput = $button.siblings('.bsbm-image-id'); const $uploadButton = $button.siblings('.bsbm-upload-button');
            $idInput.val(''); $preview.attr('src', '').hide(); $button.hide(); $uploadButton.show();
        });

        // Make postboxes collapsible (WordPress standard way)
        if (typeof postboxes !== 'undefined' && typeof postboxes.add_postbox_toggles === 'function') {
            // pagenow is a global WP variable on admin pages. bsbmAdminData.pageNow is a fallback.
            const pageHook = bsbmAdminData.pageNow || window.pagenow || '';
            if (pageHook) { // Ensure pageHook is not empty
                postboxes.add_postbox_toggles(pageHook);
                 // Also apply to initially loaded movie sections if any (though we add first one via JS)
                $('.bsbm-movie-entry-section').each(function() {
                    postboxes.add_postbox_toggles(pageHook, $(this));
                });
            } else {
                console.warn('BSBM: pagenow or bsbmAdminData.pageNow not available for postbox toggles.');
            }
        }
	});
}(jQuery));