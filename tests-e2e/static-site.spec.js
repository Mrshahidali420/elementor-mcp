/**
 * Static Site screen against a real site: it renders, axe is clean, nothing
 * scrolls sideways, and the settings drawer opens and closes. It never
 * starts a generation, so no Cloud allowance is spent.
 */
const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const { EMCP_E2E_URL, EMCP_E2E_COOKIES } = process.env;
test.skip(
	! EMCP_E2E_URL || ! EMCP_E2E_COOKIES,
	'Set EMCP_E2E_URL and EMCP_E2E_COOKIES'
);

const URL = '/wp-admin/admin.php?page=emcp-tools-static-site';

test.beforeEach( async ( { context } ) => {
	const c = JSON.parse( fs.readFileSync( EMCP_E2E_COOKIES, 'utf8' ) );
	const base = {
		domain: c.host,
		httpOnly: true,
		secure: c.secure,
		sameSite: 'Lax',
	};
	await context.addCookies( [
		{ ...base, name: c.auth[ 0 ], value: c.auth[ 1 ], path: '/wp-admin' },
		{ ...base, name: c.logged[ 0 ], value: c.logged[ 1 ], path: '/' },
	] );
} );

test( 'Static Site: renders, axe is clean, no sideways scroll', async ( {
	page,
} ) => {
	await page.goto( URL );
	await expect(
		page.getByRole( 'heading', { name: 'Static Site', level: 1 } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Generate', exact: true } )
	).toBeVisible();
	const results = await new AxeBuilder( { page } )
		.include( '#emcp-screen' )
		.analyze();
	expect( results.violations ).toEqual( [] );
	const width = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);
	expect( width ).toBeLessThanOrEqual( 0 );
} );

test( 'Static Site: settings drawer opens and closes', async ( { page } ) => {
	await page.goto( URL );
	await page.getByRole( 'button', { name: 'Settings', exact: true } ).click();
	await expect(
		page.getByRole( 'dialog', { name: 'Static Site settings' } )
	).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
} );
