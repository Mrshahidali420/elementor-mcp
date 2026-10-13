import { act, fireEvent, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { AppProviders } from '@emcp/ui';
import { StaticSiteScreen } from './StaticSiteScreen';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const data = ( over = {} ) => ( {
	settings: {
		delivery: 'zip',
		local_folder: 'site',
		url_style: 'relative',
		base_url: '',
		include: [ 'pages' ],
		additional_urls: '',
		exclude_urls: '',
		batch_size: 50,
		accept_self_signed: false,
		serve_enabled: false,
		serve_logged_in: false,
		serve_exclude_urls: '',
	},
	localEnv: false,
	connected: true,
	account: 'a•••@example.com',
	allowance: {
		monthly: null,
		free: { limit: 3, used: 1 },
		remaining: 2,
		next_source: 'free_lifetime',
		licence_check: 'ok',
	},
	allowanceError: '',
	run: null,
	current: null,
	lastCancel: null,
	denyVerified: false,
	nginxRule: 'location ^~ /wp-content/emcp-static/ { deny all; }',
	servedCount: 0,
	connectionUrl: '/wp-admin/admin.php?page=emcp-tools-connection',
	...over,
} );

function mount( d ) {
	apiFetch.mockResolvedValue( d );
	return render(
		<AppProviders>
			<StaticSiteScreen data={ d } />
		</AppProviders>
	);
}

describe( 'StaticSiteScreen', () => {
	it( 'asks to connect when Cloud is not connected', () => {
		mount( data( { connected: false, allowance: null } ) );
		expect(
			screen.getByText( /Connect this site to EMCP Cloud/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Generate Static Site' } )
		).toBeDisabled();
	} );

	it( 'shows the free allowance', () => {
		mount( data() );
		expect(
			screen.getByText( '2 of 3 free generations left' )
		).toBeInTheDocument();
	} );

	it( 'disables Generate when nothing is left', () => {
		mount(
			data( {
				allowance: {
					monthly: null,
					free: { limit: 3, used: 3 },
					remaining: 0,
					next_source: null,
					licence_check: 'ok',
				},
			} )
		);
		expect(
			screen.getByRole( 'button', { name: 'Generate Static Site' } )
		).toBeDisabled();
		expect(
			screen.getByText( /no static-site generations left/i )
		).toBeInTheDocument();
	} );

	it( 'says when the licence could not be checked', () => {
		mount(
			data( {
				allowance: {
					monthly: null,
					free: { limit: 3, used: 0 },
					remaining: 3,
					next_source: 'free_lifetime',
					licence_check: 'unavailable',
				},
			} )
		);
		expect(
			screen.getByText( /could not check your licence/i )
		).toBeInTheDocument();
	} );

	it( 'offers Continue here for a run owned by another tab once its lease expired', () => {
		mount(
			data( {
				run: {
					generation: '6c1f2f7a-0000-4000-8000-000000000000',
					mode: 'full',
					phase: 'fetch',
					cloud: 'running',
					owner: 'tab-other',
					epoch: 0,
					counts: { pending: 4, fetched: 2 },
					error: '',
					started_at: 0,
					promoted: false,
					lease_active: false,
				},
			} )
		);
		expect(
			screen.getByRole( 'button', { name: 'Continue here' } )
		).toBeInTheDocument();
	} );

	it( 'keeps serving off until the server check passed', () => {
		mount( data() );
		expect(
			screen.getByRole( 'checkbox', { name: 'Enable Static Serving' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Check the server' } )
		).toBeInTheDocument();
	} );

	it( 'shows every setting on the page with a switch for each content type', () => {
		mount( data() );
		expect(
			screen.getByRole( 'radio', { name: 'ZIP Download' } )
		).toBeChecked();
		expect(
			screen.getByRole( 'radio', { name: 'Relative' } )
		).toBeChecked();
		for ( const name of [
			'Posts',
			'Pages',
			'Archives',
			'Categories',
			'Tags',
			'Author Pages',
			'Custom Post Types',
			'Theme Assets',
			'Plugin Assets',
			'Uploads',
		] ) {
			expect(
				screen.getByRole( 'switch', { name } )
			).toBeInTheDocument();
		}
		expect( screen.getByLabelText( 'Exclude URLs' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'log', { name: 'Activity Log' } )
		).toHaveTextContent( 'Waiting to start' );
	} );

	it( 'saves the batch size and content switches with Save Settings', async () => {
		const d = data();
		mount( d );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Larger batches' } )
		);
		expect( screen.getByText( '60' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'switch', { name: 'Uploads' } ) );
		apiFetch.mockClear();
		apiFetch.mockResolvedValue( d );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save Settings' } )
			);
		} );
		const call = apiFetch.mock.calls.find( ( [ o ] ) =>
			o.path.endsWith( '/settings' )
		);
		expect( call[ 0 ].data.settings.batch_size ).toBe( 60 );
		expect( call[ 0 ].data.settings.include ).toEqual( [
			'pages',
			'uploads',
		] );
	} );

	it( 'shows the pipeline and the current step in the log for an open run', () => {
		mount(
			data( {
				run: {
					generation: '6c1f2f7a-0000-4000-8000-000000000004',
					mode: 'full',
					phase: 'fetch',
					cloud: 'running',
					owner: 'tab-other',
					epoch: 0,
					counts: { pending: 23, fetched: 240 },
					error: '',
					started_at: 0,
					promoted: false,
					lease_active: true,
				},
			} )
		);
		const steps = screen.getByRole( 'list', { name: 'Generation steps' } );
		expect( steps ).toHaveTextContent( 'Discover' );
		expect(
			screen.getByRole( 'log', { name: 'Activity Log' } )
		).toHaveTextContent( 'Fetching pages: 23 remaining of 263 total.' );
	} );

	it( 'downloads the ZIP under the server name and keeps the blob URL until the click is done', async () => {
		jest.useFakeTimers();
		const created = 'blob:zip-1';
		window.URL.createObjectURL = jest.fn( () => created );
		window.URL.revokeObjectURL = jest.fn();
		const clicks = [];
		const click = jest
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( function () {
				clicks.push( {
					download: this.download,
					attached: document.body.contains( this ),
					revokedBefore: window.URL.revokeObjectURL.mock.calls.length,
				} );
			} );
		const d = data( {
			current: {
				generation: '0f0f0f0f-0000-4000-8000-000000000001',
				date: '2026-10-12T08:00:00+00:00',
				pages: 2,
				files: 3,
				bytes: 1000,
				delivery: 'zip',
				zip: 'site.zip',
			},
		} );
		mount( d );
		apiFetch.mockResolvedValue( {
			blob: () => Promise.resolve( new Blob( [ 'PK' ] ) ),
			headers: {
				get: ( h ) =>
					'Content-Disposition' === h
						? 'attachment; filename="example-static-2026-10-12.zip"'
						: null,
			},
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Download ZIP' } )
			);
		} );
		expect( clicks ).toEqual( [
			{
				download: 'example-static-2026-10-12.zip',
				attached: true,
				revokedBefore: 0,
			},
		] );
		expect( window.URL.revokeObjectURL ).not.toHaveBeenCalled();
		act( () => {
			jest.runOnlyPendingTimers();
		} );
		expect( window.URL.revokeObjectURL ).toHaveBeenCalledWith( created );
		click.mockRestore();
		jest.useRealTimers();
	} );

	it( 'says the generation was returned after a refunded cancel', async () => {
		const run = {
			generation: '6c1f2f7a-0000-4000-8000-000000000002',
			mode: 'full',
			phase: 'discover',
			cloud: 'reserved',
			owner: 'tab-other',
			epoch: 0,
			counts: { pending: 0, fetched: 0 },
			error: '',
			started_at: 0,
			promoted: false,
			lease_active: true,
		};
		const d = data( { run } );
		mount( d );
		apiFetch.mockImplementation( ( o ) =>
			Promise.resolve(
				o.path.includes( '/build/cancel' )
					? {
							phase: 'cancelled',
							generation: run.generation,
							error: '',
							refunded: true,
						}
					: { ...d, run: null }
			)
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Cancel generation' } )
		);
		expect(
			await screen.findByText(
				'Cancelled. The generation was returned to your allowance.'
			)
		).toBeInTheDocument();
	} );

	it( 'offers Check again while the reservation is still being made', () => {
		mount(
			data( {
				run: {
					generation: '6c1f2f7a-0000-4000-8000-000000000003',
					mode: 'full',
					phase: 'reserve',
					cloud: 'reserving',
					owner: 'tab-other',
					epoch: 0,
					counts: {},
					error: '',
					started_at: 0,
					promoted: false,
					lease_active: true,
				},
			} )
		);
		expect(
			screen.getByRole( 'button', { name: 'Check again' } )
		).toBeInTheDocument();
	} );

	it( 'explains why the last generation stopped in words', () => {
		mount(
			data( {
				lastCancel: {
					generation: 'g',
					refunded: false,
					error: 'site_changing',
					at: 0,
				},
			} )
		);
		expect( screen.queryByText( /site_changing/ ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( /kept changing while it was being generated/ )
		).toBeInTheDocument();
	} );

	it( 'shows the error count of the last generation when there were errors', () => {
		const current = {
			generation: '0f0f0f0f-0000-4000-8000-000000000009',
			date: '2026-10-12T08:00:00+00:00',
			pages: 2,
			files: 3,
			bytes: 1000,
			errors: 4,
			delivery: 'zip',
			zip: 'site.zip',
		};
		const { unmount } = mount( data( { current } ) );
		expect( screen.getByText( 'Errors' ) ).toBeInTheDocument();
		expect( screen.getByText( '4' ) ).toBeInTheDocument();
		unmount();
		mount( data( { current: { ...current, errors: 0 } } ) );
		expect( screen.queryByText( 'Errors' ) ).not.toBeInTheDocument();
	} );
} );
