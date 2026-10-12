import { driveBuild } from './driver';

const wait = () => Promise.resolve();
const settle = () => new Promise( ( r ) => setTimeout( r, 0 ) );

function scripted( answers ) {
	let inFlight = 0;
	let maxInFlight = 0;
	const step = jest.fn( async () => {
		inFlight++;
		maxInFlight = Math.max( maxInFlight, inFlight );
		await Promise.resolve();
		inFlight--;
		const next = answers.shift();
		if ( next instanceof Error ) {
			throw next;
		}
		return next;
	} );
	return { api: { step }, max: () => maxInFlight };
}

const err = ( code ) => Object.assign( new Error( code ), { code } );

describe( 'driveBuild', () => {
	it( 'steps one request at a time until done', async () => {
		const s = scripted( [
			{ phase: 'fetch' },
			{ phase: 'rewrite' },
			{ phase: 'done' },
		] );
		const onDone = jest.fn();
		driveBuild( {
			api: s.api,
			generation: 'g',
			owner: 'o',
			epoch: 0,
			onProgress: () => {},
			onDone,
			wait,
		} );
		for ( let i = 0; i < 10; i++ ) {
			await settle();
		}
		expect( onDone ).toHaveBeenCalledWith( 'done', { phase: 'done' } );
		expect( s.api.step ).toHaveBeenCalledTimes( 3 );
		expect( s.max() ).toBe( 1 );
	} );

	it( 'retries busy and stops on elsewhere', async () => {
		const s = scripted( [
			err( 'busy' ),
			{ phase: 'fetch' },
			err( 'elsewhere' ),
		] );
		const onDone = jest.fn();
		driveBuild( {
			api: s.api,
			generation: 'g',
			owner: 'o',
			epoch: 0,
			onProgress: () => {},
			onDone,
			wait,
		} );
		for ( let i = 0; i < 10; i++ ) {
			await settle();
		}
		expect( onDone ).toHaveBeenCalledWith( 'elsewhere', expect.anything() );
	} );

	it( 'gives up after repeated network failures', async () => {
		const s = scripted(
			Array.from( { length: 12 }, () => new Error( 'offline' ) )
		);
		const onDone = jest.fn();
		driveBuild( {
			api: s.api,
			generation: 'g',
			owner: 'o',
			epoch: 0,
			onProgress: () => {},
			onDone,
			wait,
		} );
		for ( let i = 0; i < 40; i++ ) {
			await settle();
		}
		expect( onDone ).toHaveBeenCalledWith( 'lost', expect.anything() );
	} );

	it( 'stops when asked', async () => {
		const s = scripted( [
			{ phase: 'fetch' },
			{ phase: 'fetch' },
			{ phase: 'done' },
		] );
		const onDone = jest.fn();
		const handle = driveBuild( {
			api: s.api,
			generation: 'g',
			owner: 'o',
			epoch: 0,
			onProgress: () => handle.stop(),
			onDone,
			wait,
		} );
		for ( let i = 0; i < 10; i++ ) {
			await settle();
		}
		expect( s.api.step ).toHaveBeenCalledTimes( 1 );
		expect( onDone ).not.toHaveBeenCalled();
	} );
} );
