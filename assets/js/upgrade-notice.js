/* EMCP Tools: dismisses the upgrade banner (class-upgrade-notice.php). */
( function () {
	var banner = document.querySelector( '.emcp-upgrade-banner' );
	if ( ! banner ) return;
	var dismissBtn = banner.querySelector( '.emcp-upgrade-banner__dismiss' );
	if ( ! dismissBtn ) return;
	dismissBtn.addEventListener( 'click', function () {
		banner.classList.add( 'is-dismissed' );
		var body = new URLSearchParams();
		body.append( 'action', 'emcp_tools_dismiss_upgrade_notice' );
		body.append( 'nonce', banner.getAttribute( 'data-emcp-nonce' ) || '' );
		fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).finally( function () {
			setTimeout( function () {
				if ( banner.parentNode ) banner.parentNode.removeChild( banner );
			}, 280 );
		} );
	} );
} )();
