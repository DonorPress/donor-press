<?php
require_once 'classes/Paypal.php';
if (Donor::request_handler())  { print "</div>"; return;}

$paypal = new Paypal();
$clientId=CustomVariables::get_option('PaypalClientId');
$clientSecret=CustomVariables::get_option('PaypalSecret');

?>
<div id="pluginwrap">
    <h2>Paypal API Import</h2><?php
    if ($_POST['Function']=="MakeDonorChanges"){
        Paypal::display_notice("Donor Records Updated");
    }elseif($_POST['Function']=="PaypalDateSync"){
        $response=$paypal->get_transactions_date_range($_POST['date_from'],$_POST['date_to']);
        $process=$paypal->process_response($response,$_POST['date_to']); 
        if ($response){
            Paypal::display_notice(
                sizeof($response->transaction_details)." records retrieved. <ul>".
                "<li>".($process['DonorsAdded']?sizeof($process['DonorsAdded']):"0")." New Donor Entries Created.</li>".
                ($process['DonationsMatched'] && sizeof($process['DonationsMatched'])>0?"<li>".sizeof($process['DonationsMatched'])." donations already created.</li>":"").
                ($process['DonationsAdded'] && sizeof($process['DonationsAdded'])>0?"<li>".sizeof($process['DonationsAdded'])." new donations added. <a target='sendreceipts' href='?page=donor-reports&UploadDate=".urlencode(date("Y-m-d H:i:s",$process['time']))."'>View These Donations/Send Acknowledgements</a></li>":"").
                "</ul>"
            );
        }
    }

    if (!$clientId || !$clientSecret){
        print Paypal::display_error("Paypal API Client/Password not setup. Create a <a target='paypaltoken' href='https://developer.paypal.com/dashboard/applications/live'>Client/Password on Paypal</a> first, and then <a href='?page=donor-settings'>paste them in the settings</a>.");
    }else{
        $date_from=CustomVariables::get_option('PaypalLastSyncDate');
        if (!$date_from) $date_from=$_GET['date_from']?$_GET['date_from']:date("Y-01-01");
        $date_to=$_GET['date_to']?$_GET['date_to']:date("Y-m-d");
        ?>
        <form method=post>
            Sync Transactions From: 
            <input type="date" name="date_from" value="<?php print date('Y-m-d',strtotime($date_from))?>"/> 
            to 
            <input type="date" name="date_to" value="<?php print date('Y-m-d',strtotime($date_to))?>"/>
            <button name="Function" value="PaypalDateSync">Sync</button>
        </form><?php
    }
?>
</div>