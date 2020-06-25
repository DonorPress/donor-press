<?php
	//error_reporting(E_ALL & ~E_NOTICE);
/*
    Plugin Name: Donor Tracker With Paypal
    Plugin URI: https://denversteiner.com
    Description: A plugin for non-profits used to track donations. This integrates with Paypal as well as allows for manual entry.
    Author: Denver Steiner
    Author URI: https://denversteiner.com/wp-plugins/donorTracker
    Version: 1.0.0
*/

/* Resources: 
https://www.sitepoint.com/working-with-databases-in-wordpress/

*/
// it inserts the entry in the admin menu
add_action('admin_menu', 'donor_plugin_create_menu_entry');
register_activation_hook( __FILE__, 'donor_plugin_create_db' );

// creating the menu entries
function donor_plugin_create_menu_entry() {
	// icon image path that will appear in the menu
	$icon = plugins_url('/images/empy-plugin-icon-16.png', __FILE__);
	//add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
	// adding the main manu entry
	add_menu_page('Donors', 'Donors', 'edit_posts', 'donor-index', 'donor_show_index', $icon);
	// adding the sub menu entry
	add_submenu_page( 'donor-index', 'Add New', 'Add New', 'edit_posts', 'add-edit-donor-plugin', 'donor_plugin_add_another_page' );
}

// function triggered in add_menu_page
function donor_show_index() {
	include('donor-index.php');
}

// function triggered in add_submenu_page
function donor_plugin_add_another_page() {
	include('another-page-donor-plugin.php');
}


function reportTop($top=20){
	global $wpdb,$wp;
	$where=[];
	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";
	$results = $wpdb->get_results("SELECT `Name`,SUM(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation FROM ".getTableName()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." Group BY `Name` Order BY SUM(`Gross`) DESC, COUNT(*) DESC LIMIT ".$top);
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?=$_GET['page']?>" />
			<h3>Top <input type="number" name="topL" value="<?=($_GET['topL']?$_GET['topL']:$top)?>" style="width:50px;"/> Donor Report From <input type="date" name="topDf" value="<?=$_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?=$_GET['topDt']?>"/> <button type="submit">Go</button></h3>
		<table border=1><tr><th>Name</th><th>Total</th><th>Count</th><th>First Donation</th><th>Last Donation</th>
		<?
		foreach ($results as $r){
			?><tr><td><?=$r->Name?></td><td align=right><?=$r->Total?></td><td align=right><?=$r->Count?></td><td align=right><?=$r->FirstDonation?></td><td align=right><?=$r->LastDonation?></td></tr><?
		}
		?></table></form><?
	}
}

function reportMonthly(){

	global $wpdb,$wp;
	$where=["Gross>0","Currency='USD'","Status='Completed'"];
	//,"`Type` IN ('Subscription Payment','Donation Payment','Website Payment')"
	if ($_GET['report']=="reportMonthly"&&$_GET['view']=='detail'){
		if ($_GET['month']){
			$where[]="EXTRACT(YEAR_MONTH FROM `Date`)='".addslashes($_GET['month'])."'";
		}
		if ($_GET['type']){
			$where[]="`Type`='".addslashes($_GET['type'])."'";
		}

		$SQL="Select * From ".getTableName()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1");
		//print $SQL;
		$results = $wpdb->get_results($SQL);
		//print "<pre>"; print_r($results); print "</pre>";
		?><table border=1><?
			$i=0;
			foreach($results as $r){
				if ($i==0){
					?><tr><?
					foreach ($r as $c=>$v){?><th><?=$c?></th><? }
					?></tr><?
				}
				?><tr><?
				foreach ($r as $c=>$v){?><td><?=$v?></td><? }
				?></tr><?
				$i++;
			}
		?></table><?
		return;
		
	}


	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";

	$SQL="SELECT EXTRACT(YEAR_MONTH FROM `Date`) as Month, `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".getTableName()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."
	Group BY  EXTRACT(YEAR_MONTH FROM `Date`), `Type`";

	//print $SQL;
	$results = $wpdb->get_results($SQL);
	if (sizeof($results)>0){
		foreach ($results as $r){
			$graph['Total'][$r->Month][$r->Type]+=$r->Total;
			$graph['Count'][$r->Month][$r->Type]+=$r->Count;
			$graph['Type'][$r->Type]+=$r->Total;
		}
		?>
		  <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
  <script type="text/javascript">
    google.charts.load("current", {packages:['corechart']});
    google.charts.setOnLoadCallback(drawMonthlyChart);
    function drawMonthlyChart() {
		var data = google.visualization.arrayToDataTable([
        ['Type', '<?=implode("', '",array_keys($graph['Type']))?>', { role: 'annotation' } ]
		<?php
		foreach($graph['Total'] as $date=>$types){
			print ", ['".$date."',";
			foreach($graph['Type'] as $type=>$total){
				print ($types[$type]?$types[$type]:0).", ";
			}
			print "'']\r\n";
		}

		?>
      ]);

      var options = {
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,
      };
	
	  var chart = new google.visualization.ColumnChart(document.getElementById("MonthlyDonationsChart"));
	  chart.draw(data, options);

	}
	</script>
<form method="get" action=""><input type="hidden" name="page" value="<?=$_GET['page']?>" />
			<h3>Monthly Donations Report From <input type="date" name="topDf" value="<?=$_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?=$_GET['topDt']?>"/> <button type="submit">Go</button></h3>
		<table border=1><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?
		foreach ($results as $r){
			?><tr><td><?=$r->Month?></td><td><?=$r->Type?></td><td align=right><a href="?page=<?=$_GET['page']?>&report=reportMonthly&view=detail&month=<?=$r->Month?>&type=<?=urlencode($r->Type)?>"><?=number_format($r->Total,2)?></a></td><td align=right><?=$r->Count?></td></tr><?
		}
		?></table></form>
		<div id="MonthlyDonationsChart" style="width: 1200px; height: 500px;"></div>
		
		<?
	}
}



function csv_read_file($file,$firstLineColumns=true){//2019-2020-05-23.CSV
	$dbHeaders=getTableColumns();
	$headerRow=getPaypalCSVColumns(); 
	$row=0;
	$q=[];
	$csvFile=plugins_url('/uploads/'.$file, __FILE__);
	if (($handle = fopen($csvFile, "r")) !== FALSE) {
		while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
			if ($firstLineColumns && $row==0){
				$headerRow= preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $data);
				//$headerRow=$data;
				//print json_encode($headerRow); exit();			
			}else{
				$entry=[];
				for ($c=0; $c < sizeof($data); $c++) {
					$fieldName=preg_replace("/[^a-zA-Z0-9]+/", "", $headerRow[$c]);//str_replace(" ","",$headerRow[$c]);
					
					if (in_array($fieldName,$dbHeaders)){
						$v=$data[$c];
						switch($fieldName){
							case "Date":
								$v=date("Y-m-d",strtotime($v));
							break;
							case "Gross":
							case "Fee":
							case "Net":
							case "Balance":
								$v=str_replace(",","",$v);
							break;
						}
						$entry[$fieldName]=$v;
					}										
				}
				$q[]=$entry;
			}	
			$row++;
		}
		fclose($handle);
	}	
	return $q;
}

function q_array_insert($q){
	global $wpdb;
	if (sizeof($q)>0){		
		$iSQL=[];
		foreach ($q as $e){
			$items=[];
			foreach ($e as $f){
				$items[]="'".addslashes($f)."'";
			}
			$iSQL[]="(".implode(", ",$items).")";
		}
		$SQL="REPLACE INTO ".getTableName()." (`".implode("`,`",array_keys($q[0]))."`) VALUES ".implode(", ",$iSQL);
		print $SQL."<hr>";
		return $wpdb->query($SQL);
	}

}

function load_initial_data(){
	global $wpdb;
	$wpdb->query("TRUNCATE ".getTableName());	

	$result=csv_read_file("2015-2018.CSV",$firstLineColumns=true);
	print "<pre>"; print_r($result); print "</pre>";
	print_r(q_array_insert($result));

	$result=csv_read_file("2019-2020-05-23.CSV",$firstLineColumns=true);
	print "<pre>"; print_r($result); print "</pre>";
	print_r(q_array_insert($result));

	$result=csv_read_file("2020-05-24-06-20.CSV",$firstLineColumns=true);
	print "<pre>"; print_r($result); print "</pre>";
	print_r(q_array_insert($result));
	
	
}

function csv_upload_handle_post(){
	// First check if the file appears on the _FILES array
	if(isset($_FILES['fileToUpload'])){
			//$csv = $_FILES['fileToUpload'];

			// Use the wordpress function to upload
			// fileToUpload corresponds to the position in the $_FILES array
			// 0 means the content is not associated with any other posts
			$uploaded=media_handle_upload('fileToUpload', 0);
			// Error checking using WP functions
			if(is_wp_error($uploaded)){
					echo "Error uploading file: " . $uploaded->get_error_message();
			}else{
					echo "File upload successful!";
			}
	}
}

function getTableName(){
	global $wpdb;
	return $wpdb->prefix . 'donations';
}

function getPaypalCSVColumns(){
	return ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","From Email Address","To Email Address","Transaction ID","Address Status","Item Title","Item ID","Option 1 Name","Option 1 Value","Option 2 Name","Option 2 Value","Reference Txn ID","Invoice Number","Custom Number","Quantity","Receipt ID","Balance","Contact Phone Number","Subject","Note","Payment Source"];
}
function getTableColumns(){
	return ["Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","FromEmailAddress","ToEmailAddress","TransactionID","AddressStatus","ItemTitle","ItemID","Option1Name","Option1Value","Option2Name","Option2Value","ReferenceTxnID","InvoiceNumber","CustomNumber","Quantity","ReceiptID","Balance","ContactPhoneNumber","Subject","Note","PaymentSource"];

	//Could limit this list ["Date"=>"Date","Time"=>"Time","Name"=>"Name","Gross"=>"Amount","ToEmailAddress"=>"Email"]
}

function donor_plugin_create_db() {

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name = getTableName();

	$sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
  `Date` date NOT NULL,
  `Time` time NOT NULL,
  `TimeZone` varchar(3) DEFAULT NULL,
  `Name` varchar(29) NOT NULL,
  `Type` varchar(20) DEFAULT NULL,
  `Status` varchar(9) DEFAULT NULL,
  `Currency` varchar(3) DEFAULT NULL,
  `Gross` float(10,2) NOT NULL,
  `Fee` decimal(6,2) DEFAULT NULL,
  `Net` varchar(10) DEFAULT NULL,
  `FromEmailAddress` varchar(33) NOT NULL,
  `ToEmailAddress` varchar(26) DEFAULT NULL,
  `TransactionID` varchar(17) DEFAULT NULL,
  `AddressStatus` varchar(13) DEFAULT NULL,
  `ItemTitle` varchar(50) DEFAULT NULL,
  `ItemID` varchar(7) DEFAULT NULL,
  `Option1Name` varchar(6) DEFAULT NULL,
  `Option1Value` varchar(7) DEFAULT NULL,
  `Option2Name` varchar(10) DEFAULT NULL,
  `Option2Value` varchar(10) DEFAULT NULL,
  `ReferenceTxnID` varchar(14) DEFAULT NULL,
  `InvoiceNumber` varchar(10) DEFAULT NULL,
  `CustomNumber` varchar(10) DEFAULT NULL,
  `Quantity` varchar(1) DEFAULT NULL,
  `ReceiptID` varchar(16) DEFAULT NULL,
  `Balance` varchar(9) DEFAULT NULL,
  `ContactPhoneNumber` varchar(11) DEFAULT NULL,
  `Subject` varchar(50) DEFAULT NULL,
  `Note` varchar(256) DEFAULT NULL,
  `PaymentSource` varchar(7) DEFAULT NULL,
  PRIMARY KEY (`Date`,`Time`,`Name`,`Gross`,`FromEmailAddress`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}