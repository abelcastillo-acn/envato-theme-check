// Builds dist/queue-scraper.min.js and dist/bookmarklet.txt from queue-scraper.src.js.
// Usage: node build.js   (requires `npm install` in this folder for terser)
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, 'queue-scraper.src.js' ), 'utf8' );
const dist = path.join( __dirname, 'dist' );
fs.mkdirSync( dist, { recursive: true } );

( async () => {
	let min = src;
	try {
		const { minify } = require( 'terser' );
		const out = await minify( src, { compress: { passes: 2 }, mangle: true, format: { comments: false } } );
		min = out.code;
	} catch ( e ) {
		console.warn( 'terser not available (' + e.message + '); writing unminified code.' );
	}
	fs.writeFileSync( path.join( dist, 'queue-scraper.min.js' ), min + '\n' );
	// javascript: URL. __ETC_TARGET__ is left as a plain marker so the plugin can substitute it.
	const bookmarklet = 'javascript:' + encodeURIComponent( min ).replace( /%5F%5FETC%5FTARGET%5F%5F/g, '__ETC_TARGET__' );
	fs.writeFileSync( path.join( dist, 'bookmarklet.txt' ), bookmarklet + '\n' );
	console.log( 'dist/queue-scraper.min.js: ' + min.length + ' bytes; dist/bookmarklet.txt: ' + bookmarklet.length + ' bytes' );
} )();
