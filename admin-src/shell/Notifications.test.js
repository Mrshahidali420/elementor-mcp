import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { AppProviders } from '@emcp/ui';
import { Notifications, iconFor } from './Notifications';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const items = [
	{
		id: 'a',
		title: 'Price increase',
		body: 'Lifetime prices go up.',
		url: 'https://emcptools.com/pricing',
		cta: 'See Lifetime Plans',
		icon: 'megaphone',
		level: 'warning',
		date: '2026-10-12T05:46:20.900Z',
		unread: true,
	},
	{
		id: 'b',
		title: 'Redirect Manager',
		body: '',
		url: '',
		cta: '',
		icon: 'randomize',
		level: 'success',
		date: '',
		unread: false,
	},
];

function mount() {
	apiFetch.mockResolvedValue( { unread: 0 } );
	return render(
		<AppProviders>
			<Notifications
				open
				onClose={ () => {} }
				items={ items }
				onRead={ () => {} }
			/>
		</AppProviders>
	);
}

describe( 'Notifications drawer', () => {
	it( 'shows each notice as a card in its level colour with an icon', () => {
		mount();
		const cards = document.querySelectorAll( '.eui-notifs__item' );
		expect( cards ).toHaveLength( 2 );
		expect( cards[ 0 ] ).toHaveClass( 'is-warning' );
		expect( cards[ 1 ] ).toHaveClass( 'is-success' );
		expect(
			cards[ 0 ].querySelector( '.eui-notifs__icon svg' )
		).not.toBeNull();
		expect(
			cards[ 1 ].querySelector( '.eui-notifs__icon svg' )
		).not.toBeNull();
	} );

	it( 'marks unread notices, shows the date and the call to action', () => {
		mount();
		expect( screen.getByText( 'New' ) ).toBeInTheDocument();
		expect( screen.getByText( /2026/ ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /See Lifetime Plans/ } )
		).toHaveAttribute( 'href', 'https://emcptools.com/pricing' );
	} );

	it( 'maps feed icon names to the icon set and falls back by level', () => {
		expect( iconFor( { icon: 'megaphone', level: 'info' } ) ).toBe(
			'megaphone'
		);
		expect( iconFor( { icon: 'video-alt3', level: 'info' } ) ).toBe(
			'play'
		);
		expect( iconFor( { icon: 'undo', level: 'info' } ) ).toBe(
			'rotate-ccw'
		);
		expect( iconFor( { icon: 'something-new', level: 'warning' } ) ).toBe(
			'triangle-alert'
		);
		expect( iconFor( { icon: '', level: 'success' } ) ).toBe(
			'circle-check'
		);
		expect( iconFor( {} ) ).toBe( 'bell' );
	} );
} );
