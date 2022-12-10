<style>
@media print
{    
    #adminmenumain,#wpadminbar,.no-print, .no-print *
    {
        display: none !important;
    }
	body { background-color:white;}
	#wpcontent, #wpfooter{ margin-left:0px;}
	
}
</style>
<div id="pluginwrap">
	<?php
	if (Donation::request_handler()) { print "</div>"; return;} //important to do this first
	if (Donor::request_handler())  { print "</div>"; return;}
	if (DonationCategory::request_handler()) { print "</div>"; return;}
	//load_initial_data();
	?>
	
	<h1>Report Page</h1>
	<?
	if ($_GET['view']=='detail'){
		?><h2>Detailed View: <?php print $_GET['report']?></h2><?
		switch($_GET['report']){
			case "reportMonthly":
				reportMonthly();
			break;
		}
		print "</div>";
		return;
	}

	Donation::donation_upload_groups();
	?>
	
	<div><strong>Year End Tax Summaries:</strong> <?
	for($y=date("Y");$y>=date("Y")-4;$y--){
		?><a href="?page=<?php print $_GET['page']?>&f=YearSummaryList&Year=<?php print $y?>"><?php print $y?></a> | <?
	}
	
	?></div>
	<?php 
	//print_r(Donation::$tinyIntDescriptions);
	//dd(['one','two']);?>
	<form method="get"><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
	Source: <select name="PaymentSource"><?
	$paymentSource=array(0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Paypal","6"=>"ACH/Bank Transfer");
	foreach($paymentSource as $key=>$label){
		?><option value="<?php print $key?>"><?php print $key." - ".$label?></option><?
	}
	?></select>
	Year: <select name="Year">
	<?php for($y=date("Y");$y>=date("Y")-4;$y--){?>
		?><option value="<?php print $y;?>"><?php print $y;?></option>
	<?php }?>
	</select>
	

	<button name="f" value="ViewPaymentSourceYearSummary">Go</button>
	</form>	
	<?

	reportTop();
	reportCurrentMonthly();
	reportMonthly();
	?>	
</div>
<?php

function reportCurrentMonthly(){
	global $wpdb;

	$where=array("`Type` IN (5)","Date>='".date("Y-m-d",strtotime("-3 months"))."'");
	$selectedCatagories=$_GET['category']?$_GET['category']:array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$SQL="SELECT `Name`,AVG(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation FROM ".Donation::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY `Name` ORder BY AVG(`Gross`) DESC";
	$results = $wpdb->get_results($SQL);
	print $SQL;
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" /></form>
		<h2>Current Monthly Donors</h2>
		<table border=1><tr><th></th><th>Name</th><th>Monthly Give</th><th>Count</th><th>Give Day</th></tr>
		<?php $i=0;
		foreach ($results as $r){ 
			$i++;
			?><tr><td><?php print $i?></td><td><?php print $r->Name?></td><td align=right><?php print number_format($r->Total,2)?></td><td><?php print $r->Count?></td><td><?php print date("d",strtotime($r->LastDonation))?></td></tr><?
		}
		//print "<pre>"; print_r($results); print "</pre>";
		?></table><?
	}
}

function reportTop($top=20){
	global $wpdb,$wp;
	$dateFrom=$_GET['topDf'];
	$dateTo=$_GET['topDt'];

	?><form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
			<h3>Top <input type="number" name="topL" value="<?php print ($_GET['topL']?$_GET['topL']:$top)?>" style="width:50px;"/>Donor Report From <input type="date" name="topDf" value="<?php print $_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?php print $_GET['topDt']?>"/> 
			<select name='category[]' multiple>
				<?php
				$selectedCatagories=$_GET['category']?$_GET['category']:array();
				$donationCategory=DonationCategory::get(array('(ParentId=0 OR ParentId IS NULL)'),'Category');
				foreach($donationCategory as $cat){
					?><option value="<?php print $cat->CategoryId?>"<?php print in_array($cat->CategoryId,$selectedCatagories)?" selected":""?>><?php print $cat->Category?></option><?
				}
				?>
			</select>
			<button type="submit">Go</button></h3>
			<div><?
			for($y=date("Y");$y>=date("Y")-4;$y--){
				?><a href="?page=<?php print $_GET['page']?>&topDf=<?php print $y?>-01-01&topDt=<?php print $y?>-12-31"><?php print $y?></a> | <?
			}
			?>

			| <a href="?page=<?php print $_GET['page']?>&f=SummaryList&df=<?php print $_GET['topDf']?>&dt=<?php print $_GET['topDt']?>">View Donation Individual Summary for this Time Range</a>
			| <a href="?page=<?php print $_GET['page']?>&SummaryView=t&df=<?php print $_GET['topDf']?>&dt=<?php print $_GET['topDt']?>">Donation Report</a>
			</div><?

	$where=array("Type>0");

	if ($dateFrom) $where[]="Date>='".$dateFrom." 00:00:00'";
	if ($dateTo) $where[]="Date<='".$dateTo."  23:59:59'";
	
	if (sizeof($selectedCatagories)>0){
		$where[]="DD.CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}
	Donation::stats($where);	

	$results = $wpdb->get_results("SELECT D.`DonorId`,D.`Name`, SUM(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation, AVG(`Gross`) as Average
	FROM ".Donation::get_table_name()." DD INNER JOIN ".Donor::get_table_name()." D ON D.DonorId=DD.DonorId WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." Group BY  D.`DonorId`,D.`Name` Order BY SUM(`Gross`) DESC, COUNT(*) DESC LIMIT ".$top);
	if (sizeof($results)>0){?>
		
		<table border=1><tr><th>Name</th><th>Total</th><th>Average</th><th>Count</th><th>First Donation</th><th>Last Donation</th>
		<?
		foreach ($results as $r){
			?><tr><td><a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>"><?php print $r->Name?></a></td><td align=right><?php print $r->Total?></td><td align=right><?php print $r->Average*1?></td><td align=right><?php print $r->Count?></td><td align=right><?php print $r->FirstDonation?></td><td align=right><?php print $r->LastDonation?></td></tr><?
		}
		?></table></form><?
	}
}

function reportMonthly(){
	global $wpdb,$wp;
	$where=array("Gross>0","Currency='USD'","Status=9");
	//,"`Type` IN ('Subscription Payment','Donation Payment','Website Payment')"
	if ($_GET['report']=="reportMonthly"&&$_GET['view']=='detail'){
		if ($_GET['month']){
			$where[]="EXTRACT(YEAR_MONTH FROM `Date`)='".addslashes($_GET['month'])."'";
		}

		$selectedCatagories=$_GET['category']?$_GET['category']:array();
		if (sizeof($selectedCatagories)>0){
			$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
		}

		if (isset($_GET['type'])){
			$where[]="`Type`='".addslashes($_GET['type'])."'";
		}
		$results=Donation::get($where);
		print Donation::showResults($results);		
		return;
		
	}


	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";
	
	$selectedCatagories=$_GET['category']?$_GET['category']:array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$SQL="SELECT EXTRACT(YEAR_MONTH FROM `Date`) as Month, `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Donation::get_table_name()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."
	Group BY  EXTRACT(YEAR_MONTH FROM `Date`), `Type`";

	//print $SQL;
	$graph=array();
	$results = $wpdb->get_results($SQL);
	if (sizeof($results)>0){		
		foreach ($results as $r){
			$type=Donation::getTinyDescription('Type',$r->Type)??$r->Type;
			$graph['Total'][$r->Month][$type]+=$r->Total;
			$graph['Count'][$r->Month][$type]+=$r->Count;
			$graph['Type'][$type]+=$r->Total;
		}
		krsort($graph['Type']);
		?>
		  <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
  <script type="text/javascript">
    google.charts.load("current", {packages:['corechart']});
    google.charts.setOnLoadCallback(drawMonthlyChart);
    function drawMonthlyChart() {
		var data = google.visualization.arrayToDataTable([
        ['Type', '<?php print implode("', '",array_keys($graph['Type']))?>', {'type': 'string', 'role': 'tooltip', 'p': {'html': true}} ]
		<?php
		foreach($graph['Total'] as $date=>$types){
			print ", ['".$date."',";
			foreach($graph['Type'] as $type=>$total){
				print ($types[$type]?$types[$type]:0).", ";
			}
			//print "'<strong>".$date."</strong><br>Donation Total: $".number_format($graph['Total'][$date]['Donation Payment'],2)."']\r\n";

			print "'<strong>".$date."</strong><br>Donation Total: <a target=\"detail\" href=\"?page=donor-reports&report=reportMonthly&view=detail&month=".$date."&type=1\">$".number_format($graph['Total'][$date]['Donation Payment'],2)."</a><br>Count: ".$graph['Count'][$date]['Donation Payment']."']\r\n";
		}

		?>
      ]);

      var options = {
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,
		tooltip: { isHtml: true, trigger: 'selection' }
      };
	
	  var chart = new google.visualization.ColumnChart(document.getElementById("MonthlyDonationsChart"));
	  chart.draw(data, options);

	}
	</script>
<form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
			<h3>Monthly Donations Report From <input type="date" name="topDf" value="<?php print $_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?php print $_GET['topDt']?>"/> <button type="submit">Go</button></h3>
			<div id="MonthlyDonationsChart" style="width: 1200px; height: 500px;"></div>
			<table border=1><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?
		foreach ($results as $r){
			?><tr><td><?php print $r->Month?></td><td><?php print Donation::getTinyDescription('Type',$r->Type)??$r->Type?></td><td align=right><a href="?page=<?php print $_GET['page']?>&report=reportMonthly&view=detail&month=<?php print $r->Month?>&type=<?php print urlencode($r->Type)?>"><?php print number_format($r->Total,2)?></a></td><td align=right><?php print $r->Count?></td></tr><?
		}
		?></table></form>
		
		
		<?
	}
}
