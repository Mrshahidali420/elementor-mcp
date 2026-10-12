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
			screen.getByRole( 'button', { name: 'Generate' } )
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
			screen.getByRole( 'button', { name: 'Generate' } )
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
			screen.getByRole( 'switch', {
				name: 'Serve static pages to visitors',
			} )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Check the server' } )
		).toBeInTheDocument();
	} );

	it( 'opens the settings drawer with labelled fields', async () => {
		mount( data() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Settings' } ) );
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Static Site settings',
			} )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Delivery' ) ).toHaveValue( 'zip' );
		expect( screen.getByLabelText( 'Links' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Exclude URLs' ) ).toBeInTheDocument();
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
} );
