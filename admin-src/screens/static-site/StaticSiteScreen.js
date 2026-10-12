import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	Drawer,
	Field,
	Meter,
	Notice,
	PageHeader,
	SearchInput,
	Segmented,
	Select,
	Textarea,
	TextInput,
	Toggle,
	errorMessage,
	request,
	useConfirm,
	useToast,
} from '@emcp/ui';
import { driveBuild } from './driver';

const API = '/emcp-tools/v1/admin/static-site';
const PHASES = [
	[ 'setup', __( 'Setup', 'emcp-tools' ) ],
	[ 'discover', __( 'Discover', 'emcp-tools' ) ],
	[ 'fetch', __( 'Fetch', 'emcp-tools' ) ],
	[ 'rewrite', __( 'Rewrite', 'emcp-tools' ) ],
	[ 'package', __( 'Package', 'emcp-tools' ) ],
	[ 'recheck', __( 'Check changes', 'emcp-tools' ) ],
];
const INCLUDE = [
	[ 'posts', __( 'Posts', 'emcp-tools' ) ],
	[ 'pages', __( 'Pages', 'emcp-tools' ) ],
	[ 'archives', __( 'Date archives', 'emcp-tools' ) ],
	[ 'categories', __( 'Categories', 'emcp-tools' ) ],
	[ 'tags', __( 'Tags', 'emcp-tools' ) ],
	[ 'authors', __( 'Author pages', 'emcp-tools' ) ],
	[ 'cpts', __( 'Custom post types', 'emcp-tools' ) ],
];

const newOwner = () =>
	'tab-' +
	( window.crypto && window.crypto.randomUUID
		? window.crypto.randomUUID()
		: Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 ) );

function allowanceText( a ) {
	if ( ! a ) {
		return '';
	}
	if ( a.monthly ) {
		return sprintf(
			/* translators: 1: left, 2: monthly limit, 3: free left. */
			__( '%1$d of %2$d left this month, plus %3$d free', 'emcp-tools' ),
			Math.max( 0, a.monthly.limit - a.monthly.used ),
			a.monthly.limit,
			Math.max( 0, a.free.limit - a.free.used )
		);
	}
	return sprintf(
		/* translators: 1: left, 2: lifetime limit. */
		__( '%1$d of %2$d free generations left', 'emcp-tools' ),
		Math.max( 0, a.free.limit - a.free.used ),
		a.free.limit
	);
}

export function StaticSiteScreen( { data } ) {
	const [ state, setState ] = useState( data );
	const [ mode, setMode ] = useState( 'full' );
	const [ picked, setPicked ] = useState( [] );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ run, setRun ] = useState( data.run );
	const [ busy, setBusy ] = useState( '' );
	const [ drawer, setDrawer ] = useState( false );
	const [ form, setForm ] = useState( data.settings );
	const owner = useRef( newOwner() );
	const driver = useRef( null );
	const toast = useToast();
	const confirm = useConfirm();

	const refresh = async ( fresh = false ) => {
		try {
			const next = await request( fresh ? `${ API }?fresh=1` : API );
			setState( next );
			setRun( next.run );
			return next;
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			return null;
		}
	};

	useEffect( () => {
		refresh( true );
		return () => driver.current && driver.current.stop();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const drive = ( view, epoch ) => {
		driver.current = driveBuild( {
			api: {
				step: ( body ) =>
					request( `${ API }/build/step`, {
						method: 'POST',
						data: body,
					} ),
			},
			generation: view.generation,
			owner: owner.current,
			epoch,
			onProgress: ( v ) => setRun( v && v.generation ? v : null ),
			onDone: async ( outcome, payload ) => {
				driver.current = null;
				if ( 'done' === outcome ) {
					toast.success(
						__( 'Static site generated.', 'emcp-tools' )
					);
				} else if ( 'cancelled' === outcome ) {
					toast.info(
						payload && payload.refunded
							? __(
									'Cancelled. The generation was returned to your allowance.',
									'emcp-tools'
								)
							: __( 'Cancelled.', 'emcp-tools' )
					);
				} else if ( 'lost' === outcome ) {
					toast.error(
						__(
							'Lost the connection to WordPress. Reload the page to continue.',
							'emcp-tools'
						)
					);
				}
				await refresh( true );
			},
		} );
	};

	const generate = async () => {
		setBusy( 'generate' );
		try {
			const view = await request( `${ API }/build`, {
				method: 'POST',
				data: {
					mode,
					urls: picked.map( ( p ) => p.url ),
					owner: owner.current,
				},
			} );
			setRun( view );
			if (
				view &&
				view.generation &&
				[ 'reserved', 'running' ].includes( view.cloud )
			) {
				drive( view, view.epoch );
			}
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			await refresh( true );
		} finally {
			setBusy( '' );
		}
	};

	const resolve = async () => {
		setBusy( 'resolve' );
		try {
			const view = await request( `${ API }/build/resolve`, {
				method: 'POST',
				data: { generation: run.generation },
			} );
			setRun( view && view.generation ? view : null );
			if (
				view &&
				view.generation &&
				view.owner === owner.current &&
				[ 'reserved', 'running' ].includes( view.cloud )
			) {
				drive( view, view.epoch );
			}
			if ( ! view || ! view.generation ) {
				await refresh( true );
			}
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const takeOver = async () => {
		setBusy( 'continue' );
		try {
			const view = await request( `${ API }/build/continue`, {
				method: 'POST',
				data: { generation: run.generation, owner: owner.current },
			} );
			setRun( view );
			drive( view, view.epoch );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const cancel = async () => {
		const ok = await confirm( {
			title: __( 'Cancel this generation?', 'emcp-tools' ),
			message: __(
				'Its files are removed. A generation cancelled before it started fetching pages is returned to your allowance.',
				'emcp-tools'
			),
			confirmLabel: __( 'Cancel generation', 'emcp-tools' ),
			tone: 'danger',
		} );
		if ( ! ok ) {
			return;
		}
		if ( driver.current ) {
			driver.current.stop();
			driver.current = null;
		}
		setBusy( 'cancel' );
		try {
			const res = await request( `${ API }/build/cancel`, {
				method: 'POST',
				data: {
					generation: run.generation,
					owner: owner.current,
					epoch: run.epoch,
					confirm: true,
				},
			} );
			if ( res && 'cancelled' === res.phase ) {
				toast.info(
					res.refunded
						? __(
								'Cancelled. The generation was returned to your allowance.',
								'emcp-tools'
							)
						: __( 'Cancelled.', 'emcp-tools' )
				);
			}
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
			await refresh( true );
		}
	};

	const search = async ( q ) => {
		setQuery( q );
		if ( q.length < 2 ) {
			setResults( [] );
			return;
		}
		try {
			const r = await request(
				`${ API }/pages?q=${ encodeURIComponent( q ) }`
			);
			setResults( r.results );
		} catch {
			setResults( [] );
		}
	};

	const saveSettings = async ( next ) => {
		setBusy( 'settings' );
		try {
			const r = await request( `${ API }/settings`, {
				method: 'POST',
				data: { settings: next },
			} );
			setState( r );
			setForm( r.settings );
			setDrawer( false );
			toast.success( __( 'Settings saved.', 'emcp-tools' ) );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const check = async () => {
		setBusy( 'check' );
		try {
			const r = await request( `${ API }/serve/check`, {
				method: 'POST',
			} );
			toast[ r.passed ? 'success' : 'error' ](
				r.passed
					? __(
							'Your web server refuses direct reads. Serving can be switched on.',
							'emcp-tools'
						)
					: __(
							'Your web server can read the generated files directly. Add the rule below, then check again.',
							'emcp-tools'
						)
			);
			await refresh();
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const purge = async () => {
		const ok = await confirm( {
			title: __( 'Stop serving the static copy?', 'emcp-tools' ),
			message: __(
				'Every static page is removed from serving. WordPress answers until you generate again.',
				'emcp-tools'
			),
			confirmLabel: __( 'Purge served pages', 'emcp-tools' ),
			tone: 'danger',
		} );
		if ( ! ok ) {
			return;
		}
		try {
			setState(
				await request( `${ API }/serve/purge`, {
					method: 'POST',
					data: { confirm: true },
				} )
			);
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		}
	};

	const download = async () => {
		try {
			const res = await request(
				`${ API }/download/${ state.current.generation }`,
				{ parse: false }
			);
			const blob = await res.blob();
			const disposition =
				( res.headers && res.headers.get( 'Content-Disposition' ) ) ||
				'';
			const named = /filename="([^"]+)"/.exec( disposition );
			const url = window.URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = named
				? named[ 1 ]
				: `static-site-${ state.current.generation.slice( 0, 8 ) }.zip`;
			// Chromium cancels a download whose blob URL is revoked before
			// the click is handled, so keep it for a moment.
			document.body.appendChild( link );
			link.click();
			link.remove();
			window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		}
	};

	const a = state.allowance;
	const exhausted = a && a.remaining <= 0;
	const mine = run && run.owner === owner.current;
	const active = run && run.generation;
	const canGenerate =
		state.connected &&
		a &&
		! exhausted &&
		! active &&
		( 'full' === mode || picked.length > 0 ) &&
		! busy;
	const counts = ( run && run.counts ) || {};
	const total = Object.values( counts ).reduce( ( s, n ) => s + n, 0 );
	const doneCount =
		( counts.saved || 0 ) +
		( counts.zipped || 0 ) +
		( counts.error || 0 ) +
		( counts.skipped || 0 );

	return (
		<div className="emcp-static">
			<PageHeader
				title={ __( 'Static Site', 'emcp-tools' ) }
				description={ __(
					'Turn this site into plain HTML files.',
					'emcp-tools'
				) }
				actions={
					<Button onClick={ () => setDrawer( true ) }>
						{ __( 'Settings', 'emcp-tools' ) }
					</Button>
				}
			/>

			{ ! state.connected && (
				<Notice
					tone="warning"
					actions={
						<Button href={ state.connectionUrl } variant="primary">
							{ __( 'Connect', 'emcp-tools' ) }
						</Button>
					}
				>
					{ __(
						'Connect this site to EMCP Cloud to generate static sites. A free Cloud account includes 3 generations.',
						'emcp-tools'
					) }
				</Notice>
			) }

			{ state.connected && (
				<Card title={ __( 'Allowance', 'emcp-tools' ) }>
					{ a ? (
						<>
							<p className="emcp-static__big">
								{ allowanceText( a ) }
							</p>
							{ a.monthly && (
								<Meter
									value={ a.monthly.used }
									max={ a.monthly.limit }
									label={ __(
										'Used this month',
										'emcp-tools'
									) }
								/>
							) }
							{ exhausted && (
								<Notice tone="warning">
									{ __(
										'You have no static-site generations left. A linked EMCP Pro licence adds a monthly allowance.',
										'emcp-tools'
									) }
								</Notice>
							) }
							{ 'unavailable' === a.licence_check && (
								<Notice tone="info">
									{ __(
										'We could not check your licence just now, so only free generations are counted until it recovers.',
										'emcp-tools'
									) }
								</Notice>
							) }
						</>
					) : (
						<p>
							{ state.allowanceError
								? __(
										'EMCP Cloud did not answer. Try again in a moment.',
										'emcp-tools'
									)
								: __(
										'Checking your allowance…',
										'emcp-tools'
									) }
						</p>
					) }
					{ state.account && (
						<p className="emcp-static__muted">{ state.account }</p>
					) }
				</Card>
			) }

			<Card title={ __( 'Generate', 'emcp-tools' ) }>
				<Segmented
					label={ __( 'What to generate', 'emcp-tools' ) }
					options={ [
						{
							value: 'full',
							label: __( 'Full site', 'emcp-tools' ),
						},
						{
							value: 'pages',
							label: __( 'Selected pages', 'emcp-tools' ),
						},
					] }
					value={ mode }
					onChange={ setMode }
				/>
				{ 'pages' === mode && ! active && (
					<div className="emcp-static__picker">
						<SearchInput
							label={ __( 'Find pages', 'emcp-tools' ) }
							value={ query }
							onChange={ search }
							debounce={ 300 }
						/>
						<ul className="emcp-static__results">
							{ results.map( ( r ) => (
								<li key={ r.id }>
									<Button
										size="sm"
										onClick={ () =>
											! picked.some(
												( p ) => p.id === r.id
											) && setPicked( [ ...picked, r ] )
										}
									>
										{ r.title || r.url }
									</Button>
									<span className="emcp-static__muted">
										{ r.type }
									</span>
								</li>
							) ) }
						</ul>
						{ picked.length > 0 && (
							<ul
								className="emcp-static__picked"
								aria-label={ __(
									'Selected pages',
									'emcp-tools'
								) }
							>
								{ picked.map( ( p ) => (
									<li key={ p.id }>
										{ p.title || p.url }{ ' ' }
										<Button
											size="sm"
											variant="ghost"
											onClick={ () =>
												setPicked(
													picked.filter(
														( x ) => x.id !== p.id
													)
												)
											}
										>
											{ __( 'Remove', 'emcp-tools' ) }
										</Button>
									</li>
								) ) }
							</ul>
						) }
					</div>
				) }

				{ active && (
					<div className="emcp-static__run" aria-live="polite">
						<ol className="emcp-static__phases">
							{ PHASES.map( ( [ id, label ] ) => (
								<li
									key={ id }
									className={
										id === run.phase ? 'is-current' : ''
									}
								>
									{ label }
								</li>
							) ) }
						</ol>
						{ total > 0 && (
							<Meter
								value={ doneCount }
								max={ total }
								label={ __( 'Files', 'emcp-tools' ) }
								valueText={ `${ doneCount } / ${ total }` }
							/>
						) }
						{ 'uncertain' === run.cloud && (
							<Notice
								tone="info"
								actions={
									<Button
										onClick={ resolve }
										loading={ 'resolve' === busy }
									>
										{ __( 'Check again', 'emcp-tools' ) }
									</Button>
								}
							>
								{ __(
									'Could not confirm the reservation with EMCP Cloud yet.',
									'emcp-tools'
								) }
							</Notice>
						) }
						{ 'cancelling' === run.cloud && (
							<Notice
								tone="info"
								actions={
									<Button
										onClick={ resolve }
										loading={ 'resolve' === busy }
									>
										{ __( 'Try again', 'emcp-tools' ) }
									</Button>
								}
							>
								{ __(
									'Waiting for EMCP Cloud to confirm the cancellation.',
									'emcp-tools'
								) }
							</Notice>
						) }
						{ 'completing' === run.cloud && (
							<Notice
								tone="info"
								actions={
									<Button
										onClick={ resolve }
										loading={ 'resolve' === busy }
									>
										{ __( 'Try again', 'emcp-tools' ) }
									</Button>
								}
							>
								{ __(
									'The static site is ready. Reporting it to EMCP Cloud.',
									'emcp-tools'
								) }
							</Notice>
						) }
						{ ! mine &&
							! driver.current &&
							[ 'reserved', 'running' ].includes( run.cloud ) && (
								<Notice
									tone="info"
									actions={
										! run.lease_active && (
											<Button
												onClick={ takeOver }
												loading={ 'continue' === busy }
											>
												{ __(
													'Continue here',
													'emcp-tools'
												) }
											</Button>
										)
									}
								>
									{ run.lease_active
										? __(
												'This generation is running in another tab.',
												'emcp-tools'
											)
										: __(
												'This generation stopped in another tab.',
												'emcp-tools'
											) }
								</Notice>
							) }
					</div>
				) }

				<div className="emcp-static__actions">
					<Button
						variant="primary"
						onClick={ generate }
						disabled={ ! canGenerate }
						loading={ 'generate' === busy }
					>
						{ __( 'Generate', 'emcp-tools' ) }
					</Button>
					{ active &&
						! run.promoted &&
						'cancelling' !== run.cloud && (
							<Button
								variant="danger"
								onClick={ cancel }
								loading={ 'cancel' === busy }
							>
								{ __( 'Cancel', 'emcp-tools' ) }
							</Button>
						) }
				</div>
				{ state.lastCancel &&
					state.lastCancel.error &&
					! [ 'cancelled', '' ].includes(
						state.lastCancel.error
					) && (
						<Notice tone="danger">
							{ sprintf(
								/* translators: %s: error code. */ __(
									'The last generation stopped: %s.',
									'emcp-tools'
								),
								state.lastCancel.error
							) }
						</Notice>
					) }
			</Card>

			<Card title={ __( 'Static serving', 'emcp-tools' ) }>
				<Toggle
					label={ __(
						'Serve static pages to visitors',
						'emcp-tools'
					) }
					checked={ !! state.settings.serve_enabled }
					disabled={ ! state.denyVerified || 'settings' === busy }
					onChange={ ( on ) =>
						saveSettings( { ...state.settings, serve_enabled: on } )
					}
				/>
				{ ! state.denyVerified && (
					<>
						<p>
							{ __(
								'Before serving, check that your web server does not hand out the generated files directly. On nginx, add this rule first:',
								'emcp-tools'
							) }
						</p>
						<pre tabIndex={ 0 } className="emcp-static__code">
							{ state.nginxRule }
						</pre>
					</>
				) }
				<p className="emcp-static__muted">
					{ sprintf(
						/* translators: %d: number of pages. */ __(
							'%d pages ready to serve.',
							'emcp-tools'
						),
						state.servedCount
					) }
				</p>
				<div className="emcp-static__actions">
					<Button onClick={ check } loading={ 'check' === busy }>
						{ __( 'Check the server', 'emcp-tools' ) }
					</Button>
					<Button
						variant="danger"
						onClick={ purge }
						disabled={ ! state.servedCount }
					>
						{ __( 'Purge served pages', 'emcp-tools' ) }
					</Button>
				</div>
			</Card>

			{ state.current && (
				<Card title={ __( 'Last generation', 'emcp-tools' ) }>
					<dl className="emcp-static__facts">
						<dt>{ __( 'Date', 'emcp-tools' ) }</dt>
						<dd>
							{ new Date( state.current.date ).toLocaleString() }
						</dd>
						<dt>{ __( 'Pages', 'emcp-tools' ) }</dt>
						<dd>{ state.current.pages }</dd>
						<dt>{ __( 'Files', 'emcp-tools' ) }</dt>
						<dd>{ state.current.files }</dd>
						<dt>{ __( 'Size', 'emcp-tools' ) }</dt>
						<dd>{ `${ ( state.current.bytes / 1048576 ).toFixed( 1 ) } MB` }</dd>
					</dl>
					{ 'zip' === state.current.delivery && state.current.zip ? (
						<Button variant="primary" onClick={ download }>
							{ __( 'Download ZIP', 'emcp-tools' ) }
						</Button>
					) : (
						<p>
							{ sprintf(
								/* translators: %s: folder path. */ __(
									'Saved in %s',
									'emcp-tools'
								),
								state.current.folder
							) }
						</p>
					) }
				</Card>
			) }

			<Drawer
				open={ drawer }
				title={ __( 'Static Site settings', 'emcp-tools' ) }
				onClose={ () => setDrawer( false ) }
				footer={
					<Button
						variant="primary"
						onClick={ () => saveSettings( form ) }
						loading={ 'settings' === busy }
					>
						{ __( 'Save', 'emcp-tools' ) }
					</Button>
				}
			>
				<Field label={ __( 'Delivery', 'emcp-tools' ) }>
					{ ( p ) => (
						<Select
							{ ...p }
							value={ form.delivery }
							onChange={ ( v ) =>
								setForm( { ...form, delivery: v } )
							}
							options={ [
								{
									value: 'zip',
									label: __( 'ZIP download', 'emcp-tools' ),
								},
								{
									value: 'local',
									label: __(
										'Folder on this server',
										'emcp-tools'
									),
								},
							] }
						/>
					) }
				</Field>
				{ 'local' === form.delivery && (
					<Field
						label={ __( 'Folder name', 'emcp-tools' ) }
						help={ __(
							'Inside wp-content/emcp-static. Letters, numbers and dashes.',
							'emcp-tools'
						) }
					>
						{ ( p ) => (
							<TextInput
								{ ...p }
								value={ form.local_folder }
								onChange={ ( e ) =>
									setForm( {
										...form,
										local_folder: e.target.value,
									} )
								}
							/>
						) }
					</Field>
				) }
				<Field label={ __( 'Links', 'emcp-tools' ) }>
					{ ( p ) => (
						<Select
							{ ...p }
							value={ form.url_style }
							onChange={ ( v ) =>
								setForm( { ...form, url_style: v } )
							}
							options={ [
								{
									value: 'relative',
									label: __(
										'Relative (any web server)',
										'emcp-tools'
									),
								},
								{
									value: 'offline',
									label: __(
										'Offline (open files from disk)',
										'emcp-tools'
									),
								},
								{
									value: 'absolute',
									label: __(
										'Absolute (a new address)',
										'emcp-tools'
									),
								},
							] }
						/>
					) }
				</Field>
				{ 'absolute' === form.url_style && (
					<Field label={ __( 'New address', 'emcp-tools' ) }>
						{ ( p ) => (
							<TextInput
								{ ...p }
								type="url"
								value={ form.base_url }
								onChange={ ( e ) =>
									setForm( {
										...form,
										base_url: e.target.value,
									} )
								}
								placeholder="https://static.example.com"
							/>
						) }
					</Field>
				) }
				<fieldset className="emcp-static__include">
					<legend>
						{ __( 'Content to include', 'emcp-tools' ) }
					</legend>
					{ INCLUDE.map( ( [ id, label ] ) => (
						<Toggle
							key={ id }
							label={ label }
							checked={ form.include.includes( id ) }
							onChange={ ( on ) =>
								setForm( {
									...form,
									include: on
										? [ ...form.include, id ]
										: form.include.filter(
												( x ) => x !== id
											),
								} )
							}
						/>
					) ) }
				</fieldset>
				<Field
					label={ __( 'Additional URLs', 'emcp-tools' ) }
					help={ __( 'One per line.', 'emcp-tools' ) }
				>
					{ ( p ) => (
						<Textarea
							{ ...p }
							value={ form.additional_urls }
							onChange={ ( e ) =>
								setForm( {
									...form,
									additional_urls: e.target.value,
								} )
							}
						/>
					) }
				</Field>
				<Field
					label={ __( 'Exclude URLs', 'emcp-tools' ) }
					help={ __(
						'One per line. Use * as a wildcard.',
						'emcp-tools'
					) }
				>
					{ ( p ) => (
						<Textarea
							{ ...p }
							value={ form.exclude_urls }
							onChange={ ( e ) =>
								setForm( {
									...form,
									exclude_urls: e.target.value,
								} )
							}
						/>
					) }
				</Field>
				<Field
					label={ __( 'Do not serve', 'emcp-tools' ) }
					help={ __(
						'Paths WordPress always answers, one per line.',
						'emcp-tools'
					) }
				>
					{ ( p ) => (
						<Textarea
							{ ...p }
							value={ form.serve_exclude_urls }
							onChange={ ( e ) =>
								setForm( {
									...form,
									serve_exclude_urls: e.target.value,
								} )
							}
						/>
					) }
				</Field>
				<Toggle
					label={ __(
						'Serve static pages to logged-in users',
						'emcp-tools'
					) }
					checked={ form.serve_logged_in }
					onChange={ ( on ) =>
						setForm( { ...form, serve_logged_in: on } )
					}
				/>
				{ state.localEnv && (
					<Toggle
						label={ __(
							"Accept this site's self-signed certificate",
							'emcp-tools'
						) }
						checked={ form.accept_self_signed }
						onChange={ ( on ) =>
							setForm( { ...form, accept_self_signed: on } )
						}
					/>
				) }
			</Drawer>
		</div>
	);
}
