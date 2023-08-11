<?php
$tabs=['uploads'=>'Recent Uploads/Syncs','year'=>'Year End','trends'=>'Trends','donors'=>'Donors','merge'=>"Merge",'donations'=>'Donations','reg'=>"Regression"];
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
	}
	?>	
</div>
<?php

function year_end_summmaries(){ ?>	
	<div><strong>Year End Tax Summaries:</strong> <?php
	for($y=date("Y");$y>=date("Y")-4;$y--){
		?><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&f=YearSummaryList&Year=<?php print $y?>"><?php print $y?></a> | <?php
	}	
	?></div>
	<?php
	if($_GET['f']=="YearSummaryList" && $_GET['Year']){
		$year=$_GET['Year'];
		$limit=$_REQUEST['limit'];
		if (!$_REQUEST['limit']) $limit=1000;
		if($_POST['Function']=='SendYearEndEmail'){         
			if (sizeof($_POST['emails'])<$limit) $limit=sizeof($_POST['emails']);
			for($i=0;$i<$limit;$i++){
				$donorIds[]=$_POST['emails'][$i];
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
			Donation::display_notice("E-mailed Year End Receipts to  $limit of  ".sizeof($_POST['emails']));

		}
		Donor::summary_list_year($_GET['Year']);
		return true;
	}
}

function report_donors(){	
	$dateField=$_GET['dateField']?$_GET['dateField']:'Date';
	?><form method="post">
		<button name="Function" value="ExportAllDonors">Export All Donors</button>
	</form>
	
	<form method="get" style="font-size:90%;">
	<input type="hidden" name="page" value="<?php print $_GET['page']?>" />
	<input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
	Top: <input type="number" name="top" value="<?php print $top?>" step=1 style="width:80px;"/>
	Name Search: <input name="name" value="<?php print $_GET['name']?>" />	
	Dates From <input type="date" name="df" value="<?php print $_GET['df']?>"/> to 
	<input type="date" name="dt" value="<?php print $_GET['dt']?>"/> 
	Date Field: <select name="dateField"><?php 
	foreach (Donation::s()->dateFields as $field=>$label){?>
		<option value="<?php print $field?>"<?php print $dateField==$field?" selected":""?>><?php print $label?> Date</option>
	<?php } ?>
	</select>
	<br>
	Amount >=  <input type="number" step=".01" name="af" value="<?php print $_GET['af']?>" style="width:120px;"/>
		<label><input type="checkbox" name="yearView" value="t" <?php print $_GET['yearView']=="t"?" checked":""?>/> Year Trends</label>
		<!-- <label><input type="checkbox" name="bulkAction" value="t" <?php print $_GET['bulkAction']=="t"?" checked":""?>/> Bulk Action</label> -->
		<button name="f" value="Go">Go</button>
	</form>
	<?php
	$where=[];$having=[];
	if ($_GET['df']){
		$where[]="`$dateField`>='".$_GET['df'].($dateField=="Date"?"":" 00:00:00")."'";
	}
	if ($_GET['dt']){
		$where[]="`$dateField`<='".$_GET['dt'].($dateField=="Date"?"":" 23:59:59")."'";
	}
	if ($_GET['name']){
		$where[]="(UPPER(D.Name) LIKE '%".addslashes($_GET['name'])."%' OR UPPER(D.Name2) LIKE '%".addslashes($_GET['name'])."%')";
	}
	
	if($_GET['f']=="Go"){
		if($_GET['yearView']=="t"){	
			Donor::year_list(['where'=>$where,'orderBy'=>'D.Name, D.Name2','having'=>$having,'amount'=>$_GET['af']]);
		}else{
			if ($_GET['af']){
				$having[]="SUM(Gross)>='".$_GET['af']."'";
			}
			Donor::summary_list($where,"",['orderBy'=>'D.Name, D.Name2','having'=>$having]);
		}
	}
	
	//print "survived";
}

function report_donations(){ 
	$top=is_int($_GET['top'])?$_GET['top']:1000;	
	$dateField=$_GET['dateField']?$_GET['dateField']:'Date';		?>


	<form method="get" style="font-size:90%;">
		<input type="hidden" name="page" value="<?php print $_GET['page']?>" />
		<input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
        Top: <input type="number" name="top" value="<?php print $top?>"/>
		Dates From <input type="date" name="df" value="<?php print $_GET['df']?>"/> to 
		<input type="date" name="dt" value="<?php print $_GET['dt']?>"/> 
		Date Field: <select name="dateField"><?php 
		foreach (Donation::s()->dateFields as $field=>$label){?>
			<option value="<?php print $field?>"<?php print $dateField==$field?" selected":""?>><?php print $label?> Date</option>
		<?php } ?>
        </select>
		<br>
		Amount:  <input type="number" step=".01" name="af" value="<?php print $_GET['af']?>" style="width:120px;"/>
		to <input type="number" step=".01" name="at" value="<?php print $_GET['at']?>" style="width:120px;"/>
		Category:
		<?php print DonationCategory::select(['Name'=>'CategoryId','Count'=>true]);?>
		<br>
		Source: 		<select name="PaymentSource">
			<option value="">--All--</option>
			<?php			
			foreach(Donation::s()->tinyIntDescriptions["PaymentSource"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==$_GET['PaymentSource']?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>		

		Type:
		<select name="Type">
			<option value="">--All--</option>
			<?php			
			foreach(Donation::s()->tinyIntDescriptions["Type"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==$_GET['Type']?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		Transaction Type:
		<select name="TransactionType">
			<option value="">--All--</option>
			<?php
			foreach(Donation::s()->tinyIntDescriptions["TransactionType"] as $key=>$label){
				?><option value="<?php print $key==0?"ZERO":$key?>"<?php print ($key==0?"ZERO":$key)==$_GET['Type']?" selected":""?>><?php print $key." - ".$label?></option><?php
			}?>
		</select>
		<button name="f" value="Go">Go</button>
	</form>	<?php
	if($_GET['f']=="Go"){
		$where=["DT.Status>0"];
		if ($_GET['PaymentSource']){
			$where[]="PaymentSource='".($_GET['PaymentSource']=="ZERO"?0:$_GET['PaymentSource'])."'";		
		}	
		if ($_GET['Type']){
			$where[]="`Type`='".($_GET['Type']=="ZERO"?0:$_GET['Type'])."'";			
		}
		if ($_GET['TransactionType']){
			$where[]="TransactionType='".($_GET['TransactionType']=="ZERO"?0:$_GET['TransactionType'])."'";			
		}
		if ($_GET['df']){
			$where[]="`$dateField`>='".$_GET['df'].($dateField=="Date"?"":" 00:00:00")."'";
		}
		if ($_GET['dt']){
			$where[]="`$dateField`<='".$_GET['dt'].($dateField=="Date"?"":" 23:59:59")."'";
		}
		if ($_GET['CategoryId']){
			$where[]="CategoryId='".($_GET['CategoryId']=="ZERO"?0:$_GET['CategoryId'])."'";
		}
		if ($_GET['af'] && $_GET['at']){
			$where[]="DT.Gross BETWEEN '".$_GET['af']."' AND '".$_GET['at']."'";
		}elseif ($_GET['af']){
			$where[]="DT.Gross='".$_GET['af']."'";
		}elseif ($_GET['at']){
			$where[]="DT.Gross='".$_GET['at']."'";
		}

		$SQL="Select DT.DonationId,D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, DT.`Date`,DT.DateDeposited,DT.Gross,DT.TransactionID,DT.Subject,DT.Note,DT.PaymentSource,DT.Type ,DT.Source,DT.CategoryId,DT.TransactionType
        FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId 
        WHERE ".implode(" AND ",$where)." Order BY ".$dateField.",DT.Date,DonationId LIMIT ".$top;      
        $results = Donation::db()->get_results($SQL);
        ?><table class="dp"><tr><th>DonationId</th><th>Name</th><th>Transaction</th><th>Amount</th><th>Date</th><th>Deposit Date</th><th>Transaction Type</th><th>Category</th><th>Subject</th><th>Note</th></tr><?php
        foreach ($results as $r){
			$donation=new Donation($r);
			$donor=new Donor($r);
			?>
            <tr>
            <td><a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>"><?php print $r->DonationId?></a> | <a target="donation" href="?page=donor-reports&DonationId=<?php print $r->DonationId?>&edit=t">Edit</a></td>
                <td><?php print $donor->name_combine()?></td>
            <td><?php print ($donation->Source?$donation->Source." | ":"").$donation->show_field("PaymentSource")." - ".$r->TransactionID?></td>
            <td align=right><?php print number_format($r->Gross,2)?></td>
            <td><?php print $r->Date?></td>
            <td><?php print $r->DateDeposited?></td>
			<td><?php print $donation->show_field("TransactionType");?></td>
			<td><?php print $donation->show_field("CategoryId");?></td>
			<td><?php print $r->Subject?></td>
			<td><?php print $donation->show_field("Note");?></td>
            </tr>
			<?php
        }
        ?></table>
		<?php
	}
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

	?><form method="get" action="">
		<input type="hidden" name="page" value="<?php print $_GET['page']?>" />
		<input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
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
	if (!$_GET['yt']) $_GET['yt']=date('m')<5?date("Y")-1:date("Y");
	if (!$_GET['yf']){
		$results = $wpdb->get_results("SELECT MIN(Year(`Date`)) as Year	FROM ".Donation::get_table_name());
		$_GET['yf']=isset($results[0]->Year)?$results[0]->Year:date("Y")-1;
	}

	?><form method="get">
			<input type="hidden" name="page" value="<?php print $_GET['page']?>" />
			<input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
			Year: <input type="number" name="yf" value="<?php print $_GET['yf']?>"/> to <input type="number" name="yt" value="<?php print $_GET['yt']?>"/>
			<button>Go</button>		
	</form>
	<?php
	
	$where[]='`Gross`>0';
	$where[]="Year(`Date`) BETWEEN '".$_GET['yf']."' AND '".$_GET['yt']."'";
	if($_GET['RegressionDonorId']){
		$where[]="D.DonorId='".$_GET['RegressionDonorId']."'";
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
		for($year=key($years);$year<$_GET['yt'];$year++){
			$donorStats[$donorId]['years'][$year]=$donorYear[$donorId][$year];
		}
		if ($donorStats[$donorId]['years']) $donorStats[$donorId]['avg']=array_sum($donorStats[$donorId]['years'])/count($donorStats[$donorId]['years']);
		
		$amountDiff[$donorId]=$donorYear[$donorId][$_GET['yt']]-$donorStats[$donorId]['avg'];
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
			if ($years[$_GET['yt']]-$donorStats[$donorId]['avg']<0){
			?><tr>
				<td><?php print $donor[$donorId]->show_field('DonorId',['target'=>'donor']);?> <a href="?page=donor-reports&tab=stats&RegressionDonorId=<?php print $donorId?>" target="donor">Summary</a></td>
				<td><?php print $donor[$donorId]->name_combine()?></td>
				<td><?php print $donor[$donorId]->Email;?></td>
				<?php foreach($allYears as $year=>$total) print "<td align=right>".number_format($years[$year])."</td>";
				?><td align=right><?php print number_format($donorStats[$donorId]['avg'])?></td>
				<td align=right><?php print $donorStats[$donorId]['avg']?number_format(100*($years[$_GET['yt']]-$donorStats[$donorId]['avg'])/$donorStats[$donorId]['avg'],2)."%":"-"?></td>
			</tr>
			<?php
			}
		}
		?></table><?php
	}
	if($_GET['RegressionDonorId']){
		?>
		<div>Counts</div>	
		<table class="dp"><tr><th>#</th><th>Name</th><th>Email</th><?php
		foreach($allYears as $year=>$total) print "<th>".$year."</th>";
		?></tr><?php
		foreach ($amountDiff as $donorId=>$diff){
			$years=$donorCount[$donorId];
			//dd($years); //<a href="?page=donor-index&DonorId=print $donorId"> </a>
			if ($years[$_GET['yt']]-$donorStats[$donorId]['avg']<0){
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
			$graph['YearMonth'][date("Y",$timestamp)][date("n",$timestamp)]+=($countField=="Gross"?$r->Gross:1);
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

	  var data5 = google.visualization.arrayToDataTable([
		  ['Year' <?php
		 	foreach($graph['YearMonth'] as $y=>$a){
				print ",'".$y."'";
			}?>] 
			<?php
			for ($i=1;$i<=12;$i++){
				print ", ['".$i."'";
				foreach($graph['YearMonth'] as $y=>$a){
					print ",".($a[$i]?$a[$i]:0);
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
	<input type="hidden" name="page" value="<?php print $_GET['page']?>" />
	<input type="hidden" name="tab" value="<?php print $_GET['tab']?>" />
			<h3>Monthly Donations Report From <input type="date" name="topDf" value="<?php print $_GET['topDf']?>"/> to <input type="date" name="topDt" value="<?php print $_GET['topDt']?>"/> 
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
				?><tr><td><?php print  $yearMonth?></td><td><?php print Donation::get_tiny_description('Type',$type)??$type?></td><td align=right><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&report=reportMonthly&view=detail&month=<?php print $yearMonth?>&type=<?php print urlencode($type)?>"><?php print number_format($total,2)?></a></td><td align=right><?php print $graph['Count'][$yearMonth][$type]?></td></tr><?php
		
			}
		}
		?></table>
		</form>	
		
		<?php
	}
}
