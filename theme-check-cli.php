<?php
/**
 * Run Envato Theme Check from the command line
 */
include 'checkbase.php';
include 'main.php';

class ThemeCheckCLI extends WP_CLI_Command {

    /**
     * Theme fetcher instance
     * @var \WP_CLI\Fetchers\Theme
     */
    public $fetcher;

    function __construct()
    {
        parent::__construct();
        $this->fetcher = new \WP_CLI\Fetchers\Theme;
    }
    /**
    * Show a list of the current themes
    *
    * ## OPTIONS
    *
    * [--errors=<errors>]
    * : set true to return only themes with errors, false for only without errors. Default: false
    *
    * [--allowed=<allowed>]
    * : (Multisite) set true to return only themes allowed on this site, false for only those not allowed,
    * 'site' for only site-allowed, 'network' for only network-allowed.
    *
    * [--blog_id=<id>]
    * : (Multisite) Blog ID, if different than current
    *
    * @subcommand list
    */
    public function list_themes( $args = array(), $assoc_args = array() )
    {
        $defaults       = array( 'errors' => false, 'allowed' => null, 'blog_id' => 0 );
        $args           = wp_parse_args( $assoc_args, $defaults );
        $args['errors'] = 'true' === $args['errors'];

        if ( ( 'true' == $args['allowed'] ) || ( 'false' == $args['allowed'] ) )
            $args['allowed'] = 'true' === $args['allowed'];

        $themes = wp_get_themes( $args );

        foreach ( $themes as $slug => $theme )
        {
            WP_CLI::line( $slug . ': ' . $theme->get('Name') );
        }
    }
    /**
    * Check a theme
    *
    * <theme>
    * : The theme slug to check
    *
    * [--format=<format>]
    * : set to true to format as json. Default: false
    *
    */
    public function check( $args = array(), $assoc_args = array() )
    {
        global $themechecks;

        checkcount();

        // prevent undefined index errors
        if (!array_key_exists('format', $assoc_args)) {
            $assoc_args['format'] = '';
        }

        // empty array for the json format
        $required_json    = array();
        $warnings_json    = array();
        $recommended_json = array();
        $errors_json      = array();
        $result_json      = array();

        $theme = $this->fetcher->get_check( $args[0] );

        // Same file walk as the admin page: comment-stripped PHP with preserved line numbers,
        // parent theme files included, tgm/merlin excluded.
        $success  = run_themechecks_against_theme( $theme, $theme->get_stylesheet() );
        $findings = tc_collect_results();

        $errors = array_unique( array_column( $findings, 'html' ) );
        $errors = array_map( 'strip_tags', $errors );
        rsort( $errors );

        // Structured findings for automation consumers.
        $findings_json = array();
        foreach ( $findings as $f )
        {
            $findings_json[] = array(
                'severity' => strtoupper( $f['severity'] ),
                'check'    => $f['check'],
                'message'  => $f['message'],
                'file'     => $f['file'],
                'line'     => $f['line'],
            );
        }

        // assume to pass unless we see a required or warning message.
        $pass = true;

        foreach ( $errors as $error )
        {
            $parts = explode( ':', $error, 2 );
            $type = isset( $parts[0] ) ? $parts[0] : '';
            $message = isset( $parts[1] ) ? $parts[1] : '';

            if ( 'REQUIRED' == trim( $type ) )
            {
                if ( 'true' == $assoc_args['format'] )
                {
                    array_push( $required_json, "REQUIRED: " . trim( $message ) );
                }
                else
                {
                    WP_CLI::warning( '%rREQUIRED:%n ' . trim( $message ) );
                }
                $pass = false;
            }
            elseif ( 'WARNING' == trim( $type ) )
            {
                if ( 'true' == $assoc_args['format'] )
                {
                    array_push( $warnings_json, "WARNING: " . trim( $message ) );
                }
                else
                {
                    WP_CLI::warning( '%yWARNING:%n ' . trim( $message ) );
                }
                $pass = false;

            }
            elseif ( 'RECOMMENDED' == trim( $type ) )
            {
                if ( 'true' == $assoc_args['format'] )
                {
                    array_push( $recommended_json, "RECOMMENDED: " . trim( $message ) );
                }
                else
                {
                    WP_CLI::warning( '%cRECOMMENDED:%n ' . trim( $message ) );
                }
            }
            else
            {
                if ( 'true' == $assoc_args['format'] )
                {
                    array_push( $errors_json, "ERROR: " . trim( $error ) );
                }
                else
                {
                    WP_CLI::warning( $error );
                }
            }
        }

        WP_CLI::line();

        if ( empty( $errors ) )
        {
            if ( 'true' == $assoc_args['format'] )
            {
                array_push( $result_json, "SUCCESS" );
                array_push( $result_json, "THEME PASSED REVIEW" );
            }
            else
            {
                WP_CLI::success( "THEME PASSED REVIEW" );
            }
        }
        elseif ( true === $pass )
        {
            if ( 'true' == $assoc_args['format'] )
            {
                array_push( $result_json, "SUCCESS" );
                array_push( $result_json, "THEME PASSED REVIEW WITH RECOMMENDED CHANGES" );
            }
            else
            {
                WP_CLI::success( "THEME PASSED REVIEW WITH RECOMMENDED CHANGES" );
            }
        }
        else
        {
            if ( 'true' == $assoc_args['format'] )
            {
                array_push( $result_json, "FAIL" );
                array_push( $result_json, "THEME DID NOT PASS REVIEW" );
            }
            else
            {
                WP_CLI::line( WP_CLI::colorize( "%RFAIL:%n THEME DID NOT PASS REVIEW" ) );
            }
        }

        if ( 'true' == $assoc_args['format'] )
        {
            $output = array (
                'result'      => $result_json,
                'required'    => $required_json,
                'recommended' => $recommended_json,
                'warnings'    => $warnings_json,
                'errors'      => $errors_json,
                'findings'    => $findings_json,
            );
            echo htmlspecialchars_decode( json_encode( $output, JSON_UNESCAPED_SLASHES ) );
        }
    }
    /**
    * Manage the local review queue (items imported from the ThemeForest proofing page).
    *
    * ## OPTIONS
    *
    * <subcommand>
    * : list | import | purge
    *
    * [<file>]
    * : (import) Path to a captured JSON payload (schema etc-queue/1)
    *
    * [--status=<status>]
    * : (list) etc_pending | etc_in_review | etc_done. (purge) statuses to purge, default etc_done
    *
    * [--days=<days>]
    * : (purge) Delete items last modified more than this many days ago. Default: the retention option
    *
    * [--format=<format>]
    * : (list) table | json | csv. Default: table
    *
    * ## EXAMPLES
    *
    *     wp theme review queue list --status=etc_pending
    *     wp theme review queue import capture.json
    *     wp theme review queue purge --days=30
    */
    public function queue( $args = array(), $assoc_args = array() )
    {
        $sub = isset( $args[0] ) ? $args[0] : 'list';

        if ( 'import' === $sub )
        {
            if ( empty( $args[1] ) || ! file_exists( $args[1] ) ) {
                WP_CLI::error( 'Usage: wp theme review queue import <file.json>' );
            }
            $payload = json_decode( file_get_contents( $args[1] ), true );
            $result  = ETC_Queue_Importer::import( is_array( $payload ) ? $payload : array() );
            if ( isset( $result['error'] ) ) {
                WP_CLI::error( $result['error'] );
            }
            foreach ( $result['skipped'] as $s ) {
                WP_CLI::warning( sprintf( 'Skipped %s: %s', $s['item_id'] ? $s['item_id'] : '#' . $s['index'], $s['reason'] ) );
            }
            WP_CLI::success( sprintf( '%d imported, %d updated, %d unchanged, %d skipped.', $result['imported'], $result['updated'], $result['unchanged'], count( $result['skipped'] ) ) );
            return;
        }

        if ( 'purge' === $sub )
        {
            $days     = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : ETC_Queue_Store::retention_days();
            $statuses = isset( $assoc_args['status'] ) ? array_map( 'sanitize_key', explode( ',', $assoc_args['status'] ) ) : array( 'etc_done' );
            $n        = ETC_Queue_Store::purge_older_than( $days, $statuses );
            WP_CLI::success( sprintf( '%d item(s) deleted.', $n ) );
            return;
        }

        $status = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : '';
        $q      = new WP_Query( array(
            'post_type'      => ETC_Queue_CPT::POST_TYPE,
            'post_status'    => $status ? $status : array_keys( ETC_Queue_CPT::statuses() ),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        $rows = array();
        foreach ( $q->posts as $post ) {
            $it     = ETC_Queue_Store::to_item( $post );
            $rows[] = array(
                'post_id'   => $it['post_id'],
                'item_id'   => $it['item_id'],
                'title'     => $it['title'],
                'author'    => $it['author'],
                'status'    => $it['status'],
                'theme'     => $it['theme_slug'],
                'submitted' => $it['submitted'],
            );
        }
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        WP_CLI\Utils\format_items( $format, $rows, array( 'post_id', 'item_id', 'title', 'author', 'status', 'theme', 'submitted' ) );
    }

    /**
    * Check for the active theme
    *
    * [--format=<format>]
    * : set to true to format as json. Default: false
    *
    */
    public function active( $args = array(), $assoc_args = array() )
    {
        $active_theme = wp_get_theme();
        $this->check( array( $active_theme->get_stylesheet() ), $assoc_args );
    }
}

class ThemeCheckCLILogger extends WP_CLI\Loggers\Regular {
    public function _line( $message, $label, $color, $handle = STDOUT )
    {
        if ( ! empty( $label ) )
        {
            $label = \cli\Colors::colorize( "$color$label:%n ", $this->in_color );
        }
        $this->write( $handle, "{$label}{$message}\n" );
    }

    function warning( $message )
    {
        $this->_line( WP_CLI::colorize( $message ), '', '', STDERR );
    }
}
WP_CLI::set_logger( new ThemeCheckCLILogger( true ) );

WP_CLI::add_command( 'theme review', 'ThemeCheckCLI' );
