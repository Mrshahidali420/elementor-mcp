import { __, sprintf } from '@wordpress/i18n';

/**
 * Words for the codes a generation can stop with, from the runner or passed
 * through from EMCP Cloud. An unknown code stays visible for support.
 *
 * @param {string} code Stop code.
 * @return {string} Message.
 */
export function stopReason( code ) {
	const reasons = {
		build_too_large: __(
			'the static site grew past the size limit',
			'emcp-tools'
		),
		write_failed: __(
			'WordPress could not write the generated files',
			'emcp-tools'
		),
		too_many_urls: __(
			'the site has more pages and files than one generation can hold',
			'emcp-tools'
		),
		zip_failed: __( 'the ZIP file could not be created', 'emcp-tools' ),
		site_changing: __(
			'the site kept changing while it was being generated',
			'emcp-tools'
		),
		cancel_unconfirmed: __(
			'EMCP Cloud could not confirm the cancellation, so it was closed on this site',
			'emcp-tools'
		),
		allowance_exhausted: __(
			'there were no static-site generations left',
			'emcp-tools'
		),
		generation_active: __(
			'another generation for this site was still open in EMCP Cloud',
			'emcp-tools'
		),
		site_not_registered: __(
			'this site is no longer connected to that EMCP Cloud account',
			'emcp-tools'
		),
		plugin_outdated: __(
			'EMCP Cloud needs a newer version of EMCP Tools',
			'emcp-tools'
		),
	};
	return (
		reasons[ code ] ||
		sprintf(
			/* translators: %s: error code. */
			__( 'an unexpected error (%s)', 'emcp-tools' ),
			code
		)
	);
}
