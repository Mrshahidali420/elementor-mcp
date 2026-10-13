import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Drawer, EmptyState, Icon, ICONS, request } from '@emcp/ui';

// The feed names icons in WordPress's dashicons vocabulary; the admin uses Lucide.
const DASHICONS = {
	megaphone: 'megaphone',
	'video-alt3': 'play',
	'video-alt2': 'play',
	'controls-play': 'play',
	randomize: 'shuffle',
	undo: 'rotate-ccw',
	update: 'refresh-cw',
	backup: 'database-backup',
	cloud: 'cloud',
	'admin-plugins': 'plug',
	'admin-tools': 'wrench',
	'admin-users': 'users',
	lock: 'lock',
	shield: 'lock',
	'shield-alt': 'lock',
	star: 'sparkles',
	'star-filled': 'sparkles',
	lightbulb: 'lightbulb',
	gift: 'gift',
	cart: 'store',
	'money-alt': 'dollar-sign',
	download: 'download',
	warning: 'triangle-alert',
	info: 'info',
	yes: 'check',
	'yes-alt': 'circle-check',
};

const LEVEL_ICONS = {
	warning: 'triangle-alert',
	danger: 'circle-alert',
	error: 'circle-alert',
	success: 'circle-check',
	promo: 'gift',
	info: 'info',
};

const LEVELS = [ 'info', 'success', 'warning', 'danger', 'promo' ];

/**
 * The icon for a notice: its own when the set has one, else one for its level.
 *
 * @param {Object} n Notice.
 * @return {string} Icon name.
 */
export function iconFor( n ) {
	const own = n && n.icon ? DASHICONS[ n.icon ] || n.icon : '';
	if ( own && ICONS[ own ] ) {
		return own;
	}
	return ( n && LEVEL_ICONS[ n.level ] ) || 'bell';
}

function levelOf( n ) {
	const level = 'error' === n.level ? 'danger' : n.level;
	return LEVELS.includes( level ) ? level : 'info';
}

function dateOf( value ) {
	const d = value ? new Date( value ) : null;
	return d && ! isNaN( d.getTime() )
		? d.toLocaleDateString( undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			} )
		: '';
}

/**
 * Notifications drawer (spec 8.26). Opening it marks unread items read.
 *
 * @param {Object}                   props         Props.
 * @param {boolean}                  props.open    Whether it is open.
 * @param {() => void}               props.onClose Close handler.
 * @param {Array}                    props.items   Notifications.
 * @param {(unread: number) => void} props.onRead  Called with the new unread count.
 */
export function Notifications( { open, onClose, items, onRead } ) {
	useEffect( () => {
		const unread = items.filter( ( n ) => n.unread ).map( ( n ) => n.id );
		if ( ! open || ! unread.length ) {
			return;
		}
		request( '/emcp-tools/v1/admin/notifications/read', {
			method: 'POST',
			data: { ids: unread },
		} )
			.then( ( res ) =>
				onRead( res && 'number' === typeof res.unread ? res.unread : 0 )
			)
			.catch( () => {} );
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<Drawer
			open={ open }
			title={ __( 'Notifications', 'emcp-tools' ) }
			onClose={ onClose }
		>
			{ ! items.length && (
				<EmptyState
					icon="bell"
					title={ __( 'No announcements yet', 'emcp-tools' ) }
				/>
			) }
			<ul className="eui-notifs">
				{ items.map( ( n ) => {
					const date = dateOf( n.date );
					return (
						<li
							key={ n.id }
							className={ `eui-notifs__item is-${ levelOf( n ) }` }
						>
							<span
								className="eui-notifs__icon"
								aria-hidden="true"
							>
								<Icon name={ iconFor( n ) } size={ 18 } />
							</span>
							<div className="eui-notifs__main">
								<div className="eui-notifs__head">
									<strong className="eui-notifs__title">
										{ n.title }
									</strong>
									{ n.unread && (
										<span className="eui-notifs__new">
											{ __( 'New', 'emcp-tools' ) }
										</span>
									) }
								</div>
								{ date && (
									<span className="eui-notifs__date">
										{ date }
									</span>
								) }
								{ n.body && (
									<p className="eui-notifs__body">
										{ n.body }
									</p>
								) }
								{ n.url && (
									<a
										className="eui-notifs__cta"
										href={ n.url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ n.cta ||
											__( 'Learn more', 'emcp-tools' ) }
										<Icon name="arrow-right" size={ 14 } />
									</a>
								) }
							</div>
						</li>
					);
				} ) }
			</ul>
		</Drawer>
	);
}
