<?php
require_once("ModelLite.php");
require_once("Donation.php");
require_once("DonationReceipt.php");

class Donor extends ModelLite {
    protected $table = 'Donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    protected $fillable = ["Name","Name2","Email","EmailStatus","Phone","Address1","Address2","City","Region","PostalCode","Country","TaxReporting"];	    
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
   
    static public function createTable(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php');
          $sql="CREATE TABLE IF NOT EXISTS `".self::getTableName()."` (
            `DonorId` int(11) NOT NULL AUTO_INCREMENT,
            `Name` varchar(60) NOT NULL,
            `Name2` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `Email` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `EmailStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-1=Bounced 1=Active',
            `Phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `Address1` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `Address2` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `City` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `Region` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `PostalCode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `Country` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
            `MergedId` int(11) NOT NULL DEFAULT '0',
            `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `TaxReporting` tinyint(4) DEFAULT '0' COMMENT '0 - Standard -1 Not Required -2 Opt Out',
            PRIMARY KEY (`DonorId`)
          ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";
          dbDelta( $sql );
    }

    public function view(){     
        $this->varView();
        ?>
        <div><a href="?page=<?=$_GET['page']?>&DonorId=<?=$this->DonorId?>&f=AddDonation">Add Donation</a></div>
        <h2>Donation Summary</h2>

        <div>Year End Receipt: <a href="?page=<?=$_GET['page']?>&DonorId=<?=$this->DonorId?>&f=YearReceipt&Year=<?=date("Y")?>"><?=date("Y")?></a> | <a href="?page=<?=$_GET['page']?>&DonorId=<?=$this->DonorId?>&f=YearReceipt&Year=<?=date("Y")-1?>"><?=date("Y")-1?></a> | <a href="?page=<?=$_GET['page']?>&DonorId=<?=$this->DonorId?>&f=YearReceipt&Year=<?=date("Y")-2?>"><?=date("Y")-2?></a></div>
        <?

        global $wpdb;
        $SQL="SELECT  `Type`,	SUM(`Gross`) as Total,Count(*) as Count FROM ".Donation::getTableName()." 
        WHERE DonorId='".$this->DonorId."'  Group BY `Type`";
        $results = $wpdb->get_results($SQL);
        ?><table border=1><tr><th>Type</th><th>Count</th><th>Amount</th></tr><?
        foreach ($results as $r){?>
            <tr><td><?=$r->Type?></td><td><?=$r->Count?></td><td align=right><?=number_format($r->Total,2)?></td></tr><?
            $totals['Count']+=$r->Count;
            $totals['Total']+=$r->Total;

        }
        if (sizeof($results)>1){?>
        <tfoot style="font-weight:bold;"><tr><td>Totals:</td><td><?=$totals['Count']?></td><td align=right><?=number_format($totals['Total'],2)?></td></tr></tfoot>
        <?} ?></table>
        <h2>Donation List</h2>
		<?
		$results=Donation::get(array("DonorId='".$this->DonorId."'"));
		print Donation::showResults($results);		
		//$results = $wpdb->get_results($SQL);
    }

    static public function requestHandler(){
        if ($_GET['f']=="AddDonor"){           
            $donor=new Donor;            
            print "<h2>Add Donor</h2>";            
            $donor->editForm();           
            return true;
        }elseif($_GET['f']=="YearSummaryList" && $_GET['Year']){
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
                    $path="/wp-content/plugins/WPDonorPaypal/receipts/YearEndReceipt".$year.".pdf";
                    foreach ($donorList as $donor){
                        $donor->YearReceiptEmail($year);
                        $pdf->AddPage();
                        $pdf->writeHTML("<h2>".$donor->emailBuilder->subject."</h2>".$donor->emailBuilder->body, true, false, true, false, '');
                        if ($_REQUEST['blankBack'] && $pdf->PageNo()%2==1){ //add page number check
                            $pdf->AddPage();
                        }

                    }                    
                    if ($pdf->Output($_SERVER['DOCUMENT_ROOT'].$path, 'F')){
                       
                    }
                    print "<div class=\"notice notice-success is-dismissible\">Outputed Year End PDF: <a target=\"pdf\" href=\"".$path."\">Download</a></div>";
                    // return array('path'=>$path,'file'=>$file);

                }
              //$_REQUEST['blankBack']

            }elseif($_POST['Function']=='SendYearEndEmail'){               
                if (sizeof($_POST['emails'])<$limit) $limit=sizeof($_POST['emails']);
                for($i=0;$i<$limit;$i++){
                    $donorIds[]=$_POST['emails'][$i];
                }
                if (sizeof($donorIds)>0){
                    $donorList=Donor::get(array("DonorId IN ('".implode("','",$donorIds)."')"));
                    foreach ($donorList as $donor){
                        $donor->YearReceiptEmail($year);
                       // $donor->Email='denver@steiner7.com';
                        //print "<h2>".$donor->emailBuilder->subject."</h2><h3>Send To: ".$donor->Email."</h3>".$donor->emailBuilder->body;
                        if (wp_mail($donor->Email, $donor->emailBuilder->subject, $donor->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
                            $dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$donor->Email,"DateSent"=>date("Y-m-d H:i:s")));
                            $dr->save();
                            
                        }
                    }
                    //self::dump($donorList);
                }
                 print "<div class=\"notice notice-success is-dismissible\">E-mailed Year End Receipts to  $limit of  ".sizeof($_POST['emails'])."</div>";

            }
            self::YearSummaryList($_GET['Year']);
            return true;
        }elseif ($_GET['DonorId']){	
            if ($_POST['Function']=="YearReceiptPdf" && $_GET['Year']){
                $donor=Donor::getById($_REQUEST['DonorId']);
                $file= $donor->YearReceiptPdf($_GET['Year']);  
                $dr=new DonationReceipt(array("DonorId"=>$donor->DonorId,"KeyType"=>"YearEnd","KeyId"=>$_GET['Year'],"Type"=>"m","Address"=>$donor->MailingAddress(),"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();             
            }

            if ($_GET['f']=="YearReceipt"){
                $donor=Donor::getById($_REQUEST['DonorId']);
                print $donor->YearReceiptForm($_GET['Year']);
                return true;
            }
            
            if ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
                $donor=new Donor($_POST);
                if ($donor->save()){			
                    print "<div class=\"notice notice-success is-dismissible\">Donor #".$donor->showField("DonorId")." saved.</div>";
                }
                
            }
            $donor=Donor::getById($_REQUEST['DonorId']);	
            ?>
            <div id="pluginwrap">
                <div><a href="?page=<?=$_GET['page']?>">Return</a></div>
                <h1>Donor Profile #<?=$_REQUEST['DonorId']?> <?=$donor->Name?></h1><?	
                if ($_REQUEST['edit']){
                    $donor->editForm();
                }else{
                    ?><div><a href="?page=<?=$_GET['page']?>&DonorId=<?=$donor->DonorId?>&edit=t">Edit Donor</a></div><?
                    $donor->view();
                }
            ?></div><?            
            return true;
        }elseif ($_POST['Function']=="Save" && $_POST['table']=="Donor"){
            $donor=new Donor($_POST);
            if ($donor->save()){			
                print "<div class=\"notice notice-success is-dismissible\">Donor #".$donor->showField("DonorId")." saved.</div>";
                $donor->view();
            }
            return true;
            
        }elseif($_POST['Function']=='MakeDonorChanges'){
            global $wpdb;
            $wpdb->show_errors();
            foreach($_POST['changes'] as $line){
                $v=explode("||",$line); //$donorId."||".$field."||".$value
                $changes[$v[0]][$v[1]]=$v[2];
            }
            if (sizeof($changes)>0){
                foreach($changes as $donorId=>$change){
                    $ulinks[]='<a target="lookup" href="?page='.$_GET['page'].'&DonorId='.$donorId.'">'.$donorId.'</a>';
                    $wpdb->update(self::getTableName(),$change,array("DonorId"=>$donorId));

                }
                print "<div class=\"notice notice-success is-dismissible\">".sizeof($changes)." Entries Updated: ".implode(" | ",$ulinks)."</div>";
            }

        }else{
            return false;
        }
    }

    function MailingAddress(){
        if ($this->Address1) $address.=$this->Address1."<br>";
        if ($this->Address2) $address.=$this->Address2."<br>";
        if ($this->City || $this->Region) $address.=$this->City." ".$this->Region." ".$this->PostalCode." ".$this->Country;
        $nameLine=$this->Name.($this->Name2?" & ".$this->Name2:"");
        if (trim($address)){
            $address=$nameLine."<br>".$address;
        }
        return $address;
    }

    function NameCheck(){        
        return self::NameCheckIndividual($this->Name).($this->Name2?" & ".self::NameCheckIndividual($this->Name2):"");
    }

    static function NameCheckIndividual($name){
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

    static function YearSummaryList($year){
        global $wpdb;
        ### Find what receipt haven't been sent yet
        $SQL="SELECT `DonorId`, `Type`, `Address`, `DateSent` FROM ".DonationReceipt::getTableName()." WHERE `KeyType`='YearEnd' AND `KeyId`='".$year."'";
        $results = $wpdb->get_results($SQL);
        foreach ($results as $r){
            $receipts[$r->DonorId][]=new DonationReceipt($r);
        }

        $SQL="Select D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City, COUNT(*) as DonationCount, SUM(Gross) as Total FROM ".Donor::getTableName()." D INNER JOIN ".Donation::getTableName()." DT ON D.DonorId=DT.DonorId WHERE YEAR(Date)='".$year."' AND  Status>=0 AND Type>=0 Group BY D.DonorId, D.Name, D.Name2,`Email`,EmailStatus,Address1,City Order BY COUNT(*) DESC, SUM(Gross) DESC";
        //print $SQL;
        $results = $wpdb->get_results($SQL);
        ?><div><a href="?page=<?=$_GET['page']?>">Return</a></div><form method=post><input type="hidden" name="Year" value="<?=$year?>"/>
        <table border=1><tr><th>Donor</th><th>Name</th><th>Email</th><th>Count</th><th>Amount</th><th>Preview</th><th><input type="checkbox"/> E-mail</th><th>PDF</th><th>Sent</th></tr><?
        foreach ($results as $r){
            $donor=new self($r);
            ?>
            <tr><td><a target="Donor" href="?page=<?=$_GET['page']?>&DonorId=<?=$r->DonorId?>"><?=$r->DonorId?></a></td><td><?=$donor->NameCheck()?></td>
            <td><?=$donor->DisplayEmail()?></td>            
            <td><?=$r->DonationCount?></td>
            <td align=right><?=number_format($r->Total,2)?></td><td><a target="Donor" href="?page=<?=$_GET['page']?>&DonorId=<?=$r->DonorId?>&f=YearReceipt&Year=<?=$year?>">Receipt</a></td>
            <td><?
             if (filter_var($r->Email, FILTER_VALIDATE_EMAIL) && $r->EmailStatus>=0) {
                ?><input name="emails[]" type="checkbox" value="<?=$r->DonorId?>" <?=($receipts[$r->DonorId] ?"":" checked")?>/><?
             }
            ?></td>
            <td><?
             if ($r->Address1 && $r->City) {
                ?><input name="pdf[]" type="checkbox" value="<?=$r->DonorId?>" <?=($receipts[$r->DonorId]|| $r->DonationCount<2?"":" checked")?>/><?
             }
            ?></td><td><?
            //self::dump($receipts[$r->DonorId]);
            print DonationReceipt::displayReceipts($receipts[$r->DonorId]);
            ?></td></tr><?
        }?></table>
        Limit: <Input type="number" name="limit" value="<?=$_REQUEST['limit']?>" style="width:50px;"/>
        <button type="submit" name="Function" value="SendYearEndEmail">Send Year End E-mails</button>
        <button type="submit" name="Function" value="SendYearEndPdf">Send Year End Pdf</button> <label><input type="checkbox" name="blankBack" value="t"> Print Blank Back</label>
        </form>
        <?
        return;
    }

    function YearReceiptEmail($year){
        $this->emailBuilder=new stdClass();
        $page = get_page_by_path( 'donor-receiptyear',OBJECT);  
        //$this->dump($page);
        if (!$page){ ### Make the template page if it doesn't exist.
            self::makeReceiptYearPageTemplate();
            $page = get_page_by_path('donor-receiptyear',OBJECT);  
            print "<div class=\"notice notice-success is-dismissible\">Page /donor-receiptyear created. <a target='edit' href='post.php?post=".$page->ID."&action=edit'>Edit Template</a></div>";
        }
        $donations=Donation::get(array("DonorId=".$this->DonorId,"YEAR(Date)='".$year."'"),'Date');
        if ($donations){
            $ReceiptTable='<table border="1" cellpadding="4"><tr><th width="115">Date</th><th width="330">Subject</th><th width="100">Amount</th></tr>';
            foreach($donations as $r){
                $ReceiptTable.="<tr><td>".date("F j, Y",strtotime($r->Date))."</td><td>".($r->PaymentSource==1 &&$r->TransactionID ?(is_numeric($r->TransactionID)?"Check #":"").$r->TransactionID." ":"").$r->Subject."</td><td align=\"right\">".trim(number_format($r->Gross,2)." ".$r->Currency).'</td></tr>';
                $total+=$r->Gross;
                $lastCurrency=$r->Currency;
            }
            $ReceiptTable.="<tr><td colspan=\"2\"><strong>Total:</strong></td><td align=\"right\"><strong>".trim(number_format($total,2)." ".$lastCurrency)."</strong></td></tr></table>";
        }else{ $ReceiptTable.="<div><em>No Donations found in ".$year."</div>";}
        $organization=get_bloginfo('name'); // If different than what is wanted, should overwrite template
        $address=$this->MailingAddress();

        $subject=$page->post_title;
        $body=$page->post_content;
        $body=str_replace("##Name##",$this->Name.($this->Name2?" & ".$this->Name2:""),$body);
        $body=str_replace("##Year##",$year,$body);
        $body=str_replace("##DonationTotal##","$".number_format($total,2),$body);
        $body=str_replace("<p>##ReceiptTable##</p>",$ReceiptTable,$body);
        $body=str_replace("##ReceiptTable##",$ReceiptTable,$body);
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
    }

    function YearReceiptForm($year){
        //require ( WP_PLUGIN_DIR.'/tcpdf-wrapper/lib/tcpdf/tcpdf.php' );        
        $this->YearReceiptEmail($year);        
        if ($_POST['Function']=="SendYearReceipt" && $_POST['Email']){
            if (wp_mail($_POST['Email'], $this->emailBuilder->subject, $this->emailBuilder->body,array('Content-Type: text/html; charset=UTF-8'))){ 
                $form.="<div class=\"notice notice-success is-dismissible\">E-mail sent to ".$_POST['Email']."</div>";
                $dr=new DonationReceipt(array("DonorId"=>$this->DonorId,"KeyType"=>"YearEnd","KeyId"=>$year,"Type"=>"e","Address"=>$_POST['Email'],"DateSent"=>date("Y-m-d H:i:s")));
                $dr->save();
            }
        }

        $f=$this->receiptFileInfo($year);
        ### Form View
        $form.='<div class="no-print"><hr><form method="post">Send Receipt to: <input type="email" name="Email" value="'.($_POST['Email']?$_POST['Email']:$this->Email).'"/><button type="submit" name="Function" value="SendYearReceipt">Send E-mail</button>
        <button type="submit" name="Function" value="YearReceiptPdf">Generate PDF</button>';
        if (file_exists($f['path'])){
            $form.=' View <a target="pdf" href="'.$f['link'].'">'.$f['file'].'</a>';
        }
        $receipts=DonationReceipt::get(array("DonorId='".$this->DonorId."'","`KeyType`='YearEnd'","`KeyId`='".$year."'"));
        $form.=DonationReceipt::showResults($receipts);
        $form.='</form>';
        $form.="<div><a target='pdf' href='post.php?post=".$page->ID."&action=edit'>Edit Template</div></div>";
      
        $homeLinks="<div class='no-print'><a href='?page=".$_GET['page']."'>Home</a> | <a href='?page=".$_GET['page']."&DonorId=".$this->DonorId."'>Return to Donor Overview</a></div>";
        return $homeLinks."<h2>".$this->emailBuilder->subject."</h2>".$this->emailBuilder->body.$form;
    }
    function YearReceiptPdf($year){
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->YearReceiptEmail($year);
        $html="<h2>".$this->emailBuilder->subject."</h2>".$this->emailBuilder->body;
        $pdf->SetFont('helvetica', '', 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $f=$this->receiptFileInfo($year);
        $file=$f['file'];
        $path=$f['path'];
        $pdf->Output($path, 'F');
        return array('path'=>$path,'file'=>$file);
    }
    function receiptFileInfo($year){
        $file=substr(str_replace(" ","",get_bloginfo('name')),0,12)."-D".$this->DonorId.'-'.$year.'.pdf';
        $link="/wp-content/plugins/WPDonorPaypal/receipts/".$file;
        return array('path'=>$_SERVER['DOCUMENT_ROOT'].$link,'file'=>$file,'link'=>$link);
    }

    function makeReceiptYearPageTemplate(){
        $page = get_page_by_path( 'donor-receiptyear',OBJECT );  
        if (!$page){
            $postarr['ID']=0;
            $postarr['post_content']='<!-- wp:paragraph -->
<p>##Date##</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>##Address##</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><strong>Dear ##Name##,</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Thank you for your recent donation/s to ##Organization##.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Here is a list of your ##Year## donations totalling <strong>##DonationTotal##</strong>:</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>##ReceiptTable##</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Federal income tax law requires us to inform you that no goods or services were provided to you in return for your gift. Therefore, within the limits perscribed by law, the full amount of your gift is deducatble for federal income tax purposes. Please retain this receipt for your records.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>##Organization## is registered as a 501(c)(3) chartiable organization. Our Federal tax identifiation is: ##FederalId##.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Compare the list of donations with your personal records. If there are any discrepancies of questions, please contact ##Contact##.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Thanks again for your part in supporting the work ##Organization##!</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><strong>##Contact##</strong></p>
<!-- /wp:paragraph -->';
            $postarr['post_title']='##Organization## ##Year## Year End Receipts';
            $postarr['post_status']='private';
            $postarr['post_type']='page';
            $postarr['post_name']='donor-receiptyear';
            return wp_insert_post($postarr);            
        }
    }
}