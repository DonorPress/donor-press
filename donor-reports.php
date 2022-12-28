<?php
$tabs=['uploads'=>'Recent Uploads/Syncs','stats'=>'Stats','trends'=>'Trends'];
$active_tab=Donor::show_tabs($tabs,$active_tab);
?>
<div id="pluginwrap">
	<?php
	if (Donation::request_handler()) { print "</div>"; return;} //important to do this first
	if (Donor::request_handler())  { print "</div>"; return;}
	if (DonationCategory::request_handler()) { print "</div>"; return;}
	?>
	<h1>Report Page: <?php print $tabs[$active_tab]?></h1>
	<?php
	if ($_GET['view']=='detail'){
		?><h2>Detailed View: <?php print $_GET['report']?></h2><?php
		switch($_GET['report']){
			case "reportMonthly":
				reportMonthly();
			break;
		}
		print "</div>";
		return;
	}

	switch ($active_tab){
		case "uploads":
			Donation::donation_upload_groups();
		break;
		case "stats":
			year_end_summmaries();
		break;
		case "trends":
			report_top();
			report_current_monthly();
			reportMonthly();
		break;
	}



	

	?>	
</div>
<?php

function year_end_summmaries(){?>	
	<div><strong>Year End Tax Summaries:</strong> <?php
	for($y=date("Y");$y>=date("Y")-4;$y--){
		?><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&f=YearSummaryList&Year=<?php print $y?>"><?php print $y?></a> | <?php
	}
	
	?></div>
	<form method="get"><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
	Source: <select name="PaymentSource"><?php
	$paymentSource=array(0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Paypal","6"=>"ACH/Bank Transfer");
	foreach($paymentSource as $key=>$label){
		?><option value="<?php print $key?>"><?php print $key." - ".$label?></option><?php
	}
	?></select>
	Year: <select name="Year">
	<?php for($y=date("Y");$y>=date("Y")-4;$y--){?>
		?><option value="<?php print $y;?>"><?php print $y;?></option>
	<?php }?>
	</select>
	

	<button name="f" value="ViewPaymentSourceYearSummary">Go</button>
	</form>	
	<?php
}


function report_current_monthly(){
	global $wpdb;

	$where=array("`Type` IN (5)","Date>='".date("Y-m-d",strtotime("-3 months"))."'");
	$selectedCatagories=$_GET['category']?$_GET['category']:array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$SQL="SELECT `Name`,AVG(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation FROM ".Donation::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY `Name` ORder BY AVG(`Gross`) DESC";
	$results = $wpdb->get_results($SQL);	
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" /></form>
		<h2>Current Monthly Donors</h2>
		<table class="dp"><tr><th></th><th>Name</th><th>Monthly Give</th><th>Count</th><th>Give Day</th></tr>
		<?php $i=0;
		foreach ($results as $r){ 
			$i++;
			?><tr><td><?php print $i?></td><td><?php print $r->Name?></td><td align=right><?php print number_format($r->Total,2)?></td><td><?php print $r->Count?></td><td><?php print date("d",strtotime($r->LastDonation))?></td></tr><?php
		}
		//print "<pre>"; print_r($results); print "</pre>";
		?></table><?php
	}
}

function report_top($top=20){
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
					?><option value="<?php print $cat->CategoryId?>"<?php print in_array($cat->CategoryId,$selectedCatagories)?" selected":""?>><?php print $cat->Category?></option><?php
				}
				?>
			</select>
			<button type="submit">Go</button></h3>
			<div><?php
			for($y=date("Y");$y>=date("Y")-4;$y--){
				?><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&topDf=<?php print $y?>-01-01&topDt=<?php print $y?>-12-31"><?php print $y?></a> | <?php
			}
			?>

			| <a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&f=SummaryList&df=<?php print $_GET['topDf']?>&dt=<?php print $_GET['topDt']?>">View Donation Individual Summary for this Time Range</a>
			| <a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&SummaryView=t&df=<?php print $_GET['topDf']?>&dt=<?php print $_GET['topDt']?>">Donation Report</a>
			</div><?php

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
		
		<table class="dp"><tr><th>Name</th><th>Total</th><th>Average</th><th>Count</th><th>First Donation</th><th>Last Donation</th>
		<?php
		foreach ($results as $r){
			?><tr>
				<td><a href="?page=donor-index&DonorId=<?php print $r->DonorId?>"><?php print $r->Name?></a></td>
				<td align=right><?php print number_format($r->Total)?></td>
				<td align=right><?php print number_format($r->Average)?></td>
				<td align=right><?php print $r->Count?></td>
				<td align=right><?php print date("Y-m-d",strtotime($r->FirstDonation))?></td>
				<td align=right><?php print date("Y-m-d",strtotime($r->LastDonation))?></td>
			</tr>
			<?php
		}
		?></table></form><?php
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
		//dd($where);
		$results=Donation::get($where);
		print Donation::show_results($results);		
		return;
		
	}


	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";
	
	$selectedCatagories=$_GET['category']?$_GET['category']:array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$countField=($_GET['s']=="Count"?"Count":"Gross");	

	$graph=array();
	$SQL="SELECT `Date`, `Type`, Gross, PaymentSource FROM ".Donation::get_table_name()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."";
	$results = $wpdb->get_results($SQL);		
	foreach ($results as $r){
		$timestamp=strtotime($r->Date);
		if ($r->Type<>5){ //skip autopayments / subcriptions for day/time graph
			$graph['Month'][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);
			$graph['WeekDay'][date("N",$timestamp)]+=($countField=="Gross"?$r->Gross:1);			
			if (date("His",$timestamp)>0){ //ignore entries without timestamp
				$graph['time'][date("H",$timestamp)*1]+=($countField=="Gross"?$r->Gross:1);
			}			
		}
		$yearMonth=date("Ym",$timestamp);
		$type=$r->Type;
		$graph['Total'][$yearMonth][$type]+=$r->Gross;
		$graph['Count'][$yearMonth][$type]++;			
		$graph['Type'][$type]+=$r->Gross;
	}
	foreach($graph['Type'] as $type=>$total){
		$graph['TypeDescription'][$type]=Donation::get_tiny_description('Type',$type)??$type;
	}
	ksort($graph['WeekDay']);
	ksort($graph['time']);
	krsort($graph['Type']);

	$weekDays=array("1"=>"Mon","2"=>"Tue","3"=>"Wed","4"=>"Thu","5"=>"Fri",6=>"Sat",7=>"Sun");


	if (sizeof($graph['Type'])>0){	
		ksort($graph[$countField=="Gross"?'Total':'Count']);
		?>
  <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
  <script type="text/javascript">
    google.charts.load("current", {packages:['corechart']});
    google.charts.setOnLoadCallback(drawMonthlyChart);
    function drawMonthlyChart() {
		var data = google.visualization.arrayToDataTable([
        ['Type', '<?php print implode("', '",$graph['TypeDescription'])?>']
		<?php
		foreach($graph[$countField=="Gross"?'Total':'Count'] as $date=>$types){
			print ", ['".$date."'";
			foreach($graph['TypeDescription'] as $type=>$desc){
				print ",".($types[$type]?$types[$type]:0);
			}
			print "]";
		}
		?>
      ]);

      var options = {
		title:'Monthly Donation by <?php print $countField;?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true
      };
	
	  var chart = new google.visualization.ColumnChart(document.getElementById("MonthlyDonationsChart"));
	  chart.draw(data, options);

	  var data2 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print $countField;?>']
		<?php
		foreach($graph['WeekDay'] as $day=>$count){
			print ", ['".$weekDays[$day]."',".$count."]";
		}
		?>
      ]);
	  var options2 = {
		title:'Day of Week by <?php print $countField;?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };
	
	  var chart2 = new google.visualization.ColumnChart(document.getElementById("Weekday"));
	  chart2.draw(data2, options2);

	  var data3 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print $countField;?>']
		<?php
		for ($i=0;$i<=23;$i++){
			print ", ['".$i."',".($graph['time'][$i]?$graph['time'][$i]:0)."]";
		}
		
		?>
      ]);
	  var options3 = {
		title:'Time of Day by <?php print $countField;?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };

	  var chart3 = new google.visualization.ColumnChart(document.getElementById("TimeChart"));
	  chart3.draw(data3, options3);

	  var data4 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print $countField;?>']
		<?php
		for ($i=1;$i<=12;$i++){
			print ", ['".$i."',".($graph['Month'][$i]?$graph['Month'][$i]:0)."]";
		}	
		?>
      ]);
	  var options4 = {
		title:'Month by <?php print $countField;?>',
        width: 1200,
        height: 500,
        legend: { position: 'right', maxLines: 3 },
        bar: { groupWidth: '75%' },
        isStacked: true,		
      };
	
	  var chart4 = new google.visualization.ColumnChart(document.getElementById("MonthChart"));
	  chart4.draw(data4, options4);
	

	}
	</script>
<form method="get" action=""><input type="hidden" name="page" value="<?php print $_GET['page']?>" />
			<h3>Monthly Donations Report From <input type="date" name="topDf" value="<?php print $_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?php print $_GET['topDt']?>"/> 
			Show: <select name="s">
				<option value="Gross"<?php print ($countField=="Gross"?" selected":"")?>>Gross</option>	
				<option value="Count"<?php print ($countField=="Count"?" selected":"")?>>Count</option>
					
</select>
			<button type="submit">Go</button></h3>
			<div id="MonthlyDonationsChart" style="width: 1200px; height: 500px;"></div>
			<div id="Weekday" style="width: 1200px; height: 500px;"></div>
			<div id="MonthChart" style="width: 1200px; height: 500px;"></div>		
			<div id="TimeChart" style="width: 1200px; height: 500px;"></div>

	<table class="dp"><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?php
		foreach ($graph['Total'] as $yearMonth =>$types){
			foreach($types as $type=>$total){
				?><tr><td><?php print  $yearMonth?></td><td><?php print Donation::get_tiny_description('Type',$type)??$type?></td><td align=right><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&report=reportMonthly&view=detail&month=<?php print $yearMonth?>&type=<?php print urlencode($type)?>"><?php print number_format($total,2)?></a></td><td align=right><?php print $graph['Count'][$yearMonth][$type]?></td></tr><?php
		
			}
		}
		?></table></form>	
		
		<?php
	}
}
