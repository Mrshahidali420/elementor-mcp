const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * Drive one generation from this tab: one step request at a time, until the run
 * is done, cancelled, handed to another tab, or the connection stays down.
 *
 * @param {Object}                                  o            Options.
 * @param {Object}                                  o.api        `{ step( body ) }`.
 * @param {string}                                  o.generation Generation id.
 * @param {string}                                  o.owner      This tab's owner token.
 * @param {number}                                  o.epoch      Epoch this tab holds.
 * @param {(view: Object) => void}                  o.onProgress Called after each step.
 * @param {(outcome: string, data: Object) => void} o.onDone     Called once at the end.
 * @param {(ms: number) => Promise<void>}           o.wait       Delay helper.
 * @return {{stop: () => void}} Handle.
 */
export function driveBuild( {
	api,
	generation,
	owner,
	epoch,
	onProgress,
	onDone,
	wait = sleep,
} ) {
	let stopped = false;
	( async () => {
		let failures = 0;
		let delay = 0;
		while ( ! stopped ) {
			if ( delay ) {
				await wait( delay );
				if ( stopped ) {
					return;
				}
			}
			let view;
			try {
				view = await api.step( { generation, owner, epoch } );
			} catch ( e ) {
				if ( stopped ) {
					return;
				}
				const code = e && e.code;
				if ( 'busy' === code ) {
					delay = 1500;
					continue;
				}
				if ( 'elsewhere' === code || 'stale' === code ) {
					onDone( code, e );
					return;
				}
				failures++;
				if ( failures >= 10 ) {
					onDone( 'lost', e );
					return;
				}
				delay = Math.min( 15000, 1000 * 2 ** Math.min( failures, 4 ) );
				continue;
			}
			if ( stopped ) {
				return;
			}
			failures = 0;
			delay = 0;
			onProgress( view );
			if ( stopped ) {
				return;
			}
			if (
				view &&
				[ 'done', 'cancelled', 'none' ].includes( view.phase )
			) {
				onDone( view.phase, view );
				return;
			}
			if (
				view &&
				[
					'reserving',
					'uncertain',
					'cancelling',
					'completing',
				].includes( view.cloud )
			) {
				delay = 3000;
			}
		}
	} )();
	return {
		stop() {
			stopped = true;
		},
	};
}
