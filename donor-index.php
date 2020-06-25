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

	csv_upload_handle_post();

	//load_initial_data();
	
?>
<div id="pluginwrap"><?
	if ($_GET['view']=='detail'){
		?><h2>Detailed View: <?=$_GET['report']?></h2><?
		switch($_GET['report']){
			case "reportMonthly":
				reportMonthly();
			break;
		}
		print "</div>";
		return;
	}
?>

	<h2>Donor Tracker</h2>
	<?
		reportTop();
		reportMonthly();
	?>
	<form action="" method="post" enctype="multipart/form-data">
	<h3>Add From Paypal (.csv method)</h3>
	Import Paypal Exported File: (.csv)
  <input type="file" name="fileToUpload" id="fileToUpload" accept=".csv"> <a target="help" href="https://www.paypal.com/us/smarthelp/article/how-do-i-download-my-transaction-history-faq1007">Read how to download .csv transaction history from Paypal</a>
  <?php submit_button('Upload') ?>
  
</form>
<form action="" method="post">
	<h3>Manualy Add Donation</h3>
	<table class="form-table" role="presentation">
<tbody>
	<tr><th scope="row"><label for="Date">Date</label></th><td><input type="date" id="Date" name="Date" value="<?=date("Y-m-d")?>"/></td></tr>
	<tr><th scope="row"><label for="Name">Name</th><td><input type="text" id="Name" name="Name" value=""/></td></tr>
	<tr><th scope="row"><label for="Gross">Amount</th><td><input name="Gross" id="Gross" type="number" step='0.01' placeholder="0.00"/>
	<tr><th scope="row"><label for="FromEmailAddress">E-mail</th><td><input type="text" id="FromEmailAddress" name="FromEmailAddress" value=""/></td></tr>
	<tr><th scope="row"><label for="Note">Note</th><td><input type="text" id="Note" name="Note" value=""/></td></tr>
	<tr><th scope="row"><label for="ContactPhoneNumber">Phone #</th><td><input type="text" name="ContactPhoneNumber" id="ContactPhoneNumber" value=""/></td></tr>
	</tbody></table>

	<input type="hidden" name="Payment Source" value="Manual"/>





</form>

</div>
