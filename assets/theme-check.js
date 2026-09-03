/**
 * Envato Theme Check — results selection and plain-text message composer.
 * Vanilla JS, no dependencies. Data comes from #tc-findings-data (JSON) and window.etcConfig.
 */
( function () {
	'use strict';

	var dataEl = document.getElementById( 'tc-findings-data' );
	if ( ! dataEl ) {
		return;
	}
	var data, cfg = window.etcConfig || {};
	try {
		data = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}

	var ORDER = [ 'required', 'warning', 'recommended', 'info' ];
	var i18n  = cfg.i18n || {};
	var tpl   = Object.assign( {}, cfg.defaults || {}, cfg.template || {} );

	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };

	var els = {
		main:      $( '.tc-results-main' ),
		preview:   $( '#tc-message-preview' ),
		notes:     $( '#tc-reviewer-notes' ),
		author:    $( '#tc-author' ),
		authorHint:$( '#tc-author-hint' ),
		status:    $( '#tc-preview-status' ),
		dirty:     $( '#tc-dirty-notice' ),
		copy:      $( '#tc-copy' ),
		copyFb:    $( '#tc-copy-feedback' ),
		regen:     $( '#tc-regenerate' ),
		regenNow:  $( '#tc-regenerate-now' ),
		tplSave:   $( '#tc-template-save' ),
		tplReset:  $( '#tc-template-reset' ),
		tplStatus: $( '#tc-template-status' )
	};
	if ( ! els.main || ! els.preview ) {
		return;
	}

	var byId = {};
	data.findings.forEach( function ( f ) { byId[ f.id ] = f; } );

	var state = {
		selected: new Set(),
		notes: '',
		author: data.author || '',
		dirty: false,
		preview: ''
	};
	var storageKey = 'etc:' + ( data.theme && data.theme.slug ? data.theme.slug : 'theme' );
	var muted = false; // suppress "input" handling while we write the preview programmatically.

	/* ---------- helpers ---------- */

	function collapse( s ) {
		return String( s || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function counts() {
		var c = { required: 0, warning: 0, recommended: 0, info: 0, total: 0 };
		state.selected.forEach( function ( id ) {
			var f = byId[ id ];
			if ( f ) { c[ f.severity ]++; c.total++; }
		} );
		return c;
	}

	function substitute( s, c, notes ) {
		return String( s || '' )
			.replace( /\{author\}/g, state.author )
			.replace( /\{theme_name\}/g, data.theme.name || '' )
			.replace( /\{theme_version\}/g, data.theme.version || '' )
			.replace( /\{date\}/g, data.theme.date || '' )
			.replace( /\{required_count\}/g, c.required )
			.replace( /\{warning_count\}/g, c.warning )
			.replace( /\{recommended_count\}/g, c.recommended )
			.replace( /\{info_count\}/g, c.info )
			.replace( /\{selected_count\}/g, c.total )
			.replace( /\{reviewer_notes\}/g, notes );
	}

	function findingsBlock() {
		var max = parseInt( tpl.evidence_max_lines, 10 );
		if ( isNaN( max ) ) { max = 5; }
		var showFile = !! tpl.show_file_line;
		var groups = [];
		ORDER.forEach( function ( sev ) {
			var items = data.findings.filter( function ( f ) { return f.severity === sev && state.selected.has( f.id ); } );
			if ( ! items.length ) { return; }
			var lines = [ sev.toUpperCase() + ' (' + items.length + ')' ];
			var prevHadDetail = false;
			items.forEach( function ( f, idx ) {
				var detail = [];
				if ( showFile ) {
					if ( f.file ) { detail.push( '  File: ' + f.file ); }
					( f.lines || [] ).slice( 0, max ).forEach( function ( l ) {
						detail.push( '  Line ' + l.line + ': ' + l.text );
					} );
					if ( ( f.lines || [] ).length > max ) {
						detail.push( '  ... and ' + ( f.lines.length - max ) + ' more' );
					}
				}
				if ( idx > 0 && ( detail.length || prevHadDetail ) ) { lines.push( '' ); }
				lines.push( '- ' + collapse( f.text ) );
				lines = lines.concat( detail );
				prevHadDetail = detail.length > 0;
			} );
			groups.push( lines.join( '\n' ) );
		} );
		return groups.join( '\n\n' );
	}

	function buildMessage() {
		var c = counts();
		var notes = String( state.notes || '' ).trim();
		var fields = [ tpl.greeting, tpl.intro, tpl.notes_heading, tpl.footer ];
		var usesFindings = fields.some( function ( s ) { return String( s || '' ).indexOf( '{findings}' ) !== -1; } );
		var block = findingsBlock();
		var sub = function ( s ) { return substitute( s, c, notes ).replace( /\{findings\}/g, block ); };
		var parts = [ sub( tpl.greeting ), sub( tpl.intro ) ];
		if ( ! usesFindings ) { parts.push( block ); }
		if ( notes && String( tpl.intro + tpl.footer + tpl.greeting ).indexOf( '{reviewer_notes}' ) === -1 ) {
			parts.push( sub( tpl.notes_heading ) + '\n' + notes );
		}
		parts.push( sub( tpl.footer ) );
		return parts.filter( function ( p ) { return p && p.trim(); } ).join( '\n\n' ).replace( /\n{3,}/g, '\n\n' ).replace( /[ \t]+\n/g, '\n' );
	}

	function setPreview( text ) {
		muted = true;
		els.preview.value = text;
		state.preview = text;
		muted = false;
	}

	function updateStatus() {
		var c = counts();
		var s = i18n.status || '%1$s findings selected · %2$s characters';
		els.status.textContent = s.replace( '%1$s', c.total ).replace( '%2$s', els.preview.value.length );
		if ( els.authorHint ) { els.authorHint.classList.toggle( 'tc-hint-warn', ! state.author.trim() ); }
	}

	function regenerate( force ) {
		if ( state.dirty && ! force ) {
			els.dirty.hidden = false;
			updateStatus();
			persist();
			return;
		}
		state.dirty = false;
		els.dirty.hidden = true;
		setPreview( buildMessage() );
		updateStatus();
		persist();
	}

	function persist() {
		try {
			window.sessionStorage.setItem( storageKey, JSON.stringify( {
				selected: Array.from( state.selected ),
				notes: state.notes,
				author: state.author,
				dirty: state.dirty,
				preview: state.dirty ? els.preview.value : ''
			} ) );
		} catch ( e ) { /* storage unavailable */ }
	}

	function restore() {
		try {
			var raw = window.sessionStorage.getItem( storageKey );
			if ( ! raw ) { return false; }
			var s = JSON.parse( raw );
			if ( ! s || ! Array.isArray( s.selected ) ) { return false; }
			state.selected = new Set( s.selected.filter( function ( id ) { return !! byId[ id ]; } ) );
			state.notes = s.notes || '';
			if ( ! state.author && s.author ) { state.author = s.author; }
			state.dirty = !! s.dirty;
			$$( '.tc-item-check', els.main ).forEach( function ( cb ) { cb.checked = state.selected.has( cb.value ); } );
			els.notes.value = state.notes;
			els.author.value = state.author;
			if ( state.dirty && s.preview ) {
				setPreview( s.preview );
				els.dirty.hidden = false;
			}
			return true;
		} catch ( e ) { return false; }
	}

	/* ---------- selection ---------- */

	function syncGroupCheckbox( sev ) {
		var gc = $( '.tc-group-check[data-severity="' + sev + '"]' );
		if ( ! gc ) { return; }
		var items = $$( '.tc-item[data-severity="' + sev + '"] .tc-item-check', els.main );
		var on = items.filter( function ( cb ) { return cb.checked; } ).length;
		gc.checked = on > 0 && on === items.length;
		gc.indeterminate = on > 0 && on < items.length;
	}

	function readSelection() {
		state.selected = new Set();
		$$( '.tc-item-check', els.main ).forEach( function ( cb ) {
			if ( cb.checked ) { state.selected.add( cb.value ); }
		} );
		ORDER.forEach( syncGroupCheckbox );
	}

	els.main.addEventListener( 'change', function ( ev ) {
		var t = ev.target;
		if ( t.classList.contains( 'tc-group-check' ) ) {
			var sev = t.getAttribute( 'data-severity' );
			$$( '.tc-item[data-severity="' + sev + '"] .tc-item-check', els.main ).forEach( function ( cb ) { cb.checked = t.checked; } );
		} else if ( ! t.classList.contains( 'tc-item-check' ) ) {
			return;
		}
		readSelection();
		regenerate( false );
	} );

	els.main.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-tc-select], [data-tc-toggle-evidence]' );
		if ( ! btn ) { return; }
		if ( btn.hasAttribute( 'data-tc-toggle-evidence' ) ) {
			var details = $$( 'details.tc-evidence', els.main );
			var anyClosed = details.some( function ( d ) { return ! d.open; } );
			details.forEach( function ( d ) { d.open = anyClosed; } );
			return;
		}
		var mode = btn.getAttribute( 'data-tc-select' );
		$$( '.tc-item-check', els.main ).forEach( function ( cb ) {
			var sev = cb.closest( '.tc-item' ).getAttribute( 'data-severity' );
			cb.checked = mode === 'all' ? true : mode === 'none' ? false : sev === mode;
		} );
		readSelection();
		regenerate( false );
	} );

	/* ---------- panel inputs ---------- */

	els.notes.addEventListener( 'input', function () {
		state.notes = els.notes.value;
		regenerate( true );
	} );
	els.author.addEventListener( 'input', function () {
		state.author = els.author.value;
		regenerate( true );
	} );
	els.preview.addEventListener( 'input', function () {
		if ( muted ) { return; }
		state.dirty = true;
		updateStatus();
		persist();
	} );
	els.regen.addEventListener( 'click', function () { regenerate( true ); } );
	if ( els.regenNow ) { els.regenNow.addEventListener( 'click', function () { regenerate( true ); } ); }

	/* ---------- clipboard ---------- */

	function feedback( msg ) {
		els.copyFb.textContent = msg;
		window.clearTimeout( feedback.t );
		feedback.t = window.setTimeout( function () { els.copyFb.textContent = ''; }, 4000 );
	}

	function fallbackCopy( text ) {
		els.preview.focus();
		els.preview.select();
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
		feedback( ok ? ( i18n.copied || 'Copied' ) : ( i18n.copyFailed || 'Copy failed — press Ctrl/Cmd+C' ) );
	}

	els.copy.addEventListener( 'click', function () {
		var text = els.preview.value;
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () {
				feedback( i18n.copied || 'Copied' );
			}, function () { fallbackCopy( text ); } );
		} else {
			fallbackCopy( text );
		}
	} );

	/* ---------- template editor ---------- */

	function readTemplateForm() {
		var t = {};
		$$( '[data-tpl]' ).forEach( function ( el ) {
			var k = el.getAttribute( 'data-tpl' );
			t[ k ] = el.type === 'checkbox' ? el.checked : el.value;
		} );
		t.default_included = $$( '[data-tpl-included]' ).filter( function ( cb ) { return cb.checked; } ).map( function ( cb ) { return cb.getAttribute( 'data-tpl-included' ); } );
		return t;
	}

	function writeTemplateForm( t ) {
		$$( '[data-tpl]' ).forEach( function ( el ) {
			var k = el.getAttribute( 'data-tpl' );
			if ( ! ( k in t ) ) { return; }
			if ( el.type === 'checkbox' ) { el.checked = !! t[ k ]; } else { el.value = t[ k ]; }
		} );
		$$( '[data-tpl-included]' ).forEach( function ( cb ) {
			cb.checked = ( t.default_included || [] ).indexOf( cb.getAttribute( 'data-tpl-included' ) ) !== -1;
		} );
	}

	function templateRequest( action, template ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		if ( template ) { body.append( 'template', JSON.stringify( template ) ); }
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( 'save failed' ); }
				return j.data;
			} );
	}

	$$( '[data-tpl], [data-tpl-included]' ).forEach( function ( el ) {
		el.addEventListener( 'input', function () {
			tpl = Object.assign( {}, tpl, readTemplateForm() );
			regenerate( true );
		} );
	} );

	if ( els.tplSave ) {
		els.tplSave.addEventListener( 'click', function () {
			els.tplStatus.textContent = '…';
			templateRequest( 'etc_save_message_template', readTemplateForm() ).then( function ( saved ) {
				tpl = Object.assign( {}, tpl, saved );
				writeTemplateForm( tpl );
				els.tplStatus.textContent = i18n.saved || 'Template saved.';
				regenerate( true );
			} ).catch( function () {
				els.tplStatus.textContent = i18n.saveFailed || 'Could not save the template.';
			} );
		} );
	}
	if ( els.tplReset ) {
		els.tplReset.addEventListener( 'click', function () {
			els.tplStatus.textContent = '…';
			templateRequest( 'etc_reset_message_template' ).then( function ( defaults ) {
				tpl = Object.assign( {}, defaults );
				writeTemplateForm( tpl );
				els.tplStatus.textContent = i18n.resetDone || 'Template reset to defaults.';
				regenerate( true );
			} ).catch( function () {
				els.tplStatus.textContent = i18n.saveFailed || 'Could not save the template.';
			} );
		} );
	}

	/* ---------- init ---------- */

	var restored = restore();
	if ( ! restored ) {
		readSelection();
	} else {
		ORDER.forEach( syncGroupCheckbox );
	}
	if ( ! state.dirty ) {
		setPreview( buildMessage() );
	}
	updateStatus();
	persist();
}() );
