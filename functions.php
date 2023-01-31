<?php

/* set fastcgi_ops.sh path here*/
$wpfcgi = "/home/websiteuser1.com/scripts/fastcgi_ops.sh";

add_action('admin_bar_menu', 'add_item', 100);
function add_item( $admin_bar ){
  global $pagenow;
  $admin_bar->add_menu( array( 'id'=>'cache-purge','title'=>'FCGI Cache Purge','href'=>'#' ) );
  $admin_bar->add_menu( array( 'id'=>'cache-preload','title'=>'FCGI Cache Preload','href'=>'#' ) );
}

add_action( 'admin_footer', 'fastcgi_cache_purge_action_js' );
function fastcgi_cache_purge_action_js() { ?>
  <script type="text/javascript" >
     jQuery("li#wp-admin-bar-cache-purge .ab-item").on( "click", function() {
        var data = {
                      'action': 'fastcgi_cache_purge',
                    };

        jQuery.post(ajaxurl, data, function(response) {
           alert( response );
        });
      });
  </script> <?php
}

add_action( 'admin_footer', 'fastcgi_cache_preload_action_js' );
function fastcgi_cache_preload_action_js() { ?>
  <script type="text/javascript" >
     jQuery("li#wp-admin-bar-cache-preload .ab-item").on( "click", function() {
        var data = {
                      'action': 'fastcgi_cache_preload',
                    };

        jQuery.post(ajaxurl, data, function(response) {
           alert( response );
        });
      });
  </script> <?php
}


/* purge ajax handler function */
add_action( 'wp_ajax_fastcgi_cache_purge', 'fastcgi_cache_purge_callback' );
function fastcgi_cache_purge_callback() {
    global $wpfcgi;
    $result = shell_exec("$wpfcgi --purge");
    echo $result;
    wp_die();
}


/* preload ajax handler function */
add_action( 'wp_ajax_fastcgi_cache_preload', 'fastcgi_cache_preload_callback' );
function fastcgi_cache_preload_callback() {
    global $wpfcgi;
    $result = shell_exec("$wpfcgi --preload");
    echo $result;
    wp_die();
}

/* admin notice */
add_action( 'admin_notices', 'fcgi_admin_notice_warn');
function fcgi_admin_notice_warn() {
      global $wpfcgi;
      $result = exec("$wpfcgi --admin", $out, $status );
      if ( $status == 0 ) {
        ob_start();
        echo implode(PHP_EOL, $out);
        $elapsed = ob_get_contents();
        ob_end_clean();
        if($elapsed) {
            echo "<div class='notice notice-warning is-dismissible'>
            <p>Important: FastCGI cache preload is completed in $elapsed !</p>
            </div>";
        } else {
            echo "<div class='notice notice-warning is-dismissible'>
            <p>Important: FastCGI cache preload is completed !</p>
            </div>";
        }
      }
}
