<?php
require_once 'classes/Paypal.php';
if (Donor::request_handler())  { print "</div>"; return;}
?>
<div id="pluginwrap">
    <h2>Paypal Test</h2><?php
    $paypal = new Paypal();

    if ($_POST['Function']=="MakeDonorChanges"){
        print "Results updated";
    }else{
        //$results=$paypal->get_transactions_date_range(date("Y-m-d",strtotime("-33 days")));
        $results=$paypal->get_transactions_date_range('2022-01-01','2022-11-21');
        print "<pre>"; print_r($results); print "</pre>";
    }


?>
</div>