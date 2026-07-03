<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
use DonorPress\Donor;
use DonorPress\Paypal;
use DonorPress\CustomVariables;
if (Donor::request_handler())  { print "</div>"; return;}

$paypal = new Paypal();
$clientId=CustomVariables::get_option('PaypalClientId');
$clientSecret=CustomVariables::get_option('PaypalSecret');

?>
<div id="pluginwrap">
    <h2>Paypal API Import</h2><?php
    if (Donor::input('Function','post')=="MakeDonorChanges"){
        Paypal::display_notice("Donor Records Updated");
    }elseif(Donor::input('Function','post')=="PaypalDateSyncDebug"){
        $response=$paypal->get_transactions_date_range(Donor::input('date_from','post'),Donor::input('date_to','post'));
        ?><h1>Debug Mode: Paypal API Response</h1><pre><?php print_r($response);?></pre><?php
    }elseif(Donor::input('Function','post')=="PaypalDateSync"){
        $response=$paypal->get_transactions_date_range(Donor::input('date_from','post'),Donor::input('date_to','post'));
        $process=$paypal->process_response($response,Donor::input('date_to','post'));
        if (!$process['time']) $process['time']=time();
        if (!is_numeric($process['time'])){
            $process['time']=strtotime($process['time']);
        }
        if ($response){
            if ($response->transaction_details){
                Paypal::display_notice(
                    ($response->transaction_details?sizeof($response->transaction_details):"0")." records retrieved. <ul>".
                    "<li>".($process['DonorsAdded']?sizeof($process['DonorsAdded']):"0")." New Donor Entries Created.</li>".
                    ($process['DonationsMatched'] && sizeof($process['DonationsMatched'])>0?"<li>".sizeof($process['DonationsMatched'])." donations already created.</li>":"").
                    ($process['DonationsAdded'] && sizeof($process['DonationsAdded'])>0?"<li>".sizeof($process['DonationsAdded'])." new donations added. <a target='sendreceipts' href='?page=donorpress-reports&UploadDate=".urlencode(date("Y-m-d H:i:s",$process['time']))."'>View These Donations/Send Acknowledgements</a></li>":"").
                    "</ul>"
                );
            }else{
                if ($response->message){                    
                    Paypal::display_error("<strong>".$response->name."</strong> ".$response->message);
                    foreach($response->details as $d){
                        Paypal::display_error("<strong>".$d->issue."</strong> ".$d->description);
                    }
                }
            }
        }
    }

    if (!$clientId || !$clientSecret){
        print Paypal::display_error("Paypal API Client/Password not setup. Create a <a target='paypaltoken' href='https://developer.paypal.com/dashboard/applications/live'>Client/Password on Paypal</a> first, and then <a href='?page=donorpress-settings'>paste them in the settings</a>.");
    }else{
        $date_from=$lastSync=CustomVariables::get_option('PaypalLastSyncDate');
        if ($date_from) $date_from=date("Y-m-d",strtotime($date_from." -1 day"));
        if (!$date_from) $date_from=Donor::input('date_from','get')?Donor::input('date_from','get'):date("Y-01-01");
        $date_to=Donor::input('date_to','get')?Donor::input('date_to','get'):date("Y-m-d");
        ?>
        <form method=post>
            <strong>Sync Transactions From:</strong>
            <input type="date" name="date_from" value="<?php print date('Y-m-d',strtotime($date_from))?>"/> 
            to 
            <input type="date" name="date_to" value="<?php print date('Y-m-d',strtotime($date_to))?>"/>
            <button name="Function" value="PaypalDateSync">Sync</button> 
            <?php if ($lastSync){ print "<span style='margin-left:20px;'><em>Last Sync: ".date("Y-m-d",strtotime($lastSync))."</em></span>";}?>
            <div><button name="Function" value="PaypalDateSyncDebug">Debug Mode</button> <em>Retreives results from the above date range and displays the raw output, but does not process them.</em></div>
        </form><?php
    }
?>
</div>