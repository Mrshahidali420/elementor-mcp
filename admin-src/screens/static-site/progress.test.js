import { logMessage, percent, steps } from './progress';

const view = ( phase, counts = {}, over = {} ) => ( {
	generation: 'g',
	mode: 'full',
	phase,
	cloud: 'running',
	counts,
	...over,
} );

describe( 'steps', () => {
	it( 'marks earlier steps done and the current one active', () => {
		const s = steps( view( 'fetch' ) );
		expect( s.map( ( x ) => x.id ) ).toEqual( [
			'setup',
			'discover',
			'fetch',
			'rewrite',
			'package',
		] );
		expect( s.map( ( x ) => x.status ) ).toEqual( [
			'done',
			'done',
			'current',
			'todo',
			'todo',
		] );
	} );

	it( 'leaves out Discover for selected pages and maps start to Fetch', () => {
		const s = steps( view( 'start', {}, { mode: 'pages' } ) );
		expect( s.map( ( x ) => x.id ) ).toEqual( [
			'setup',
			'fetch',
			'rewrite',
			'package',
		] );
		expect( s[ 1 ].status ).toBe( 'current' );
	} );

	it( 'counts the closing phases as Package and done as all complete', () => {
		expect( steps( view( 'recheck' ) )[ 4 ].status ).toBe( 'current' );
		expect(
			steps( view( 'done' ) ).every( ( x ) => 'done' === x.status )
		).toBe( true );
	} );
} );

describe( 'percent', () => {
	it( 'grows through fetch with the files handled', () => {
		const a = percent( view( 'fetch', { pending: 30, fetched: 10 } ) );
		const b = percent( view( 'fetch', { pending: 10, fetched: 30 } ) );
		expect( b ).toBeGreaterThan( a );
		expect( a ).toBeGreaterThan( percent( view( 'discover' ) ) );
	} );

	it( 'is 100 when done', () => {
		expect( percent( view( 'done' ) ) ).toBe( 100 );
	} );
} );

describe( 'logMessage', () => {
	it( 'reports what remains while fetching', () => {
		expect(
			logMessage( view( 'fetch', { pending: 23, fetched: 240 } ) )
		).toBe( 'Fetching pages: 23 remaining of 263 total.' );
	} );

	it( 'reports what remains while rewriting', () => {
		expect(
			logMessage( view( 'rewrite', { fetched: 113, saved: 150 } ) )
		).toBe( 'Rewriting URLs: 113 files remaining.' );
	} );

	it( 'says when Cloud is still reserving', () => {
		expect(
			logMessage( view( 'setup', {}, { cloud: 'reserving' } ) )
		).toMatch( /Reserving/ );
	} );
} );
