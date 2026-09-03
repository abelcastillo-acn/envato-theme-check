/**
 * Envato Theme Check — Review Queue page: hash/paste import preview, inline actions.
 */
( function () {
	'use strict';

	var cfg = window.etcQueue || {};
	var i18n = cfg.i18n || {};
	var $ = function ( s, r ) { return ( r || document ).querySelector( s ); };
	var $$ = function ( s, r ) { return Array.prototype.slice.call( ( r || document ).querySelectorAll( s ) ); };

	function post( action, fields, nonce ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce || cfg.nonce );
		Object.keys( fields || {} ).forEach( function ( k ) {
			if ( Array.isArray( fields[ k ] ) ) {
				fields[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
			} else {
				body.append( k, fields[ k ] );
			}
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( ! j || ! j.success ) { throw new Error( ( j && j.data && j.data.message ) || 'failed' ); } return j.data; } );
	}

	function flash( el, msg, ok ) {
		if ( ! el ) { return; }
		el.textContent = msg;
		el.className = ok === false ? 'etc-status-error' : 'etc-status-ok';
		window.clearTimeout( el._t );
		el._t = window.setTimeout( function () { el.textContent = ''; }, 4000 );
	}

	function decodeFragment( enc ) {
		var b64 = enc.replace( /-/g, '+' ).replace( /_/g, '/' );
		while ( b64.length % 4 ) { b64 += '='; }
		var bin = window.atob( b64 );
		try {
			return JSON.parse( decodeURIComponent( escape( bin ) ) );
		} catch ( e ) {
			return JSON.parse( bin );
		}
	}

	/* ---------- import preview ---------- */

	var preview = {
		box: $( '#etc-import-preview' ),
		tbody: $( '#etc-import-preview tbody' ),
		run: $( '#etc-import-run' ),
		status: $( '#etc-import-status' ),
		payload: null
	};

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}

	function showPreview( payload ) {
		if ( ! payload || payload.schema !== cfg.schema || ! Array.isArray( payload.items ) || ! payload.items.length ) {
			flash( preview.status, i18n.invalid || 'Unrecognised payload.', false );
			preview.box.hidden = false;
			preview.tbody.innerHTML = '';
			preview.run.hidden = true;
			return;
		}
		var known = new Set( ( cfg.known || [] ).map( String ) );
		preview.tbody.innerHTML = payload.items.map( function ( it ) {
			var dup = known.has( String( it.item_id ) );
			return '<tr class="' + ( dup ? 'etc-dup' : '' ) + '"><td>' + esc( it.title ) + '</td><td>' + esc( it.author ) + '</td><td>' + esc( it.item_id ) + '</td><td>' + esc( it.submitted_at ) + '</td><td>' + ( dup ? '<span class="etc-badge">' + esc( i18n.dup || 'already imported' ) + '</span>' : '' ) + '</td></tr>';
		} ).join( '' );
		preview.payload = payload;
		preview.run.hidden = false;
		preview.run.textContent = ( i18n.importN || 'Import %d items' ).replace( '%d', payload.items.length );
		preview.box.hidden = false;
		if ( typeof preview.box.scrollIntoView === 'function' ) {
			preview.box.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	if ( preview.run ) {
		preview.run.addEventListener( 'click', function () {
			if ( ! preview.payload ) { return; }
			preview.run.disabled = true;
			post( 'etc_queue_import', { payload: JSON.stringify( preview.payload ) }, cfg.importNonce ).then( function ( r ) {
				var msg = ( i18n.result || '%1$d imported, %2$d updated, %3$d unchanged, %4$d skipped.' )
					.replace( '%1$d', r.imported ).replace( '%2$d', r.updated ).replace( '%3$d', r.unchanged ).replace( '%4$d', r.skipped.length );
				if ( r.skipped.length ) {
					msg += ' ' + r.skipped.map( function ( s ) { return ( s.item_id || '#' + s.index ) + ': ' + s.reason; } ).join( '; ' );
				}
				flash( preview.status, msg, true );
				if ( window.location.hash ) { window.history.replaceState( null, '', window.location.pathname + window.location.search ); }
				window.setTimeout( function () { window.location.reload(); }, 1500 );
			} ).catch( function ( e ) {
				preview.run.disabled = false;
				flash( preview.status, ( i18n.failed || 'Request failed' ) + ': ' + e.message, false );
			} );
		} );
	}

	var pasteBtn = $( '#etc-preview-payload' );
	if ( pasteBtn ) {
		pasteBtn.addEventListener( 'click', function () {
			var raw = $( '#etc-payload' ).value.trim();
			var payload = null;
			try { payload = JSON.parse( raw ); } catch ( e ) { payload = null; }
			showPreview( payload );
		} );
	}

	if ( window.location.hash.indexOf( '#import=' ) === 0 ) {
		var payload = null;
		try { payload = decodeFragment( window.location.hash.slice( 8 ) ); } catch ( e ) { payload = null; }
		showPreview( payload );
	}

	/* ---------- bookmarklet code ---------- */

	var copyBtn = $( '#etc-copy-bookmarklet' );
	if ( copyBtn ) {
		copyBtn.addEventListener( 'click', function () {
			var ta = $( '#etc-bookmarklet-code' );
			ta.focus(); ta.select();
			var ok = false;
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( ta.value ).then( function () { flash( $( '#etc-copy-status' ), i18n.copied || 'Copied', true ); } );
				return;
			}
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			flash( $( '#etc-copy-status' ), ok ? ( i18n.copied || 'Copied' ) : ( i18n.failed || 'Copy failed' ), ok );
		} );
	}

	/* ---------- inline row actions ---------- */

	function updateCounts( counts ) {
		if ( ! counts ) { return; }
		$$( '.subsubsub a' ).forEach( function ( a ) {
			var m = a.getAttribute( 'href' ).match( /status=(etc_[a-z_]+)/ );
			var key = m ? m[ 1 ] : 'all';
			var c = a.querySelector( '.count' );
			if ( c && counts[ key ] != null ) { c.textContent = '(' + counts[ key ] + ')'; }
		} );
	}

	document.addEventListener( 'change', function ( ev ) {
		var t = ev.target;
		if ( t.classList.contains( 'etc-status' ) ) {
			post( 'etc_queue_set_status', { post: t.dataset.post, status: t.value } ).then( function ( r ) { updateCounts( r.counts ); } ).catch( function () { window.alert( i18n.failed || 'Request failed' ); } );
		} else if ( t.classList.contains( 'etc-theme' ) ) {
			post( 'etc_queue_set_theme', { post: t.dataset.post, theme: t.value } ).then( function () {
				var row = t.closest( 'tr' );
				var link = row && row.querySelector( '.etc-check' );
				if ( link ) {
					if ( t.value ) {
						link.classList.remove( 'disabled' );
						link.removeAttribute( 'aria-disabled' );
						link.href = link.href.replace( /([?&]themename=)[^&]*/, '$1' + encodeURIComponent( t.value ) ).replace( /^#$/, '' );
						if ( link.getAttribute( 'href' ) === '#' ) {
							link.href = window.location.pathname + '?page=themecheck&themename=' + encodeURIComponent( t.value ) + '&queue_item=' + t.dataset.post;
						}
					} else {
						link.classList.add( 'disabled' );
						link.setAttribute( 'aria-disabled', 'true' );
					}
				}
			} ).catch( function () { window.alert( i18n.failed || 'Request failed' ); } );
		}
	} );

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( t.classList.contains( 'etc-check' ) && t.classList.contains( 'disabled' ) ) {
			ev.preventDefault();
			return;
		}
		if ( t.classList.contains( 'etc-done' ) ) {
			post( 'etc_queue_set_status', { post: t.dataset.post, status: 'etc_done' } ).then( function ( r ) {
				var sel = t.closest( 'tr' ).querySelector( '.etc-status' );
				if ( sel ) { sel.value = 'etc_done'; }
				updateCounts( r.counts );
			} ).catch( function () { window.alert( i18n.failed || 'Request failed' ); } );
		} else if ( t.classList.contains( 'etc-delete' ) ) {
			if ( ! window.confirm( i18n.confirmDel || 'Delete this item?' ) ) { return; }
			post( 'etc_queue_delete', { post: t.dataset.post } ).then( function ( r ) {
				t.closest( 'tr' ).remove();
				updateCounts( r.counts );
			} ).catch( function () { window.alert( i18n.failed || 'Request failed' ); } );
		}
	} );

	// Bulk actions: intercept the list-table form submit and do it via AJAX.
	var listForm = $( '.etc-list-form' );
	if ( listForm ) {
		listForm.addEventListener( 'submit', function ( ev ) {
			var action = ( $( '#bulk-action-selector-top' ) || {} ).value;
			if ( ! action || action === '-1' ) { return; }
			var ids = $$( 'input[name="items[]"]:checked', listForm ).map( function ( cb ) { return cb.value; } );
			if ( ! ids.length ) { ev.preventDefault(); return; }
			ev.preventDefault();
			if ( action === 'delete' && ! window.confirm( i18n.confirmDel || 'Delete these items?' ) ) { return; }
			post( 'etc_queue_bulk', { bulk: action, items: ids } ).then( function () { window.location.reload(); } ).catch( function () { window.alert( i18n.failed || 'Request failed' ); } );
		} );
	}

	/* ---------- retention ---------- */

	var saveRet = $( '#etc-retention-save' );
	if ( saveRet ) {
		saveRet.addEventListener( 'click', function () {
			post( 'etc_queue_retention', { days: $( '#etc-retention-days' ).value } ).then( function ( r ) {
				$( '#etc-retention-days' ).value = r.days;
				flash( $( '#etc-retention-status' ), i18n.saved || 'Saved', true );
			} ).catch( function () { flash( $( '#etc-retention-status' ), i18n.failed || 'Request failed', false ); } );
		} );
	}
	var purge = $( '#etc-purge' );
	if ( purge ) {
		purge.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirmPurge || 'Purge done items?' ) ) { return; }
			post( 'etc_queue_purge' ).then( function ( r ) {
				flash( $( '#etc-retention-status' ), r.deleted + ' deleted', true );
				updateCounts( r.counts );
				window.setTimeout( function () { window.location.reload(); }, 1000 );
			} ).catch( function () { flash( $( '#etc-retention-status' ), i18n.failed || 'Request failed', false ); } );
		} );
	}
}() );
