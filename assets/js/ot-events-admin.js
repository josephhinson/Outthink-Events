/**
 * Out:Think Events: live preview of how the freeform Event Date field will be read.
 * Parsing happens in PHP (same rules as strtotime), so the preview matches what gets saved.
 */
( function () {
	const input = document.getElementById( 'ot_e_date' );
	const preview = document.getElementById( 'ot_e_date_preview' );
	if ( ! input || ! preview || ! window.otEventsAdmin ) {
		return;
	}

	const { ajaxUrl, nonce, unreadable } = window.otEventsAdmin;
	let timer;
	let latest = 0;

	function show( text, isError ) {
		preview.textContent = text;
		preview.style.color = isError ? '#b32d2e' : '';
	}

	function check() {
		const value = input.value.trim();
		if ( ! value ) {
			show( '', false );
			return;
		}
		const request = ++latest;
		const body = new URLSearchParams( { action: 'ot_events_parse_date', nonce, value } );
		fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( response ) => response.json() )
			.then( ( json ) => {
				if ( request !== latest ) {
					return; // A newer keystroke already sent its own request.
				}
				if ( json.success ) {
					show( json.data.label, json.data.past );
				} else {
					show( unreadable, true );
				}
			} )
			.catch( () => {} );
	}

	input.addEventListener( 'input', () => {
		clearTimeout( timer );
		timer = setTimeout( check, 300 );
	} );
} )();
