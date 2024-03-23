
<div id="pluginwrap">
	<?php
	if (Donation::request_handler()) { print "</div>"; return;} //important to do this first
	if (Donor::request_handler())  { print "</div>"; return;}
	if (DonationCategory::request_handler()) { print "</div>"; return;}
	global $donor_press_db_version;
	?>
	<h1>Donor Manager <span style="font-size:60%">Version: <?php print $donor_press_db_version;?></span></h1>
	<form method="get">
	<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>"/>
	<!-- <div class="auto-search-wrapper">
		<input type="text" id="basic" placeholder="type w">
	</div> -->
		<strong>Donor Search:</strong> <input id="donorSearch" name="dsearch" value="<?php print htmlentities(stripslashes(Donor::input('dsearch','get')))?>"/><button class="button-primary" type="submit">Go</button> <button class="button-secondary" name="f" value="AddDonor">Add New Donor</button>
	</form>
	<?php	
	/*
	?>
	<script>
		//https://tomik23.github.io/autocomplete/
		new Autocomplete("basic", {
		delay: 100,
		clearButton: true,
		howManyCharacters: 2,
		onSearch: ({ currentValue }) => {
			const api = `?donorAutocomplete=t&query=${encodeURI(
			currentValue
			)}`;
			return new Promise((resolve) => {
			fetch(api)
				.then((response) => response.json())
				.then((data) => {
				resolve(data);
				})
				.catch((error) => {
				console.error(error);
				});
			});
		},

		onResults: ({ matches }) =>
			matches.map((el) => `<li>>${el.Name}</li>`).join(""),
		}); //<a href="?page=donor-index&DonorId=${el.DonorId}"
	</script>

	<?php */
	
	if (Donor::input('dsearch','get') && trim(Donor::input('dsearch','get'))<>''){
		$search=trim(strtoupper(Donor::input('dsearch','get')));
		$list=Donor::get(array("(UPPER(Name) LIKE '%".$search."%' 
		OR UPPER(Name2)  LIKE '%".$search."%'
		OR UPPER(Email) LIKE '%".$search."%'
		OR UPPER(Phone) LIKE '%".$search."%')","(MergedId =0 OR MergedId IS NULL)"));
		//print "do lookup here...";
		if ($list){
			print Donor::show_results($list,"",['DonorId',"Name","Name2","Email","Phone","Address"]);
		}else{
			Donor::display_error("No results found for: ".stripslashes(Donor::input('dsearch','get')));
		}
				
	}?>

<form action="" method="post" enctype="multipart/form-data" style="border:1px solid gray; padding: 20px; margin-top:10px;">
	<h2>Upload CSV Donation File</h2>
  <input type="file" name="fileToUpload" accept=".csv"> 
  <input type="hidden" name="uploadGenericFile" value="true" checked/>
  <?php submit_button('Upload File','primary','submit',false) ?>
  <div><em>A file with a header row is required.</em></div>
 </form>
	
<!-- <form action="" method="post" enctype="multipart/form-data" style="border:1px solid gray; padding: 20px; margin-top:10px;">
	<h2>Paypal CSV File</h2>	
	<div><em>Note: it is recommended setting up Paypal API integration access instead</em></div>
	Import Paypal Exported File: (.csv)
<input type="file" name="fileToUpload" id="fileToUpload" accept=".csv"> 
<input type="hidden" name="uploadPaypalSummary" value="true" checked/>

<?php submit_button('Upload Paypal File','primary','submit',false) ?>
<br><a target="help" href="https://www.paypal.com/us/smarthelp/article/how-do-i-download-my-transaction-history-faq1007">Read how to download .csv transaction history from Paypal</a>	

</form> -->

