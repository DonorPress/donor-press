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
	   // Pass ajax_url to script.js
    wp_localize_script( 'script-js', 'plugin_ajax_object',
	   array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
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
		Donor Search: <input id="donorSearch" name="dsearch" value="<?=$_GET['dsearch']?>"/><button class="button-primary" type="submit">Go</button> <button class="button-secondary" name="f" value="AddDonor">Add New Donor</button>
	</form>
	<? if (trim($_GET['dsearch'])<>''){
		$list=Donor::get(array("(UPPER(Name) LIKE '%".strtoupper($_GET['dsearch'])."%' 
		OR UPPER(Name2)  LIKE '%".strtoupper($_GET['dsearch'])."%'
		OR UPPER(Email) LIKE '%".strtoupper($_GET['dsearch'])."%'
		OR UPPER(Phone) LIKE '%".strtoupper($_GET['dsearch'])."%')","(MergedId =0 OR MergedId IS NULL)"));
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
