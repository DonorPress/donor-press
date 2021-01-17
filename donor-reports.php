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
	if (Donation::RequestHandler()) { print "</div>"; return;} //important to do this first
	if (Donor::RequestHandler())  { print "</div>"; return;}
	if (DonationCategory::RequestHandler()) { print "</div>"; return;}
	//load_initial_data();
	?>
	
	<h1>Report Page</h1>
	<?
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
	?><div><?
	for($y=date("Y");$y>=date("Y")-4;$y--){
		?><a href="?page=<?=$_GET['page']?>&f=YearSummaryList&Year=<?=$y?>"><?=$y?></a> | <?
	}
	
	?></div><?

	reportTop();
	reportCurrentMonthly();
	reportMonthly();
	?>	
</div>
<?php

function reportCurrentMonthly(){
	global $wpdb;
	$SQL="SELECT `Name`,AVG(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation FROM ".Donation::getTableName()." WHERE  `Type` IN ('Subscription Payment') AND Date>='".date("Y-m-d",strtotime("-3 months"))."' Group BY `Name` ORder BY AVG(`Gross`) DESC";
	$results = $wpdb->get_results($SQL);
	print $SQL;
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?=$_GET['page']?>" /></form>
		<h2>Current Monthly Donors</h2>
		<table border=1><tr><th></th><th>Name</th><th>Monthly Give</th><th>Count</th><th>Give Day</th></tr>
		<? $i=0;
		foreach ($results as $r){ 
			$i++;
			?><tr><td><?=$i?></td><td><?=$r->Name?></td><td><?=$r->Total?></td><td><?=$r->Count?></td><td><?=date("d",strtotime($r->LastDonation))?></td></tr><?
		}
		//print "<pre>"; print_r($results); print "</pre>";
		?></table><?
	}
}

function reportTop($top=20){
	global $wpdb,$wp;
	$where=[];
	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";
	$results = $wpdb->get_results("SELECT D.`DonorId`,D.`Name`, SUM(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation 
	FROM ".Donation::getTableName()." DD INNER JOIN ".Donor::getTableName()." D ON D.DonorId=DD.DonorId WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." Group BY  D.`DonorId`,D.`Name` Order BY SUM(`Gross`) DESC, COUNT(*) DESC LIMIT ".$top);
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?=$_GET['page']?>" />
			<h3>Top <input type="number" name="topL" value="<?=($_GET['topL']?$_GET['topL']:$top)?>" style="width:50px;"/> Donor Report From <input type="date" name="topDf" value="<?=$_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?=$_GET['topDt']?>"/> <button type="submit">Go</button></h3>
		<table border=1><tr><th>Name</th><th>Total</th><th>Count</th><th>First Donation</th><th>Last Donation</th>
		<?
		foreach ($results as $r){
			?><tr><td><a href="?page=<?=$_GET['page']?>&DonorId=<?=$r->DonorId?>"><?=$r->Name?></a></td><td align=right><?=$r->Total?></td><td align=right><?=$r->Count?></td><td align=right><?=$r->FirstDonation?></td><td align=right><?=$r->LastDonation?></td></tr><?
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
		$results=Donation::get($where);
		print Donation::showResults($results);		
		return;
		
	}


	if ($_GET['topDf']) $where[]="Date>='".$_GET['topDf']."'";
	if ($_GET['topDt']) $where[]="Date<='".$_GET['topDt']."'";

	$SQL="SELECT EXTRACT(YEAR_MONTH FROM `Date`) as Month, `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Donation::getTableName()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."
	Group BY  EXTRACT(YEAR_MONTH FROM `Date`), `Type`";

	//print $SQL;
	$results = $wpdb->get_results($SQL);
	if (sizeof($results)>0){
		foreach ($results as $r){
			$graph['Total'][$r->Month][$r->Type]+=$r->Total;
			$graph['Count'][$r->Month][$r->Type]+=$r->Count;
			$graph['Type'][$r->Type]+=$r->Total;
		}
		krsort($graph['Type']);
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
			<div id="MonthlyDonationsChart" style="width: 1200px; height: 500px;"></div>
			<table border=1><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?
		foreach ($results as $r){
			?><tr><td><?=$r->Month?></td><td><?=$r->Type?></td><td align=right><a href="?page=<?=$_GET['page']?>&report=reportMonthly&view=detail&month=<?=$r->Month?>&type=<?=urlencode($r->Type)?>"><?=number_format($r->Total,2)?></a></td><td align=right><?=$r->Count?></td></tr><?
		}
		?></table></form>
		
		
		<?
	}
}
