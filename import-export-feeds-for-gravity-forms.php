<?php
/*
Plugin Name: Import/Export Add-On Feeds for Gravity Forms
Plugin URI: https://ambrdetroit.com
Description: This plugin allows you to import/export Gravity Form add-on feeds
Version: 0.1.0
Author: AMBR Detroit
Author URI: https://ambrdetroit.com
Text Domain: gf-import-export-feeds
*/

define( 'GF_IMPORTEXPORTFEEDS_VERSION', '0.1.0' );

add_action( 'gform_loaded', array( 'GF_ImportExportFeeds_Bootstrap', 'load' ), 5 );

class GF_ImportExportFeeds_Bootstrap {

	public static function load(){

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once 'GFImportExportFeeds.class.php';
		
		new GFFeedImportExport;
	}
}