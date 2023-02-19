<?php
/*
    Plugin Name: Donor Press - A Donation Tracking System
    Plugin URI: https://denversteiner.com/donorpress/
    Description: A plugin for non-profits used to track donations and send donation acknowledgements and year end receipts. This integrates with Paypal as well as allows for manual entry. Syncing data to Quickbooks is also in beta.
    Author: Denver Steiner
    Author URI: https://denversteiner.com/donorpress/
    Version: 0.0.2
*/
global $donor_press_db_version;
$donor_press_db_version='0.0.3';

### recommended to run "composer install" on the plugin directory to add PDF and other functionality, but not required
if (file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once __DIR__ . '/vendor/autoload.php';
}
require_once 'classes/Donation.php';
require_once 'classes/Donor.php';
require_once 'classes/DonorType.php';
require_once 'classes/DonationCategory.php';
require_once 'classes/DonationUpload.php';
require_once 'classes/DonorTemplate.php';
require_once 'classes/CustomVariables.php'; 
require_once 'classes/QuickBooks.php';
/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/
https://webdesign.tutsplus.com/tutorials/create-a-custom-wordpress-plugin-from-scratch--net-2668
*/
// it inserts the entry in the admin menu
//wp_enqueue_style( 'style', get_stylesheet_uri() );
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_tables' );



function donor_header_check() {
	global $donor_press_db_version;
	if (!session_id()) session_start();	
	
	## When Code base is updated, make sure database upgrade is run
	if (get_option( "donor_press_db_version")!=$donor_press_db_version){
		require_once "donor-upgrade.php";
		donor_press_upgrade();
	}

	if ($_GET['redirect']){
		$qb=new Quickbooks();
		$qb->check_redirects($_GET['redirect']);
	}
	## download functions before page is loaded
	switch($_REQUEST['Function']){
		case "DonationReceiptPdf":
			$donation=Donation::get_by_id($_REQUEST['DonationId']);	
			$donation->pdf_receipt(stripslashes_deep($_POST['customMessage']));
		break;	
		case 'BackupDonorPress':		
			CustomVariables::backup(true);
			break;
		break;
		case "YearEndReceiptPdf":
			$donor=Donor::get_by_id($_REQUEST['DonorId']);
			$donor->year_receipt_pdf($_REQUEST['Year'],stripslashes_deep($_REQUEST['customMessage']));
			break;
		case 'SendYearEndPdf':
			Donor::YearEndReceiptMultiple($_REQUEST['Year'],$_POST['pdf'],$_REQUEST['limit'],$_REQUEST['blankBack'],$_REQUEST['preview']?false:true);
		break;
		case 'PdfLabelDonationReceipts':			
			Donation::label_by_id($_POST['EmailDonationId'],$_POST['col'],$_POST['row'],$_REQUEST['limit']);
		break;
		case 'PrintYearEndLabels':
			Donor::YearEndLabels($_REQUEST['Year'],$_POST['pdf'],$_POST['col'],$_POST['row'],$_REQUEST['limit']);
		break;
		case 'ExportAllDonors':
			Donor::get_mail_list();
		break;
		case 'ExportDonorList':
			Donor::get_mail_list(["D.DonorId IN (".implode(",",$_POST['pdf']).")"]);
		break;
		case 'QuickbookSessionKill';
			$qb=new QuickBooks();
			$qb->clearSession();
			return header("Location: ?page=donor-quickbooks");
		break;
	}

	if ($_GET['donorAutocomplete']){
		Donor::autocomplete($_GET['query']);
		exit();
	}
	## add style
	wp_enqueue_style('donorPressPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ), false);
	//wp_enqueue_style('donorPressPluginAutoComplete', plugins_url( '/css/autocomplete.min.css', __FILE__ ), false);
	//wp_enqueue_script('donorPressPluginAutoComplete', plugins_url( '/js/autocomplete.min.js', __FILE__ ), false);
}

//wp_register_style( 'donorPressPluginStylesheet', plugins_url( '/css/style.css', __FILE__ ) );
add_action( 'admin_init', 'donor_header_check',1);


// creating the menu entries
function donor_plugin_create_menu_entry() {
	// icon image path that will appear in the menu
	$icon = plugins_url('/images/DonorPressWPIcon2.svg', __FILE__);
	//add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	// adding the main manu entry
	add_menu_page('Donors', 'Donors', 'edit_posts', 'donor-index', 'donor_show_index', $icon);
	// adding the sub menu entry
	add_submenu_page( 'donor-index', 'Reports', 'Reports', 'edit_posts', 'donor-reports', 'donor_show_reports',2 );
	add_submenu_page( 'donor-index', 'Settings', 'Settings', 'edit_posts', 'donor-settings', 'donor_show_settings',3);
	add_submenu_page( 'donor-index', 'Paypal', 'Paypal', 'edit_posts', 'donor-paypal', 'donor_show_paypal',4);
	add_submenu_page( 'donor-index', 'QuickBooks', 'QuickBooks', 'edit_posts', 'donor-quickbooks', 'donor_show_quickbooks',4);
}

// function triggered in add_menu_page
function donor_show_index() {
	include('donor-index.php');
}

// function triggered in add_submenu_page
function donor_show_reports() {
	include('donor-reports.php');
}

function donor_show_settings() {
	include('donor-settings.php');
}

function donor_show_paypal() {
	include('donor-paypal.php');
}

function donor_show_quickbooks() {
	include('donor-quickbooks.php');
}


function dn_plugin_base_dir(){
	return str_replace("\\","/",dirname(__FILE__));
}

function load_initial_data(){	
	Donor::make_receipt_year_template();
}

function donor_press_tables(){
	return ["Donor","Donation","DonationReceipt","DonationCategory","DonorType"];
}


function nuke(){
	### used in testing to wipe out everything and recreate blank
	global $wpdb;
	$wpdb->show_errors();

	$tableNames=donor_press_tables();
	foreach($tableNames as $table){
		$wpdb->query("DROP TABLE IF EXISTS ".$table::get_table_name());
	}
	donor_plugin_create_tables();
}

function donor_plugin_create_tables() {	
	global $donor_press_db_version;
	$tableNames=donor_press_tables();
	foreach($tableNames as $table){
		$table::create_table();
	}
	add_option( "donor_press_db_version", $donor_press_db_version );
}




## Search record
//add_action( 'wp_ajax_searchDonorList', 'searchDonorList_callback' );
//add_action( 'wp_ajax_nopriv_searchDonorList', 'searchDonorList_callback' );
// add_action("init", "ur_theme_start_session", 1);
// function ur_theme_start_session(){
// 	if (!session_id()) session_start();
// }

// function wpdocs_register_plugin_styles() {
// 	wp_register_style( 'donor-press', plugins_url( 'donor-press/css/style.css' ) );
// 	wp_enqueue_style( 'donor-press' );
// }
// // Register style sheet.
// add_action( 'wp_enqueue_scripts', 'wpdocs_register_plugin_styles' );




