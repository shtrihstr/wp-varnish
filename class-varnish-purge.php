<?php

defined( 'VARNISH_SECRET' ) || define( 'VARNISH_SECRET', 'SECRET KEY' );

class Varnish_Purge
{
    const VARNISH_PURGE_HOST_KEY = 'obj.http.X-Purge-Host';
    const VARNISH_PURGE_URL_KEY = 'obj.http.X-Purge-URL';

    protected function _send_purge_request( $regex )
    {
        $request_args = [
            'timeout' => 0.3,
            'blocking' => false,
            'body' => [
                'regex' => $regex,
                'secret' => VARNISH_SECRET,
            ],
        ];

        wp_remote_post( get_home_url(), $request_args );
    }

    protected function _purge( $regex )
    {
        $regex_hosts = [];
        foreach( $this->_get_blog_hosts() as $host ) {
            $regex_hosts[] = '^(www\.)?' . $this->_string_regex( $host ) . '$';
        }
        $regex_hosts = array_unique( $regex_hosts );

        $ban = static::VARNISH_PURGE_HOST_KEY . ' ~ (' . implode( '|', $regex_hosts ) . ') && ' . $regex;
        $this->_send_purge_request( $ban );
    }


    protected function _get_blog_hosts()
    {
        if ( false === ( $hosts = wp_cache_get( 'hosts', 'varnish' ) ) ) {

            $hosts = [
                parse_url( home_url(), PHP_URL_HOST ),
            ];

            if( defined( 'DOMAIN_MAPPING' ) ) {
                global $wpdb, $blog_id;
                $domains1 = $wpdb->get_col( "SELECT domain FROM {$wpdb->blogs} WHERE blog_id = '$blog_id'" );
                $mapping = $wpdb->base_prefix . 'domain_mapping';
                $domains2 = $wpdb->get_col( "SELECT domain FROM {$mapping} WHERE blog_id = '$blog_id'" ) ;
                $hosts = array_merge( $hosts, $domains1, $domains2 );
            }

            foreach( $hosts as $key => $host ) {
                $hosts[ $key ] = preg_replace( '/^www\./' , '', $host );
            }
            $hosts = array_unique( $hosts );

            wp_cache_set( 'hosts', $hosts, 'varnish', HOUR_IN_SECONDS );
        }
        return $hosts;
    }

    protected function _string_regex( $string )
    {
        return preg_quote( $string );
    }

    protected function _get_path_purge_regex( $path, $matching = '~' )
    {
        return static::VARNISH_PURGE_URL_KEY . ' ' . $matching . ' ' . $path;
    }

    protected function _get_url_path( $url )
    {
        return parse_url( $url, PHP_URL_PATH );
    }

    protected function _param_to_regex( $key, $value )
    {
        return '(\?|&)' . $this->_string_regex( $key ) . '=' . $this->_string_regex( $value ) . '(&|$)';
    }

    public function purge_home()
    {
        $regex = $this->_get_path_purge_regex( '/', '==' );
        $this->_purge( $regex );
    }

    public function purge_url( $url )
    {
        $path = $this->_string_regex( $this->_get_url_path( $url ) );
        $regex = $this->_get_path_purge_regex( '^' . $path );
        $this->_purge( $regex );
    }

    public function purge_post( $post_id )
    {
        $this->_purge_post( $post_id );

        $post_type = get_post_type( $post_id );
        $this->purge_post_type_archive( $post_type );

        $taxonomies = (array) get_object_taxonomies( $post_type );
        foreach( $taxonomies as $taxonomy ) {
            $ids = (array) wp_get_post_terms( $post_id, $taxonomy, ['fields' => 'ids'] );
            foreach( $ids as $id )  {
                $this->purge_term( $id, $taxonomy );
            }
        }

        $this->purge_rss();
        if( 'post' == $post_type ) {
            $this->purge_time_archive();
            $this->purge_home();
        }
    }

    protected function _purge_post( $post_id )
    {
        $url = get_permalink( $post_id );
        if( ! $url ) {
            return;
        }
        $this->purge_url( $url );
    }

    public function purge_term( $term_id, $taxonomy )
    {
        $url = get_term_link( $term_id, $taxonomy );
        if( ! $url ) {
            return;
        }
        $this->purge_url( $url );
    }

    public function purge_ajax( $action, array $attr = [] )
    {
        $path = $this->_string_regex( 'admin-ajax.php' ) . '*' . $this->_param_to_regex( 'action', $action );
        $regex = $this->_get_path_purge_regex( $path );
        foreach( $attr as $key => $value) {
            $regex .= ' && ' . $this->_get_path_purge_regex( $this->_param_to_regex( $key, $value ) );
        }
        $this->_purge( $regex );
    }

    public function purge_post_type_archive( $post_type )
    {
        $url = get_post_type_archive_link( $post_type );
        if( ! $url ) {
            return;
        }
        $this->purge_url( $url );
    }

    public function purge_time_archive()
    {
        $regex = $this->_get_path_purge_regex( '((\?|&)m=|(\?|&)y=|^/[0-9]{4}(/[0-9]{2})?(/[0-9]{2})?/$)' );
        $this->_purge( '( ' . $regex . ' )' );
    }

    public function purge_rss()
    {
        $regex = $this->_get_path_purge_regex( '/feed/' );
        $this->_purge( $regex );
    }

    public function purge_all()
    {
        $regex = $this->_get_path_purge_regex( '/' );
        $this->_purge( $regex );
    }

    public function register()
    {
        add_action( 'varnish_flush_url', function( $url ) {
            $this->purge_url( esc_url( $url ) );
        } );

        add_action( 'varnish_flush_home', function() {
            $this->purge_home();
        } );

        add_action( 'varnish_flush_post', function( $post_id ) {
            if( empty( $post_id ) ) {
                return;
            }
            $this->purge_post( $post_id );
        } );

        add_action( 'varnish_flush_term', function( $term_id, $taxonomy ) {
            if( empty( $term_id ) || empty( $taxonomy ) ) {
                return;
            }
            $this->purge_term( $term_id, $taxonomy );
        }, 0, 2 );

        add_action( 'varnish_flush_ajax', function( $action, $attr ) {
            if( empty( $action ) ) {
                return;
            }
            if( empty( $attr ) ) {
                $attr = [];
            }
            $this->purge_ajax( $action, $attr );
        }, 0, 2 );

        add_action( 'varnish_flush_post_type_archive', function( $post_type ) {
            if( empty( $post_type ) ) {
                return;
            }
            $this->purge_post_type_archive( $post_type );
        } );

        add_action( 'varnish_flush_time_archive', function() {
            $this->purge_time_archive();
        } );

        add_action( 'varnish_flush_rss', function() {
            $this->purge_rss();
        } );

        add_action( 'varnish_flush_all', function() {
            $this->purge_all();
        }  );

        add_action( 'varnish_no_cache', function() {
            if ( ! did_action( 'send_headers' ) ) {
                @header( 'X-Varnish-No-Cache: true' );
            };
        } );

        $this->_register_default_purges();
    }

    protected function _register_default_purges()
    {
        add_action( 'save_post', function( $post_id ) {
            $this->purge_post( $post_id );
        } );

        // todo: theme switch, menu updated, etc
    }
}

