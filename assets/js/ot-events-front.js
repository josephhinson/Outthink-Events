/**
 * Out:Think Events: keep event lists current on cached pages.
 *
 * 1. Roll-off: removes any element whose data-ot-expires time (end of the event's
 *    day, site timezone) has passed. Runs immediately, then every minute.
 * 2. Refresh: fetches the current list from the REST endpoint and swaps it in,
 *    which also picks up events added or edited since the page was cached.
 *
 * Markup contract (see templates/list.php):
 *   [data-ot-events]            list wrapper; data-view, data-limit, data-category, optional data-nonce
 *   [data-ot-expires]           one event
 *   [data-ot-group]             removed when it no longer contains events (e.g. a month)
 *   [data-ot-empty]             shown when no events remain
 *   [data-ot-hide-when-empty]   hidden when no events remain
 *
 * Fires "ot-events:updated" on the wrapper after each change.
 */
( function () {
	const config = window.otEvents || {};

	function rollOff( root ) {
		const now = Date.now() / 1000;
		root.querySelectorAll( '[data-ot-expires]' ).forEach( ( el ) => {
			const expires = Number( el.getAttribute( 'data-ot-expires' ) );
			if ( expires && now >= expires ) {
				el.remove();
			}
		} );
		root.querySelectorAll( '[data-ot-group]' ).forEach( ( group ) => {
			if ( ! group.querySelector( '[data-ot-expires]' ) ) {
				group.remove();
			}
		} );

		const items = root.querySelectorAll( '[data-ot-expires]' );
		items.forEach( ( el, index ) => el.classList.toggle( 'even', index % 2 === 0 ) );

		const empty = items.length === 0;
		root.querySelectorAll( '[data-ot-empty]' ).forEach( ( el ) => ( el.hidden = ! empty ) );
		root.querySelectorAll( '[data-ot-hide-when-empty]' ).forEach( ( el ) => ( el.hidden = empty ) );

		root.dispatchEvent( new CustomEvent( 'ot-events:updated', { bubbles: true } ) );
	}

	function refresh( root, sendNonce ) {
		if ( ! config.restUrl ) {
			return;
		}
		const params = new URLSearchParams( {
			view: root.dataset.view || 'list',
			limit: root.dataset.limit || '0',
			category: root.dataset.category || '',
			// Changes every hour, so a host that caches the API can't serve a stale list for long.
			_: String( Math.floor( Date.now() / 3600000 ) ),
		} );
		const headers = {};
		if ( sendNonce && root.dataset.nonce ) {
			headers[ 'X-WP-Nonce' ] = root.dataset.nonce;
		}
		const url = config.restUrl + ( config.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + params;

		fetch( url, { headers, credentials: 'same-origin' } )
			.then( ( response ) => {
				// A nonce from a cached page may belong to someone else's session: retry without it.
				if ( response.status === 403 && headers[ 'X-WP-Nonce' ] ) {
					refresh( root, false );
					return null;
				}
				return response.ok ? response.json() : null;
			} )
			.then( ( data ) => {
				if ( data && typeof data.html === 'string' ) {
					root.innerHTML = data.html;
					rollOff( root );
				}
			} )
			.catch( () => {} );
	}

	function init() {
		const lists = document.querySelectorAll( '[data-ot-events]' );
		lists.forEach( ( root ) => {
			rollOff( root );
			refresh( root, true );
		} );
		if ( lists.length ) {
			setInterval( () => document.querySelectorAll( '[data-ot-events]' ).forEach( rollOff ), 60000 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
