<?php
// let's include the feed addon framework 
GFForms::include_feed_addon_framework();

// let's create a class to interact with for importing and exporting feeds
class GFFeedImportExport extends GFAddOn {

	/*
	 * The minimum Gravity Forms version for importing
	 *
	 * @var string
	 */
	private static $min_import_version = '2.1.0.1';
	
	/**
	 * This is the constructor to instantiate the object.  When creating, we setup
	 * the necessary WordPress hooks
	 *
	 * @since 2016-11-05
	 */
	public function __construct() {
		// let's add the menu items
		add_filter( 'gform_export_menu', array($this, 'add_gf_import_export_menu_items'), 10, 1);
		// let's add new tooltips to Gravity Forms
		add_filter( 'gform_tooltips', array($this, 'add_new_gravityforms_tooltips'), 10, 1);
		// let's add the HTML for exporting feeds
		add_action('gform_export_page_export_feeds', array($this, 'export_feeds_html'));
		// let's add the HTML for the importing feeds
		add_action('gform_export_page_import_feeds', array($this, 'import_feeds_html'));
		// let's check to see if the user is trying to export any feeds
		$this->maybe_export();
		// let's check to see if the user is trying to import any feeds
		$this->maybe_import();
	}
	
	/**
	 * This is called as a filter to the Gravity Forms export menu.  Let's add our two
	 * new options to Export Feeds and Import Feeds
	 *
	 * @param string $settings_tabs gform_export_menu's settings object
	 *
	 * @since 2016-11-05
	 */
	public function add_gf_import_export_menu_items($settings_tabs) {
		if ( GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
			$settings_tabs[25] = array( 'name' => 'export_feeds', 'label' => __( 'Export Feeds', 'gf-import-export-feeds' ) );
			$settings_tabs[45] = array( 'name' => 'import_feeds', 'label' => __( 'Import Feeds', 'gf-import-export-feeds' ) );
		}
		
		return $settings_tabs;
	}

	/**
	 * This will return all of the feeds registered within Gravity Forms.  If a $feed_id is provided
	 * only that singular feed is returned.
	 *
	 * @param integer $feed_id The feed ID to get
	 *
	 * @since 2016-11-05
	 */
	public static function get_feeds( $feed_id = null ) {
		global $wpdb;

		$form_filter = is_numeric( $feed_id ) ? $wpdb->prepare( 'WHERE id=%d', absint( $feed_id ) ) : '';

		$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}gf_addon_feed {$form_filter}", ARRAY_A );
		foreach ( $results as &$result ) {
			$result['meta'] = json_decode( $result['meta'], true );
		}

		return $results;
	}

	/**
	 * This is called immediately after instantiating the object. We want to check to
	 * see if the user is trying to export anything.
	 *
	 * @since 2016-11-05
	 */
	public static function maybe_export() {
		if ( isset( $_POST['export_feeds'] ) ) {
			check_admin_referer( 'gf_export_feeds', 'gf_export_feeds_nonce' );
			$selected_feeds = rgpost( 'gf_feed_id' );
			if ( empty( $selected_feeds ) ) {
				GFCommon::add_error_message( __( 'Please select the feeds to be exported', 'gf-import-export-feeds' ) );

				return;
			}

			self::export_feeds( $selected_feeds );
		}
	}
	
	/**
	 * This is called immediately after instantiating the object. We want to check to
	 * see if the user is trying to import anything.
	 *
	 * @since 2016-11-05
	 */
	public static function maybe_import() {
		if ( isset( $_POST['import_feeds'] ) ) {
			check_admin_referer( 'gf_import_feeds', 'gf_import_feeds_nonce' );
		
			if ( ! empty( $_FILES['gf_import_file']['tmp_name'] ) ) {
				$count = self::import_feeds( file_get_contents( $_FILES['gf_import_file']['tmp_name'] ) );
		
				if ( $count == 0 ) {
					GFCommon::add_error_message( __( 'Feeds could not be imported. Please make sure your export file is in the correct format.', 'gf-import-export-feeds' ) );
				} else if ( $count == '-1' ) {
					GFCommon::add_error_message( __( 'Feeds could not be imported. Your export file is not compatible with your current version of Gravity Forms.', 'gf-import-export-feeds' ) );
				} else {
					$feed_text = $count > 1 ? __( 'feeds', 'gf-import-export-feeds' ) : __( 'feed', 'gf-import-export-feeds' );
					GFCommon::add_message( sprintf( __( "Gravity Forms imported %d {$feed_text} successfully.", 'gf-import-export-feeds' ), $count ) );
				}
			}
		}
	}

	/**
	 * This will generate a JSON  export file to the browser based on the series of
	 * feed IDs provided. 
	 *
	 * @param array $feed_ids The list of feed IDs to export
	 *
	 * @since 2016-11-05
	 */
	public static function export_feeds( $feed_ids ) {

		$feeds = self::prepare_feeds_for_export( $feed_ids );

		$feeds['version'] = GFForms::$version;
		$feeds_json       = json_encode( $feeds );

		$filename = 'gravityforms-export-feeds-' . date( 'Y-m-d' ) . '.json';
		header( 'Content-Description: File Transfer' );
		header( "Content-Disposition: attachment; filename=$filename" );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );
		echo $feeds_json;
		die();
	}

	/**
	 * This will return all of the feeds registered within Gravity Forms.  If a $feed_id is provided
	 * only that singular feed is returned.
	 *
	 * @param integer $feed_id The feed ID to get
	 *
	 * @return array
	 * 
	 * @since 2016-11-05
	 */
	public static function prepare_feeds_for_export( $feed_ids ) {
		$feeds_for_export = array();
		
		foreach($feed_ids as $feed_id) {
			$feeds_for_export[] = self::get_feeds($feed_id);
		}

		return $feeds_for_export;
	}

	/**
	 * This will import feeds into Gravity Forms based on the feeds JSON
	 *
	 * @param integer $feed_id The feed ID to get
	 *
	 * @return array
	 *
	 * @since 2016-11-05
	 */
	public static function import_feeds( $feeds_json ) {

		$feeds = json_decode( $feeds_json, true );

		if ( ! $feeds ) {
			GFCommon::log_debug( __METHOD__ . '(): Import Failed. Invalid feed objects.' );

			return 0;
		} else if ( version_compare( $feeds['version'], self::$min_import_version, '<' ) ) {
			GFCommon::log_debug( __METHOD__ . '(): Import Failed. The JSON version is not compatible with the current Gravity Forms version.' );

			return - 1;
		} //Error. JSON version is not compatible with current Gravity Forms version

		unset( $feeds['version'] );

		$feed_ids = self::add_feeds($feeds);
		
		if ( is_wp_error( $feed_ids ) ) {
			GFCommon::log_debug( __METHOD__ . '(): Import Failed => ' . print_r( $feed_ids, 1 ) );
			$feed_ids = array();
		}

		return sizeof( $feed_ids );
	}
	
	/**
	 * This adds feeds to Gravity Forms. Will return an array of new feed IDs
	 *
	 * @param array $feeds This is the array of feed data
	 * 
	 * @return array
	 * 
	 * @since 2016-11-05
	 */
	public static function add_feeds( $feeds ) {
		global $wpdb;
		$imported_addon_feeds = array();
		
		foreach($feeds as $feed) {
			$imported_addon_feeds[] = $wpdb->insert($wpdb->prefix . 'gf_addon_feed',
				array(
					'form_id' => $feed[0]['form_id'],
					'is_active' => $feed[0]['is_active'],
					'meta' => json_encode($feed[0]['meta']),
					'addon_slug' => $feed[0]['addon_slug'],
					'feed_order' => $feed[0]['feed_order'],
				),
				array(
					'%d',
					'%d',
					'%s',
					'%s',
					'%d',
				)
			);
		}
		
		return $imported_addon_feeds;
	}
	
	/**
	 * This generates the HTML for exporting feeds.
	 *
	 * @since 2016-11-05
	 */
	public function export_feeds_html() {

		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
			wp_die( 'You do not have permission to access this page' );
		}
		
		GFExport::page_header( __( 'Export Feeds', 'gf-import-export-feeds' ) );
		?>
		<p class="textleft">
			<?php esc_html_e( 'Select the feeds you would like to export. When you click the download button below, We will create a JSON file for you to save to your computer. Once you\'ve saved the download file, you can use the Import tool to import the feeds.', 'gf-import-export-feeds' ); ?>
		</p>
		<div class="hr-divider"></div>
		<form id="gform_export" method="post" style="margin-top:10px;">
			<?php wp_nonce_field( 'gf_export_feeds', 'gf_export_feeds_nonce' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="export_fields"><?php esc_html_e( 'Select Feeds', 'gf-import-export-feeds' ); ?></label> <?php gform_tooltip( 'export_select_feeds' ) ?>
					</th>
					<td>
						<ul id="export_form_list">
							<?php
							// get all feed addons
							$feed_addons = $this->_get_feed_addon_titles();
							
							// let's create a list of all the feeds for export
							foreach ( $this->get_feeds() as $feed ) { 
								if(empty($feed['meta']))
									continue;
								
								$form = RGFormsModel::get_form_meta( $feed['form_id'] );
								?>
								<li>
									<input type="checkbox" name="gf_feed_id[]" id="gf_feed_id[] echo absint( $feed['id'] ) ?>" value="<?php echo absint( $feed['id'] ) ?>" />
									<label for="gf_feed_id[] echo absint( $feed['id'] ) ?>"><strong><?php echo esc_html( $feed['meta']['feedName'] ) ?></strong> for <em><?php echo esc_html( $form['title'] ) ?></em> form (Add-on: <?php echo esc_html( $feed_addons[$feed['addon_slug']] ) ?>)</label>
								</li>
							<?php
							}
							?>
						</ul>
					</td>
				</tr>
			</table>
			<br /><br />
			<input type="submit" value="<?php esc_attr_e( 'Download Export File', 'gf-import-export-feeds' ) ?>" name="export_feeds" class="button button-large button-primary" />
		</form>
		<?php
		GFExport::page_footer();
	}
	
	/**
	 * This returns all of the addons organized by slug and title
	 *
	 * @return array
	 * 
	 * @since 2016-11-05
	 */
	protected function _get_feed_addon_titles() {
		$addons_by_slug = array();
		
		foreach($this->get_registered_addons() as $registered_addons) {
			$tempAddOn = new $registered_addons();
			$addons_by_slug[$tempAddOn->get_slug()] = $tempAddOn->get_short_title();
		}
		
		return $addons_by_slug;
	}
	
	/**
	 * This generates the HTML for imorting feeds.
	 *
	 * @since 2016-11-05
	 */
	public function import_feeds_html() {
		if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
			wp_die( 'You do not have permission to access this page' );
		}
		
		GFExport::page_header( __( 'Import Feeds', 'gf-import-export-feeds' ) );
		?>
		<p class="textleft">
			<?php esc_html_e( 'Select the Gravity Form Feeds export file you would like to import. When you click the import button below, We will import the feeds.', 'gf-import-export-feeds' ); ?>
		</p>
		<div class="hr-divider"></div>
		<form method="post" enctype="multipart/form-data" style="margin-top:10px;">
			<?php wp_nonce_field( 'gf_import_feeds', 'gf_import_feeds_nonce' ); ?>
			<table class="form-table">
				<tr valign="top">

					<th scope="row">
						<label for="gf_import_file"><?php esc_html_e( 'Select File', 'gf-import-export-feeds' ); ?></label> <?php gform_tooltip( 'import_select_file' ) ?>
					</th>
					<td><input type="file" name="gf_import_file" id="gf_import_file" /></td>
				</tr>
			</table>
			<br /><br />
			<input type="submit" value="<?php esc_html_e( 'Import', 'gf-import-export-feeds' ) ?>" name="import_feeds" class="button button-large button-primary" />
		</form>
		<?php
		GFExport::page_footer();
	}
	
	/**
	 * This adds a new tooltip to Gravity Forms for exporting feeds
	 *
	 * @since 2016-11-05
	 */
	public function add_new_gravityforms_tooltips($tooltips) {
		$tooltips['export_select_feeds']  = '<h6>' . __( 'Export Selected Feeds', 'gf-import-export-feeds' ) . '</h6>' . __( 'Select the feeds you would like to export.', 'gf-import-export-feeds' );
		return $tooltips;
	}
}

?>