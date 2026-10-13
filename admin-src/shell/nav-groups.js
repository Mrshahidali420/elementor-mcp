import { request } from '@emcp/ui';

const defaultSave = ( closed ) =>
	request( '/emcp-tools/v1/admin/frame/groups', {
		method: 'POST',
		data: { closed },
	} );

/**
 * Sidebar section headers open and close their list. The server renders the
 * saved state (no flash on load); each change is saved per user, and a cookie
 * carries it to the next page before the save lands.
 *
 * @param {Element}                                frame        .eui-frame.
 * @param {Object}                                 options
 * @param {(closed: string[]) => Promise<unknown>} options.save Persist the state.
 */
export function initNavGroups( frame, { save = defaultSave } = {} ) {
	const headers = [ ...frame.querySelectorAll( '[data-emcp-nav-group]' ) ];
	headers.forEach( ( header ) => {
		header.addEventListener( 'click', () => {
			const open = 'true' !== header.getAttribute( 'aria-expanded' );
			header.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			const list = frame.querySelector(
				`#${ header.getAttribute( 'aria-controls' ) }`
			);
			if ( list ) {
				list.classList.toggle( 'is-closed', ! open );
			}
			const closed = headers
				.filter(
					( h ) => 'false' === h.getAttribute( 'aria-expanded' )
				)
				.map( ( h ) => h.getAttribute( 'data-emcp-nav-group' ) );
			document.cookie = `emcp_tools_nav_closed=${
				closed.length ? encodeURIComponent( closed.join( ',' ) ) : '-'
			}; path=/; max-age=31536000; SameSite=Lax`;
			Promise.resolve( save( closed ) ).catch( () => {} );
		} );
	} );
}
