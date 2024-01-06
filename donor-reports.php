<?php
$tabs=['uploads'=>'Recent Uploads/Syncs','year'=>'Year End','trends'=>'Trends','donors'=>'Donors','merge'=>"Merge",'donations'=>'Donations','reg'=>"Regression",'tax'=>"Tax"];
$active_tab=Donor::show_tabs($tabs);
?>
<div id="pluginwrap">
	<?php
	if (Donation::request_handler()) { print "</div>"; return;} //important to do this first
	if (Donor::request_handler())  { print "</div>"; return;}
	if (DonationCategory::request_handler()) { print "</div>"; return;}
	?>
	<h1>Report Page: <?php print $tabs[$active_tab]?></h1>
	<?php
	if (Donor::input('view','get')=='detail'){
		?><h2>Detailed View: <?php print Donor::input('view','report')?></h2><?php
		switch(Donor::input('view','report')){
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
		case "year":
			year_end_summmaries();
			break;
		case "donors":
			report_donors();
		break;
		case "merge":
			Donor::merge_suggestions();
		break;
		case "donations":
			report_donations();
		break;
		case "reg":
			donor_regression();
		break;
		case "trends":
			report_top();
			report_current_monthly();
			reportMonthly();
		break;
		case "tax":
			report_tax();
		break;
	}
	?>	
</div>
<?php

function year_end_summmaries(){ ?>	
	<div><strong>Year End Tax Summaries:</strong> <?php
	for($y=date("Y");$y>=date("Y")-4;$y--){
		?><a href="?page=<?php print Donor::input('page','get')?>&tab=<?php print Donor::input('tab','get')?>&f=YearSummaryList&Year=<?php print $y?>"><?php print $y?></a> | <?php
	}	
	?></div>
	<?php
	if(Donor::input('f','get')=="YearSummaryList" && Donor::input('Year','get')){
		$year=Donor::input('Year','get');
		$limit=Donor::input('limit');
		if (!Donor::input('limit')) $limit=1000;
		if(Donor::input('Function','post')=='SendYearEndEmail'){         
			if (sizeof(Donor::input('emails','post'))<$limit) $limit=sizeof(Donor::input('emails','post'));
			for($i=0;$i<$limit;$i++){
				$donorIds[]=Donor::input('emails','post')[$i];
			}
			
			if (sizeof($donorIds)>0){
				$donorList=Donor::get(array("DonorId IN ('".implode("','",$donorIds)."')"));
				foreach ($donorList as $donor){                        
					$donor->year_receipt_email($year);					
					if (wp_mail($donor->Email, $donor->emailBuilder->subject, $donor->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){                            
						$dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$donor->Email,
						"Subject"=>$donor->emailBuilder->subject,"Content"=>$donor->emailBuilder->body,"DateSent"=>date("Y-m-d H:i:s")));                            
						$dr->save();                     
					}                      
				}
			}
			Donation::display_notice("E-mailed Year End Receipts to  $limit of  ".sizeof(Donor::input('emails','post')));

		}
		Donor::summary_list_year(Donor::input('Year','get'));
		return true;
	}
}

function report_donors(){	
	$dateField=Donor::input('dateField','get')?Donor::input('dateField','get'):'Date';

	$typeIds=is_array($_GET['typeIds'])?$_GET['typeIds']:array();	//if ($donorTypes[0]=="") $donorTypes=array();
	
	?><form method="post">
		<button name="Function" value="ExportAllDonors">Export All Donors</button>
	</form>
	
	<form method="get" style="font-size:90%;">
	<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
	<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
	Top: <input type="number" name="top" value="<?php print $top?>" step=1 style="width:80px;"/>
	Name Search: <input name="name" value="<?php print Donor::input('name','get')?>" />	
	Dates From <input type="date" name="df" value="<?php print Donor::input('df','get')?>"/> to 
	<input type="date" name="dt" value="<?php print Donor::input('dt','get')?>"/> 
	Date Field: <select name="dateField"><?php 
	foreach (Donation::s()->dateFields as $field=>$label){?>
		<option value="<?php print $field?>"<?php print $dateField==$field?" selected":""?>><?php print $label?> Date</option>
	<?php } ?>
	</select>
	<br>
	Amount >=  <input type="number" step=".01" name="af" value="<?php print Donor::input('af','get')?>" style="width:120px;"/>
		<label><input type="checkbox" name="yearView" value="t" <?php print Donor::input('yearView','get')=="t"?" checked":""?>/> Year Trends</label>
		<!-- <label><input type="checkbox" name="bulkAction" value="t" <?php print Donor::input('bulkAction','get')=="t"?" checked":""?>/> Bulk Action</label> -->
		Type: <select multiple name="typeIds[]">
			<option value="">--All--</option>
			<option value="ZERO"<?php print (in_array("ZERO",$typeIds)?" selected":"")?>>--Not Set--</option>
		<?php
		$donorTypes=DonorType::list_array();
		foreach($donorTypes as $typeId=>$desc){?>
			<option value="<?php print $typeId?>"<?php print (in_array($typeId,$typeIds)?" selected":"")?>><?php print $desc;?></option><?php
		}
		?>		
		</select>
		
		<button name="f" value="Go">Go</button>
	</form>
	<?php
	$where=[];$having=[];
	if (Donor::input('df','get')){
		$where[]="`$dateField`>='".Donor::input('df','get').($dateField=="Date"?"":" 00:00:00")."'";
	}
	if (Donor::input('dt','get')){
		$where[]="`$dateField`<='".Donor::input('dt','get').($dateField=="Date"?"":" 23:59:59")."'";
	}
	if (Donor::input('name','get')){
		$where[]="(UPPER(D.Name) LIKE '%".addslashes(Donor::input('name','get'))."%' OR UPPER(D.Name2) LIKE '%".addslashes(Donor::input('name','get'))."%')";
	}
	if (sizeof($typeIds)>0 && $typeIds[0]!=""){
		if (in_array('ZERO',$typeIds)){
			$where[]="(TypeId IN ('0','".implode("','",$typeIds)."') OR TypeId IS NULL)";
		}else{
			$where[]="TypeId IN ('".implode("','",$typeIds)."')";
		}		
	}
	
	if(Donor::input('f','get')=="Go"){
		if(Donor::input('yearView','get')=="t"){	
			Donor::year_list(['where'=>$where,'orderBy'=>'D.Name, D.Name2','having'=>$having,'amount'=>Donor::input('af','get')]);
		}else{
			if (Donor::input('af','get')){
				$having[]="SUM(Gross)>='".Donor::input('af','get')."'";
			}
			Donor::summary_list($where,"",['orderBy'=>'D.Name, D.Name2','having'=>$having]);
		}
	}
	
	//print "survived";
}

function report_tax(){
	$taxYear=Donor::input('TaxYear','get')?Donor::input('TaxYear','get'):date("Y")-1;
	$taxMonthStart=Donor::input('TaxMonthStart','get')?Donor::input('TaxMonthStart','get'):1;
	?>
	<div>These reports are meant to be an aid when filling out a <strong>990 form</strong>. They are only as good as the data provided, and we recommend review by a tax professional to make sure you are meeting the current tax reporting year requirements.</div>

	<form method="get" style="font-size:90%;">
	<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
		<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
		<strong>Tax year:</strong> <input type="number" min="2000" max="<?php print date("Y")?>" name="TaxYear" value="<?php print $taxYear;?>"/> 
		<!-- <strong>Tax Period Starting Month:</strong> <input type="number" min="1" max="12" name="TaxMonthStart" value="<?php print $taxYear;?>"/> -->
		<br>
		<strong>Schedule A Part II</strong>
		Lines 2-3 (tax levied, gov help) Total: <input type="number" name="extraIncome23" value="<?php print Donor::input('extraIncome23','get')?>"/>
		<br>
		Lines 9-10 (other income) Total: <input type="number" name="extraIncome810" value="<?php print Donor::input('extraIncome810','get')?>"/>
		<strong>Extra Income (like interest) that needs added to for 2% calc:</strong> 			

		<button>Go</button>
		<?php 
		$ignore=is_array(Donor::input('ignore','get'))?Donor::input('ignore','get'):[];
		$unusual=is_array(Donor::input('unusual','get'))?Donor::input('unusual','get'):[]; 
		if (sizeof($ignore)>0){
			print "<br>Ignoring DonorIds: ";
			foreach($ignore as $ig){
				?><label><input type="checkbox" name="ignore[]" value="<?php print $ig?>" checked/>  <?php print $ig?></label><?php
			}
		}?>
	</form>
	<h3>Public Support Test</h3>
	<div>Reviews giving for the past 5 years, and anybody who gave over 2% of the 5 year total.</div>
	
	<?php
	### GET Qualifying Yearly Totals (TransationType ID between 0 to 99 are gifts)
	$SQL="SELECT YEAR(Date) as TaxYear,SUM(Gross) as Gross
		FROM ".Donation::get_table_name()."
		WHERE YEAR(Date) BETWEEN ".($taxYear-4)." AND ".$taxYear." AND TransactionType Between 0 AND 99
		".(sizeof($unusual)>0?" AND DonorId NOT IN (".implode(",",$unusual).")":"")."
		Group By YEAR(Date) 
		Order BY YEAR(Date)";
	 $results = Donation::db()->get_results($SQL);	
	 foreach ($results as $r){
		$total['donated']['year'][$r->TaxYear]+=$r->Gross;
		$total['donated']['total']+=$r->Gross;		
	 }

	### Find first donation record to guess number of years
	$SQL="Select MIN(YEAR(Date)) as startYear FROM ".Donation::get_table_name();
	$results = Donation::db()->get_results($SQL);
	$firstYear=$results[0]->startYear;

	### Other Income: 90=interest, Product Service Income (100,101)
	$SQL="SELECT TransactionType,YEAR(Date) as TaxYear,SUM(Gross) as Gross
	 FROM ".Donation::get_table_name()."
	 WHERE YEAR(Date) BETWEEN ".($taxYear-4)." AND ".$taxYear." AND TransactionType >= 90 
	 Group By TransactionType, YEAR(Date) 
	 Order BY TransactionType,YEAR(Date)";
	$results = Donation::db()->get_results($SQL);	
	foreach ($results as $r){
		switch($r->TransactionType){
			case 90: $key='interest'; break;
			case 100:
			case 101: $key='service'; break;
			default: $key='other'; break;
		}
		$total[$key]['year'][$r->TaxYear]+=$r->Gross;
		$total[$key]['total']+=$r->Gross;		
	}



	 $totalSupport=$total['donated']['total']+$total['interest']['total']+Donor::input('extraIncome23','get')+Donor::input('extraIncome810','get');
	 $twoPercent=round($totalSupport*.02,0);
	 //dump($total,$twoPercent);
	 $SQL="Select D.DonorId,D.Name,D.Name2, YEAR(DT.Date) as TaxYear,SUM(DT.Gross) as Gross
	  FROM  ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
		WHERE YEAR(DT.Date) BETWEEN ".($taxYear-4)." AND ".$taxYear." AND DT.TransactionType Between 0 AND 89 AND ".(sizeof($ignore)>0?" D.DonorId NOT IN (".implode(",",$ignore).") AND ":"")."
		D.DonorId IN (
			Select DonorId FROM ".Donation::get_table_name()." WHERE YEAR(Date) BETWEEN ".($taxYear-4)." AND ".$taxYear." AND TransactionType Between 0 AND 99 Group By DonorId Having SUM(Gross)>'".$twoPercent."' ) 
			Group By D.DonorId, YEAR(DT.Date),D.Name,D.Name2 Order BY D.Name,YEAR(DT.Date) ";		
	$results = Donation::db()->get_results($SQL);	
	foreach ($results as $r){
		$donors[$r->DonorId]['info']=$r;
		$donors[$r->DonorId]['year'][$r->TaxYear]+=$r->Gross;
		$donors[$r->DonorId]['total']+=$r->Gross;	
	}
	?><div>Total Support: <strong><?php print number_format($totalSupport)?></strong>  2% threshold = <strong><?php print number_format($twoPercent) ?></strong></div>
	<style>td.r {text-align:right;}</style>
	<table class="dp"><tr><th>Donor</th><?php
	for($y=$taxYear-4;$y<=$taxYear;$y++) print "<th>".$y."</th>";
	?><th>Total</th><th>Excess contributions (Total minus 2% limitation)</th></tr>
	<?php
	foreach($donors as $donorId => $a){
		?><tr><td><?php print $a['info']->Name.($a['info']->Name2?" and ".$a['info']->Name2:"")?> (<a target="lookup" href="?page=donor-index&DonorId=<?php print $donorId;?>"><?php print $donorId;?></a>) 
		<a href="<?php print basename($_SERVER['REQUEST_URI'])?>&ignore[]=<?php print $donorId?>">ignore</a>
		| <?php if (in_array($donorId,$unusual) ) print "<strong>Unusual Grant*</strong>";
		else {?> <a href="<?php print basename($_SERVER['REQUEST_URI'])?>&unusual[]=<?php print $donorId?>">Unusual Grant</a>
		<?php } ?>
		</td><?php
		for($y=$taxYear-4;$y<=$taxYear;$y++) print "<td class='r'>".($a['year'][$y]?number_format($a['year'][$y]):"")."</td>";
		print "<td class='r'>".number_format($a['total'])."</td>";
		print "<td class='r'>".number_format($a['total']-$twoPercent)."</td></tr>";
		$total['excess']+=$a['total']-$twoPercent;
		if (!in_array($donorId,$unusual)) $total['excessMinusUnusual']+=$a['total']-$twoPercent;

	}?>
	<tr><td colspan=7>Total</td><td class='r'><?php print number_format($total['excess'])?></td></tr>
	<?php 
	if($total['excessMinusUnusual']!=$total['excess']){
		?><tr><td colspan=7>Total Minus Unusual</td><td class='r'><?php print number_format($total['excessMinusUnusual'])?></td></tr><?php
	}?>
	</table>
	<?php
	if (sizeof($unusual)>0){
		print "<div>* Usual Grants are not currently saved to the database, but are only active via this page link for 'what if' scenerios.</div>";
	}
	if ($sheduleBThreshold=$twoPercent<5000?$twoPercent:5000);
	$i=0;
	?>
	<h2>Schedule A (Form 990)</h2>
	<h3>Part II - Section A. Public Support</h3>
	<div>The donor system does not currently distinguish between tax revenues, or government services. 
	<table class="dp">
		<tr><th colspan=2>Calendar year (or fiscal year beginning in)</th><?php
	for($y=$taxYear-4;$y<=$taxYear;$y++){ 		
		print "<th>(".chr($i+97).") ".$y."</th>";
		$i++;
	}
	?><th>(f) Total</th></tr>
	<tr><td>1</td><td>Gifts, grants, contributions, and membership fees received. (Do not include any "unusual grants."")</td>
	<?php
	for($y=$taxYear-4;$y<=$taxYear;$y++){ 		
		print "<td class='r'>".($total['donated']['year'][$y]?number_format($total['donated']['year'][$y]):"")."</td>";
		$i++;
	}print "<td class='r'>".($total['donated']['total']?number_format($total['donated']['total']):"")."</td>";
	?></tr>
	<tr><td>2-3</td><td>Tax revenues levied for the
organization’s benefit and either paid to
or expended on its behalf.<br> The value of services or facilities
furnished by a governmental unit to the
organization without charge .
</td>
	<?php
	for($y=$taxYear-4;$y<=$taxYear;$y++){ 		
		print "<td class='r'></td>";
	}print "<td class='r'>".Donor::input('extraIncome23','get')."</td>";
	?></tr>
	<tr><td>4</td><td>Total. Add lines 1 through 3</td>
	<?php
	for($y=$taxYear-4;$y<=$taxYear;$y++){ 		
		print "<td class='r'></td>";
	}print "<td class='r'>".number_format(Donor::input('extraIncome23','get')+$total['donated']['total'])."</td>";
	?></tr>
	<tr><td>5</td><td colspan=6>The portion of total contributions by each person (other than a governmental unit or publicly supported organization) included on line 1 that exceeds 2% of the amount shown on line 11, column (f)</td>
	<?php
	print "<td class='r'>".($total['excessMinusUnusual']?number_format($total['excessMinusUnusual']):"")."</td>";
	?></tr>
	<tr><td>6</td><td colspan=6>Public support. Subtract line 5 from line 4</td>
	<?php
	print "<td class='r'>".number_format(Donor::input('extraIncome23','get')+$total['donated']['total']-$total['excessMinusUnusual'])."</td>";
	?></tr>
	<tr><td>7</td><td colspan=6>Amounts from line 4</td>
	<?php
	print "<td class='r'>".number_format(Donor::input('extraIncome23','get')+$total['donated']['total'])."</td>";
	?></tr>
	
	<tr><td>8</td><td>Gross income from interest, dividends,
payments received on securities loans,
rents, royalties, and income from
similar sources</td>
	<?php
	for($y=$taxYear-4;$y<=$taxYear;$y++){ 		
		print "<td class='r'>".($total['interest']['year'][$y]?number_format($total['interest']['year'][$y]):"")."</td>";
		$i++;
	}print "<td class='r'>".($total['interest']['total']?number_format($total['interest']['total']):"")."</td>";
	?></tr>
	<tr><td>9-10</td><td  colspan=6>Interest, unrelated business income, Other total</td>
	<?php
	print "<td class='r'>".number_format(Donor::input('extraIncome810','get'))."</td>";
	?></tr>
	<tr><td>11</td><td colspan=6>Total Support</td>
	<?php
	print "<td class='r'>".number_format($totalSupport)."</td>";
	?></tr>
	<tr><td>12</td><td colspan=6>Gross receipts from related activities, etc.</td>
	<?php	print "<td class='r'>".number_format($total[$key]['total'])."</td>";?></tr>
	<tr><td>13</td><td colspan=6>First 5 years. If the Form 990 is for the organization’s first, second, third, fourth, or fifth tax year as a section 501(c)(3)
organization, check this box and stop here</td>
	<?php	print "<td>".($taxYear-$firstYear+1>5?"No":"Yes")." (".($taxYear-$firstYear+1)." estimated reporting years)</td>";?></tr>
	<tr><td>14</td><td colspan=6>Public support percentage for <?=$taxYear?> (line 6, column (f), divided by line 11, column (f))</td>
	<?php	print "<td>".number_format(100*(Donor::input('extraIncome23','get')+$total['donated']['total']-$total['excessMinusUnusual'])/$totalSupport,2)."%</td>";?></tr>
	Donor::input('extraIncome23','get')+$total['donated']['total']-$total['excessMinusUnusual']
	</table>
	
	<h2>Schedule B (Form 990)</h2>
	<div>Top Donors needing reported because they are over $5,000 for the <?php print $taxYear?> tax year OR over the 2% Threshold: <?php print number_format($twoPercent)?></div>
	<table class="dp">
		<tr>
			<th>(a)<br>No.</th>
			<th>(b)<br>Name, address, and ZIP + 4</th>
			<th>(c)<br>Total contributions</th>
		</tr>
	<?php
	$i=0;
	 $SQL="Select D.DonorId, D.Name, D.Name2, D.Address1, D.Address2, D.City, D.Region, D.PostalCode, D.Country, SUM(DT.Gross) as Gross
	 FROM  ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
	   WHERE YEAR(DT.Date) = ".$taxYear." AND DT.TransactionType Between 0 AND 99  	  
		   Group By D.DonorId, D.Name, D.Name2, D.Address1, D.Address2, D.City, D.Region, D.PostalCode, D.Country
		   HAVING SUM(DT.Gross) > ".$sheduleBThreshold."
		   Order BY SUM(DT.Gross) DESC  ";
		   //print $SQL;
	$results = Donation::db()->get_results($SQL);	
	foreach ($results as $r){
		$i++;
		$donor=new Donor($r);
		?><tr>
			<td><?php print $i?></td>
			<td><?php print $donor->mailing_address("<br>",true,['NameOnlyOkay'=>true])?></td>
			<td class="r"><?php print number_format($r->Gross)?></td>
		</tr>
		<?php
	}
	?></table><?php
	//SELECT DonorId, YEAR(Date) as TaxYear, SUM(Gross) as Gross FROM wordpress.dwp_donation WHERE

	

}

function report_donations(){ 
	$top=is_int(Donor::input('top','get'))?Donor::input('top','get'):1000;	
	$dateField=Donor::input('dateField','get')?Donor::input('dateField','get'):'Date';
	?>
	<form method="get" style="font-size:90%;">
		<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
		<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
        Top: <input type="number" name="top" value="<?php print $top?>"/>
		Dates From <input type="date" name="df" value="<?php print Donor::input('df','get')?>"/> to 
		<input type="date" name="dt" value="<?php print Donor::input('dt','get')?>"/> 
		Date Field: <select name="dateField"><?php 
		foreach (Donation::s()->dateFields as $field=>$label){?>
			<option value="<?php print $field?>"<?php print $dateField==$field?" selected":""?>><?php print $label?> Date</option>
		<?php } ?>
        </select>
		<br>
		Amount:  <input type="number" step=".01" name="af" value="<?php print Donor::input('af','get')?>" style="width:120px;"/>
		to <input type="number" step=".01" name="at" value="<?php print Donor::input('at','get')?>" style="width:120px;"/>
		Category:
		<?php print DonationCategory::select(['Name'=>'CategoryId','Count'=>true]);?>
		<br>
		Source:
		<select name="PaymentSource">
			<option value="">--All--</option>
			<?php			
			foreach(Donation::s()->tinyIntDescriptions["PaymentSource"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==Donor::input('PaymentSource','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>		

		Type:
		<select name="Type">
			<option value="">--All--</option>
			<?php			
			foreach(Donation::s()->tinyIntDescriptions["Type"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==Donor::input('Type','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		Transaction Type:
		<select name="TransactionType">
			<option value="">--All--</option>
			<?php
			foreach(Donation::s()->tinyIntDescriptions["TransactionType"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==Donor::input('TransactionType','get')?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		<button name="Function" value="DonationList">Go</button><button name="Function" value="DonationListCsv">CSV Download</button>
	</form>	<?php
	if(Donor::input('Function','get')=="DonationList"){
		Donation::report($top,$dateField);
	}
}


function report_current_monthly(){
	global $wpdb;

	$where=array("`Type` IN (5)","Date>='".date("Y-m-d",strtotime("-3 months"))."'");
	$selectedCatagories=Donor::input('category','get')?Donor::input('category','get'):array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$SQL="SELECT `Name`,AVG(`Gross`) as Total, Count(*) as Count, MIN(Date) as FirstDonation, MAX(Date)as LastDonation FROM ".Donation::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY `Name` ORder BY AVG(`Gross`) DESC";
	print $SQL;
	$results = $wpdb->get_results($SQL);	
	if (sizeof($results)>0){
		?><form method="get" action=""><input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" /></form>
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
	$dateFrom=Donor::input('topDf','get');
	$dateTo=Donor::input('topDt','get');

	$selectedCatagories=Donor::input('category','get')?Donor::input('category','get'):array();

	?><form method="get" action="">
		<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
		<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
		<h3>Top <input type="number" name="topL" value="<?php print (Donor::input('topL','get')?Donor::input('topL','get'):$top)?>" style="width:50px;"/>Donor Report From <input type="date" name="topDf" value="<?php print Donor::input('topDf','get')?>"/> to <input type="date" name="topDt" value="<?php print Donor::input('topDt','get')?>"/> 
		<?php
		print DonationCategory::select(['Name'=>"category[]",'selected'=>$selectedCatagories,'Multiple'=>true]);
		?>
		<button type="submit">Go</button></h3>
		<div><?php
		for($y=date("Y");$y>=date("Y")-4;$y--){
			?><a href="?page=<?php print Donor::input('page','get')?>&tab=<?php print Donor::input('tab','get')?>&topDf=<?php print $y?>-01-01&topDt=<?php print $y?>-12-31"><?php print $y?></a> | <?php
		}
		?>

		| <a href="?page=<?php print Donor::input('page','get')?>&tab=<?php print Donor::input('tab','get')?>&f=SummaryList&df=<?php print Donor::input('topDf','get')?>&dt=<?php print Donor::input('topDt','get')?>">View Donation Individual Summary for this Time Range</a>
		| <a href="?page=<?php print Donor::input('page','get')?>&tab=<?php print Donor::input('tab','get')?>&SummaryView=t&df=<?php print Donor::input('topDf','get')?>&dt=<?php print Donor::input('topDt','get')?>">Donation Report</a>
		</div>
	</form><?php

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
		?></table><?php
	}
}

function donor_regression($where=[]){
	global $wpdb,$wp;
	if (!Donor::input('yt','get')) $_GET['yt']=date('m')<5?date("Y")-1:date("Y");
	if (!Donor::input('yf','get')){
		$results = $wpdb->get_results("SELECT MIN(Year(`Date`)) as Year	FROM ".Donation::get_table_name());
		$_GET['yt']=isset($results[0]->Year)?$results[0]->Year:date("Y")-1;
	}

	?><form method="get">
			<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
			<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
			Year: <input type="number" name="yf" value="<?php print Donor::input('yf','get')?>"/> to <input type="number" name="yt" value="<?php print Donor::input('yt','get')?>"/>
			<button>Go</button>		
	</form>
	<?php
	
	$where[]='`Gross`>0';
	$where[]="Year(`Date`) BETWEEN '".Donor::input('yf','get')."' AND '".Donor::input('yt','get')."'";
	if(Donor::input('RegressionDonorId','get')){
		$where[]="D.DonorId='".Donor::input('RegressionDonorId','get')."'";
	}
	$results = $wpdb->get_results("SELECT D.`DonorId`,D.`Name`,D.Name2,D.Email, Year(`Date`) as Year, SUM(`Gross`) as Total, Count(*) as Count
	FROM ".Donation::get_table_name()." DD INNER JOIN ".Donor::get_table_name()." D ON D.DonorId=DD.DonorId WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")." 
	Group BY  D.`DonorId`,D.`Name`,D.Name2,D.Email,Year(`Date`) Order BY Year(`Date`),SUM(`Gross`) DESC, COUNT(*)");//DESC LIMIT ".$top
	foreach ($results as $r){
		$donorYear[$r->DonorId][$r->Year]=$r->Total;
		$donorCount[$r->DonorId][$r->Year]=$r->Count;
		$allYears[$r->Year]=$r->Total;
		$donor[$r->DonorId]=new Donor(['DonorId'=>$r->DonorId,'Name'=>$r->Name,'Name2'=>$r->Name2,'Email'=>$r->Email]);
		//$donorEmail[$r->DonorId]=$r->Email;
	}
	//dd($donorYear);
	//Stategy: take the earliest donor year for a specific donor. Compare the average of that start date to last year to this year, and show as a percentage and total.
	foreach ($donorYear as $donorId=>$years){
		ksort($years);
		for($year=key($years);$year<Donor::input('yt','get');$year++){
			$donorStats[$donorId]['years'][$year]=$donorYear[$donorId][$year];
		}
		if ($donorStats[$donorId]['years']) $donorStats[$donorId]['avg']=array_sum($donorStats[$donorId]['years'])/count($donorStats[$donorId]['years']);
		
		$amountDiff[$donorId]=$donorYear[$donorId][Donor::input('yt','get')]-$donorStats[$donorId]['avg'];
	}
	asort($amountDiff);
	//dd($donorStats,$donorYear,$amountDiff);

	if (sizeof($results)>0){?>		
		<table class="dp"><tr><th>#</th><th>Name</th><th>Email</th><?php
		foreach($allYears as $year=>$total) print "<th>".$year."</th>";
		?><th>Avg</th><th>%</th></tr><?php
		foreach ($amountDiff as $donorId=>$diff){
			$years=$donorYear[$donorId];
			//dd($years); //<a href="?page=donor-index&DonorId=print $donorId"> </a>
			if ($years[Donor::input('yt','get')]-$donorStats[$donorId]['avg']<0){
			?><tr>
				<td><?php print $donor[$donorId]->show_field('DonorId',['target'=>'donor']);?> <a href="?page=donor-reports&tab=stats&RegressionDonorId=<?php print $donorId?>" target="donor">Summary</a></td>
				<td><?php print $donor[$donorId]->name_combine()?></td>
				<td><?php print $donor[$donorId]->Email;?></td>
				<?php foreach($allYears as $year=>$total) print "<td align=right>".number_format($years[$year])."</td>";
				?><td align=right><?php print number_format($donorStats[$donorId]['avg'])?></td>
				<td align=right><?php print $donorStats[$donorId]['avg']?number_format(100*($years[Donor::input('yt','get')]-$donorStats[$donorId]['avg'])/$donorStats[$donorId]['avg'],2)."%":"-"?></td>
			</tr>
			<?php
			}
		}
		?></table><?php
	}
	if(Donor::input('RegressionDonorId','get')){
		?>
		<div>Counts</div>	
		<table class="dp"><tr><th>#</th><th>Name</th><th>Email</th><?php
		foreach($allYears as $year=>$total) print "<th>".$year."</th>";
		?></tr><?php
		foreach ($amountDiff as $donorId=>$diff){
			$years=$donorCount[$donorId];
			//dd($years); //<a href="?page=donor-index&DonorId=print $donorId"> </a>
			if ($years[Donor::input('yt','get')]-$donorStats[$donorId]['avg']<0){
			?><tr>
				<td><?php print $donor[$donorId]->show_field('DonorId',['target'=>'donor']);?> <a href="?page=donor-reports&tab=stats&RegressionDonorId=<?php print $donorId?>" target="donor">Summary</a></td>
				<td><?php print $donor[$donorId]->name_combine()?></td>
				<td><?php print $donor[$donorId]->Email;?></td>
				<?php foreach($allYears as $year=>$total) print "<td align=right>".number_format($years[$year])."</td>";
				?>				
			</tr>
			<?php
			}
		}
		?></table><?php
	}

}

function reportMonthly(){
	global $wpdb,$wp;
	$where=array("Gross>0","Currency='USD'","Status=9");
	//,"`Type` IN ('Subscription Payment','Donation Payment','Website Payment')"
	if (Donor::input('view','report')=="reportMonthly"&&Donor::input('view','get')=='detail'){
		if (Donor::input('month','get')){
			$where[]="EXTRACT(YEAR_MONTH FROM `Date`)='".addslashes(Donor::input('month','get'))."'";
		}

		$selectedCatagories=Donor::input('category','get')?Donor::input('category','get'):array();
		if (sizeof($selectedCatagories)>0){
			$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
		}

		if (Donor::input('type','get')){
			$where[]="`Type`='".addslashes(Donor::input('type','get'))."'";
		}
		//dd($where);
		$results=Donation::get($where);
		print Donation::show_results($results);		
		return;
		
	}


	if (Donor::input('topDf','get')) $where[]="Date>='".Donor::input('topDf','get')."'";
	if (Donor::input('topDt','get')) $where[]="Date<='".Donor::input('topDt','get')."'";
	
	$selectedCatagories=Donor::input('category','get')?Donor::input('category','get'):array();
	if (sizeof($selectedCatagories)>0){
		$where[]="CategoryId IN ('".implode("','",$selectedCatagories)."')";
	}

	$countField=(Donor::input('s','get')=="Count"?"Count":"Gross");	

	$graph=array('Month'=>array(),'WeekDay'=>array(),'YearMonth'=>array(),'Total'=>array(),'Count'=>array(),'time'=>array());
	//Donor::dump($graph);
	$SQL="SELECT `Date`, `Type`, Gross, PaymentSource FROM ".Donation::get_table_name()." WHERE ".(sizeof($where)>0?implode(" AND ",$where):"1")."";
	$results = $wpdb->get_results($SQL);		
	foreach ($results as $r){
		$timestamp=strtotime($r->Date);
		$yearMonth=date("Ym",$timestamp);
		$type=$r->Type;
		/* set variables to avoid wrnings */
		if (!isset($graph['Month'][date("n",$timestamp)])) $graph['Month'][date("n",$timestamp)]=0;
		if (!isset($graph['WeekDay'][date("N",$timestamp)])) $graph['WeekDay'][date("N",$timestamp)]=0;
		if (!isset($graph['time'][date("H",$timestamp)])) $graph['time'][date("H",$timestamp)]=0;
		if (!isset($graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)])) $graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)]=0;
		if (!isset($graph['Total'][$yearMonth][$type])) $graph['Total'][$yearMonth][$type]=0;
		if (!isset($graph['Count'][$yearMonth][$type])) $graph['Count'][$yearMonth][$type]=0;
		if (!isset($graph['Type'][$type])) $graph['Type'][$type]=0;

		if ($r->Type<>5){ //skip autopayments / subcriptions for day/time graph
			$graph['Month'][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);			
			$graph['WeekDay'][date("N",$timestamp)]+=($countField=="Gross"?$r->Gross:1);			
			if (date("His",$timestamp)>0){ //ignore entries without timestamp
				$graph['time'][date("H",$timestamp)*1]+=($countField=="Gross"?$r->Gross:1);
			}			
		}
		$graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);

		$graph['Total'][$yearMonth][$type]+=$r->Gross;
		$graph['Count'][$yearMonth][$type]++;			
		$graph['Type'][$type]+=$r->Gross;
	}
	ksort($graph['YearMonth']);
	foreach($graph['Type'] as $type=>$total){
		$graph['TypeDescription'][$type]=Donation::get_tiny_description('Type',$type)??$type;
	}
	if ($graph['WeekDay']) ksort($graph['WeekDay']);
	if ($graph['time']) ksort($graph['time']);
	if ($graph['Type']) krsort($graph['Type']);

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

<?php if ($graph['WeekDay']){?> 
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
	
	  var chart2 = new google.visualization.ColumnChart(document.getElementById("WeekDay"));
	  chart2.draw(data2, options2);
<?php } ?>

	  var data3 = google.visualization.arrayToDataTable([
        ['Week Day', '<?php print $countField;?>']
		<?php
		for ($i=0;$i<=23;$i++){
			print ", ['".$i."',".(isset($graph['time'][$i])?$graph['time'][$i]:0)."]";
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
			print ", ['".$i."',".(isset($graph['Month'][$i])?$graph['Month'][$i]:0)."]";
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

	  var data5 = google.visualization.arrayToDataTable([
		  ['Year' <?php
		 	foreach($graph['YearMonth'] as $y=>$a){
				print ",'".$y."'";
			}?>] 
			<?php
			for ($i=1;$i<=12;$i++){
				print ", ['".$i."'";
				foreach($graph['YearMonth'] as $y=>$a){
					print ",".(isset($a[$i])?$a[$i]:0);
				}
				print "]";
			}?>
		]);

        var options5 = {          
            title: 'Year Monthly Trends'			
        };
        var chart5 = new google.visualization.ColumnChart(document.getElementById('YearMonthChart'));
        chart5.draw(data5, options5);

	}
	</script>
<form method="get">
	<input type="hidden" name="page" value="<?php print Donor::input('page','get')?>" />
	<input type="hidden" name="tab" value="<?php print Donor::input('tab','get')?>" />
			<h3>Monthly Donations Report From <input type="date" name="topDf" value="<?php print Donor::input('topDf','get')?>"/> to <input type="date" name="topDt" value="<?php print Donor::input('topDt','get')?>"/> 
			Show: <select name="s">
				<option value="Gross"<?php print ($countField=="Gross"?" selected":"")?>>Gross</option>	
				<option value="Count"<?php print ($countField=="Count"?" selected":"")?>>Count</option>
					
			</select>
			<button type="submit">Go</button></h3>
			<div id="MonthlyDonationsChart" style="width: 1200px; height: 500px;"></div>
			<div id="YearMonthChart" style="width: 1200px; height: 500px;"></div>
			<div id="MonthChart" style="width: 1200px; height: 500px;"></div>		
			
			<?php if ($graph['WeekDay']){?> 
				<div id="WeekDay" style="width: 1200px; height: 500px;"></div>
			<?php }?>
			<div id="TimeChart" style="width: 1200px; height: 500px;"></div>

	<table class="dp"><tr><th>Month</th><th>Type</th><th>Amount</th><th>Count</th>
		<?php
		foreach ($graph['Total'] as $yearMonth =>$types){
			foreach($types as $type=>$total){
				?><tr><td><?php print  $yearMonth?></td><td><?php print Donation::get_tiny_description('Type',$type)??$type?></td><td align=right><a href="?page=<?php print Donor::input('page','get')?>&tab=<?php print Donor::input('tab','get')?>&report=reportMonthly&view=detail&month=<?php print $yearMonth?>&type=<?php print urlencode($type)?>"><?php print number_format($total,2)?></a></td><td align=right><?php print $graph['Count'][$yearMonth][$type]?></td></tr><?php
		
			}
		}
		?></table>
		</form>	
		
		<?php
	}
}
