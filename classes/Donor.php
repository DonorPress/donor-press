<?php

require_once 'ModelLite.php';
require_once 'Donation.php';
require_once 'DonationReceipt.php';

class Donor extends ModelLite {
    protected $table = 'Donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    protected $fillable = ["Source","SourceId","Name","Name2","Email","EmailStatus","Phone","Address1","Address2","City","Region","PostalCode","Country","TaxReporting","MergedId"];	    
	### Default Values
	protected $attributes = [        
        'Country' => 'US',
        'TaxReporting'=> 0,
        'EmailStatus'=>1
    ];
    
    protected $tinyIntDescriptions=[
        "EmailStatus"=>["-1"=>"Returned","0"=>"Not Set","1"=>"Valid"],        
    ];
	//public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt';
   
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::get_table_name()."` (
            `DonorId` int(11) NOT NULL AUTO_INCREMENT,
            `Source` varchar(20) NOT NULL,
            `SourceId` varchar(50) NOT NULL,
            `Name` varchar(60) NOT NULL,
            `Name2` varchar(60)  NULL,
            `Email` varchar(80)  NULL,
            `EmailStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-1=Bounced 1=Active',
            `Phone` varchar(20)  NULL,
            `Address1` varchar(80)  NULL,
            `Address2` varchar(80)  NULL,
            `City` varchar(50)  NULL,
            `Region` varchar(20)  NULL,
            `PostalCode` varchar(20)  NULL,
            `Country` varchar(2)  NULL,
            `MergedId` int(11) NOT NULL DEFAULT '0',
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `TaxReporting` tinyint(4) DEFAULT '0' COMMENT '0 - Standard -1 Not Required -2 Opt Out',
            PRIMARY KEY (`DonorId`)
            )";
          dbDelta( $sql );
    }

    static public function from_paypal_api_detail($detail){
        $payer=$detail->payer_info;
        $shipInfo=$detail->shipping_info;

        $d = new self();
        $d->Source='paypal';
        $d->SourceId=$payer->account_id;
        $d->Email=$payer->email_address; 
        //$d->EmailStatus=1; //if already set elswhere... should not be etting this
        $d->Name=$payer->payer_name->alternate_full_name;
        $address=$payer->address?$payer->address:$shipInfo; //address not always provided, but if it is, look first if it is on the payer object, otherwise look at shipping_info
        if ($address){
            $d->Address1=$address->line1;
            if ($address->line2) $d->Address2=$address->line2;
            $d->City=$address->city;
            if ($address->State) $d->Region=$address->state;
            $d->Country=$address->country_code;
        }elseif($payer->country_code){
            $d->Country=$payer->country_code;  //entries without addresses usually at least have country codes.
        }

        return $d;
        //$d->TaxReporting=0        

    }

    public function merge_form(){
        ?><form method="post">
        <input type="hidden" name="MergeFrom" value="<?php print $this->DonorId?>"/> 
        Merge To Id: <input type="number" name="MergedId" value="">
        <button method="submit" name="Function" value="MergeConfirm">Merge</button>
        Enter the ID of the Donor you want to merge to. You will have the option to review this merge. Once merged, all donations will be relinked to the new profile.</form><?
    }

    public function merge_form_compare($oldDonor){
        if ($this->DonorId==$oldDonor->DonorId){
            self::display_error("Can't Merge entry to itself.");           
            return;
        }
        $where= array("DonorId IN (".$this->DonorId.",".$oldDonor->DonorId.")");
        $SQL="SELECT DonorId, Count(*) as C,SUM(`Gross`) as Total, MIN(Date) as DateEarliest, MAX(Date) as DateLatest FROM ".Donation::get_table_name()." WHERE ".implode(" AND ",$where)." Group BY DonorId";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $stats[$r->DonorId]=$r;
        }
        //self::dump($stats);
        foreach($oldDonor as $field=>$value){
            if ($value!=$this->$field){
                $changes[$field]=$value;
            }
        }
        ?><form method='post'>
        <input type='hidden' name='donorIds[]' value='<?php print $this->DonorId?>'>
        <input type='hidden' name='donorIds[]' value='<?php print $oldDonor->DonorId?>'>
        <h2>The following changes are suggested</h2>
        <form method='post'>
        <table border='1'><tr><th>Field</th><th>Donor A</th><th>Donor B</th></tr><?
        foreach($changes as $field=>$value){
            ?><tr><td><?php print $field?></td>
            <td><input type="radio" name="<?php print $field?>" value="<?php print $value?>"<?php print !$this->$field?" checked":""?>><?php print $value?></td>
            <td><input type="radio" name="<?php print $field?>" value="<?php print $this->$field?>"<?php print $this->$field?" checked":""?>><?php print $this->$field?></td>
            </tr><?php                                    
        }
        ?><tr><td>Donation Details Will Merge</td><td><?
        $thisStat=$stats[$oldDonor->DonorId];
        print $thisStat->C." Donations $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?></td>
        <td><?
        $thisStat=$stats[$this->DonorId];
        print $thisStat->C." Donations $".number_format($thisStat->Total,2);
        print " ".substr($thisStat->DateEarliest,0,10).($thisStat->DateEarliest!=$thisStat->DateLatest?" to ".substr($thisStat->DateLatest,0,10):"");
        ?>
        </td></tr>
        </table>
        <button type='submit' name='Function' value='MergeDonor'>Merge Donors</button>
        </form><?

    }

    public function view(){ 
        $totals=[];    
        $this->var_view();
        ?>
        <div><a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=AddDonation">Add Donation</a></div><?
        $this->merge_form();
        ?>
        <h2>Donation Summary</h2>
        <div>Year End Receipt: <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")?>"><?php print date("Y")?></a> | <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-1?>"><?php print date("Y")-1?></a> | <a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $this->DonorId?>&f=YearReceipt&Year=<?php print date("Y")-2?>"><?php print date("Y")-2?></a></div>
        <?php
        $SQL="SELECT  `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Donation::get_table_name()." 
        WHERE DonorId='".$this->DonorId."'  Group BY `Type`";
        $results = self::db()->get_results($SQL);
        ?><table border=1><tr><th>Type</th><th>Count</th><th>Amount</th></tr><?
        foreach ($results as $r){?>
            <tr><td><?php print $r->Type?></td><td><?php print $r->Count?></td><td align=right><?php print number_format($r->Total,2)?></td></tr><?
            $totals['Count']+=$r->Count;
            $totals['Total']+=$r->Total;
        }        
        if (sizeof($results)>1){?>
        <tfoot style="font-weight:bold;"><tr><td>Totals:</td><td><?php print $totals['Count']?></td><td align=right><?php print number_format($totals['Total'],2)?></td></tr></tfoot>
        <?} ?></table>
        <h2>Donation List</h2>
		<?
		$results=Donation::get(array("DonorId='".$this->DonorId."'"),"Date DESC");
		print Donation::show_results($results);		
    }

    static public function request_handler(){      
        if ($_POST['Function']=='MergeDonor' && $_POST['DonorId']){
            //self::dump($_POST);            
            $data=array();
            foreach(self::s()->fillable as $field){
                if ($_POST[$field] && $field!='DonorId'){
                    $data[$field]=$_POST[$field];
                }
            }
            if (sizeof($data)>0){
                ### Update Master Entry with Fields from merged details
                self::db()->update(self::s()->get_table(),$data,array('DonorId'=>$_POST['DonorId']));
            }
            $mergeUpdate['MergedId']=$_POST['DonorId'];
            foreach($_POST['donorIds'] as $oldId){
                if ($oldId!=$_POST['DonorId']){
                    ### Set MergedId on Old Donor entry
                    self::db()->update(self::s()->get_table(),$mergeUpdate,array('DonorId'=>$oldId));
                    ### Update all donations on old donor to new donor
                    $uSQL="UPDATE ".Donation::s()->get_table()." SET DonorId='".$_POST['DonorId']."' WHERE DonorId='".$oldId."'";  
                    self::db()->query($uSQL);
                    self::display_notice("Donor #<a href='?page=".$_GET['page']."&DonorId=".$oldId."'>".$oldId."</a> merged to #<a href='?page=".$_GET['page']."&DonorId=".$_POST['DonorId']."'>".$_POST['DonorId']."</a>");
                }
            }  
            $_GET['DonorId']=$_POST['DonorId']; 

        }
        if ($_GET['f']=="AddDonor"){
            $donor=new Donor;
            if ($_REQUEST['dsearch'] && !$donor->DonorId && !$donor->Name){
                $donor->Name=$_REQUEST['dsearch'];
            }          
            print "<h2>Add Donor</h2>";            
            $donor->edit_form();           
            return true;
        }elseif($_GET['f']=="summary_list" && $_GET['dt'] && $_GET['df']){            
            self::summary_list(array("Date BETWEEN '".$_GET['df']." 00:00:00' AND '".$_GET['dt']." 23:59:59'"));
            return true;
        }elseif($_GET['f']=="summary_list_year" && $_GET['Year']){
            $year=$_GET['Year'];
            $limit=$_REQUEST['limit'];
            if (!$_REQUEST['limit']) $limit=1000;

            if($_POST['Function']=='SendYearEndPdf'){
                if (sizeof($_POST['pdf'])<$limit) $limit=sizeof($_POST['pdf']);
                for($i=0;$i<$limit;$i++){
                    $donorIds[]=$_POST['pdf'][$i];
                }
                if (sizeof($donorIds)>0){
                    $donorList=Donor::get(array("DonorId IN ('".implode("','",$donorIds)."')"));
                    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                    $pdf->SetFont('helvetica', '', 12);
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false); 
                    $path=plugin_dir_url( __FILE__ )."resources/YearEndReceipt".$year.".pdf"; //not acceptable on live server...
                    foreach ($donorList as $donor){
                        $donor->year_receipt_email($year);
                        $pdf->AddPage();
                        $pdf->writeHTML("<h2>".$donor->emailBuilder->subject."</h2>".$donor->emailBuilder->body, true, false, true, false, '');
                        if ($_REQUEST['blankBack'] && $pdf->PageNo()%2==1){ //add page number check
                            $pdf->AddPage();
                        }
                    }                    
                    if ($pdf->Output($_SERVER['DOCUMENT_ROOT'].$path, 'F')){
                       
                    }
                    self::display_notice("Outputed Year End PDF: <a target=\"pdf\" href=\"".$path."\">Download</a>");
                }

            }elseif($_POST['Function']=='SendYearEndEmail'){               
                if (sizeof($_POST['emails'])<$limit) $limit=sizeof($_POST['emails']);
                for($i=0;$i<$limit;$i++){
                    $donorIds[]=$_POST['emails'][$i];
                }
                
                if (sizeof($donorIds)>0){
                    $donorList=Donor::get(array("DonorId IN ('".implode("','",$donorIds)."')"));
                    foreach ($donorList as $donor){                        
                        $donor->year_receipt_email($year);
                        //self::dd("I am before here");
                        if (wp_mail($donor->Email, $donor->emailBuilder->subject, $donor->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){                            
                            $dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$donor->Email,"DateSent"=>date("Y-m-d H:i:s")));                            
                            $dr->save();                     
                        }                      
                    }
                }
                self::display_notice("E-mailed Year End Receipts to  $limit of  ".sizeof($_POST['emails']));

            }
            self::summary_list_year($_GET['Year']);
            return true;
        }elseif($_POST['Function']=='MergeConfirm'){ 
            $donorA=Donor::get_by_id($_POST['MergeFrom']);
            $donorB=Donor::get_by_id($_POST['MergedId']);
            if (!$donorB->DonorId){
                 print self::display_error("Donor ".$_POST['MergedId']." not found.");
                 return false;
            }else{
                $donorB->merge_form_compare($donorA);
            }
            return true;
        }elseif ($_GET['DonorId']){	
            if ($_POST['Function']=="year_receipt_pdf" && $_GET['Year']){
                $donor=Donor::get_by_id($_REQUEST['DonorId']);
                $file= $donor->year_receipt_pdf($_GET['Year']);  
                $dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$_GET['Year'],"Type"=>"m","Address"=>$donor->mailing_address(),"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();             
            }

            if ($_GET['f']=="YearReceipt"){
                $donor=Donor::get_by_id($_REQUEST['DonorId']);
                print $donor->year_receipt_form($_GET['Year']);
                return true;
            }
            
            if ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
                $donor=new Donor($_POST);
                if ($donor->save()){			
                    self::display_notice("Donor #".$donor->showField("DonorId")." saved.");
                }
                
            }
            $donor=Donor::get_by_id($_REQUEST['DonorId']);	
            ?>
            <div id="pluginwrap">
                <div><a href="?page=<?php print $_GET['page']?>">Return</a></div>
                <h1>Donor Profile #<?php print $_REQUEST['DonorId']?> <?php print $donor->Name?></h1><?	
                if ($_REQUEST['edit']){
                    $donor->edit_form();
                }else{
                    ?><div><a href="?page=<?php print $_GET['page']?>&DonorId=<?php print $donor->DonorId?>&edit=t">Edit Donor</a></div><?
                    $donor->view();                    
                }
            ?></div><?php            
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
            $donor=new Donor($_POST);
            if ($donor->save()){			
                self::display_notice("Donor #".$donor->showField("DonorId")." saved.");
                $donor->view();
            }
            return true;
            
        }elseif($_POST['Function']=='MakeDonorChanges'){            
            self::db()->show_errors();
            foreach($_POST['changes'] as $line){
                $v=explode("||",$line); //$donorId."||".$field."||".$value
                $changes[$v[0]][$v[1]]=$v[2];
            }
            if (sizeof($changes)>0){
                foreach($changes as $donorId=>$change){
                    $ulinks[]='<a target="lookup" href="?page='.$_GET['page'].'&DonorId='.$donorId.'">'.$donorId.'</a>';
                    self::db()->update(self::get_table_name(),$change,array("DonorId"=>$donorId));

                }
                self::display_notice(sizeof($changes)." Entries Updated: ".implode(" | ",$ulinks));
            }

        }else{
            return false;
        }
    }

    function mailing_address(){
        $address="";
        if ($this->Address1) $address.=$this->Address1."<br>";
        if ($this->Address2) $address.=$this->Address2."<br>";
        if ($this->City || $this->Region) $address.=$this->City." ".$this->Region." ".$this->PostalCode." ".$this->Country;
        $nameLine=$this->Name.($this->Name2?" & ".$this->Name2:"");
        if (trim($address)){
            $address=$nameLine."<br>".$address;
        }
        return $address;
    }

    function name_check(){        
        return self::name_check_individual($this->Name).($this->Name2?" & ".self::name_check_individual($this->Name2):"");
    }

    static function name_check_individual($name){
        $names=explode(" ",$name);
        $alert=false;
        foreach ($names as $n){
            if (ucfirst($n)!=$n){
                $alert=true;
            }
        }
       
        if ($alert) return "<span style='background-color:yellow;'>".$name."</span>";
        else return $name;

    }

    public function address(){
        return ($this->Address1?$this->Address1.", ":"").($this->Address2?$this->Address2.", ":"").$this->City." ".$this->Region." ".$this->PostalCode." ".$thiCountry;
    }
    
    public function phone(){
        return preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1) $2-$3', $this->Phone);
    }
    
    static function summary_list($where=[]){
        $total=0;
        $where[]="Status>=0";
        $where[]="Type>=1";
        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country`, COUNT(*) as donation_count, SUM(Gross)  as Total , MIN(DT.Date) as DateEarliest, MAX(DT.Date) as DateLatest FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE ".implode(" AND ",$where)." Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,`Phone`, `Address1`, `Address2`, `City`, `Region`, `PostalCode`, `Country` Order BY  SUM(Gross) DESC,COUNT(*) DESC";
        $results = self::db()->get_results($SQL);
        ?><div><a href="?page=<?php print $_GET['page']?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table border=1><tr><th>Donor</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Count</th><th>Amount</th><th>First Donation</th><th>Last Donation</th></tr><?
        foreach ($results as $r){
            $donor=new self($r);
            ?>
            <tr>
                <td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a></td><td><?php print $donor->name_check()?></td>
                <td><?php print $donor->DisplayEmail()?></td>    
                <td><?php print $donor->phone()?></td> 
                <td><?php print $donor->address()?></td>          
                <td><?php print $r->donation_count?></td>
                <td><?php print $r->Total?></td>
                <td><?php print $r->DateEarliest?></td>
                <td><?php print $r->DateLatest?></td>
            </tr><?
            $total+=$r->Total;
        }?><tr><td></td><td></td><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td></tr></table>
                  
        <?
        return;
    }

    static function summary_list_year($year){       
        ### Find what receipt haven't been sent yet
        $SQL="SELECT `DonorId`, `Type`, `Address`, `DateSent` FROM ".DonationReceipt::get_table_name()." WHERE `KeyType`='YearEnd' AND `KeyId`='".$year."'";
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){
            $receipts[$r->DonorId][]=new DonationReceipt($r);
        }

        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, COUNT(*) as donation_count, SUM(Gross) as Total FROM ".Donor::get_table_name()." D INNER JOIN ".Donation::get_table_name()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 AND DT.NotTaxExcempt=0 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";
        $results = self::db()->get_results($SQL);
        ?><div><a href="?page=<?php print $_GET['page']?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?php print $year?>"/>
        <table border=1><tr><th>Donor</th><th>Name</th><th>Email</th><th>Count</th><th>Amount</th><th>Preview</th><th><input type="checkbox" checked onClick="toggleChecked(this,'emails[]');")/>
        <script>
            function toggleChecked(source,name){                
                checkboxes = document.getElementsByName(name);
                for(var i=0, n=checkboxes.length;i<n;i++) {
                    checkboxes[i].checked = source.checked;
                }                    
            }
        </script> E-mail</th><th><input type="checkbox" checked onClick="toggleChecked(this,'pdf[]');")/> PDF</th><th>Sent</th></tr><?
        foreach ($results as $r){
            $donor=new self($r);
            ?>
            <tr><td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>"><?php print $r->DonorId?></a></td><td><?php print $donor->name_check()?></td>
            <td><?php print $donor->DisplayEmail()?></td>            
            <td><?php print $r->donation_count?></td>
            <td align=right><?php print number_format($r->Total,2)?></td><td><a target="Donor" href="?page=<?php print $_GET['page']?>&DonorId=<?php print $r->DonorId?>&f=YearReceipt&Year=<?php print $year?>">Receipt</a></td>
            <td><?
             if (filter_var($r->Email, FILTER_VALIDATE_EMAIL) && $r->EmailStatus>=0) {
                ?><input name="emails[]" type="checkbox" value="<?php print $r->DonorId?>" <?php print ($receipts[$r->DonorId] ?"":" checked")?>/><?
             }
            ?></td>
            <td><?
             if ($r->Address1 && $r->City) {
                ?><input name="pdf[]" type="checkbox" value="<?php print $r->DonorId?>" <?php print ($receipts[$r->DonorId]|| $r->donation_count<2?"":" checked")?>/><?
             }
            ?></td><td><?
            //self::dump($receipts[$r->DonorId]);
            print DonationReceipt::displayReceipts($receipts[$r->DonorId]);
            ?></td></tr><?
            $total+=$r->Total;
        }?><tr><td></td><td></td><td></td><td></td><td style="text-align:right;"><?php print number_format($total,2)?></td><td></td><td></td><td></td></table>
        Limit: <Input type="number" name="limit" value="<?php print $_REQUEST['limit']?>" style="width:50px;"/>
        <button type="submit" name="Function" value="SendYearEndEmail">Send Year End E-mails</button>
        <button type="submit" name="Function" value="SendYearEndPdf">Send Year End Pdf</button> <label><input type="checkbox" name="blankBack" value="t"> Print Blank Back</label>
        </form>
        <?
        return;
    }

    function year_receipt_email($year){
        $this->emailBuilder=new stdClass();
        $page = DonorTemplate::get_by_name('donor-receiptyear');  
        //$this->dump($page);
        if (!$page){ ### Make the template page if it doesn't exist.
            self::make_receipt_year_template();
            $page = DonorTemplate::get_by_name('donor-receiptyear');  
            self::display_notice("Page /donor-receiptyear created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a>");
        }
        $this->emailBuilder->pageID=$page->ID;
        $donations=Donation::get(array("DonorId=".$this->DonorId,"YEAR(Date)='".$year."'","NotTaxExcempt=0"),'Date');
        if ($donations){
            $ReceiptTable='<table border="1" cellpadding="4"><tr><th width="115">Date</th><th width="330">Subject</th><th width="100">Amount</th></tr>';
            foreach($donations as $r){
                $ReceiptTable.="<tr><td>".date("F j, Y",strtotime($r->Date))."</td><td>";
                switch($r->PaymentSource){
                    case 1:
                        $ReceiptTable.="Check".(is_numeric($r->TransactionID)?" #".$r->TransactionID:"");$ReceiptTable.=$r->Subject?" ".$r->Subject:"";
                        break;
                    case "5":
                        $ReceiptTable.="Paypal".($r->Subject?": ".$r->Subject:"");
                        break;
                    case "6": 
                        $ReceiptTable.="ACH/Wire".($r->Subject?": ".$r->Subject:($r->TransactionID?" #".$r->TransactionID:""));
                        break;
                    default:  $ReceiptTable.= $r->Subject;
                    break;                  
                } 
                if (!$r->Subject && $r->CategoryId) $ReceiptTable.=" ".$r->show_field("CategoryId",false) ;
                            
                $ReceiptTable.="</td><td align=\"right\">".trim(number_format($r->Gross,2)." ".$r->Currency).'</td></tr>';
                $total+=$r->Gross;
                $lastCurrency=$r->Currency;
            }
            $ReceiptTable.="<tr><td colspan=\"2\"><strong>Total:</strong></td><td align=\"right\"><strong>".trim(number_format($total,2)." ".$lastCurrency)."</strong></td></tr></table>";
        }else{ $ReceiptTable.="<div><em>No Donations found in ".$year."</div>";}
        
        $organization=get_option( 'donation_Organization');
        if (!$organization) $organization=get_bloginfo('name');
        $subject=$page->post_title;
        $body=$page->post_content;
        
        ### custom variables
        $body=trim(str_replace("##Organization##",$organization,$body));
        $body=trim(str_replace("##FederalId##", get_option( 'donation_FederalId' ),$body)); 
        $body=trim(str_replace("##ContactName##", get_option( 'donation_ContactName' ),$body)); 
        $body=trim(str_replace("##ContactTitle##", get_option( 'donation_ContactTitle' ),$body)); 
        $body=trim(str_replace("##ContactEmail##", get_option( 'donation_ContactEmail' ),$body)); 
        ### generated variables
        $body=str_replace("##Name##",$this->Name.($this->Name2?" & ".$this->Name2:""),$body);
        $body=str_replace("##Year##",$year,$body);
        $body=str_replace("##DonationTotal##","$".number_format($total,2),$body);
        $body=str_replace("<p>##ReceiptTable##</p>",$ReceiptTable,$body);
        $body=str_replace("##ReceiptTable##",$ReceiptTable,$body);
        $address=$this->mailing_address();
        if (!$address){ //remove P
            $body=str_replace("<p>##Address##</p>",$address,$body);
        }
        $body=str_replace("##Address##",$address,$body);

        $body=str_replace("##Date##",date("F j, Y"),$body);

        $body=str_replace("<!-- wp:paragraph -->",'',$body);
        $body=str_replace("<!-- /wp:paragraph -->",'',$body);
        $subject=trim(str_replace("##Year##",$year,$subject));
        $subject=trim(str_replace("##Organization##",$organization,$subject));
        $this->emailBuilder->subject=$subject;
        $this->emailBuilder->body=$body; 
        

        $pageHashes=explode("##",$body); 
        $c=0;
        foreach($pageHashes as $r){
            if ($c%2==1){
                if (strlen($r)<16){
                    $variableNotFilledOut[$r]=1;
                }
            }
            $c++;
        }
        if (sizeof($variableNotFilledOut)>0){
            print self::display_error("The Following Variables need manually changed:<ul><li>##".implode("##</li><li>##",array_keys($variableNotFilledOut))."##</li></ul> Please <a target='pdf' href='post.php?post=".$this->emailBuilder->pageID."&action=edit'>correct template</a>.");
        }
    }

    function year_receipt_form($year){
        //require ( WP_PLUGIN_DIR.'/tcpdf-wrapper/lib/tcpdf/tcpdf.php' );        
        $this->year_receipt_email($year);  
        $form="";      
        if ($_POST['Function']=="SendYearReceipt" && $_POST['Email']){
            if (wp_mail($_POST['Email'], $this->emailBuilder->subject, $this->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
                $form.="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$_POST['Email']."</div>";
                $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$_POST['Email'],"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();
            }
        }

        $f=$this->receipt_file_info($year);
        ### Form View
        $form.='<div class="no-print"><hr><form method="post">Send Receipt to: <input type="email" name="Email" value="'.($_POST['Email']?$_POST['Email']:$this->Email).'"/><button type="submit" name="Function" value="SendYearReceipt">Send E-mail</button>
        <button type="submit" name="Function" value="year_receipt_pdf">Generate PDF</button>';
        if (file_exists($f['path'])){
            $form.=' View <a target="pdf" href="'.$f['link'].'">'.$f['file'].'</a>';
        }
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","`KeyType`='YearEnd'","`KeyId`='".$year."'"));
        $form.=DonationReceipt::show_results($receipts);
        $form.='</form>';

        $form.="<div><a target='pdf' href='post.php?post=".$this->emailBuilder->pageID."&action=edit'>Edit Template</a></div>";
      
        $homeLinks="<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        return $homeLinks."<h2>".$this->emailBuilder->subject."</h2>".$this->emailBuilder->body.$form;
    }
    function year_receipt_pdf($year){
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->year_receipt_email($year);
        $html="<h2>".$this->emailBuilder->subject."</h2>".$this->emailBuilder->body;
        $pdf->SetFont('helvetica', '', 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $f=$this->receipt_file_info($year);
        $file=$f['file'];
        $path=$f['path'];
        $pdf->Output($path, 'F');
        return array('path'=>$path,'file'=>$file);
    }
    function receipt_file_info($year){
        $file=substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-'.$year.'.pdf';
        $link=plugin_dir_url( __FILE__ )."resources/".$file; //Not acceptable on live server... May need to scramble code name on file so it isn't guessale.
        return array('path'=>$_SERVER['DOCUMENT_ROOT'].$link,'file'=>$file,'link'=>$link);
    }

    static function make_receipt_year_template(){
        $page = DonorTemplate::get_by_name('donor-receiptyear');  
        if (!$page){
            $postarr['ID']=0;
            $tempLoc=dn_plugin_base_dir()."/resources/template_default_receipt_year.html";          
            $postarr['post_content']=file_get_contents($tempLoc);
            $postarr['post_title']='##Organization## ##Year## Year End Receipts';
            $postarr['post_status']='private';
            $postarr['post_type']='donortemplate';
            $postarr['post_name']='donor-receiptyear';           
            return wp_insert_post($postarr);            
        }
    }
}