<?php

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Core\ServiceContext;
use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
use QuickBooksOnline\API\Facades\Customer;

use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPDescriptionLineDetail;

require_once 'CustomVariables.php';
// use QuickBooksOnline\API\Core\ServiceContext;
// use QuickBooksOnline\API\PlatformService\PlatformService;
// use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
// use QuickBooksOnline\API\Facades\Customer;


class QuickBooks extends ModelLite
{
	protected $dataService;
    protected $oAuth2LoginHelper;
    protected $accessTokenObj;
    const SESSION_PREFIX='quickBooks_';
    const SESSION_VARS=['code','state','realmId','accessToken','refreshToken','accessTokenExpiresAt','refreshTokenExpiresAt'];
    const SETTING_URL='https://www.developer.intuit.com/app/developer/dashboard';

    public function getOAuth2LoginHelper(){        
        if (!$this->dataService){
            $clientId=CustomVariables::get_option('QuickbooksClientId',true);
            $clientSecret=CustomVariables::get_option('QuickbooksSecret',true);
            if (!$clientId || !$clientSecret){
                $this->missing_api_error();
                return false;
            }
            $quickbooks_base=CustomVariables::get_option('QuickbooksBase');
            if ($quickbooks_base!="Production") $quickbooks_base="Development";
            $this->dataService = DataService::Configure(array(
                'auth_mode' => 'oauth2',
                'ClientID' => $clientId,
                'ClientSecret' =>  $clientSecret,
                'RedirectURI' => self::redirect_url(),
                'scope' => "com.intuit.quickbooks.accounting",
                'baseUrl' => $quickbooks_base, ///Production
                'accessTokenKey' => $this->session(self::SESSION_PREFIX."accessToken"),
                'refreshTokenKey' => $this->session(self::SESSION_PREFIX."refreshToken"),
                'QBORealmID' => $this->session(self::SESSION_PREFIX."realmId")
            ));            
        }
        if (!$this->oAuth2LoginHelper){ 
            $this->oAuth2LoginHelper=$this->dataService->getOAuth2LoginHelper();
        }
        //$this->dataService->disableLog();   
        //$dataService->setLogLocation("/Your/Path/ForLog");     
        return $this->oAuth2LoginHelper;  
    }

    static public function redirect_url(){
        return get_site_url()."/wp-admin/admin.php?redirect=donor_quickBooks_redirectUrl";
    }
    
    public function authenticate(){  
        $this->getOAuth2LoginHelper();
        ### if past accesss token expiration, clear out session, and start over
        if ($this->session(self::SESSION_PREFIX."accessToken") && $this->session(self::SESSION_PREFIX."accessTokenExpiresAt")<time()){
            $this->clearSession();  
        }
        ### refresh the token if expired
        if ($this->session(self::SESSION_PREFIX."refreshToken") && $this->session(self::SESSION_PREFIX."refreshTokenExpiresAt")<time()){
            $this->accessTokenObj=$this->oAuth2LoginHelper->refreshToken();
            $error = $this->oAuth2LoginHelper->getLastError();
            if($error){
                self::display_error("<strong>Error Refreshing Token</strong> ".$error->getResponseBody()."</div>");
            }else{
                $this->dataService->updateOAuth2Token($this->accessTokenObj);
                $this->session([
                    self::SESSION_PREFIX."accessToken"=>$this->accessTokenObj->getAccessToken(),
                    self::SESSION_PREFIX."refreshToken"=>$this->accessTokenObj->getRefreshToken(),
                    self::SESSION_PREFIX."accessTokenExpiresAt"=>strtotime($this->accessTokenObj->getRefreshTokenExpiresAt()),
                    self::SESSION_PREFIX."refreshTokenExpiresAt"=>strtotime($this->accessTokenObj->getAccessTokenExpiresAt())
                ]);
            }
        }
        ### assume we have a good access token.
        if ($this->session(self::SESSION_PREFIX."accessToken")){
            return true;  
        }       
        ### No Access Token, but we have a code and realmId returned 
        if ($this->session(self::SESSION_PREFIX."code") && $this->session(self::SESSION_PREFIX."realmId")){
            try {
                $this->accessTokenObj = $this->oAuth2LoginHelper->exchangeAuthorizationCodeForToken($this->session(self::SESSION_PREFIX."code"),$this->session(self::SESSION_PREFIX."realmId"));               
                $this->session([
                    self::SESSION_PREFIX."accessToken"=>$this->accessTokenObj->getAccessToken(),
                    self::SESSION_PREFIX."refreshToken"=>$this->accessTokenObj->getRefreshToken(),
                    self::SESSION_PREFIX."accessTokenExpiresAt"=>strtotime($this->accessTokenObj->getRefreshTokenExpiresAt()),
                    self::SESSION_PREFIX."refreshTokenExpiresAt"=>strtotime($this->accessTokenObj->getAccessTokenExpiresAt())
                ]); 
                return true;
            } catch (Exception $e) {                
                $this->clearSession();
                $this->accessTokenObj = null;
                self::dump($e);
            }
        }else{
            print "<a href='?redirect=donor_quickBooks_authorizeUrl'>Quickbooks API Login</a>";    
            return false; 
        }
    }
    
    public function display_line($field,$value){
        $notset=array();
        if ($value){
            if(is_object($value)||is_array($value)){
                foreach($value as $sf =>$sv){ 
                    $this->display_line(($field?$field."_":"").$sf,$sv); // $this->display_line(($field?$field.(is_object($sv)?"->".$sf:"[".$sf."]"):""),$sv);                    
                }
            }else{
                print "<tr><td><strong>".$field."</strong></td><td>".$value."</td></tr>";
            }
            
        }else{
            $notset[]=$field;
        }
        return $notset;
    }

    public function edit_line($field,$value){        
        if(is_object($value)||is_array($value)){
            foreach($value as $sf =>$sv){ 
                $this->edit_line(($field?$field."_":"").$sf,$sv); 
            }
        }else{
            print "<tr><td><strong>".$field."</strong></td><td>";
            if ($value=="false" || $value=="true"){
                print "<label><input type='radio' name='".$field."' value='true'".($value=="true"?" checked":"")."/> true</label> <label><input type='radio' name='".$field."' value='false'".($value=="false"?" checked":"")."/> false</label>";
            }else{
                print "<input name='".$field."' value='".$value."'>";
            }
           
            print "</td></tr>";
        }
    }


    public function find_changed_fields($field,$value, $changedFields=[]){
        if(is_object($value)||is_array($value)){
            foreach($value as $sf =>$sv){ 
                $changedFields=$this->find_changed_fields(($field?$field."_":"").$sf,$sv,$changedFields); 
            }
        }else{
            if ($_POST[$field]!=$value){
                $changedFields[$field]=$_POST[$field];
            }            
        }
        return $changedFields;
    }

    public function request_handler(){      
        if ($this->authenticate()){
            if ($_GET['syncDonorId']){
                $this->donor_to_customer_check($_GET['syncDonorId'],$_GET['QuickBooksId']);
                return true;
            }elseif($_GET['syncDonation']){
                $this->donation_to_invoice_check($_GET['syncDonation']);
                return true;
            }elseif ($_POST['Function']=='SaveQuickBooks' && $_POST['quickbooks_table'] && $_POST['quickbooks_id']){
                ### Get Current entry
                $entity=$this->dataService->FindById($_POST['quickbooks_table'], $_POST['quickbooks_id']);
                $this->dataService->throwExceptionOnError(true);               
                if($this->check_dateService_error()){
                    $changedFields=$this->find_changed_fields('',$entity);
                    dump($changedFields);
                }
            
            }
        }else{
            return true; //if not authenticated, then stop at the Quickbokos API Login Link
        }
        return false;
    }

    public function check_dateService_error(){
        $error =$this->dataService->getLastError();
            if($error){ 
                self::display_error($error->getResponseBody());
                return false;
            }else{
                return true;
            }
    }


    public function donation_to_invoice_check($donationId){
        $donation=Donation::get_by_id($donationId);
        if (!$donation){
            self::display_error("Donation #".$donationId." not found.");
            return;
        }
        if ($donation->QBOInvoiceId){
            self::display_error("Donation #".$donation->show_field("DonationId")." already linked to Invoice #".$donation->show_field("QBOInvoiceId"));
            return $donation->QBOInvoiceId;
        }
        $donor=Donor::get_by_id($donation->DonorId);
        if (!$donor->QuickBooksId){
            $donor->QuickBooksId=$this->donor_to_customer_check($donation->DonorId);
        }
        if ($donor->QuickBooksId){
            $invoice=$this->donation_to_invoice($donation,$donor);
            if (!$invoice) return false; //errored out earlier        
            $resultObj=$this->dataService->Add($invoice);
            if($this->check_dateService_error()){
                if ($resultObj->Id){
                    $donation->QBOInvoiceId=$resultObj->Id;
                    $donation->save();
                    self::display_notice("Quick Books Invoice Id #".$donation->show_field('QBOInvoiceId')." created and linked to Donation #".$donation->show_field('DonationId'));
                     dd($resultObj,$invoice,$donation,$donor);
                    return $donation->QBOInvoiceId;
                }
            }
            //dd($invoice,$donation,$donor);
        }       
    }

    public function donor_to_customer_check($donorId,$quickBookId=""){
        $donor=Donor::get_by_id($donorId);
        if (!$donor){
            self::display_error("Donor #".$donorId." not found.");
            return;
        }
        if ($donor->QuickBooksId>0){
            self::display_notice("Quickbooks customer account #".$donor->show_field("QuickBooksId")." already connected to this Donor #".$donor->show_field("DonorId"));
            return $donor->QuickBooksId;          
        }

        if ($donor->MergedId>0){
            self::display_error("Donor #".$donorId." has been merged to #".$donor->show_field('MergedId',$donor->MergedId,true,['donationlink'=>false]).". Please don't sync a donor that has been merged.");
            return;
        }
        if ($this->authenticate()){
            $customer=false;
            if ($_GET['forceNew'=='true']){ //skip lookups and jump to creation.

            }elseif ($quickBookId){
                $customer=$this->dataService->FindById("Customer", $quickBookId);
            }elseif ($donor->QuickBooksId){
                $customer=$this->dataService->FindById("Customer", $donor->QuickBooksId);
            }else{
                if ($donor->Email){
                    $SQL="SELECT * FROM Customer Where PrimaryEmailAddr = '".$donor->Email."'";
                    $entities =$this->dataService->Query($SQL);
                    
                }
                if (!$entities || sizeof($entities)==0){
                    $names=[];
                    if ($donor->Name) $names[]=$donor->Name;
                    if ($donor->Name2) $names[]=$donor->Name2;
                    if (sizeof($names)>0) {
                        $SQL="SELECT * FROM Customer Where DisplayName IN ('".implode("','",$names)."')";                   
                        $entities =$this->dataService->Query($SQL);     
                    }                   
                }
                if (isset($entities) && sizeof($entities)==1) $customer=$entities[0];
                if (isset($entities) && sizeof($entities)>1){
                    print "<div class=\"notice notice-success\">Attempting to Sync Donor #".$donor->show_field('DonorId').", however multiple Quick Book Matches were found. Please select the closest match:<ul>";
                    foreach($entities as $customer){
                        print '<li><a href="?page='.$_GET['page'].'&syncDonorId='.$donorId.'&QuickBooksId='.$customer->Id.'">#'.$customer->Id.' '.$customer->DisplayName.'</a> '.$customer->PrimaryEmailAddr->Address.'</li>';
                    }
                    print '<li><a href="?page='.$_GET['page'].'&syncDonorId='.$donorId.'&forceNew=true">Force New Entry</a></li>';
                    print "</ul><div>";
                    return false;
                }
            }
            if ($customer){
                self::display_notice('Match Found. #'.$customer->Id.' '.$customer->DisplayName.'</a> '.$customer->PrimaryEmailAddr->Address);
                print '<div><a href="?page='.$_GET['page'].'&syncDonorId='.$donorId.'&forceNew=true">Force New Entry</a> - careful when doing this. You want to avoid creating duplicate entries.</div>';
                print "Add Sync Logic Here";
            }else{                    
                //create new entry here.
                $customerToCreate=$this->donor_to_customer($donor);
                $resultObj = $this->dataService->Add($customerToCreate);
                if($this->check_dateService_error()){
                    if ($resultObj->Id){
                        $donor->QuickBooksId=$resultObj->Id;
                        $donor->save();
                        self::display_notice("Quick Books Id #".$donor->show_field('QuickBooksId')." created and linked to Donor #".$donor->show_field('DonorId'));
                        return $donor->QuickBooksId;
                    }
                }
            }
        }
        return false;
    }

    public function donor_to_customer($donor){
        ### takes a Donor Object and puts it into a QuickBooks Customer Object
        $name=explode(" ",$donor->Name);        
        $array=[           
            'FullyQualifiedName'=>$donor->Name,
            'CompanyName'=>$donor->Name,
            'DisplayName'=>$donor->Name,
            'OtherContactInfo'=>$donor->Name2,            
            'PrimaryEmailAddr'=>['Address'=>$donor->Email,'Default'=>$donor->EmailStatus==1?1:0],
            'PrimaryPhone'=>['FreeFormNumber'=>$donor->phone()],
            'BillAddr'=>['Line1'=>$donor->Address1,'Line2'=>$donor->Address2,'City'=>$donor->City,'CountrySubDivisionCode'=>$donor->Region,'PostalCode'=>$donor->PostalCode,'PostalCode'=>$donor->PostalCode,'Country'=>$donor->Country,'CountryCode'=>$donor->Country],
            'Notes'=>'donor|'.$donor->DonorId."|".$donor->Source."|".$donor->SourceId,
            'BusinessNumber'=> $donor->DonorId      
        ];
        if (sizeof($name)==2){
            $array['GivenName']=$name[0];    $array['FamilyName']=$name[1]; 
        }

        if (sizeof($name)==3){
            $array['GivenName']=$name[0];    $array['MiddleName']=$name[2]; $array['FamilyName']=$name[2]; 
        }
        return Customer::create($array);
    }

    public function item_donation(){
        if (!$this->authenticate()){
            self::display_error("Could Not Authenticate to Quickbooks API.");
            return false;
        }
        ### finds Donation item. If it doesn't exist, creates it.
        $entities =$this->dataService->Query("SELECT * FROM Item WHERE FullyQualifiedName = 'Donation'");
        if($this->check_dateService_error()){
            if ($entities[0]){               
                return $entities[0];
            }else{
                if ($_POST['IncomeAccount']){ //creation form submitted
                $item = new IPPItem();
                $item->Name="Donation";
                $item->Description="Donation";
                $item->Active=true;
                $item->Type="NonInventory";
                $item->IncomeAccountRef=$_POST['IncomeAccount'];
                $resultObj=$this->dataService->Add($item);
                if($this->check_dateService_error()){                   
                    return $resultObj;
                }
                }else{ //creation form.
                    self::display_error("You must create an item Type 'Donation' before proceeding either in Quickbooks, or through this interface.");
                    ?><h2>Create Donation Item</h2>
                    <form method="post">
                    <select name="IncomeAccount"><?php
                    $entities =$this->dataService->Query("SELECT * FROM Account WHERE AccountType = 'Income' Orderby FullyQualifiedName");
                    if($this->check_dateService_error()){
                        foreach($entities as $account){
                            print '<option value="'.$account->Id.'">'.$account->FullyQualifiedName."</option>";
                        }
                    }?></select><button>Create Donation Item</button>
                    </form>
                    <?php
                    return false;
                }
            }
        }
    }
    public function account_list($where=""){       
        if ($this->authenticate()){            
            $entities =$this->dataService->Query("SELECT * FROM Account".($where?" WHERE ".$where." ":" ")." Orderby Name");
            if($this->check_dateService_error()){
                return $entities; 
            }  
        }else {
            print "Must connect to Quickbooks before viewing accounts.";
            die();
        }          
    }

    public function item_list($where=""){       
        if ($this->authenticate()){            
            $entities =$this->dataService->Query("SELECT * FROM Item".($where?" WHERE ".$where." ":" ")." Orderby FullyQualifiedName");
            if($this->check_dateService_error()){
                return $entities; 
            }  
        }else {
            print "Must connect to Quickbooks before editing Categories.";
            die();
        }          
    }

    static function is_setup(){ 
        if (CustomVariables::get_option('QuickbooksClientId') && CustomVariables::get_option('QuickbooksSecret')){
            return true;
        }else{
            return false;
        }
    }

    public function donation_to_invoice($donation,$donor){
        $item=$this->item_donation();
        if (!$item){
            return false; //expects error message to have already been displayed in above function.
        }
        
        $invoice = new IPPInvoice();
        $invoice->Deposit       = 0;
        $invoice->domain        =  "QBO";
        //$invoice->AutoDocNumber = true;
        $invoice->DocNumber=$donation->DonorId."|".$donation->DonationId;
        $invoice->TxnDate = date('Y-m-d', strtotime($donation->DateDeposited));
        $invoice->ShipDate = date('Y-m-d', strtotime($donation->Date)); //check date... not sure if this is the best field
        $invoice->CustomerRef   = $donor->QuickBooksId;
        $invoice->PrivateNote   = $donation->DonorId."|".$donation->DonationId."|".$donation->Source."|".$donation->SourceId."|".$donation->Note;
        $invoice->TxnStatus     = "Payable";
        $invoice->PONumber      = substr($donation->TransactionID,0,15); //

        
        $lineDetail=new IPPDescriptionLineDetail();
        $lineDetail->ItemRef=(object)['value'=>$item->Id,'name'=>"Donation"];
        $lineDetail->TaxCodeRef="NON";
        //$lineDetail->UnitPrice        = 1;
        //$lineDetail->Qty              = $donation->Gross;
        $QBItemId=null;
        if ($donor->TypeId){
            $donorType=DonorType::get(["TypeId=".$donor->TypeId]);
            $QBItemId=$donorType->QBItemId;
        }
        if (!$QBItemId){
            $QBItemId=CustomVariables::get_option('DefaultQBItemId');
        }
        if (!$QBItemId){
            self::display_error("Quickbook <a href='?page=donor-settings'>Default Item Id Not Set</a> and <a href='?page=donor-settings&tab=type'>Donor Type</a> not set.");
            return;
        }
        dd($donor->TypeId,$QBItemId);
        // CustomVariables::get_option('DefaultQBItemId')

        $line = new IPPLine();
        $line->Id = "0";
        $line->LineNum          = "1";
        $line->Description      = $donation->Subject?$donation->Subject:"Donation";
        $line->Amount           = $donation->Gross; 
        $line->DetailType       = "SalesItemLineDetail"; 
        $line->SalesItemLineDetail=$lineDetail;

        //should we add something for the fee? $donation->Fee

        $invoice->Line          = array($line);
        $invoice->RemitToRef    = $donor->QuickBooksId;
        $invoice->TotalAmt      = $donation->Gross;
        $invoice->FinanceCharge = 'false';
        return $invoice;

    }

    public function customer_to_donor($customer){
        $array=[
            "Source"=>'QuickBooks',
            "SourceId"=>$customer->Id,
            "Name"=>$customer->DisplayName,
            "Name2"=>$customer->Organization?$customer->Organization:$customer->OtherContactInfo,
            "Email"=>$customer->PrimaryEmailAddr->Address,
            "Phone"=>$customer->PrimaryPhone->FreeFormNumber,
            "Address1"=>$customer->BillAddr->Line1,
            "Address2"=>$customer->BillAddr->Line2,
            "City"=>$customer->BillAddr->City,
            "Region"=>$customer->BillAddr->CountrySubDivisionCode,
            "PostalCode"=>$customer->BillAddr->PostalCode,
            "Country"=>$customer->BillAddr->CountryCode,
            "QuickBooksId"=>$customer->Id         
        ];
        return new Donor($array);
    }

    public function show(){
        if ($this->authenticate()){
            self::display_notice("<strong>You are authenticated!</strong><div>Token expires: ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."accessTokenExpiresAt")).". Refresh Expires at ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."refreshTokenExpiresAt"))." in ".($this->session(self::SESSION_PREFIX."refreshTokenExpiresAt")-time())." seconds. <a href='?page=donor-quickbooks&Function=QuickbookSessionKill'>Logout/Kill Session</a></div>");
            $tables=['Customer'=>'DisplayName','Invoice'=>'Balance','Vendor'=>'DisplayName','Employee'=>'DisplayName','Item'=>'Name','Account'=>'Name','Bill'=>'VendorRef','BillPayment'=>'VendorRef','CompanyInfo'=>'CompanyName','CreditMemo'=>'TotalAmt'
            ,'Deposit'=>'CashBack.Memo','JournalEntry'=>'PrivateNote','SalesReceipt'=>'DocNumber']; //,'Department',,'Budget'
            if ($_GET['table']){      
                if ($_GET['Id']){
                    $entity=$this->dataService->FindById($_GET['table'], $_GET['Id']);
                    if($this->check_dateService_error()){
                        print "<div><a href='?page=donor-quickbooks&table=".$_GET['table']."'>Back to ".$_GET['table']." list</a></div>
                        <h3>".$_GET['table']." #".$_GET['Id']."</h3>";
                        if ($_GET['edit']){
                            print "                        
                            <form method='post' action='?page=donor-quickbooks&table=".$_GET['table']."&Id=".$_GET['Id']."'>
                            <input type='hidden' name='quickbooks_table' value='".$_GET['table']."'/>
                            <input type='hidden' name='quickbooks_id' value='".$_GET['Id']."'/>
                            <table class=\"dp\">";
                            $this->edit_line('',$entity); 
                            print "</table>
                            <button name='Function' value='SaveQuickBooks'>Save</button><button>Cancel</button>
                            </form>";

                        }else{
                            print "
                            <div><a href='?page=donor-quickbooks&table=".$_GET['table']."&Id=".$_GET['Id']."&edit=t'>edit</a></div>
                            <table class=\"dp\">";
                            $notset=$this->display_line('',$entity);                        
                            print "</table>";
                        }                        
                       
                        if ($notset && sizeof($notset)>0){
                            print "<div><strong>Fields Not Set: </strong>".implode(", ",$notset)."</div>";
                        }                        
                    }
                }else{
                    $entities =$this->dataService->Query("SELECT * FROM ".$_GET['table']);
                    if($this->check_dateService_error()){
                        print "<div><a href='?page=donor-quickbooks'>Back to Quickbook list</a></div>
                        <h3>".$_GET['table']." List</h3>";
                        ?><table class="dp">
                        <?php
                        foreach ($entities as $entity){
                            $field=$tables[$_GET['table']];
                            ?><tr><td><a href="?page=donor-quickbooks&table=<?php print $_GET['table']?>&Id=<?php print $entity->Id?>"><?php print $entity->Id?></a></td><td><?php print $entity->$field?></td></tr><?php
                        }?>
                        </table>
                        <?php    
                    }                           
                }
            }else{ 
                $companyInfo = $this->dataService->getCompanyInfo();               
                ?>
                <h2>Company: <?php print $companyInfo->LegalName?> | <?php print CustomVariables::get_option('QuickbooksBase');?></h2>             
                <h3>View</h3>
                 <?php foreach ($tables as $tbl=>$key){ ?>
                    <div><a href="?page=donor-quickbooks&table=<?php print $tbl?>"><?php print $tbl?></a></div>
                 <?php 
                 }  
                         
            }
            return;           
        }
	}

    public function oauthcallback(){
        ///api/quickbooks/oauth2/callback?code=AB11670607450L4ne1zzbuPBxTHOz00f8oPv9ZKA0B2qVIBw34&state=QANVI&realmId=4620816365259820260
        foreach($_REQUEST as $key=>$val){
            $this->session([self::SESSION_PREFIX.$key=>$val]);               
        }          
        return header("Location: ?redirect=donor_quickBooks_authorizeRedirect");      
    }

    public function authorizeUrl(){ //external redirect to QuickBooks Authroization URL      
        return header("Location: ".$this->getOAuth2LoginHelper()->getAuthorizationCodeURL()); 
    }

    public function authorizeRedirect(){ //Captures variables returned, stores them as session variables, and then redirects to main page
        $this->requestToSession();  
        return header("Location: ?page=donor-quickbooks");
    }

    public function requestToSession(){ 
        foreach(self::SESSION_VARS as $key){
            if ($_GET[$key]){
                $this->session([self::SESSION_PREFIX.$key=>$_GET[$key]]);  
            }   
        }       
    }

    public function clearSession(){
        foreach(self::SESSION_VARS as $key){
            unset($_SESSION[self::SESSION_PREFIX.$key]);
        }       
    }

    public function session($var){
        if (is_array($var)){
            foreach ($var as $f=>$v){
                $_SESSION[$f]=$v;
            }
            return true;            
        }
    
        return $_SESSION[$var];
    }

    public function check_redirects($redirect){ 
        //reads a url like: ?redirect=donor_quickBooks_xxx       
       $prefix="donor_".self::SESSION_PREFIX;       
       if (strpos($redirect, $prefix)>-1){ 
            switch(substr($redirect,strlen($prefix))){
                case "authorizeUrl": $this->authorizeUrl(); break; //step 1 redirect to external url for oath
                case "redirectUrl" : $this->oauthcallback(); break;        //step 3 after returning from external url -> read returned variables.
                case "authorizeRedirect" : $this->authorizeRedirect(); break; 
            }
       }      
    }

    public function missing_api_error(){
        self::display_error("Quickbook API Client/Password not setup. Create a <a target='quickbooktoken' href='".QuickBooks::SETTING_URL."'>Client/Password on QuickBooks Developer</a> first, and then <a href='?page=donor-settings'>paste them in the settings</a>.");
    }
}