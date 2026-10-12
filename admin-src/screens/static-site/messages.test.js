import { stopReason } from './messages';

describe( 'stopReason', () => {
	it( 'puts every runner stop code into words', () => {
		for ( const code of [
			'build_too_large',
			'write_failed',
			'too_many_urls',
			'zip_failed',
			'site_changing',
			'cancel_unconfirmed',
			'allowance_exhausted',
			'generation_active',
		] ) {
			const text = stopReason( code );
			expect( text ).not.toContain( code );
			expect( text.length ).toBeGreaterThan( 20 );
		}
	} );

	it( 'keeps an unknown code visible for support', () => {
		expect( stopReason( 'odd_thing' ) ).toContain( 'odd_thing' );
	} );
} );
