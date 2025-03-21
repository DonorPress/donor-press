<?php
namespace DonorPress;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Paypal extends ModelLite{
    var $token;
    var $url;
    var $error;

    public function __construct(){
        
    }

    public function get_token(){
        $this->destroy_session();        
        //unset($_SESSION['wp_paypal_access_token'],$_SESSION['wp_paypal_access_token_expires']);
        //Paypal token is cached as a SESSION variable to avoid the need to request a token multiple times.
        if($this->token){ //current classes token trumps anything stored in session. Not abosolutely necessary to do this.
        }elseif ($_SESSION['wp_paypal_access_token'] && date("Y-m-d H:i:s")<$_SESSION['wp_paypal_access_token_expires']){
            $this->token=$_SESSION['wp_paypal_access_token'];       
        }else{
            $this->destroy_session();
        }

        if ($this->token) return $this->token;
        $clientId=CustomVariables::get_option('PaypalClientId',true);
        $clientSecret=CustomVariables::get_option('PaypalSecret',true);        
        $args = array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'sslverify'       => true,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($clientId.':'.$clientSecret),
            ),
            'body' => array(
                'grant_type'=>'client_credentials'
            )            
        );
        $response = wp_remote_post( $this->get_url().'oauth2/token', $args,$clientId.':'.$clientSecret);
        if ( is_wp_error( $response ) ) {
            $this->error="Error from ".$this->get_url()."oauth2/token: ". $response->get_error_message();
            self::display_error($this->error);           
        }else {
            $json = json_decode($response['body']);
            if ($json->error){              
                $this->error="<strong>".$json->error.":</strong> ".$json->error_description;
                if ($json->error=="invalid_client"){
                    $this->error.=". Check your PaypalClientId and Paypal Secret. You may have to <a target='paypaltoken' href='https://developer.paypal.com/dashboard/applications/live'>create a new one here</a>. Once created, make sure it is <a target='paypaltoken' href='?page=donorpress-settings'>set here</a>.";
                }
                self::display_error($this->error);
            }else{
                $_SESSION['wp_paypal_access_token']=$json->access_token;
                $_SESSION['wp_paypal_access_token_expires']=date("Y-m-d H:i:s",strtotime("+".($json->expires_in-30)." seconds")); //30 seconds removed to avoid timeouts on longer queries that might be stacked.           
                $this->token =$json->access_token;
                return $this->token;
            }    
        }       
        return false;
    }    

    public function destroy_session(){
        unset($_SESSION['wp_paypal_access_token'],$_SESSION['wp_paypal_access_token_expires']);
    }

    public function get_url(){
        if (!$this->url) $this->url=CustomVariables::get_option('PaypalUrl',true);
        if (!$this->url) $this->url="https://api-m.paypal.com/v1/"; 
        return $this->url;       
    }

    public function get_transactions_date_range($start_date,$end_date=null){
        //API only allows for Date range  under 31 days. If it is greater the provided date range is greater, then this function chunks it into shorter date ranges 
        $response=null;
        $ts_start=strtotime($start_date);
        $ts_end=$end_date?strtotime($end_date):time();
        if ($ts_end-$ts_start>30*24*60*60){
            for($month=0;$month<ceil(($ts_end-$ts_start)/(30*24*60*60));$month++){
                //print "<strong>".$month."</strong> - ";
                $start=date("Y-m-d",$ts_start+$month*30*24*60*60);
                $end=date("Y-m-d",$ts_start+(($month+1)*30-1)*24*60*60);
                if ($end>date("Y-m-d",$ts_end)) $end=date("Y-m-d",$ts_end);
                if ( $start> $end) return;// shouldn't happen, but just in case 
                $response=$this->get_transactions_date_range($start,$end);
                if ($response)  $responses[]=$response;
            }
            ### combine results into one array.
            foreach($responses as $res){
                if (!$response) $response=$res;
                else{
                    foreach($res->transaction_details as $t){
                        $response->transaction_details[]=$t;
                    }
                    foreach($res->links as $l){
                        $response->links[]=$l;
                    }
                }
                
            }
            return $response;
        }
        
        $start_date=date("Y-m-d",$ts_start)."T00:00:00.000Z";
        $end_date=($end_date?date("Y-m-d",$ts_end):date("Y-m-d"))."T23:59:59.999Z";       
        $token=$this->get_token();
        if ($token){            
            $args = array(
                'method'      => 'GET',
                'timeout'     => 45,
                'redirection' => 10,
                'httpversion' => '1.1',
                'blocking'    => true,
                'sslverify'       => true,
                'headers' => array(
                    'Authorization' => 'Bearer '.$token,
                ),
                'body' => array(
                    'grant_type'=>'client_credentials'
                )            
            );
            $response = wp_remote_get( $this->get_url().'reporting/transactions?fields=transaction_info,payer_info,shipping_info,cart_info&start_date='.$start_date.'&end_date='.$end_date, $args);
            if ( is_wp_error( $response ) ) {
                $this->error="Error from ".$this->get_url()."oauth2/token: ". $response->get_error_message();
                self::display_error($this->error);           
            }else {
                $json = json_decode($response['body']);
            } 
            if ($json->localizedMessage){
                self::display_error("Response Error: ".$json->localizedMessage);
            } 
            return $json;
        }
        return false;
    }

    public function process_response($response,$dateEnd=""){
        $process=$donations=$donors=$donorEmails=array();
        $process['time']=time();
        $donationSkip=0;
        //This first loop caches results and puts them in Donor and Donation objects, but does NOT save them yet.         
        foreach($response->transaction_details as $r){
            $donor=Donor::from_paypal_api_detail($r);
            $donors[$donor->SourceId]=$donor;
            if ($r->payer_info->email_address){
                $donorEmails[$r->payer_info->email_address]=$r->payer_info->account_id; //potentially one e-mail could have multiple... this grabs the most recent.
            }           
            if ($donations[$r->transaction_info->transaction_id]){
                self::display_error("Duplicate Transaction: ".$r->transaction_info->transaction_id." - using latest entry");
            }
            $donations[$r->transaction_info->transaction_id]=Donation::from_paypal_api_detail($r);
        }

        //Do a database check on donors to ensure no duplicates - If not found, insert.
        $SQL="SELECT * FROM ".Donor::get_table_name()." WHERE (Source='paypal' AND SourceId IN ('".implode("','",array_keys($donors))."')) OR (Email<>'' AND Email IS NOT NULL AND Email IN ('".implode("','",array_keys($donorEmails))."'))";
        $results = self::db()->get_results($SQL);        
        foreach($results as $r){
            $donorOriginal[$r->DonorId]=$r;          
            $account_id=$donors[$r->SourceId]?$r->SourceId:$donorEmails[$r->Email];
            $donor_id=$r->MergedId>0?$r->MergedId:$r->DonorId;
            if ($donors[$account_id]){
                $donors[$account_id]->DonorId=$donor_id;
                $process['DonorsMatched'][$account_id]=$donor_id;
            }           
        }       
        Donor::donor_update_suggestion($donorOriginal,$donors,$process['time']);

        ### need to do some sort of compare -> check out existing...
        foreach($donors as $account_id=>$donor){            
            if (!$donor->DonorId){ //new Donor detected
                $donor->save();
                $donors[$account_id]->DonorId=$donor->DonorId;
                $process['DonorsAdded'][$account_id]=$donor->DonorId;
            }
        }

        //Do database check on donations to ensure no duplicates.
        $SQL="SELECT * FROM ".Donation::get_table_name()." WHERE Source='paypal' AND TransactionID IN ('".implode("','",array_keys($donations))."')";
        $results = self::db()->get_results($SQL);       
        foreach($results as $r){
            if ($donations[$r->TransactionID]){ 
                $donations[$r->TransactionID]->DonationId=$r->DonationId;
                if ($r->SourceId==""){
                    $donations[$r->TransactionID]->UpdateSourceId=true;
                }
                //$donations[$r->TransactionID]->CreatedAt=$r->CreatedAt;
                $process['DonationsMatched'][$r->TransactionID]=$r->DonationId;                
            }
        }
        
        foreach($donations as $transaction_id=>$donation){
            if ($donation->DonationId){  
                //print "<div>Found ". $donation->DonationId."</div>";     
                if ($donation->UpdateSourceId){
                    //print "UpdateSource Id to: ".$donation->SourceId;
                    ### avoid a save... we don't want to overwrite everything in case manual adjustments were made. But update a few thigns we hadn't saved before. Can comment this out once DB is fixed.                    
                    self::db()->update($donation->get_table(),array('Source'=>'paypal','SourceId'=>$donation->SourceId),array('DonationId'=>$donation->DonationId));
                }
            }else{
                //print "<div>New". $donation->DonationId."</div>"; 
                if($donors[$donation->SourceId]){
                    $donation->DonorId=$donors[$donation->SourceId]->DonorId;
                    //print "DonorId is: ".$donation->DonorId;
                    $donation->save($process['time']);
                    $process['DonationsAdded'][$donation->TransactionID]=$donation->DonationId;
                }else{
                    self::display_error("<div>Error: Donor Id not found on Paypal Transaction: ".$donation->TransactionID." on SourceId: ".$donation->SourceId."</div>");
                }
            }
        }
        ### Set last Sync Date
        if ($dateEnd && $process['DonationsAdded'] && sizeof($process['DonationsAdded'])>0){
            CustomVariables::set_option('PaypalLastSyncDate',$dateEnd);
        }
        return $process;
    }

    static function is_setup(){ 
        if (CustomVariables::get_option('PaypalClientId') && CustomVariables::get_option('PaypalSecret')){
            return true;
        }else{
            return false;
        }
    }
}