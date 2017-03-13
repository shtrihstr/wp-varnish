<?php
/*
Plugin Name: Varnish
Description: WordPress plugin for flushing Varnish cache
Version: 1.2
Plugin URI: https://github.com/shtrihstr/wp-varnish
Author: Oleksandr Strikha
Author URI: https://github.com/shtrihstr
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

require_once __DIR__ . '/class-varnish-purge.php';

$GLOBALS['varnish_purge'] = new Varnish_Purge();

add_action( 'muplugins_loaded', function() {

    if ( function_exists( 'flush_cache_add_button' ) ) {

        flush_cache_add_button( __( 'Varnish cache' ), function() {
            do_action( 'varnish_flush_all' );
        } );
    }
} );


