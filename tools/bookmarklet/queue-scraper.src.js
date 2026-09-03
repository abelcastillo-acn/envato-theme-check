/**
 * Envato Theme Check — proofing queue capture bookmarklet (source).
 *
 * Runs inside the reviewer's authenticated browser tab on
 * https://themeforest.net/admin/awesome_proofing, reads the visible queue items from the DOM and
 * hands them to the local plugin by opening its Review Queue page with the payload in the URL
 * fragment (never sent to any server). Falls back to an overlay with the JSON to copy.
 *
 * Self-contained: no remote scripts, no network requests, styles via CSSOM only (CSP-safe).
 *
 * Build: node build.js  →  dist/queue-scraper.min.js + dist/bookmarklet.txt
 * The plugin replaces __ETC_TARGET__ with its own Review Queue URL when rendering the install link.
 */
( function () {
	'use strict';

	var CONFIG = {
		target: '__ETC_TARGET__',
		maxItems: 200,
		maxField: 2000,
		maxUrlBytes: 1.5 * 1024 * 1024,
		version: 'bookmarklet/0.1.0',
		// DOM selectors for the proofing page. TO BE CONFIRMED against a saved sample of the page
		// (tests/fixtures/awesome_proofing.sample.html). Several candidates are tried in order.
		selectors: {
			row: [ '[data-item-id]', 'tr.item', 'li.item', '.proofing-item', 'table tbody tr' ],
			itemLink: [ 'a[href*="/item/"]' ],
			title: [ '.item-title', 'h3', 'h2', 'a[href*="/item/"]' ],
			author: [ 'a[href*="/user/"]' ],
			thumb: [ 'img' ],
			excerpt: [ '.description', '.excerpt', 'p' ],
			category: [ '.category', '[data-category]' ],
			submitted: [ 'time', '[datetime]', '.submitted', '.date' ],
			status: [ '.status', '[data-status]' ],
			itemIdRegex: /\/item\/[^/]+\/(\d+)/
		}
	};

	function first( root, list ) {
		for ( var i = 0; i < list.length; i++ ) {
			var el = root.querySelector( list[ i ] );
			if ( el ) { return el; }
		}
		return null;
	}
	function text( el, max ) {
		return el ? String( el.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, max ) : '';
	}
	function attr( el, name ) {
		return el ? ( el.getAttribute( name ) || '' ) : '';
	}
	function abs( href ) {
		try { return href ? new URL( href, location.href ).href : ''; } catch ( e ) { return ''; }
	}

	function rows() {
		for ( var i = 0; i < CONFIG.selectors.row.length; i++ ) {
			var found = Array.prototype.slice.call( document.querySelectorAll( CONFIG.selectors.row[ i ] ) )
				.filter( function ( r ) { return first( r, CONFIG.selectors.itemLink ); } );
			if ( found.length ) { return found; }
		}
		return [];
	}

	function collect() {
		var all = rows();
		var items = [];
		var seen = {};
		all.slice( 0, CONFIG.maxItems ).forEach( function ( row ) {
			var link = first( row, CONFIG.selectors.itemLink );
			var href = abs( attr( link, 'href' ) );
			var m = href.match( CONFIG.selectors.itemIdRegex );
			var id = m ? m[ 1 ] : ( attr( row, 'data-item-id' ) || '' );
			if ( ! id || seen[ id ] ) { return; }
			seen[ id ] = true;
			var author = first( row, CONFIG.selectors.author );
			var thumb = first( row, CONFIG.selectors.thumb );
			var time = first( row, CONFIG.selectors.submitted );
			items.push( {
				item_id: id,
				title: text( first( row, CONFIG.selectors.title ) || link, CONFIG.maxField ),
				author: text( author, CONFIG.maxField ),
				author_url: abs( attr( author, 'href' ) ),
				item_url: href,
				thumb_url: abs( attr( thumb, 'src' ) || attr( thumb, 'data-src' ) ),
				preview_url: '',
				excerpt: text( first( row, CONFIG.selectors.excerpt ), CONFIG.maxField ),
				category: text( first( row, CONFIG.selectors.category ), CONFIG.maxField ) || attr( first( row, CONFIG.selectors.category ), 'data-category' ),
				submitted_at: attr( time, 'datetime' ) || text( time, 200 ),
				queue_status: text( first( row, CONFIG.selectors.status ), 200 ) || attr( first( row, CONFIG.selectors.status ), 'data-status' ) || 'pending',
				raw: {}
			} );
		} );
		return { items: items, total: all.length };
	}

	function encode( obj ) {
		var json = JSON.stringify( obj );
		var b64 = btoa( unescape( encodeURIComponent( json ) ) );
		return b64.replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	function overlay( title, body, json ) {
		var old = document.getElementById( 'etc-capture-overlay' );
		if ( old ) { old.remove(); }
		var box = document.createElement( 'div' );
		box.id = 'etc-capture-overlay';
		var s = box.style;
		s.position = 'fixed'; s.top = '16px'; s.right = '16px'; s.zIndex = '2147483647'; s.width = '420px'; s.maxWidth = '90vw';
		s.background = '#fff'; s.color = '#1d2327'; s.border = '1px solid #c3c4c7'; s.borderRadius = '6px';
		s.boxShadow = '0 4px 20px rgba(0,0,0,.25)'; s.font = '13px/1.5 -apple-system, Segoe UI, Roboto, sans-serif'; s.padding = '14px';
		var h = document.createElement( 'strong' ); h.textContent = title; h.style.display = 'block'; h.style.marginBottom = '6px';
		var p = document.createElement( 'div' ); p.textContent = body; p.style.marginBottom = '8px';
		box.appendChild( h ); box.appendChild( p );
		if ( json ) {
			var ta = document.createElement( 'textarea' ); ta.value = json; ta.readOnly = true;
			ta.style.width = '100%'; ta.style.height = '110px'; ta.style.font = '11px monospace'; ta.style.marginBottom = '8px';
			box.appendChild( ta );
			var copy = document.createElement( 'button' ); copy.type = 'button'; copy.textContent = 'Copy JSON';
			copy.onclick = function () {
				ta.focus(); ta.select();
				var done = function () { copy.textContent = 'Copied — paste it in the plugin'; };
				if ( navigator.clipboard ) { navigator.clipboard.writeText( json ).then( done, function () { document.execCommand( 'copy' ); done(); } ); }
				else { document.execCommand( 'copy' ); done(); }
			};
			box.appendChild( copy );
		}
		var close = document.createElement( 'button' ); close.type = 'button'; close.textContent = 'Close'; close.style.marginLeft = '8px';
		close.onclick = function () { box.remove(); };
		box.appendChild( close );
		document.body.appendChild( box );
	}

	var res = collect();
	if ( ! res.items.length ) {
		overlay( 'Envato Theme Check', 'No queue items found on this page — the selectors may need updating (see docs/proofing-import.md in the plugin).' );
		return;
	}
	var payload = {
		schema: 'etc-queue/1',
		source: location.href,
		captured_at: new Date().toISOString(),
		captured_by: CONFIG.version,
		items: res.items
	};
	var note = res.total > res.items.length ? ' (' + ( res.total - res.items.length ) + ' not captured — over the limit)' : '';
	var enc = encode( payload );
	var url = CONFIG.target + '#import=' + enc;
	var win = null;
	if ( url.length <= CONFIG.maxUrlBytes ) {
		win = window.open( url, '_blank' );
	}
	if ( ! win ) {
		overlay( 'Envato Theme Check', res.items.length + ' items captured' + note + '. The plugin tab could not be opened (pop-up blocked or payload too large): copy the JSON and paste it in the Review Queue page.', JSON.stringify( payload ) );
	}
}() );
