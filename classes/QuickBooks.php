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
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;

use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
//use QuickBooksOnline\API\Data\IPPPaymentLineDetail;

require_once 'CustomVariables.php';
// use QuickBooksOnline\API\Core\ServiceContext;
// use QuickBooksOnline\API\PlatformService\PlatformService;
// use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
// use QuickBooksOnline\API\Facades\Customer;


class QuickBooks extends ModelLite
{
	protected $dataService;
    protected $QBurl;
    protected $oAuth2LoginHelper;
    protected $accessTokenObj;

    protected $fieldLinks=[
        'DepositToAccountRef'=>'Account',
        'Line_LinkedTxn_TxnId'=>'+Line_LinkedTxn_TxnType',/***/
        'LinkedTxn_TxnId'=>'+LinkedTxn_TxnType',
        'CustomerRef'=>'Customer'        
    ];

    protected $QBtables=[
        'Customer'=>['DisplayName','PrimaryEmailAddr_Address','Notes'],
        'Invoice'=>['CustomerRef','Balance','TotalAmt','ShipFromAddr_Line1','ShipFromAddr_Line2'],
        'Vendor'=>'DisplayName',
        'Employee'=>'DisplayName',
        'Item'=>['Name','Description','Active','UnitPrice','Type','IncomeAccountRef','TrackQtyOnHand'],
        'Account'=>['Name','Active','Classification','AccountType','AccountSubType','CurrentBalance','CurrentBalanceWithSubAccounts'],
        'Bill'=>['VendorRef','TxnDate','DueDate','TotalAmt','VendorAddr_Line1','VendorAddr_City','VendorAddr_CountrySubDivisionCode'],
        'BillPayment'=>['VendorRef','PayType','TotalAmt','Line_LinkedTxn_TxnId','Line_LinkedTxn_TxnType'],
        'CreditMemo'=>['TxnDate','TotalAmt','BillAddr_Id','BillAddr_Line1','BillAddr_Line2']
        ,'JournalEntry'=>['PrivateNote','TxnDate','Line_0_Amount','Line_0_JournalEntryLineDetail_PostingType','Line_0_JournalEntryLineDetail_AccountRef','Line_1_Amount','Line_1_JournalEntryLineDetail_PostingType','Line_1_JournalEntryLineDetail_AccountRef'],
        'SalesReceipt'=>['DocNumber','TxnDate','CustomerRef','BillAddr_Line1','TotalAmt'],
        'Payment'=>['TxnDate','PaymentRefNum','TotalAmt','CustomerRef','Line_LinkedTxn_TxnId','Line_LinkedTxn_TxnType','LinkedTxn_TxnId','LinkedTxn_TxnType'],
        'PaymentMethod'=>'Name','Budget'=>"Name",
        "Deposit"=>["DepositToAccountRef","TxnDate","TotalAmt","Line_LinkedTxn_TxnId","Line_LinkedTxn_TxnType"]
    ];


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

            $this->QBurl=self::get_QB_url($quickbooks_base); 
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
            try{ 
                $this->accessTokenObj=$this->oAuth2LoginHelper->refreshToken();
                $error = $this->oAuth2LoginHelper->getLastError();
            } catch (\Exception $ex) {
                self::display_error($ex->getMessage()."<br>Reload this page.");
                $this->clearSession();  
                return false;
            }
            
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
    
    public function display_line($field,$value,$entity=""){
        $notset=array();
        if ($value){
            if(is_object($value)||is_array($value)){
                foreach($value as $sf =>$sv){ 
                    $this->display_line(($field?$field."_":"").$sf,$sv,$entity); // $this->display_line(($field?$field.(is_object($sv)?"->".$sf:"[".$sf."]"):""),$sv);                    
                }
            }else{
                print "<tr><td><strong>".$field."</strong></td><td>";
                
                if ($value && $this->fieldLinks[$field]){
                  
                    if (substr($this->fieldLinks[$field],0,1)=="+"){ //special type of "+" in front allows it to read another field
                        $tableField=substr($this->fieldLinks[$field],1);               
                        $table=$this->showQBfield($entity,$tableField);
                    //dd($tableField,$this->fieldLinks[$field],$entity,$table);
                    }else{
                        $table=$this->fieldLinks[$field];
                    }
                    //dd($this->fieldLinks[$field],$table,$tableField);

                    print '<a target="QBdetail" href="?page=donor-quickbooks&table='.$table.'&Id='.$value.'">'.$value."</a> ".self::qbLink($table,$value,'QB');
                }else print $value;
                print "</td></tr>"; //rewrite this to use: $this->showQBfield($entity,$field)  -
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
        if($_GET['ignoreSyncDonation']){ //doesn't require authentication first...
            $this-> ignore_sync_donation($_GET['ignoreSyncDonation']);
            return true;           
        }     
        if ($this->authenticate()){
            if ($_GET['syncDonorId']){
                $this->donor_to_customer_check($_GET['syncDonorId'],$_GET['QuickBooksId']);
                return true;
            }elseif($_GET['syncDonationPaid']){
                $this->donation_to_payment_check($_GET['syncDonationPaid']);
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

    public function donation_to_payment_check($donationId){
        $donation=Donation::find($donationId);
       
        if (!$donation){
            self::display_error("Donation #".$donationId." not found.");
            return;
        }
        if (!$donation->QBOInvoiceId){
            self::display_error("Donation #".$donation->show_field("DonationId")." not linked to an invoice");           
        }
        $donor=Donor::find($donation->DonorId);        
        if ($donor->QuickBooksId){
            $payment=$this->donation_to_payment($donation,$donor);
            $this->process_payment_obj($payment,$donation);
        }
    }

    public function ignore_sync_donation($donationId){
        $donation = Donation::find($donationId);
        $changes=[];
        if ($donation->QBOInvoiceId>0){ 
            self::display_error("Invoice already matched in QB ".self::qbLink('Invoice',$donation->QBOInvoiceId).". Manually edit/override if this is a mistake.");
        }else{ $donation->QBOInvoiceId=-1; $changes[]="Invoice"; }

        if ($donation->QBOPaymentId>0){ 
            self::display_error("Payment already matched in QB ".self::qbLink('Payment',$donation->QBOPaymentId).". Manually edit/override if this is a mistake.");
        }else{ $donation->QBOPaymentId=-1; $changes[]="Payment"; }

        if(sizeof($changes)>0){
            $donation->save();
            self::display_notice("The following QB Fields were marked ignored: ".implode(', ',$changes));
        }
        $donation->full_view();
    }

    public function process_payment_obj($payment,$donation,$settings=[]){
        if (!$payment) return false; //errored out earlier        
        $resultObj=$this->dataService->Add($payment);
        if($this->check_dateService_error()){
            if ($resultObj->Id){
                $donation->QBOPaymentId=$resultObj->Id;
                $donation->save();
                if (!$settings['silent']) self::display_notice("Payment: ".self::qbLink('Payment',$resultObj->Id)." send to Quickbooks");
            }
        }
        $donation->full_view();
    }


    public function donation_to_invoice_check($donationId){
        $donation=Donation::find($donationId);
        if (!$donation){
            self::display_error("Donation #".$donationId." not found.");
            return;
        }
        if ($donation->QBOInvoiceId){
            self::display_error("Donation #".$donation->show_field("DonationId")." already linked to Invoice #".$donation->show_field("QBOInvoiceId"));
            $donation->full_view();
            return $donation->QBOInvoiceId;
        }
        $donor=Donor::find($donation->DonorId);
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
                     //dd($resultObj,$invoice,$donation,$donor);
                    
                     $payment=$this->donation_to_payment($donation,$donor);
                     $this->process_payment_obj($payment,$donation);
                    
                     return $donation->QBOInvoiceId;
                }
            }
            //dd($invoice,$donation,$donor);
        }       
    }

    public function donation_to_invoice_process($donation,$donor=""){
        if (!$donor) $donor=Donor::find($donation->DonorId);
        if ($donor->QuickBooksId){          
            if ($this->authenticate()){
                $invoice=$this->donation_to_invoice($donation,$donor);
                if (!$invoice) return false; //errored out earlier        
                $resultObj=$this->dataService->Add($invoice);
                if($this->check_dateService_error()){
                    if ($resultObj->Id){
                        $donation->QBOInvoiceId=$resultObj->Id;
                        $donation->save();
                        $payment=$this->donation_to_payment($donation,$donor);
                        $this->process_payment_obj($payment,$donation,['silent'=>true]);
                    }
                }
            }
        }
    }


    public function donor_to_customer_check($donorId,$quickBookId=""){
        $donor=Donor::find($donorId);
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
            // 'CompanyName'=>$donor->Name,
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

    public function item_donation(){ //don't like automatically doing this...
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

    public function item_find($id){
        return $this->item_list("Id = ".$id)[0];
    }

    public function item_list($where="",$exitOnFail=true){       
        if ($this->authenticate()){            
            $entities =$this->dataService->Query("SELECT * FROM Item".($where?" WHERE ".$where." ":" ")." Orderby FullyQualifiedName");
            if($this->check_dateService_error()){
                return $entities; 
            }  
        }else {
            print "Must connect to Quickbooks before editing Categories.";
            if($exitOnFail) die();
            return false;
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
        // $item=$this->item_donation();
        // if (!$item){
        //     return false; //expects error message to have already been displayed in above function.
        // }
        
        $invoice = new IPPInvoice();
        $invoice->Deposit       = 0;
        $invoice->domain        =  "QBO";
        $invoice->AutoDocNumber = false;
        $invoice->DocNumber=$donation->DonorId."|".$donation->DonationId;
        $invoice->TxnDate = date('Y-m-d', strtotime($donation->DateDeposited));
        $invoice->ShipDate = date('Y-m-d', strtotime($donation->Date)); //check date... not sure if this is the best field
        $invoice->CustomerRef   = $donor->QuickBooksId;
        $invoice->PrivateNote   = $donation->DonorId."|".$donation->DonationId."|".$donation->Source."|".$donation->SourceId."|".$donation->Note;
        //$invoice->TxnStatus     = "Payable";
        $invoice->PONumber      = substr($donation->TransactionID,0,15); //

        if ($donation->CategoryId){
            $category=DonationCategory::find($donation->CategoryId);
        }

       
        //$lineDetail->UnitPrice        = 1;
        //$lineDetail->Qty              = $donation->Gross;
        $QBItemId=$category?$category->getQuickBooksId():null;
       

        if (!$QBItemId && $donor->TypeId){
            $donorType=DonorType::find($donor->TypeId);            
            $QBItemId=$donorType->QBItemId;
        }
        if (!$QBItemId){
            $QBItemId=CustomVariables::get_option('DefaultQBItemId');
        }
        if (!$QBItemId){
            self::display_error("Quickbook <a href='?page=donor-settings'>Default Item Id Not Set</a> and <a href='?page=donor-settings&tab=type'>Donor Type</a> not set.");
            return;
        }

        $SalesItemLineDetail = new IPPSalesItemLineDetail();
        $SalesItemLineDetail->ItemRef =   $QBItemId;
        $SalesItemLineDetail->TaxCodeRef =  'NON'; //'TAX' in USA or 'NON'  
        
        $l = new IPPLine();
      
        $l->SalesItemLineDetail = $SalesItemLineDetail;        
        $l->Id = "0";
        $l->LineNum          = 1;
        $description=$category?$category->Description:'Donation';
        //$l->QtyOnPurchaseOrder = $line->quanity;
        $l->Amount           = $donation->Gross;
        $l->DetailType = "SalesItemLineDetail";
        
        $invoice->Line[]=$l;          

        $invoice->RemitToRef    = $donor->QuickBooksId;
        $invoice->SalesTermRef= 1;
        $invoice->TotalAmt      = $donation->Gross;
        $invoice->FinanceCharge = 'false';      
        return $invoice;

    }

    public function donation_to_payment($donation,$donor){
        //https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/payment#the-payment-object
        if (!$donation->QBOInvoiceId){
            self::display_error("Could not find QB invoice attached to donation ".$donation->show_field("DonationId"));
            return false;
        }
        $payment= new IPPPayment();

        $payment->domain =  "QBO";
        $payment->TotalAmt = $donation->Gross;
        $payment->CustomerRef = $donor->QuickBooksId;
        $payment->TxnDate = date('Y-m-d', strtotime($donation->DateDeposited));
        //$payment->DepositToAccountRef =  1;// new stdClass() ->value=1
        $payment->ProcessPayment = false;
        $paymentSourceMap=["2"=>"1","1"=>"2","10"=>"4"]; //paymentSource to PaymentMethod on QB
        //"PaymentSource"=>[0=>"Not Set","1"=>"Check","2"=>"Cash","5"=>"Instant","6"=>"ACH/Bank Transfer","10"=>"Paypal"]
        if ($paymentSourceMap[$donation->PaymentSource]){
            $payment->PaymentMethodRef=$paymentSourceMap[$donation->PaymentSource];
        }        
        $payment->PaymentRefNum=$donation->TransactionID;

        $linkedTxn = new IPPLinkedTxn();
        $linkedTxn->TxnId=$donation->QBOInvoiceId;
        $linkedTxn->TxnType='Invoice';

        $l = new IPPLine();      
        $l->Amount           = $donation->Gross;
        $l->LinkedTxn = $linkedTxn;    

       
        $l->DetailType = "PaymentLineDetail";
        
        $payment->Line[]=$l; 
        //dd($paymenet);
        //$detail=new IPPPaymentLineDetail();
        /*
        "Line": [
      {
        "Amount": 55.0, 
        "LineEx": {
          "any": [
            {
              "name": "{http://schema.intuit.com/finance/v3}NameValue", 
              "nil": false, 
              "value": {
                "Name": "txnId", 
                "Value": "70"
              }, 
              "declaredType": "com.intuit.schema.finance.v3.NameValue", 
              "scope": "javax.xml.bind.JAXBElement$GlobalScope", 
              "globalScope": true, 
              "typeSubstituted": false
            }, 
            {
              "name": "{http://schema.intuit.com/finance/v3}NameValue", 
              "nil": false, 
              "value": {
                "Name": "txnOpenBalance", 
                "Value": "71.00"
              }, 
              "declaredType": "com.intuit.schema.finance.v3.NameValue", 
              "scope": "javax.xml.bind.JAXBElement$GlobalScope", 
              "globalScope": true, 
              "typeSubstituted": false
            }, 
            {
              "name": "{http://schema.intuit.com/finance/v3}NameValue", 
              "nil": false, 
              "value": {
                "Name": "txnReferenceNumber", 
                "Value": "1024"
              }, 
              "declaredType": "com.intuit.schema.finance.v3.NameValue", 
              "scope": "javax.xml.bind.JAXBElement$GlobalScope", 
              "globalScope": true, 
              "typeSubstituted": false
            }
          ]
        }, 
        "LinkedTxn": [
          {
            "TxnId": "70", 
            "TxnType": "Invoice"
          }
        ]
      }
    ],
    */
        

        return $payment;
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

    public function hash_donor_add($donor,&$hash){
        if ($donor->Email) $hash['Email'][$this->hash($donor->Email)]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;
        if ($donor->Phone) $hash['Phone'][$this->hash($donor->Phone)]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;
        if ($donor->Name) $hash['Name'][$this->hash($donor->Name)]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;
        if ($donor->Name2) $hash['Name'][$this->hash($donor->Name2)]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;        
        $partial=$this->partialHash($donor->Name);
        foreach($partial as $p){
            $hash['partial'][$p][]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;
        }
        $partial=$this->partialHash($donor->Name2);
        foreach($partial as $p){
            $hash['partial'][$p][]=$donor->MergedId>0?$donor->MergedId:$donor->DonorId;
        }
        return $hash;
    }
    
    public function hash($string){
        return preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($string));
    }

    public function partialHash($string){
        $segments=explode(" ",$string);
        $return=[];
        foreach($segments as $s){
            $hash=$this->hash($s);
            if (strlen($hash)>2) $return[]=$hash;
        }
        return $return;
    }

    public function process_customer_match($match,$rmatch){
        $updatedCount=0;  $createNew=[];
        foreach($match as $cId=>$donorId){
            if ($donorId){
                $uSQL="UPDATE ".Donor::get_table_name()." SET QuickBooksId='".$cId."' WHERE DonorId='".$donorId."'";
                print $uSQL."<br>";
                self::db()->query($uSQL);
                $updatedCount++;                           
            }
        }
        foreach($rmatch as $donorId =>$cId){
            if ($cId=="new"){
                $createNew[]=$donorId;
            }elseif ($cId){
                $uSQL="UPDATE ".Donor::get_table_name()." SET QuickBooksId='".$cId."' WHERE DonorId='".$donorId."'";
                print $uSQL."<br>"; 
                self::db()->query($uSQL); 
                $updatedCount++;                        
            }
        }
        self::display_notice("Linked ".$updatedCount." entries");
        if (sizeof($createNew)>0){ 
            $created=[];           
            $donors=Donor::get(["DonorId IN ('".implode("','",$createNew)."')"]);
            foreach($donors as $donor){
                if ($donor->QuickBooksId>0){
                    print self::display_error("Donor ".$donor->DonorId." already connectd to QB Customer account: ".$donor->QuickBooksId);
                }else{
                    if ($this->authenticate()){
                        $customerToCreate=$this->donor_to_customer($donor);
                        $resultObj = $this->dataService->Add($customerToCreate);
                        if($this->check_dateService_error()){
                            if ($resultObj->Id){
                                $donor->QuickBooksId=$resultObj->Id;
                                $donor->save();
                                self::display_notice("Quick Books Id #".$donor->show_field('QuickBooksId')." created and linked to Donor #".$donor->show_field('DonorId'));                               
                                $created[]=$donor->QuickBooksId;
                            }
                        }
                    }
                }
            }
            //if (sizeof($created)>0) self:display_notice("Created ".sizeof($created)." new Customers from Donors");
        }
    }

    public function show(){
        if ($this->authenticate()){
            self::display_notice("<strong>You are authenticated!</strong><div>Token expires: ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."accessTokenExpiresAt")).". Refresh Expires at ".date("Y-m-d H:i:s",$this->session(self::SESSION_PREFIX."refreshTokenExpiresAt"))." in ".($this->session(self::SESSION_PREFIX."refreshTokenExpiresAt")-time())." seconds. <a href='?page=donor-quickbooks&Function=QuickbookSessionKill'>Logout/Kill Session</a></div>");            
            if ($_GET['debug']){
                $this->debug();
                return;
            }
            if ($_GET['syncDonorsToQB']){         
                if ($_POST['Function']=="LinkMatchQBtoDonorId"){
                    $this->process_customer_match($_POST['match'],$_POST['rmatch']);                    
                }

                $match=[];
                
                ?>
                
                <h2>Sync Donors to Quickbooks</h2><?php
                $index=$_GET['index']??1;
                
                $customer=$this->get_all_entity('Customer');                                         
                
                if($this->check_dateService_error()){
                    $hash=[];
                    $results = Donor::get(['MergedId=0']);
                    print "<div>".sizeof($customer)." Customer in QB found. ".sizeof($results)." Donors in Donor Press found.</div>";    
                    foreach($results as $d){
                        $donors[$d->DonorId]=$d;
                        if ($d->QuickBooksId && $customer[$d->QuickBooksId]){
                            $existing[$d->QuickBooksId][]=$d->DonorId;
                        }else{
                            $this->hash_donor_add($d,$hash);
                            $leftOverDonors[$d->DonorId]=1;                            
                        }
                    }
                    $match=self::customers_to_donor_hash($customer,$hash,['existing'=>$existing]); 
                    //->match, ->partial ->donorId
                    if ($match->donorId){ 
                        foreach($match->donorId as $donorId=>$customerMatches){ unset($leftOverDonors[$matchId]);}
                    }

                    ?><form method="post"> <button name="Function" value="LinkMatchQBtoDonorId"/>Match Selected Below</button><?php
                    if (sizeof($match->match)>0){
                        ?>
                        
                        <h2>Potential Matches Founds</h2>
                        <table class="dp"><tr><th></th><th>QuickBooks</th><th>Donor Press</th><th>Matched On</th></tr>
                        <?php 
                        foreach($match->match as $cId=>$donorIds){
                            $i=0;
                            foreach($donorIds as $donorId=>$matchedOn){
                                ?><tr>
                                    <td><input type="checkbox" name="match[<?php print $cId;?>]" value="<?php print $donorId?>"<?php if ($i==0) print " checked"?>></td>
                                <?php if ($i==0){?>
                                    <td rowspan="<?php print sizeof($donorIds)?>"><?php print '<a href="?page=donor-quickbooks&table=Customer&Id='.$cId.'">'.$cId."</a>".QuickBooks::qbLink('Customer',$cId,'QB')."  - ".$customer[$cId]->FullyQualifiedName?></td>
                                <?php } ?>  
                                <td><?php print $donors[$donorId]->show_field('DonorId')." - ".$donors[$donorId]->name_combine();?></td><td><?php print implode(", ",array_keys($matchedOn));?></td></tr><?php
                                $i++;
                            }                                  
                               
                        }?></table>
                        
                        <?php
                    }                   
                    ?>                    
                    <h2>On QuickBooks, but not Donor Press</h2> 
                    <table class="dp"><tr><th>&#8592;</th><th>QuickBooks</th><th>Link to Donor Id</th><th>Partial matches</th></tr>                  
                    <?php
                    foreach($notFound as $cId){ ?>
                        <tr><td>&#8592;</td><td><?php print '<a href="?page=donor-quickbooks&table=Customer&Id='.$cId.'">'.$cId."</a> ".QuickBooks::qbLink('Customer',$cId,'QB')."- ".self::show_customer_name($customer[$cId])?></td>
                        <td><input type="number" id="match_<?php print $cId;?>" name="match[<?php print $cId;?>]" value="" step=1></td>
                        <td><?php
                        if ($match->partial[$cId]){
                            $i=0;
                            foreach($match->partial[$cId] as $donorId=>$matchedOn){
                                if ($i>0) print ", ";
                                print '<a onclick="document.getElementById(\'match_'.$cId.'\').value='.$donorId.';return false;">'.$donors[$donorId]->name_combine()."</a>"." (".implode(", ",$matchedOn).") ".$donors[$donorId]->show_field('DonorId');
                                $i++;
                            }
                        }
                        ?>
                        </td>
                        </tr><?php

                    }?></table>
                    <h2>On Donor Press, but not QuickBooks</h2> 
                    <table class="dp"><tr><th>&#8594;</th><th>Donor Press</th><th>Link to Customer</tH></tr>
                    <?php
                    foreach($leftOverDonors as $donorId=>$true){
                        ?><tr><td>&#8594;</td>                      
                        <td><?php print $donors[$donorId]->show_field('DonorId')." - ".$donors[$donorId]->name_combine();?></td>
                        <td><input type="number" name="rmatch[<?php print $donorId;?>]" value="" step=1></td>
                        
                        </tr><?php
                        
                    } 
                    ?></table>
                    <?php 
                    if (sizeof($existing)>0){?>
                        <h2>Existing Matches Found</h2>
                        <table class="dp"><tr><th>QuickBooks</th><th>Donor Press</th></tr>
                            <?php 
                            foreach($existing as $cId=>$donorIds){
                                $i=0;
                                foreach($donorIds as $donorId){
                                    ?><tr>                                
                                    <?php if ($i==0){?>
                                        <td rowspan="<?php print sizeof($donorIds)?>"><?php print '<a href="?page=donor-quickbooks&table=Customer&Id='.$cId.'">'.$cId."</a> ".QuickBooks::qbLink('Customer',$cId,'QB')." - ".self::show_customer_name($customer[$cId])?></td>
                                    <?php } ?>  
                                    <td><?php print $donors[$donorId]->show_field('DonorId')." - ".$donors[$donorId]->name_combine();?></td></tr><?php
                                    $i++;
                                }                                  
                                
                            }?>
                        </table>
                    <?php 
                    }?>
                    </form>
                    <?php            
                    
                }
                return;
            }elseif ($_GET['table']){      
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
                            $notset=$this->display_line('',$entity,$entity);                        
                            print "</table>";
                        }                        
                       
                        if ($notset && sizeof($notset)>0){
                            print "<div><strong>Fields Not Set: </strong>".implode(", ",$notset)."</div>";
                        }                        
                    }
                }else{
                    if ($_GET['Report']=="UnMatched" && $_GET['table']=="Payment"){
                        return self::reportUnMatchedPayments();
                    }


                    $index=$_GET['index']??1;
                    $max=$_GET['max']??100;
                    if ($max>1000) $max=1000;

                    $count =$this->dataService->Query("SELECT count(*) FROM ".$_GET['table']);

                    switch($_GET['table']){
                        case "Payment":
                            print "<div><a href='?page=donor-quickbooks&table=".$_GET['table']."&Report=UnMatched'>Show all unmatched Payments</a></div>";
                        break;
                    }

                    $entities =$this->dataService->Query("SELECT * FROM ".$_GET['table']." STARTPOSITION ".(($index-1)*$max)." MAXRESULTS ".$max);
                    //dd($count,$entities,"SELECT * FROM ".$_GET['table']." STARTPOSITION ".($index*$max)." MAXRESULTS ".$max);
                    if($this->check_dateService_error()){
                        print "<div><a href='?page=donor-quickbooks'>Back to Quickbook list</a></div>
                        <h3>".$_GET['table']." List <span style=\"font-size:60%\">- ".($entities?sizeof($entities).($count!=sizeof($entities)?" of ". $count:""):0)." Entries</span></h3>";
                        if (!$entities || sizeof($entities)==0){
                            self::display_error("No Results Found");
                            return;
                        }
                        print self::pagination($index,$max,$count);
                        $field=$this->QBtables[$_GET['table']]?$this->QBtables[$_GET['table']]:"Id";
                        ?><table class="dp"><th>Id</th><?php
                        if (is_array($field)){
                            foreach($field as $f) print "<th>".str_replace("_"," ",$f)."</th>";
                        }
                        else print "<th>".str_replace("_"," ",$field)."</th>";
                        ?></tr>
                        <?php
                        foreach ($entities as $entity){                                                      
                            ?><tr><td><a href="?page=donor-quickbooks&table=<?php print $_GET['table']?>&Id=<?php print $entity->Id?>"><?php print $entity->Id?></a></td><?php 
                            if (is_array($field)){
                                foreach($field as $f)  print "<td>".$this->showQBfield($entity,$f)."</td>"; //show

                            }else{
                                print "<td>".$this->showQBfield($entity,$field)."</td>";
                            }                           
                            
                            ?></tr><?php
                        }?>
                        </table>
                        <?php    
                        print self::pagination($index,$max,$count);
                    }                           
                }
            }else{ 
                $companyInfo = $this->dataService->getCompanyInfo();               
                ?>
                <h2>Company: <?php print $companyInfo->LegalName?> | <?php print CustomVariables::get_option('QuickbooksBase');?></h2> 
                <div><a href="?page=<?php print $_GET['page']?>&debug=t">Debug Mode</a></div> 
                <div><a href="?page=<?php print $_GET['page']?>&syncDonorsToQB=t">Sync Donors to QuickBooks</a></div>           
                <h3>View</h3>
                 <?php foreach ($this->QBtables as $tbl=>$key){ ?>
                    <div><a href="?page=donor-quickbooks&table=<?php print $tbl?>"><?php print $tbl?></a></div>
                 <?php 
                 }  
                         
            }
            return;           
        }
	}

    function showQBfield($entity,$field){
        //example: $entity,'PrimaryPhone_FreeFormNumber' returns $entity->PrimaryPhone->FreeFormNumber 
        $pieces=explode("_",$field);
        $result=$entity;
        foreach($pieces as $p){
            if (is_array($result) && $result[$p]){
                $result=$result[$p];
            }elseif(is_object($result) && $result->$p){
                $result=$result->$p;
            }else return "";
        }
        if ($result && $this->fieldLinks[$field]){
            $table=$field;
            if (substr($this->fieldLinks[$field],0,1)=="+"){ //special type of "+" in front allows it to read another field
                $tableField=substr($this->fieldLinks[$field],1);               
                $table=$this->showQBfield($entity,$tableField);
                //dd($tableField,$this->fieldLinks[$field],$entity,$table);
            }
            $result=$this->showQBfieldLink($table,$result); 
        }
               
        return $result; 
    }

    function showQBfieldLinkB($table,$value){
        if ($value && $this->fieldLinks[$field]){
            $result='<a target="QBdetail" href="?page=donor-quickbooks&table='.$table.'&Id='.$value.'">'.$value."</a>";
            return $result;
        }else return $value;
    }

    function showQBfieldLink($table,$value){
        return '<a target="QBdetail" href="?page=donor-quickbooks&table='.$table.'&Id='.$value.'">'.$value."</a>";
    }

    static function show_customer_name($c){
        if ($c->CompanyName==$c->FullyQualifiedName){
            return $c->CompanyName.($c->GivenName?" (".$c->GivenName." ".$c->FamilyName.")":"");
        }else{
            return $c->FullyQualifiedName.($c->CompanyName?" (".$c->CompanyName.")":"");
        }
    } 

    public function pagination($index,$max,$count){
        if ($max>$count) return;

        $return="<div style='padding:4px 8px;'>";
        if ($index >1){
            $return.='<a href="'.self::make_link(['index'=>$index-1,'max'=>$max]).'"><- Previous '.$max.'</a>';
        }             
        
        if ($count>$max){
            for($i=1;$i<ceil($count/$max);$i++){                
                $return.='<a href="'.self::make_link(['index'=>$i,'max'=>$max]).'" style="border:1px solid #CCC; padding:4px 8px;';
                if($i==$index) $return.='font-weight:bold; background-color:#CCC;';
                $return.='">'.$i.'</a>';
            }
        }

        if (($index+1)*$max<$count){
            $return.='<a href="'.self::make_link(['index'=>$index+1,'max'=>$max]).'">Next '.(($index+2)*$max>$count?$count-(($index+1)*$max):$max).' -></a>';
        }
        $return.="</div>";
        return $return;
        
    }

    static function make_link($replace=[]){
        $get=$_GET;
        foreach($replace as $f=>$v) $get[$f]=$v;
        $return="?";
        $i=0;
        foreach($get as $f=>$v){           
            if ($i>0) $return.="&";
            $return.=$f."=".urlencode($v);
            $i++;
        }
        return $return;
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
                $this->session([self::SESSION_PREFIX.$key => $_GET[$key]]);  
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

    function DonorToCustomer($donorIds){   
        $hash=[];   
        
        $qb = new self();
        if ($qb->authenticate()){
            $donors=Donor::get(["DonorId IN ('".implode("','",$donorIds)."')"]);
            foreach($donors as $donor){
                $qb->hash_donor_add($donor,$hash);
            }
            $customer=$qb->get_all_entity('Customer');           
            $match=self::customers_to_donor_hash($customer,$hash,['existing'=>$existing]); 

           ?><h2>Create/Sync Donors to QuickBook Customers</h2>
           <form method="post">
           <table class="dp"><tr><th>Donor</th><th>QuickBooks</th></tr>
           <?php foreach($donors as $donor){               
            print '<tr><td>'.$donor->show_field('DonorId')." ".$donor->name_combine()."</td><td>";
            if ($donor->QuickBooksId>0){
                print "Already matched to: ".self::qbLink('Customer',$donor->QuickBooksId);
            }else{
                print '<select name="rmatch['.$donor->DonorId.']">
                <option value="new">-- Create New --</option>
                <option value="0">-- Skip for Now --</option>
                <option value="-1">-- Ignore/Do not create --</option>';                
                if (isset($match->donorId[$donor->DonorId])){
                    foreach($match->donorId[$donor->DonorId] as $cId=>$matchedOn){
                        print '<option value="'.$cId.'" selected>'.self::show_customer_name($customer[$cId]).' - '.$cId.'</option>';
                    }
                }
                if (isset($match->partial[$donor->DonorId])){
                    foreach($match->partial[$donor->DonorId] as $cId=>$matchedOn){
                        print '<option value="'.$cId.'" selected>'.self::show_customer_name($customer[$cId]).' - '.$cId.'</option>';
                    }
                }
                print "</select>";
                if (isset($match->donorId[$donor->DonorId])){
                    print "<span style='color:green;'>Match Found</span>";
                }
                if (isset($match->partial[$donor->DonorId])){
                    print "<span style='color:green;'>Partial Matches Found</span>";
                }
            }    

                
            print "</td></tr>";
           }?>
           </table> <button name="Function" value="LinkMatchQBtoDonorId">Match or Created Selected Above</button>
         </form>
           <?php
        
            
        }
    }

    static public function donation_process_check($donation,$donor){
        if ($donor->TypeId){
            $donorType=DonorType::find($donor->TypeId);      
            print "<strong>Type:</strong> ".$donorType->Title.($donorType->QBItemId?" QB: ".self::qbLink('Item',$donorType->QBItemId):"")." | ";
        }
        if ($donor->QuickBooksId){
            print "<strong>Donor in QB:</strong> ".$donor->show_field("QuickBooksId");       
            if($donation->QBOInvoiceId){
                print " | <strong>Invoice:</strong> ".$donation->show_field("QBOInvoiceId")." synced to QB";
                if($donation->QBOPaymentId){     
                    print " | <strong>Payment</strong>: ".$donation->show_field("QBOPaymentId")." synced to QB";                             
                }else{
                    print ' | <a style="background-color:lightgreen;" target="QB" href="?page=donor-quickbooks&syncDonationPaid='.$donation->DonationId.'">Sync Payment to QuickBooks</a>';
                }
            }elseif($donor->QuickBooksId>0){ //dont' create on ignored entries.
                $return['newInvoicesFromDonation'][]=$donation->DonationId;
                print ' | <a style="background-color:lightgreen;" target="QB" a href="?page=donor-quickbooks&syncDonation='.$donation->DonationId.'">Create Invoice & Payment In QB</a> | <a style="background-color:orange;" target="QB" a href="?page=donor-quickbooks&ignoreSyncDonation='.$donation->DonationId.'">Ignore/Don\'t Sync to QB</a>';
            }
        }else{
            print '<a style="background-color:lightgreen;" target="QB" a href="?page=donor-quickbooks&syncDonorId='.$donation->DonorId.'">Create Donor in QB</a>';
            $return['newCustomerFromDonor'][]=$donation->DonorId;              
        }
        return  $return;      
    }
    
    public function debug(){
        $qb = new self();
        if ($qb->authenticate()){
            $max=100;
            $query=$_REQUEST['query']?$_REQUEST['query']:"Select * From Customer MAXRESULTS ".$max;
            ?><form method="post">
                Query: <textarea name="query"><?php print $query;?></textarea>
                <button>Go</button>
            </form>
            <?php
            if ($query){
                $result=$this->dataService->Query($query);
                $error = $this->oAuth2LoginHelper->getLastError();
                if($error){
                    self::display_error("<strong>Error Refreshing Token</strong> ".$error->getResponseBody()."</div>");
                }else{
                    dump($result);
                }                
            }
            
            /*
            $payments=$qb->get_all_entity('Payment','LinkedTxn IS NULL');            
            <h2>Unmatched Payments</h2>
            <table class="dp"><tr><th</th></table>
            */ 

        }
    }

    public function reportUnMatchedPayments(){
        $unmatched=[];
        $payments=self::get_all_entity('Payment');
        //dump($payments);
        foreach($payments as $p){
            if (!$p->LinkedTxn){
                $unmatched[$p->Id]=$p;
            }            
        }

        if (sizeof($unmatched)>0){
            $list=Donation::get(['QBOPaymentId IN ('.implode(",",array_keys($unmatched)).')'],'DateDeposited');
            print "<h2>UnMatched Payments</h2>".Donation::show_results($list);
        }else{
            print "None Found";
        }
    }
    
    public function get_all_entity($table,$where=""){ //get past the max of 1000 entries on a query from QB
        $return = new stdClass();
        $max=1000;
        $return->count =$this->dataService->Query("SELECT count(*) FROM ".$table.($where?" WHERE ".$where:""));
        # get past Quickbook limit of 1000 results.
        for($i=0;$i<ceil($return->count/$max);$i++){
            $SQL="SELECT * FROM ".$table." STARTPOSITION ".($i*$max+1)." MAXRESULTS ".$max;           
            $chunks[] =$this->dataService->Query($SQL);
        }

        foreach($chunks as $a){
            foreach($a as $c){
                $entity[$c->Id]=$c;      
            }                    
        }
        return $entity;
    }

    static function customers_to_donor_hash($customer,$hash,$settings=[]){       
        $return = new stdClass();
        $return->match=[];
        $return->partial=[];
        $return->donorId=[];
        $qb=new self();

        foreach($customer as $c){                       
            if ($settings['existing'][$c->Id]){ //skip over donors that are already matched if provided

            }else{
                $found=0;                
                $check=[];
                if ($c->PrimaryEmailAddr) $check['Email'][]=$c->PrimaryEmailAddr->Address;
                if ($c->PrimaryPhone) $check['Phone'][]=$c->PrimaryPhone->FreeFormNumber;
                if ($c->FamilyName) $check['Name'][]=$c->GivenName.$c->FamilyName;
                if ($c->FullyQualifiedName) $check['Name'][]=$c->FullyQualifiedName;

                foreach($check as $field=>$values){
                    foreach($values as $val){
                        $matchId=$hash[$field][$qb->hash($val)];
                        if ($matchId){
                            $return->donorId[$matchId][$c->Id][$field]++;
                            $return->match[$c->Id][$matchId][$field]++;
                            $found++;
                        }
                    }
                }                           
                if ($found==0){
                    $notFound[]=$c->Id;
                    ### attempt parital matches on name
                    $check=['FamilyName','FullyQualifiedName','GivenName'];
                    foreach($check as $f){
                        $array=$qb->partialHash($c->FamilyName);
                        foreach( $array as $p){
                            if ($hash['partial'][$p]){
                                foreach ($hash['partial'][$p] as $donorId){
                                    $return->partial[$c->Id][$donorId][]=$f;
                                }
                            }                                        
                        }
                    }                              
                }                          
            }
        }
        return $return;                                               
    }

    static public function qbLink($type,$v,$labelOverride=""){
        if (!$labelOverride) $labelOverride=$v;
        switch($type){
            case "Payment":
                return '<a target="QB" href="'.self::get_QB_url().'app/recvpayment?txnId='.$v.'">'.$labelOverride.'</a>';
                break;	
            case "Invoice":
                return '<a target="QB" href="'.self::get_QB_url().'app/invoice?txnId='.$v.'">'.$labelOverride.'</a>';
                break;          
            case "Customer":
                return '<a target="QB" href="'.self::get_QB_url().'app/customerdetail?nameId='.$v.'">'.$labelOverride.'</a>';
                break;
            case "Item": //not currently linkable
                return '<a target="QB" href="?page=donor-quickbooks&table=Item&Id='.$v.'">'.$labelOverride.'</a>';
               
                //return $labelOverride; //return '<a target="QB" href="'.self::get_QB_url().'app/items?itemId='.$v.'">'.$labelOverride.'</a>';
                break;
        }
    }

    static public function get_QB_url($base=""){
        if (!$base) $base=CustomVariables::get_option('QuickbooksBase');
        return $base=="Production"?"https://app.qbo.intuit.com/":"https://app.sandbox.qbo.intuit.com/";
    }
}