import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	Checkbox,
	Field,
	Icon,
	IconButton,
	Meter,
	Notice,
	PageHeader,
	SearchInput,
	Tabs,
	Textarea,
	TextInput,
	Toggle,
	errorMessage,
	request,
	useConfirm,
	useToast,
} from '@emcp/ui';
import { driveBuild } from './driver';
import { stopReason } from './messages';
import { logMessage, percent, steps } from './progress';

const API = '/emcp-tools/v1/admin/static-site';
const INCLUDE = [
	[ 'posts', __( 'Posts', 'emcp-tools' ), 'pin', 'purple' ],
	[ 'pages', __( 'Pages', 'emcp-tools' ), 'file-text', 'indigo' ],
	[ 'archives', __( 'Archives', 'emcp-tools' ), 'archive', 'teal' ],
	[ 'categories', __( 'Categories', 'emcp-tools' ), 'folder', 'orange' ],
	[ 'tags', __( 'Tags', 'emcp-tools' ), 'tag', 'rose' ],
	[ 'authors', __( 'Author Pages', 'emcp-tools' ), 'user', 'sky' ],
	[ 'cpts', __( 'Custom Post Types', 'emcp-tools' ), 'pencil', 'amber' ],
	[ 'theme_assets', __( 'Theme Assets', 'emcp-tools' ), 'palette', 'purple' ],
	[ 'plugin_assets', __( 'Plugin Assets', 'emcp-tools' ), 'plug', 'indigo' ],
	[ 'uploads', __( 'Uploads', 'emcp-tools' ), 'upload', 'teal' ],
];
const SERVE_KEYS = [ 'serve_enabled', 'serve_logged_in', 'serve_exclude_urls' ];
const LOG_MAX = 300;

const newOwner = () =>
	'tab-' +
	( window.crypto && window.crypto.randomUUID
		? window.crypto.randomUUID()
		: Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 ) );

const pick = ( settings, keys ) =>
	Object.fromEntries( keys.map( ( k ) => [ k, settings[ k ] ] ) );

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

function Pills( { legend, name, value, options, onChange } ) {
	return (
		<fieldset className="emcp-static__pills">
			<legend className="emcp-static__label">{ legend }</legend>
			<div className="emcp-static__pill-row">
				{ options.map( ( o ) => (
					<label
						key={ o.value }
						htmlFor={ `${ name }-${ o.value }` }
						className={
							'emcp-static__pill' +
							( o.value === value ? ' is-on' : '' )
						}
					>
						<input
							id={ `${ name }-${ o.value }` }
							type="radio"
							name={ name }
							value={ o.value }
							checked={ o.value === value }
							onChange={ () => onChange( o.value ) }
						/>
						{ o.icon && <Icon name={ o.icon } size={ 15 } /> }
						<span>{ o.label }</span>
					</label>
				) ) }
			</div>
		</fieldset>
	);
}

function BatchSize( { value, onChange } ) {
	const set = ( v ) => onChange( Math.max( 10, Math.min( 200, v ) ) );
	return (
		<div className="emcp-static__field">
			<span className="emcp-static__label" id="emcp-static-batch">
				{ __( 'Batch Size', 'emcp-tools' ) }
			</span>
			<div
				className="emcp-static__stepper"
				role="group"
				aria-labelledby="emcp-static-batch"
			>
				<IconButton
					icon="minus"
					label={ __( 'Smaller batches', 'emcp-tools' ) }
					onClick={ () => set( value - 10 ) }
					disabled={ value <= 10 }
				/>
				<output aria-live="polite">{ value }</output>
				<IconButton
					icon="plus"
					label={ __( 'Larger batches', 'emcp-tools' ) }
					onClick={ () => set( value + 10 ) }
					disabled={ value >= 200 }
				/>
			</div>
			<p className="emcp-static__hint">
				{ __( 'URLs per batch (10–200)', 'emcp-tools' ) }
			</p>
		</div>
	);
}

function Pipeline( { view } ) {
	return (
		<ol
			className="emcp-static__pipeline"
			aria-label={ __( 'Generation steps', 'emcp-tools' ) }
		>
			{ steps( view ).map( ( s ) => (
				<li
					key={ s.id }
					className={ `is-${ s.status }` }
					aria-current={ 'current' === s.status ? 'step' : undefined }
				>
					<span className="emcp-static__dot" aria-hidden="true">
						{ 'done' === s.status && (
							<Icon name="check" size={ 14 } />
						) }
					</span>
					<span className="emcp-static__step">{ s.label }</span>
				</li>
			) ) }
		</ol>
	);
}

function ActivityLog( { lines } ) {
	const box = useRef( null );
	useEffect( () => {
		if ( box.current ) {
			box.current.scrollTop = box.current.scrollHeight;
		}
	}, [ lines ] );
	return (
		// A scrolling region must be reachable with the keyboard.

		<div
			ref={ box }
			className="emcp-static__log"
			role="log"
			aria-label={ __( 'Activity Log', 'emcp-tools' ) }
			tabIndex={ 0 }
		>
			{ lines.length ? (
				lines.map( ( l, i ) => (
					<div key={ i } className={ l.tone ? `is-${ l.tone }` : '' }>
						<span className="emcp-static__time">[{ l.time }]</span>{ ' ' }
						{ l.text }
					</div>
				) )
			) : (
				<div className="is-muted">
					{ __( 'Waiting to start…', 'emcp-tools' ) }
				</div>
			) }
		</div>
	);
}

function CardTitle( { icon, children } ) {
	return (
		<span className="emcp-static__title">
			<Icon name={ icon } size={ 18 } />
			{ children }
		</span>
	);
}

export function StaticSiteScreen( { data } ) {
	const [ state, setState ] = useState( data );
	const [ mode, setMode ] = useState(
		data.run && 'pages' === data.run.mode ? 'pages' : 'full'
	);
	const [ picked, setPicked ] = useState( [] );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ run, setRun ] = useState( data.run );
	const [ busy, setBusy ] = useState( '' );
	const [ form, setForm ] = useState( data.settings );
	const [ serve, setServe ] = useState( () =>
		pick( data.settings, SERVE_KEYS )
	);
	const [ lines, setLines ] = useState( () =>
		data.run && data.run.generation
			? [
					{
						time: new Date().toLocaleTimeString(),
						text: logMessage( data.run ),
					},
				]
			: []
	);
	const [ finished, setFinished ] = useState( null );
	const owner = useRef( newOwner() );
	const driver = useRef( null );
	const toast = useToast();
	const confirm = useConfirm();

	const log = ( text, tone = '' ) => {
		if ( ! text ) {
			return;
		}
		setLines( ( prev ) =>
			prev.length && prev[ prev.length - 1 ].text === text
				? prev
				: [
						...prev,
						{ time: new Date().toLocaleTimeString(), text, tone },
					].slice( -LOG_MAX )
		);
	};

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
			onProgress: ( v ) => {
				setRun( v && v.generation && 'done' !== v.phase ? v : null );
				log( logMessage( v ) );
			},
			onDone: async ( outcome, payload ) => {
				driver.current = null;
				if ( 'done' === outcome ) {
					setFinished( { mode: view.mode, phase: 'done' } );
					toast.success(
						__( 'Static site generated.', 'emcp-tools' )
					);
					const next = await refresh( true );
					if ( next && next.current ) {
						log(
							sprintf(
								/* translators: 1: pages, 2: files, 3: size in MB, 4: errors. */
								__(
									'Static site ready: %1$d pages, %2$d files, %3$s MB, %4$d errors.',
									'emcp-tools'
								),
								next.current.pages,
								next.current.files,
								( next.current.bytes / 1048576 ).toFixed( 1 ),
								next.current.errors || 0
							),
							'success'
						);
					}
					return;
				}
				if ( 'cancelled' === outcome ) {
					log( __( 'Cancelled.', 'emcp-tools' ) );
					toast.info(
						payload && payload.refunded
							? __(
									'Cancelled. The generation was returned to your allowance.',
									'emcp-tools'
								)
							: __( 'Cancelled.', 'emcp-tools' )
					);
				} else if ( 'lost' === outcome ) {
					log(
						__( 'Lost the connection to WordPress.', 'emcp-tools' ),
						'error'
					);
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
		setFinished( null );
		setLines( [] );
		log(
			'pages' === mode
				? sprintf(
						/* translators: %d: number of pages. */
						__(
							'Starting generation of %d selected pages…',
							'emcp-tools'
						),
						picked.length
					)
				: __( 'Starting full site generation…', 'emcp-tools' )
		);
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
			log( logMessage( view ) );
			if (
				view &&
				view.generation &&
				[ 'reserved', 'running' ].includes( view.cloud )
			) {
				drive( view, view.epoch );
			}
		} catch ( e ) {
			log( errorMessage( e ), 'error' );
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
			log( logMessage( view ) );
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
			log( __( 'Continuing the generation in this tab.', 'emcp-tools' ) );
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
				log( __( 'Cancelled.', 'emcp-tools' ) );
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

	const saveSettings = async ( part, which ) => {
		setBusy( which );
		try {
			const r = await request( `${ API }/settings`, {
				method: 'POST',
				data: { settings: { ...state.settings, ...part } },
			} );
			setState( r );
			if ( 'settings' === which ) {
				setForm( r.settings );
			} else {
				setServe( pick( r.settings, SERVE_KEYS ) );
			}
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
			confirmLabel: __( 'Purge Served Files', 'emcp-tools' ),
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
			toast.success( __( 'Served files purged.', 'emcp-tools' ) );
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
	const shown = active ? run : finished;
	const driving = active && mine && driver.current;
	const serving = state.settings.serve_enabled && state.servedCount > 0;
	const set = ( key, value ) => setForm( { ...form, [ key ]: value } );
	const zipReady =
		state.current && 'zip' === state.current.delivery && state.current.zip;

	const allowanceCard = state.connected && (
		<div className="emcp-static__allowance">
			{ a ? (
				<>
					<p className="emcp-static__big">{ allowanceText( a ) }</p>
					{ a.monthly && (
						<Meter
							value={ a.monthly.used }
							max={ a.monthly.limit }
							label={ __( 'Used this month', 'emcp-tools' ) }
						/>
					) }
				</>
			) : (
				<p className="emcp-static__muted">
					{ state.allowanceError
						? __(
								'EMCP Cloud did not answer. Try again in a moment.',
								'emcp-tools'
							)
						: __( 'Checking your allowance…', 'emcp-tools' ) }
				</p>
			) }
			{ state.account && (
				<p className="emcp-static__muted">{ state.account }</p>
			) }
		</div>
	);

	const runPanel = ( panelMode ) => {
		const here = shown && ( shown.mode || 'full' ) === panelMode;
		return (
			<>
				{ here && (
					<>
						<Pipeline view={ shown } />
						<div className="emcp-static__bar">
							<span
								style={ {
									inlineSize: `${ percent( shown ) }%`,
								} }
							/>
						</div>
						{ active && (
							<p
								className="emcp-static__status"
								aria-live="polite"
							>
								{ logMessage( run ) }
							</p>
						) }
					</>
				) }
				{ here && active && (
					<>
						{ [ 'uncertain', 'reserving' ].includes(
							run.cloud
						) && (
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
					</>
				) }
				<div className="emcp-static__actions is-stacked">
					{ here && ! active && zipReady && (
						<Button
							variant="primary"
							icon="download"
							onClick={ download }
						>
							{ __( 'Download ZIP', 'emcp-tools' ) }
						</Button>
					) }
					<div className="emcp-static__actions">
						{ driving ? (
							<Button variant="primary" loading disabled>
								{ __( 'Generating…', 'emcp-tools' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								icon="download"
								onClick={ generate }
								disabled={ ! canGenerate }
								loading={ 'generate' === busy }
							>
								{ 'pages' === panelMode
									? __(
											'Generate Selected Pages',
											'emcp-tools'
										)
									: __(
											'Generate Static Site',
											'emcp-tools'
										) }
							</Button>
						) }
						{ here &&
							active &&
							! run.promoted &&
							'cancelling' !== run.cloud && (
								<Button
									variant="danger"
									icon="x"
									onClick={ cancel }
									loading={ 'cancel' === busy }
								>
									{ __( 'Cancel', 'emcp-tools' ) }
								</Button>
							) }
					</div>
				</div>
			</>
		);
	};

	return (
		<div className="emcp-static">
			<PageHeader
				title={ __( 'Static Site', 'emcp-tools' ) }
				description={ __(
					'Turn this site into plain HTML files.',
					'emcp-tools'
				) }
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

			<div className="emcp-static__cols">
				<div className="emcp-static__main">
					<Card
						title={
							<CardTitle icon="settings">
								{ __( 'Settings', 'emcp-tools' ) }
							</CardTitle>
						}
					>
						<div className="emcp-static__top">
							<Pills
								legend={ __( 'Delivery Method', 'emcp-tools' ) }
								name="emcp-static-delivery"
								value={ form.delivery }
								onChange={ ( v ) => set( 'delivery', v ) }
								options={ [
									{
										value: 'zip',
										label: __(
											'ZIP Download',
											'emcp-tools'
										),
										icon: 'archive',
									},
									{
										value: 'local',
										label: __(
											'Local Directory',
											'emcp-tools'
										),
										icon: 'folder',
									},
								] }
							/>
							<Pills
								legend={ __( 'URL Style', 'emcp-tools' ) }
								name="emcp-static-urls"
								value={ form.url_style }
								onChange={ ( v ) => set( 'url_style', v ) }
								options={ [
									{
										value: 'relative',
										label: __( 'Relative', 'emcp-tools' ),
									},
									{
										value: 'offline',
										label: __( 'Offline', 'emcp-tools' ),
									},
									{
										value: 'absolute',
										label: __( 'Absolute', 'emcp-tools' ),
									},
								] }
							/>
							<BatchSize
								value={ form.batch_size }
								onChange={ ( v ) => set( 'batch_size', v ) }
							/>
						</div>
						{ 'local' === form.delivery && (
							<Field
								label={ __( 'Output Directory', 'emcp-tools' ) }
								help={ __(
									'A folder inside wp-content/emcp-static. Letters, numbers and dashes.',
									'emcp-tools'
								) }
							>
								{ ( p ) => (
									<TextInput
										{ ...p }
										value={ form.local_folder }
										onChange={ ( e ) =>
											set(
												'local_folder',
												e.target.value
											)
										}
									/>
								) }
							</Field>
						) }
						{ 'absolute' === form.url_style && (
							<Field label={ __( 'Base URL', 'emcp-tools' ) }>
								{ ( p ) => (
									<TextInput
										{ ...p }
										type="url"
										value={ form.base_url }
										onChange={ ( e ) =>
											set( 'base_url', e.target.value )
										}
										placeholder="https://static.example.com"
									/>
								) }
							</Field>
						) }

						<fieldset className="emcp-static__include">
							<legend className="emcp-static__label">
								{ __( 'Content to Include', 'emcp-tools' ) }
							</legend>
							<div className="emcp-static__grid">
								{ INCLUDE.map(
									( [ id, label, icon, tone ] ) => (
										<div
											key={ id }
											className="emcp-static__feature"
										>
											<span
												className={ `emcp-static__tile is-${ tone }` }
												aria-hidden="true"
											>
												<Icon
													name={ icon }
													size={ 16 }
												/>
											</span>
											<span
												className="emcp-static__name"
												aria-hidden="true"
											>
												{ label }
											</span>
											<Toggle
												label={ label }
												hideLabel
												checked={ form.include.includes(
													id
												) }
												onChange={ ( on ) =>
													set(
														'include',
														on
															? [
																	...form.include,
																	id,
																]
															: form.include.filter(
																	( x ) =>
																		x !== id
																)
													)
												}
											/>
										</div>
									)
								) }
							</div>
						</fieldset>

						<div className="emcp-static__pair">
							<Field
								label={ __( 'Additional URLs', 'emcp-tools' ) }
							>
								{ ( p ) => (
									<Textarea
										{ ...p }
										rows={ 2 }
										value={ form.additional_urls }
										placeholder={ __(
											'One URL per line',
											'emcp-tools'
										) }
										onChange={ ( e ) =>
											set(
												'additional_urls',
												e.target.value
											)
										}
									/>
								) }
							</Field>
							<Field
								label={ __( 'Exclude URLs', 'emcp-tools' ) }
								help={ __(
									'Supports * wildcards.',
									'emcp-tools'
								) }
							>
								{ ( p ) => (
									<Textarea
										{ ...p }
										rows={ 2 }
										value={ form.exclude_urls }
										placeholder={ '/wp-admin/*\n/cart/*' }
										onChange={ ( e ) =>
											set(
												'exclude_urls',
												e.target.value
											)
										}
									/>
								) }
							</Field>
						</div>
						<Checkbox
							label={ __(
								"Accept this site's self-signed certificate",
								'emcp-tools'
							) }
							checked={ form.accept_self_signed }
							onChange={ ( on ) =>
								set( 'accept_self_signed', on )
							}
						/>
						<p className="emcp-static__hint">
							{ __(
								'Only for a site with a self-signed certificate, such as a local or staging site. Pages are then fetched without checking the certificate.',
								'emcp-tools'
							) }
						</p>
						<div className="emcp-static__actions">
							<Button
								variant="primary"
								onClick={ () =>
									saveSettings(
										Object.fromEntries(
											Object.entries( form ).filter(
												( [ k ] ) =>
													! SERVE_KEYS.includes( k )
											)
										),
										'settings'
									)
								}
								loading={ 'settings' === busy }
							>
								{ __( 'Save Settings', 'emcp-tools' ) }
							</Button>
						</div>
					</Card>

					<Card
						title={
							<CardTitle icon="scroll-text">
								{ __( 'Activity Log', 'emcp-tools' ) }
							</CardTitle>
						}
					>
						<ActivityLog lines={ lines } />
					</Card>
				</div>

				<div className="emcp-static__side">
					{ allowanceCard && (
						<Card
							title={
								<CardTitle icon="cloud">
									{ __( 'Allowance', 'emcp-tools' ) }
								</CardTitle>
							}
						>
							{ allowanceCard }
						</Card>
					) }
					<Card className="emcp-static__generator" padded={ false }>
						<Tabs
							label={ __( 'What to generate', 'emcp-tools' ) }
							idPrefix="emcp-static"
							className="emcp-static__tabs"
							options={ [
								{
									value: 'full',
									label: __( 'Full Site', 'emcp-tools' ),
								},
								{
									value: 'pages',
									label: __(
										'Individual Pages',
										'emcp-tools'
									),
								},
							] }
							value={ mode }
							onChange={ setMode }
						/>
						<div
							className="emcp-static__panel"
							role="tabpanel"
							id={ `emcp-static-panel-${ mode }` }
							aria-labelledby={ `emcp-static-tab-${ mode }` }
						>
							<p className="emcp-static__desc">
								{ 'pages' === mode
									? __(
											'Search and select specific pages to generate as static HTML.',
											'emcp-tools'
										)
									: __(
											'Generate a complete static copy of your entire WordPress site.',
											'emcp-tools'
										) }
							</p>

							{ a && exhausted && (
								<Notice tone="warning">
									{ __(
										'You have no static-site generations left. A linked EMCP Pro licence adds a monthly allowance.',
										'emcp-tools'
									) }
								</Notice>
							) }
							{ a && 'unavailable' === a.licence_check && (
								<Notice tone="info">
									{ __(
										'We could not check your licence just now, so only free generations are counted until it recovers.',
										'emcp-tools'
									) }
								</Notice>
							) }

							{ 'pages' === mode && ! active && (
								<div className="emcp-static__picker">
									<SearchInput
										label={ __(
											'Search pages by title or URL…',
											'emcp-tools'
										) }
										value={ query }
										onChange={ search }
										debounce={ 300 }
									/>
									{ results.length > 0 && (
										<ul className="emcp-static__results">
											{ results.map( ( r ) => (
												<li key={ r.id }>
													<button
														type="button"
														onClick={ () =>
															! picked.some(
																( p ) =>
																	p.id ===
																	r.id
															) &&
															setPicked( [
																...picked,
																r,
															] )
														}
													>
														<span>
															{ r.title || r.url }
														</span>
														<span className="emcp-static__muted">
															{ r.type }
														</span>
													</button>
												</li>
											) ) }
										</ul>
									) }
									{ picked.length > 0 ? (
										<table className="emcp-static__picked">
											<thead>
												<tr>
													<th>
														{ __(
															'Title',
															'emcp-tools'
														) }
													</th>
													<th>
														{ __(
															'Type',
															'emcp-tools'
														) }
													</th>
													<th>
														<span className="screen-reader-text">
															{ __(
																'Remove',
																'emcp-tools'
															) }
														</span>
													</th>
												</tr>
											</thead>
											<tbody>
												{ picked.map( ( p ) => (
													<tr key={ p.id }>
														<td>
															{ p.title || p.url }
															<span className="emcp-static__url">
																{ p.url }
															</span>
														</td>
														<td>{ p.type }</td>
														<td>
															<IconButton
																icon="x"
																label={ sprintf(
																	/* translators: %s: page title. */
																	__(
																		'Remove %s',
																		'emcp-tools'
																	),
																	p.title ||
																		p.url
																) }
																onClick={ () =>
																	setPicked(
																		picked.filter(
																			(
																				x
																			) =>
																				x.id !==
																				p.id
																		)
																	)
																}
															/>
														</td>
													</tr>
												) ) }
											</tbody>
										</table>
									) : (
										<p className="emcp-static__muted">
											{ __(
												'Search and select pages to generate.',
												'emcp-tools'
											) }
										</p>
									) }
								</div>
							) }

							{ runPanel( mode ) }

							{ state.lastCancel &&
								state.lastCancel.error &&
								! [ 'cancelled', '' ].includes(
									state.lastCancel.error
								) && (
									<Notice tone="danger">
										{ sprintf(
											/* translators: %s: why the generation stopped. */ __(
												'The last generation stopped because %s.',
												'emcp-tools'
											),
											stopReason( state.lastCancel.error )
										) }
									</Notice>
								) }
						</div>
					</Card>

					<Card
						title={
							<CardTitle icon="gauge">
								{ __( 'Static Serving', 'emcp-tools' ) }
							</CardTitle>
						}
					>
						<Checkbox
							label={ __(
								'Enable Static Serving',
								'emcp-tools'
							) }
							checked={ serve.serve_enabled }
							disabled={ ! state.denyVerified }
							onChange={ ( on ) =>
								setServe( { ...serve, serve_enabled: on } )
							}
						/>
						<p className="emcp-static__hint">
							{ __(
								'Serve pre-generated static HTML to visitors instead of loading WordPress dynamically.',
								'emcp-tools'
							) }
						</p>
						{ ! state.denyVerified && (
							<div className="emcp-static__deny">
								<p>
									{ __(
										'Before serving, check that your web server does not hand out the generated files directly. On nginx, add this rule first:',
										'emcp-tools'
									) }
								</p>
								<pre
									tabIndex={ 0 }
									className="emcp-static__code"
								>
									{ state.nginxRule }
								</pre>
								<Button
									onClick={ check }
									loading={ 'check' === busy }
								>
									{ __( 'Check the server', 'emcp-tools' ) }
								</Button>
							</div>
						) }
						<div
							className={
								'emcp-static__serve-status' +
								( serving ? ' is-on' : '' )
							}
						>
							{ serving
								? sprintf(
										/* translators: %d: number of pages. */ __(
											'Serving %d static page(s)',
											'emcp-tools'
										),
										state.servedCount
									)
								: sprintf(
										/* translators: %d: number of pages. */ __(
											'%d page(s) ready to serve',
											'emcp-tools'
										),
										state.servedCount
									) }
						</div>
						{ serve.serve_enabled && (
							<>
								<Checkbox
									label={ __(
										'Serve to logged-in users',
										'emcp-tools'
									) }
									checked={ serve.serve_logged_in }
									onChange={ ( on ) =>
										setServe( {
											...serve,
											serve_logged_in: on,
										} )
									}
								/>
								<Field
									label={ __(
										'Exclude URLs from Serving',
										'emcp-tools'
									) }
									help={ __(
										'One URL per line. Supports * wildcards.',
										'emcp-tools'
									) }
								>
									{ ( p ) => (
										<Textarea
											{ ...p }
											rows={ 3 }
											value={ serve.serve_exclude_urls }
											placeholder={
												'/cart/*\n/checkout/*'
											}
											onChange={ ( e ) =>
												setServe( {
													...serve,
													serve_exclude_urls:
														e.target.value,
												} )
											}
										/>
									) }
								</Field>
							</>
						) }
						<div className="emcp-static__actions">
							<Button
								variant="primary"
								size="sm"
								onClick={ () => saveSettings( serve, 'serve' ) }
								loading={ 'serve' === busy }
							>
								{ __( 'Save', 'emcp-tools' ) }
							</Button>
							<Button
								variant="danger"
								size="sm"
								onClick={ purge }
								disabled={ ! state.servedCount }
							>
								{ __( 'Purge Served Files', 'emcp-tools' ) }
							</Button>
						</div>
					</Card>

					{ state.current && (
						<Card
							title={
								<CardTitle icon="history">
									{ __( 'Last Generation', 'emcp-tools' ) }
								</CardTitle>
							}
						>
							<dl className="emcp-static__facts">
								<dt>{ __( 'Date', 'emcp-tools' ) }</dt>
								<dd>
									{ new Date(
										state.current.date
									).toLocaleString() }
								</dd>
								<dt>{ __( 'Pages', 'emcp-tools' ) }</dt>
								<dd>{ state.current.pages }</dd>
								<dt>{ __( 'Files', 'emcp-tools' ) }</dt>
								<dd>{ state.current.files }</dd>
								{ state.current.errors > 0 && (
									<>
										<dt>
											{ __( 'Errors', 'emcp-tools' ) }
										</dt>
										<dd>{ state.current.errors }</dd>
									</>
								) }
								<dt>{ __( 'Size', 'emcp-tools' ) }</dt>
								<dd>{ `${ (
									state.current.bytes / 1048576
								).toFixed( 1 ) } MB` }</dd>
							</dl>
							{ zipReady && ! finished && (
								<Button icon="download" onClick={ download }>
									{ __( 'Download ZIP', 'emcp-tools' ) }
								</Button>
							) }
							{ ! zipReady && (
								<p className="emcp-static__muted">
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
				</div>
			</div>
		</div>
	);
}
