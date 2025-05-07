/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	TextControl, TextareaControl, Button, Spinner, DatePicker,
	__experimentalNumberControl as NumberControl, PanelBody, Flex, FlexItem, Icon
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useDebounce } from '@wordpress/compose';
import { InnerBlocks, useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { trash } from '@wordpress/icons';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

/**
 * Define allowed blocks for the container
 */
const ALLOWED_BLOCKS = ['bsbm/movie-entry'];

/**
 * Custom Sidebar Panel Component for Experiment Settings
 */
const ExperimentSettingsSidebar = () => {
	// Get the current post type
	const postType = useSelect((select) => select(editorStore).getCurrentPostType(), []);

	// Only show the panel for our 'bsbm_experiment' CPT
	if (postType !== 'bsbm_experiment') {
		return null;
	}

	// Get current meta values using useSelect
	const { eventHost, eventNotes } = useSelect((select) => {
		const meta = select(editorStore).getEditedPostAttribute('meta') || {};
		return {
			// Use the meta keys registered in PHP
			eventHost: meta['_bsbm_event_host'] === undefined ? null : meta['_bsbm_event_host'], // Handle undefined initial state
			eventNotes: meta['_bsbm_event_notes'] || '',
		};
	}, []);

	// Get function to update meta values
	const { editPost } = useDispatch(editorStore);

	// Get current user for default host value
	const currentUser = useSelect((select) => select(coreStore).getCurrentUser(), []);
	const defaultHost = currentUser ? currentUser.name : '';

	// Set initial host value if empty (runs once after initial render)
	useEffect(() => {
		// Only set default if the field is null (meaning it hasn't been set yet) and we have a defaultHost
		if (eventHost === null && defaultHost) {
			editPost({ meta: { _bsbm_event_host: defaultHost } });
		}
		// Intentionally not including editPost or eventHost in dependency array
		// to avoid re-running this on every keystroke. We only want to set the
		// default once if the field is initially empty.
	}, [defaultHost]); // Run only when defaultHost changes (initially)


	return (
		<PluginDocumentSettingPanel
			name="bsbm-experiment-settings"
			title={__('Experiment Details', 'bsbm-integration')}
			className="bsbm-experiment-settings-panel"
		>
			<TextControl
				label={__('Event Host', 'bsbm-integration')}
				// Use the meta value directly; the useEffect handles the default
				value={eventHost ?? defaultHost} // Show default if meta is null/not set yet
				onChange={(value) => editPost({ meta: { _bsbm_event_host: value } })}
				help={__('Defaults to the logged-in user.', 'bsbm-integration')}
			/>
			<TextareaControl
				label={__('Event Notes', 'bsbm-integration')}
				value={eventNotes}
				onChange={(value) => editPost({ meta: { _bsbm_event_notes: value } })}
				rows={5}
				help={__('Any specific notes about the event (e.g., game winners, technical issues).', 'bsbm-integration')}
			/>
		</PluginDocumentSettingPanel>
	);
};

// Register the Sidebar Panel Plugin
registerPlugin('bsbm-experiment-settings-sidebar', {
	render: ExperimentSettingsSidebar,
	icon: 'clipboard', // Optional icon for the panel in the Plugin Settings menu
});


/**
 * Register the Parent Container Block: bsbm/movies-container
 */
registerBlockType('bsbm/movies-container', {
	title: __('Movie Entries Container', 'bsbm-integration'),
	description: __('A container to hold one or more movie entries for an experiment.', 'bsbm-integration'),
	category: 'widgets', // Or a custom category like 'bsbm-blocks'
	icon: 'format-gallery',
	keywords: [__('movie', 'bsbm-integration'), __('experiment', 'bsbm-integration'), __('container', 'bsbm-integration')],
	supports: {
		html: false,
		reusable: false,
		inserter: true,
	},
	attributes: {
		// Container doesn't need attributes if parent saves meta based on children
	},

	/**
	 * The edit function for the container block.
	 * Uses InnerBlocks to manage child movie entry blocks.
	 */
	edit: (props) => {
		const { className } = props;
		const blockProps = useBlockProps({ className: className });

		return (
			<div {...blockProps}>
				<h4 style={{ marginBottom: '0.5em', marginTop: '0.5em', fontSize: '0.9em', fontStyle: 'italic' }}>
                    {__('Movies Watched:', 'bsbm-integration')}
                </h4>
				<InnerBlocks
					allowedBlocks={ALLOWED_BLOCKS} // Only allow movie entry blocks
					orientation="vertical" // Arrange blocks vertically
					// Optional: Add a ButtonBlockAppender for a more explicit add button
					renderAppender={ () => <InnerBlocks.ButtonBlockAppender /> }
				/>
			</div>
		);
	},

	/**
	 * The save function for the container block.
	 * Saves the content of the inner blocks.
	 */
	save: (props) => {
		const blockProps = useBlockProps.save();
		return (
			<div {...blockProps}>
				<InnerBlocks.Content />
			</div>
		);
	},
});

/**
 * Register the Child Block: bsbm/movie-entry
 * This block represents a single movie entry.
 */
registerBlockType('bsbm/movie-entry', {
	title: __('Movie Entry', 'bsbm-integration'),
	description: __('Search for and manage details of a single movie within an experiment.', 'bsbm-integration'),
	category: 'widgets', // Match parent or use custom
	icon: 'video-alt3',
	parent: ['bsbm/movies-container'], // Restrict to only be added inside the container
	supports: {
		html: false,
		reusable: false,
		inserter: false, // Don't show in main inserter, only add via parent block's UI
	},
	attributes: {
		// Define attributes matching PHP registration
		tmdb_id: { type: 'integer', default: 0 },
		title: { type: 'string', default: '' },
		year: { type: 'integer', default: 0 },
		overview: { type: 'string', default: '' },
		poster_url: { type: 'string', default: '' },
		backdrop_url: { type: 'string', default: '' },
		director: { type: 'string', default: '' },
		cast: { type: 'string', default: '' },
		genres: { type: 'string', default: '' },
		rating: { type: 'number', default: 0.0 },
		trailer_url: { type: 'string', default: '' },
		imdb_id: { type: 'string', default: '' },
		runtime: { type: 'integer', default: 0 },
		tagline: { type: 'string', default: '' },
		release_date: { type: 'string', default: '' }, // Format: YYYY-MM-DD
		budget: { type: 'integer', default: 0 },
		revenue: { type: 'integer', default: 0 },
		affiliate_links: {
			type: 'array',
			default: [],
			items: { type: 'object' } // Indicate items are objects, schema not strictly needed here
		},
	},

	/**
	 * The edit function for the movie entry block.
	 *
	 * @param {Object} props Props passed to the component (attributes, setAttributes, etc.).
	 * @return {WPElement} Element to render.
	 */
	edit: (props) => {
		const { attributes, setAttributes, className, isSelected } = props; // Added isSelected
		// Destructure all attributes for easier use
		const {
			tmdb_id, title, year, overview, poster_url, backdrop_url, director, cast,
			genres, rating, trailer_url, imdb_id, runtime, tagline, release_date,
			budget, revenue, affiliate_links
		} = attributes;

		// --- State ---
		const [searchTerm, setSearchTerm] = useState('');
		const [searchResults, setSearchResults] = useState([]);
		const [isLoading, setIsLoading] = useState(false);
		const [error, setError] = useState(null);
		// Initialize selectedMovie based on whether tmdb_id exists
		const [selectedMovie, setSelectedMovie] = useState(tmdb_id ? attributes : null);
		const [newLinkName, setNewLinkName] = useState('');
		const [newLinkUrl, setNewLinkUrl] = useState('');

		// --- Debounced Search Function ---
		const debouncedSearch = useDebounce(useCallback((searchQuery) => {
			if (!searchQuery || searchQuery.length < 3) {
				setSearchResults([]); setIsLoading(false); setError(null); return;
			}
			setIsLoading(true); setError(null);
			const path = `/bsbm/v1/tmdb/search?query=${encodeURIComponent(searchQuery)}`;
			apiFetch({ path })
				.then((results) => { setSearchResults(Array.isArray(results) ? results : []); setIsLoading(false); })
				.catch((fetchError) => {
					console.error('TMDb Search Error:', fetchError);
					setError(fetchError.message || __('Error fetching search results.', 'bsbm-integration'));
					setSearchResults([]); setIsLoading(false);
				});
		}, []), 500);

		// --- Handlers ---
		const handleSearchChange = (value) => { setSearchTerm(value); setIsLoading(true); debouncedSearch(value); };
		const handleMovieSelect = (movie) => {
			setIsLoading(true); setError(null); setSearchTerm(''); setSearchResults([]);
			const path = `/bsbm/v1/tmdb/details/${movie.id}`;
			apiFetch({ path })
				.then((details) => {
					if (typeof details !== 'object' || details === null) { throw new Error(__('Invalid details received.', 'bsbm-integration')); }
					const newAttributes = {
						tmdb_id: details.tmdb_id || 0, title: details.title || '', year: details.year || 0,
						overview: details.overview || '', poster_url: details.poster_url || '', backdrop_url: details.backdrop_url || '',
						director: details.director || '', cast: details.cast || '', genres: details.genres || '',
						rating: details.rating || 0.0, trailer_url: details.trailer_url || '', imdb_id: details.imdb_id || '',
						runtime: details.runtime || 0, tagline: details.tagline || '', release_date: details.release_date || '',
						budget: details.budget || 0, revenue: details.revenue || 0, affiliate_links: [],
					};
					setAttributes(newAttributes); setSelectedMovie(newAttributes); setIsLoading(false);
				})
				.catch((fetchError) => {
					console.error('TMDb Details Error:', fetchError);
					setError(fetchError.message || __('Error fetching movie details.', 'bsbm-integration'));
					setIsLoading(false); setSelectedMovie(null);
				});
		};
		const handleAttributeChange = (attributeName, value) => {
			setAttributes({ [attributeName]: value });
			if (selectedMovie) { setSelectedMovie(prev => ({ ...prev, [attributeName]: value })); }
		};
		const handleNumberChange = (attributeName, value) => {
			const numValue = value === '' ? 0 : parseInt(value, 10);
			handleAttributeChange(attributeName, isNaN(numValue) ? 0 : numValue);
		};
		const handleFloatChange = (attributeName, value) => {
			const floatValue = value === '' ? 0.0 : parseFloat(value);
			handleAttributeChange(attributeName, isNaN(floatValue) ? 0.0 : floatValue);
		};
		const handleDateChange = (newDate) => {
			const formattedDate = newDate ? newDate.substring(0, 10) : '';
			handleAttributeChange('release_date', formattedDate);
		};
		const handleAddAffiliateLink = () => {
			if (!newLinkName || !newLinkUrl) return;
			const newLink = { name: newLinkName, url: newLinkUrl };
			const updatedLinks = [...(affiliate_links || []), newLink];
			setAttributes({ affiliate_links: updatedLinks });
			setNewLinkName(''); setNewLinkUrl('');
		};
		const handleRemoveAffiliateLink = (indexToRemove) => {
			const updatedLinks = (affiliate_links || []).filter((_, index) => index !== indexToRemove);
			setAttributes({ affiliate_links: updatedLinks });
		};
		const handleAffiliateLinkChange = (index, field, value) => {
			const updatedLinks = (affiliate_links || []).map((link, i) => i === index ? { ...link, [field]: value } : link);
			setAttributes({ affiliate_links: updatedLinks });
		};
		const clearSelection = () => {
			setSelectedMovie(null);
			setAttributes({
				tmdb_id: 0, title: '', year: 0, overview: '', poster_url: '', backdrop_url: '',
				director: '', cast: '', genres: '', rating: 0.0, trailer_url: '', imdb_id: '',
				runtime: 0, tagline: '', release_date: '', budget: 0, revenue: 0, affiliate_links: []
			});
		};

		// --- Render Logic ---
		const blockProps = useBlockProps({ className: `${className} bsbm-movie-entry-editor ${isSelected ? 'is-selected' : ''}` }); // Add is-selected class when block is active

		return (
			<div {...blockProps} style={{ border: isSelected ? '1px solid #007cba' : '1px solid #e0e0e0', padding: '15px', marginBottom: '15px', background: '#fff' }}> {/* Change border/bg on selection */}
				{/* Inspector Controls (Sidebar) */}
				<InspectorControls>
					<PanelBody title={__('Advanced Details', 'bsbm-integration')} initialOpen={false}>
						<TextControl label={__('IMDb ID', 'bsbm-integration')} value={imdb_id} onChange={(value) => handleAttributeChange('imdb_id', value)} disabled={!selectedMovie} />
						<NumberControl label={__('Budget', 'bsbm-integration')} value={budget} onChange={(value) => handleNumberChange('budget', value)} isShiftStepEnabled={true} shiftStep={1000000} disabled={!selectedMovie} />
						<NumberControl label={__('Revenue', 'bsbm-integration')} value={revenue} onChange={(value) => handleNumberChange('revenue', value)} isShiftStepEnabled={true} shiftStep={1000000} disabled={!selectedMovie} />
						<TextControl label={__('Backdrop URL', 'bsbm-integration')} value={backdrop_url} onChange={(value) => handleAttributeChange('backdrop_url', value)} disabled={!selectedMovie} />
					</PanelBody>
					<PanelBody title={__('Affiliate Links', 'bsbm-integration')} initialOpen={true}>
						{(affiliate_links || []).length > 0 && (
							<div style={{ marginBottom: '15px' }}>
								<strong>{__('Current Links:', 'bsbm-integration')}</strong>
								{(affiliate_links || []).map((link, index) => (
									<Flex key={index} style={{ marginBottom: '5px', gap: '5px' }}>
										<FlexItem> <TextControl label={__('Name', 'bsbm-integration')} hideLabelFromVision={true} value={link.name || ''} onChange={(value) => handleAffiliateLinkChange(index, 'name', value)} placeholder={__('Platform Name', 'bsbm-integration')} /> </FlexItem>
										<FlexItem isBlock> <TextControl label={__('URL', 'bsbm-integration')} hideLabelFromVision={true} value={link.url || ''} onChange={(value) => handleAffiliateLinkChange(index, 'url', value)} placeholder={__('Affiliate URL', 'bsbm-integration')} type="url" /> </FlexItem>
										<FlexItem> <Button icon={trash} label={__('Remove Link', 'bsbm-integration')} isDestructive isSmall onClick={() => handleRemoveAffiliateLink(index)} /> </FlexItem>
									</Flex>
								))}
							</div>
						)}
						<hr style={{ margin: '10px 0' }}/>
						<strong>{__('Add New Link:', 'bsbm-integration')}</strong>
						<TextControl label={__('Platform Name', 'bsbm-integration')} value={newLinkName} onChange={setNewLinkName} disabled={!selectedMovie} />
						<TextControl label={__('Affiliate URL', 'bsbm-integration')} value={newLinkUrl} onChange={setNewLinkUrl} type="url" disabled={!selectedMovie} />
						<Button isPrimary onClick={handleAddAffiliateLink} disabled={!newLinkName || !newLinkUrl || !selectedMovie}> {__('Add Link', 'bsbm-integration')} </Button>
					</PanelBody>
				</InspectorControls>

				{/* Main Block Content */}
				<h5 style={{ marginTop: 0, marginBottom: '10px', fontSize: '1.1em' }}>{selectedMovie?.title || __('New Movie Entry', 'bsbm-integration')}</h5>

				{!selectedMovie ? (
					<>
						{/* Search Area */}
						<TextControl label={__('Search TMDb', 'bsbm-integration')} value={searchTerm} onChange={handleSearchChange} placeholder={__('Type movie title (min 3 chars)...', 'bsbm-integration')} help={ error ? <span style={{color: 'red'}}>{error}</span> : null } />
						{isLoading && <Spinner />}
						{!isLoading && searchResults.length > 0 && (
							<ul style={{ listStyle: 'none', margin: '10px 0 0 0', padding: '0', border: '1px solid #eee', maxHeight: '200px', overflowY: 'auto', background: '#fff' }}>
								{searchResults.map((movie) => (
									<li key={movie.id} style={{ display: 'flex', alignItems: 'center', padding: '5px', borderBottom: '1px solid #eee' }}>
										{movie.poster_path && <img src={movie.poster_path} alt="" style={{ width: '40px', height: 'auto', marginRight: '10px', flexShrink: 0 }} />}
										<Button isLink onClick={() => handleMovieSelect(movie)}> {movie.title} {movie.release_date ? `(${movie.release_date.substring(0, 4)})` : ''} </Button>
									</li>
								))}
							</ul>
						)}
						{!isLoading && searchTerm.length >= 3 && searchResults.length === 0 && !error && ( <p>{__('No results found.', 'bsbm-integration')}</p> )}
					</>
				) : (
					<>
						{/* Display/Edit Movie Details Area */}
						<div style={{ display: 'flex', gap: '20px', flexWrap: 'wrap' }}>
							{/* Left Column */}
							<div style={{ flex: '1 1 150px', minWidth: '120px' }}>
								{poster_url && <img src={poster_url} alt="Poster" style={{ maxWidth: '100%', height: 'auto', marginBottom: '10px', border: '1px solid #ddd' }} />}
								<TextControl label={__('Poster URL', 'bsbm-integration')} value={poster_url} onChange={(value) => handleAttributeChange('poster_url', value)} />
								<TextControl label={__('Trailer URL (YouTube)', 'bsbm-integration')} value={trailer_url} onChange={(value) => handleAttributeChange('trailer_url', value)} type="url" />
							</div>
							{/* Right Column */}
							<div style={{ flex: '2 1 350px', minWidth: '300px' }}>
								<TextControl label={__('Title', 'bsbm-integration')} value={title} onChange={(value) => handleAttributeChange('title', value)} />
								<TextControl label={__('Tagline', 'bsbm-integration')} value={tagline} onChange={(value) => handleAttributeChange('tagline', value)} />
								<Flex>
									<FlexItem> <NumberControl label={__('Year', 'bsbm-integration')} value={year} onChange={(value) => handleNumberChange('year', value)} /> </FlexItem>
									<FlexItem> <NumberControl label={__('Runtime (min)', 'bsbm-integration')} value={runtime} onChange={(value) => handleNumberChange('runtime', value)} /> </FlexItem>
									<FlexItem> <NumberControl label={__('Rating', 'bsbm-integration')} step="0.1" value={rating} onChange={(value) => handleFloatChange('rating', value)} /> </FlexItem>
								</Flex>
								<div style={{marginTop: '10px', marginBottom: '10px'}}>
									<label>{__('Release Date', 'bsbm-integration')}</label>
									<DatePicker currentDate={release_date ? `${release_date}T00:00:00` : null} onChange={handleDateChange} />
								</div>
								<TextareaControl label={__('Overview', 'bsbm-integration')} value={overview} onChange={(value) => handleAttributeChange('overview', value)} rows={5} />
								<TextControl label={__('Director(s)', 'bsbm-integration')} value={director} onChange={(value) => handleAttributeChange('director', value)} />
								<TextareaControl label={__('Cast (Top 10)', 'bsbm-integration')} value={cast} onChange={(value) => handleAttributeChange('cast', value)} rows={3} help={__('Comma-separated list', 'bsbm-integration')} />
								<TextControl label={__('Genres', 'bsbm-integration')} value={genres} onChange={(value) => handleAttributeChange('genres', value)} help={__('Comma-separated list', 'bsbm-integration')} />
							</div>
						</div>
						<Button isSecondary onClick={clearSelection} style={{ marginTop: '15px', display: 'block' }}>
							{__('Change Movie / Search Again', 'bsbm-integration')}
						</Button>
						{error && <p style={{color: 'red', marginTop: '10px'}}>{error}</p>}
					</>
				)}
			</div>
		);
	},

	/**
	 * The save function for the movie entry block.
	 */
	save: (props) => {
		return null; // Use Server-Side Rendering (render_callback in PHP)
	},
});
