import { initNavGroups } from './nav-groups';

function frame() {
	document.body.innerHTML = `<div class="eui-frame"><nav>
		<button type="button" class="eui-frame-nav__group" aria-expanded="true" aria-controls="l-build" data-emcp-nav-group="build">Build</button>
		<ul class="eui-frame-nav__list" id="l-build"><li>a</li></ul>
		<button type="button" class="eui-frame-nav__group" aria-expanded="false" aria-controls="l-library" data-emcp-nav-group="library">Library</button>
		<ul class="eui-frame-nav__list is-closed" id="l-library"><li>b</li></ul>
	</nav></div>`;
	return document.querySelector( '.eui-frame' );
}

describe( 'initNavGroups', () => {
	it( 'opens and closes a section and saves every closed section', () => {
		const root = frame();
		const save = jest.fn( () => Promise.resolve() );
		initNavGroups( root, { save } );
		const build = root.querySelector( '[data-emcp-nav-group="build"]' );
		build.click();
		expect( build.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect(
			root.querySelector( '#l-build' ).classList.contains( 'is-closed' )
		).toBe( true );
		expect( save ).toHaveBeenLastCalledWith( [ 'build', 'library' ] );
		expect( document.cookie ).toContain(
			'emcp_tools_nav_closed=build%2Clibrary'
		);
		const library = root.querySelector( '[data-emcp-nav-group="library"]' );
		library.click();
		build.click();
		expect( save ).toHaveBeenLastCalledWith( [] );
		expect( document.cookie ).toContain( 'emcp_tools_nav_closed=-' );
		expect(
			root.querySelector( '#l-library' ).classList.contains( 'is-closed' )
		).toBe( false );
	} );

	it( 'keeps the new state when saving fails', async () => {
		const root = frame();
		initNavGroups( root, {
			save: () => Promise.reject( new Error( 'x' ) ),
		} );
		root.querySelector( '[data-emcp-nav-group="build"]' ).click();
		await Promise.resolve();
		expect(
			root.querySelector( '#l-build' ).classList.contains( 'is-closed' )
		).toBe( true );
	} );
} );
