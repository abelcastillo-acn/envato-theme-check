<?php
/**
 * Renders check results grouped by severity plus the "Message to author" panel.
 *
 * @package Theme Check
 */

/**
 * Tags allowed inside a finding message body (unchanged from previous versions).
 */
function tc_results_kses_allowlist() {
	return array(
		'li'     => array(),
		'span'   => array( 'class' => array() ),
		'strong' => array(),
		'code'   => array(),
		'pre'    => array(),
		'a'      => array( 'href' => array() ),
	);
}

function tc_severity_order() {
	return array( 'required', 'warning', 'recommended', 'info' );
}

/**
 * Message body without the lead badge and the evidence blocks.
 */
function tc_finding_body_html( $f ) {
	$body = preg_replace( '#^\s*<span class="tc-lead[^"]*">.*?</span>:?\s*#is', '', $f['html'], 1 );
	$body = preg_replace( "#<pre class=['\"]tc-grep['\"]>.*?</pre>#s", '', $body );
	return trim( $body );
}

/**
 * Full results page section: grouped findings, message panel and data block.
 */
function tc_render_results_page( $findings, $theme, $author = '' ) {
	tc_render_results( $findings, $theme );
	tc_render_message_panel( $theme, $findings, $author );
	tc_render_findings_json( $theme, $findings, $author );
}

function tc_render_results( $findings, $theme ) {
	$tpl    = ETC_Message_Template::get();
	$groups = array_fill_keys( tc_severity_order(), array() );
	foreach ( $findings as $f ) {
		$groups[ $f['severity'] ][] = $f;
	}

	echo '<div class="tc-results-layout"><div class="tc-results-main">';

	echo '<p class="tc-summary" role="status">';
	foreach ( tc_severity_order() as $sev ) {
		printf(
			'<span class="tc-badge tc-badge-%1$s">%2$s <span class="tc-count">%3$d</span></span> ',
			esc_attr( $sev ),
			esc_html( strtoupper( $sev ) ),
			count( $groups[ $sev ] )
		);
	}
	echo '</p>';

	echo '<p class="tc-toolbar">';
	echo '<button type="button" class="button" data-tc-select="all">' . esc_html__( 'Select all', 'theme-check' ) . '</button> ';
	echo '<button type="button" class="button" data-tc-select="none">' . esc_html__( 'Select none', 'theme-check' ) . '</button> ';
	foreach ( tc_severity_order() as $sev ) {
		if ( ! empty( $groups[ $sev ] ) ) {
			printf(
				'<button type="button" class="button" data-tc-select="%1$s">%2$s</button> ',
				esc_attr( $sev ),
				/* translators: %s: severity label */
				esc_html( sprintf( __( 'Select %s', 'theme-check' ), strtoupper( $sev ) ) )
			);
		}
	}
	echo '<button type="button" class="button" data-tc-toggle-evidence>' . esc_html__( 'Expand/collapse evidence', 'theme-check' ) . '</button>';
	echo '</p>';

	foreach ( tc_severity_order() as $sev ) {
		if ( empty( $groups[ $sev ] ) ) {
			continue;
		}
		$label   = strtoupper( $sev );
		$count   = count( $groups[ $sev ] );
		$checked = in_array( $sev, $tpl['default_included'], true );

		printf( '<section class="tc-group tc-group-%1$s" data-severity="%1$s" aria-labelledby="tc-h-%1$s">', esc_attr( $sev ) );
		printf(
			'<h3 id="tc-h-%1$s" class="tc-group-title"><input type="checkbox" class="tc-group-check" id="tc-gc-%1$s" data-severity="%1$s"%4$s aria-label="%5$s"> <span class="tc-badge tc-badge-%1$s">%2$s</span> <span class="tc-count">%3$d</span></h3>',
			esc_attr( $sev ),
			esc_html( $label ),
			$count,
			$checked ? ' checked' : '',
			/* translators: %s: severity label */
			esc_attr( sprintf( __( 'Include all %s findings', 'theme-check' ), $label ) )
		);
		if ( 'info' === $sev ) {
			/* translators: %d: number of INFO findings */
			echo '<details class="tc-group-body"><summary>' . esc_html( sprintf( _n( 'Show %d INFO finding', 'Show %d INFO findings', $count, 'theme-check' ), $count ) ) . '</summary>';
		}
		echo '<ul class="tc-result">';
		foreach ( $groups[ $sev ] as $f ) {
			tc_render_finding( $f, $checked );
		}
		echo '</ul>';
		if ( 'info' === $sev ) {
			echo '</details>';
		}
		echo '</section>';
	}

	echo '</div>'; // .tc-results-main
}

function tc_render_finding( $f, $checked ) {
	$id    = $f['id'];
	$allow = tc_results_kses_allowlist();
	printf(
		'<li class="tc-item" id="%1$s" data-severity="%2$s" data-check="%3$s">',
		esc_attr( $id ),
		esc_attr( $f['severity'] ),
		esc_attr( $f['check'] )
	);
	printf(
		'<input type="checkbox" class="tc-item-check" id="tc-chk-%1$s" value="%1$s"%2$s aria-describedby="tc-msg-%1$s"><label for="tc-chk-%1$s" class="screen-reader-text">%3$s</label> ',
		esc_attr( $id ),
		$checked ? ' checked' : '',
		esc_html__( 'Include this finding in the message', 'theme-check' )
	);
	printf( '<span class="tc-lead tc-%1$s">%2$s</span>: ', esc_attr( $f['severity'] ), esc_html( $f['label'] ) );
	printf( '<span class="tc-msg" id="tc-msg-%1$s">%2$s</span>', esc_attr( $id ), wp_kses( tc_finding_body_html( $f ), $allow ) );
	if ( ! empty( $f['lines'] ) ) {
		$n = count( $f['lines'] );
		/* translators: %d: number of matching lines */
		echo '<details class="tc-evidence"><summary>' . esc_html( sprintf( _n( '%d matching line', '%d matching lines', $n, 'theme-check' ), $n ) ) . '</summary>';
		echo wp_kses( $f['evidence'], $allow );
		echo '</details>';
	}
	echo '</li>';
}

function tc_render_message_panel( $theme, $findings, $author = '' ) {
	$tpl = ETC_Message_Template::get();
	echo '<aside class="tc-message-panel" aria-labelledby="tc-message-heading">';
	echo '<h3 id="tc-message-heading">' . esc_html__( 'Message to author', 'theme-check' ) . '</h3>';

	echo '<p><label for="tc-author">' . esc_html__( 'Author username (ThemeForest)', 'theme-check' ) . '</label><br>';
	printf( '<input type="text" id="tc-author" class="regular-text" value="%s" placeholder="studioexample" autocomplete="off"> ', esc_attr( $author ) );
	echo '<span class="tc-hint" id="tc-author-hint">' . esc_html__( 'Used for {author}. Taken from the review queue when the check was started from there.', 'theme-check' ) . '</span></p>';

	echo '<p><label for="tc-reviewer-notes">' . esc_html__( 'Reviewer notes', 'theme-check' ) . '</label><br><textarea id="tc-reviewer-notes" class="large-text" rows="4"></textarea></p>';

	echo '<p><label for="tc-message-preview">' . esc_html__( 'Message preview (plain text, editable)', 'theme-check' ) . '</label><br><textarea id="tc-message-preview" class="large-text code" rows="24" spellcheck="false"></textarea></p>';
	echo '<p class="tc-preview-meta" id="tc-preview-status" aria-live="polite"></p>';
	echo '<p class="tc-dirty-notice" id="tc-dirty-notice" hidden>' . esc_html__( 'Preview was edited manually.', 'theme-check' ) . ' <button type="button" class="button-link" id="tc-regenerate-now">' . esc_html__( 'Regenerate?', 'theme-check' ) . '</button></p>';
	echo '<p><button type="button" class="button button-primary" id="tc-copy">' . esc_html__( 'Copy to clipboard', 'theme-check' ) . '</button> ';
	echo '<button type="button" class="button" id="tc-regenerate">' . esc_html__( 'Regenerate from selection', 'theme-check' ) . '</button> ';
	echo '<span class="tc-copy-feedback" id="tc-copy-feedback" role="status" aria-live="polite"></span></p>';

	echo '<details class="tc-template-editor" id="tc-template-editor"><summary>' . esc_html__( 'Edit template', 'theme-check' ) . '</summary>';
	echo '<p><label for="tc-tpl-greeting">' . esc_html__( 'Greeting', 'theme-check' ) . '</label><br><input type="text" id="tc-tpl-greeting" class="large-text" data-tpl="greeting" value="' . esc_attr( $tpl['greeting'] ) . '"></p>';
	echo '<p><label for="tc-tpl-intro">' . esc_html__( 'Intro', 'theme-check' ) . '</label><br><textarea id="tc-tpl-intro" class="large-text" rows="3" data-tpl="intro">' . esc_textarea( $tpl['intro'] ) . '</textarea></p>';
	echo '<p><label for="tc-tpl-notes-heading">' . esc_html__( 'Reviewer notes heading', 'theme-check' ) . '</label><br><input type="text" id="tc-tpl-notes-heading" class="large-text" data-tpl="notes_heading" value="' . esc_attr( $tpl['notes_heading'] ) . '"></p>';
	echo '<p><label for="tc-tpl-footer">' . esc_html__( 'Footer', 'theme-check' ) . '</label><br><textarea id="tc-tpl-footer" class="large-text" rows="4" data-tpl="footer">' . esc_textarea( $tpl['footer'] ) . '</textarea></p>';
	echo '<p>' . esc_html__( 'Included by default:', 'theme-check' ) . ' ';
	foreach ( tc_severity_order() as $sev ) {
		printf(
			'<label class="tc-inline"><input type="checkbox" data-tpl-included="%1$s"%2$s> %3$s</label> ',
			esc_attr( $sev ),
			in_array( $sev, $tpl['default_included'], true ) ? ' checked' : '',
			esc_html( strtoupper( $sev ) )
		);
	}
	echo '</p>';
	printf(
		'<p><label for="tc-tpl-evidence">%1$s</label> <input type="number" id="tc-tpl-evidence" min="0" max="20" data-tpl="evidence_max_lines" value="%2$d"> &nbsp; <label class="tc-inline"><input type="checkbox" data-tpl="show_file_line"%3$s> %4$s</label></p>',
		esc_html__( 'Evidence lines per finding', 'theme-check' ),
		(int) $tpl['evidence_max_lines'],
		$tpl['show_file_line'] ? ' checked' : '',
		esc_html__( 'Show file and line information', 'theme-check' )
	);
	echo '<p><button type="button" class="button" id="tc-template-save">' . esc_html__( 'Save template', 'theme-check' ) . '</button> <button type="button" class="button" id="tc-template-reset">' . esc_html__( 'Reset to default', 'theme-check' ) . '</button> <span id="tc-template-status" role="status" aria-live="polite"></span></p>';
	echo '<dl class="tc-placeholders">';
	foreach ( ETC_Message_Template::placeholders() as $ph => $desc ) {
		echo '<dt><code>' . esc_html( $ph ) . '</code></dt><dd>' . esc_html( $desc ) . '</dd>';
	}
	echo '</dl>';
	echo '</details>';

	echo '</aside></div>'; // .tc-message-panel, .tc-results-layout
}

function tc_render_findings_json( $theme, $findings, $author = '' ) {
	$items = array();
	foreach ( $findings as $f ) {
		$items[] = array(
			'id'       => $f['id'],
			'severity' => $f['severity'],
			'label'    => $f['label'],
			'check'    => $f['check'],
			'text'     => $f['text'],
			'file'     => $f['file'],
			'lines'    => $f['lines'],
		);
	}
	$data = array(
		'theme'    => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'slug'    => $theme->get_stylesheet(),
			'date'    => wp_date( get_option( 'date_format' ) ),
		),
		'author'   => (string) $author,
		'findings' => $items,
	);
	echo '<script type="application/json" id="tc-findings-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . '</script>';
}
