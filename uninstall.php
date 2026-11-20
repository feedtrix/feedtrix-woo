<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'feedtrix_settings' );
delete_option( 'feedtrix_account' );
delete_option( 'feedtrix_connect_nonce' ); // older versions (sign-up / Google from the plugin)
delete_transient( 'feedtrix_remote_config' );
delete_option( 'feedtrix_bot_hits' );
delete_option( 'feedtrix_llms_txt_stale' );
delete_option( 'feedtrix_version' );
delete_option( 'feedtrix_bot_hits_sent_at' );
delete_transient( 'feedtrix_llms_txt' );
delete_transient( 'feedtrix_llms_txt_full' );
delete_option( 'feedtrix_llms_txt_stale_full' );
for ( $feedtrix_page = 1, $feedtrix_pages = (int) get_option( 'feedtrix_llms_full_pages', 0 ); $feedtrix_page <= $feedtrix_pages; ++$feedtrix_page ) {
	delete_transient( 'feedtrix_llms_txt_full-' . $feedtrix_page );
}
delete_option( 'feedtrix_llms_full_pages' );
delete_option( 'feedtrix_jsonld_generation' );
wp_clear_scheduled_hook( 'feedtrix_send_bot_hits' );
