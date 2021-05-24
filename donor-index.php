<style>
@media print
{    
    #adminmenumain, #wpadminbar, .no-print, .no-print *
    {
        display: none !important;
    }
	#pluginwrap { background-color:white;}
}
</style>
<?php
	//print Donor::DisplayNotice(DonationCategory::getCategoryId("Donation to Mas Mariposas Food Relief Project"));

	/*
	 * Be very careful were you place wp_enqueue_style and wp_enqueue_script. 
	 * If you write those two functions in the beginning of an administration
	 * page (eg: main-page-empty-plugin.php) you include the CSS and the JS
	 * file only in that administration page.
	 * But if you write those two functions in the main plugin file you will
	 * include the CSS/JS file in all the pages of your website.
	 */
	// loading the CSS
	wp_enqueue_style('empty-plugin-style', plugins_url( '/css/style.css', __FILE__ ) );
	// loading the JS
	wp_enqueue_script('empty-plugin-scripts', plugins_url( '/js/scripts.js', __FILE__ ) );
	?>
	<div id="pluginwrap">
	<?php
	if (Donation::RequestHandler()) { print "</div>"; return;} //important to do this first
	if (Donor::RequestHandler())  { print "</div>"; return;}
	if (DonationCategory::RequestHandler()) { print "</div>"; return;}
	//load_initial_data();
	?>
	<h2>Donor Tracker</h2>
	<form method="get">
		<input type="hidden" name="page" value="<?=$_GET['page']?>"/>
		Donor Search: <input name="dsearch" value="<?=$_GET['dsearch']?>"/><button type="submit">Go</button> <a href="?page=<?=$_GET['page']?>&f=AddDonor"> Add New Donor</a>
	</form>
	<? if (trim($_GET['dsearch'])<>''){
		$list=Donor::get(array("(Name LIKE '%".$_GET['dsearch']."%' 
		OR Name2  LIKE '%".$_GET['dsearch']."%'
		OR Email LIKE '%".$_GET['dsearch']."%'
		OR Phone LIKE '%".$_GET['dsearch']."%')"));
		//print "do lookup here...";
		print Donor::showResults($list);
		
	}?>

	<form action="" method="post" enctype="multipart/form-data">
	<h3>Add From Paypal (.csv method)</h3>
	Import Paypal Exported File: (.csv)
  <input type="file" name="fileToUpload" id="fileToUpload" accept=".csv"> 
  <input type="hidden" name="uploadSummary" value="true" checked/>

  <?php submit_button('Upload','primary','submit',false) ?>
  <a target="help" href="https://www.paypal.com/us/smarthelp/article/how-do-i-download-my-transaction-history-faq1007">Read how to download .csv transaction history from Paypal</a>
 
  
</form>

<form action="" method="post" enctype="multipart/form-data">
	<h3>Upload From Template (Non Paypal)</h3>
  <input type="file" name="fileToUpload" id="fileToUpload" accept=".csv"> 
  <input type="hidden" name="uploadSummary" value="true" checked/>
  <!--<label><input type="checkbox" name="nuke" value="true"/> Purge DB</label>-->
  <?php submit_button('Upload NonPaypal','primary','submit',false) ?>
 <a href="<?=plugin_dir_url( __FILE__ )?>uploads/SampleNonPaypalFileUpload.csv">Download Non Paypal Template</a> - Must keep this structure intact!
  
</form>


</div>
