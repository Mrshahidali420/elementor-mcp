import { __, sprintf } from '@wordpress/i18n';

const STEPS = [
	[ 'setup', __( 'Setup', 'emcp-tools' ) ],
	[ 'discover', __( 'Discover', 'emcp-tools' ) ],
	[ 'fetch', __( 'Fetch', 'emcp-tools' ) ],
	[ 'rewrite', __( 'Rewrite', 'emcp-tools' ) ],
	[ 'package', __( 'Package', 'emcp-tools' ) ],
];

// The runner's phases, folded onto the five steps the screen shows.
const STEP_OF = {
	setup: 'setup',
	discover: 'discover',
	start: 'fetch',
	fetch: 'fetch',
	rewrite: 'rewrite',
	package: 'package',
	recheck: 'package',
	promote: 'package',
	complete: 'package',
};

const SPAN = {
	setup: [ 0, 5 ],
	discover: [ 5, 15 ],
	fetch: [ 15, 75 ],
	rewrite: [ 75, 90 ],
	package: [ 90, 100 ],
};

function tally( counts = {} ) {
	const n = ( k ) => counts[ k ] || 0;
	const total = Object.values( counts ).reduce( ( s, v ) => s + v, 0 );
	return { n, total };
}

/**
 * The pipeline steps for a run, each `done`, `current` or `todo`.
 *
 * @param {Object} view Run view from the server.
 * @return {Array<{id: string, label: string, status: string}>} Steps.
 */
export function steps( view ) {
	const list = STEPS.filter(
		( [ id ] ) => ! ( view && 'pages' === view.mode && 'discover' === id )
	);
	const finished = view && 'done' === view.phase;
	const at = list.findIndex(
		( [ id ] ) => id === STEP_OF[ view && view.phase ]
	);
	return list.map( ( [ id, label ], i ) => {
		let status = 'todo';
		if ( finished || ( at >= 0 && i < at ) ) {
			status = 'done';
		} else if ( i === at ) {
			status = 'current';
		}
		return { id, label, status };
	} );
}

/**
 * Overall progress, 0 to 100.
 *
 * @param {Object} view Run view from the server.
 * @return {number} Percent.
 */
export function percent( view ) {
	if ( ! view ) {
		return 0;
	}
	if ( 'done' === view.phase ) {
		return 100;
	}
	const step = STEP_OF[ view.phase ];
	if ( ! step ) {
		return 0;
	}
	const [ from, to ] = SPAN[ step ];
	const { n, total } = tally( view.counts );
	let part = 0;
	if ( total > 0 && 'fetch' === step ) {
		part = 1 - n( 'pending' ) / total;
	} else if ( total > 0 && 'rewrite' === step ) {
		part = 1 - n( 'fetched' ) / total;
	}
	return Math.round( from + ( to - from ) * part );
}

/**
 * One line for the activity log describing where a run is.
 *
 * @param {Object} view Run view from the server.
 * @return {string} Message.
 */
export function logMessage( view ) {
	if ( ! view ) {
		return '';
	}
	if ( 'reserving' === view.cloud || 'uncertain' === view.cloud ) {
		return __( 'Reserving a generation with EMCP Cloud…', 'emcp-tools' );
	}
	if ( 'cancelling' === view.cloud ) {
		return __( 'Cancelling…', 'emcp-tools' );
	}
	const { n, total } = tally( view.counts );
	switch ( view.phase ) {
		case 'setup':
			return __( 'Setting up…', 'emcp-tools' );
		case 'discover':
			return sprintf(
				/* translators: %d: URLs found so far. */
				__( 'Discovering URLs: %d found so far.', 'emcp-tools' ),
				total
			);
		case 'start':
			return sprintf(
				/* translators: %d: URLs found. */
				__( 'Discovered %d URLs. Starting fetch…', 'emcp-tools' ),
				total
			);
		case 'fetch':
			return sprintf(
				/* translators: 1: URLs left, 2: URLs in all. */
				__(
					'Fetching pages: %1$d remaining of %2$d total.',
					'emcp-tools'
				),
				n( 'pending' ),
				total
			);
		case 'rewrite':
			return sprintf(
				/* translators: %d: files left. */
				__( 'Rewriting URLs: %d files remaining.', 'emcp-tools' ),
				n( 'fetched' )
			);
		case 'package':
			return __( 'Packaging files…', 'emcp-tools' );
		case 'recheck':
			return __(
				'Checking pages that changed during the generation…',
				'emcp-tools'
			);
		case 'promote':
		case 'complete':
			return __( 'Finishing…', 'emcp-tools' );
		case 'done':
			return __( 'Done.', 'emcp-tools' );
		case 'cancelled':
			return __( 'Cancelled.', 'emcp-tools' );
	}
	return '';
}
