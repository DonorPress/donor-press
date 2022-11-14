<style>
@media print
{    
    #adminmenumain,#wpadminbar,.no-print, .no-print *
    {
        display: none !important;
    }
	body { background-color:white;}
	#wpcontent, #wpfooter{ margin-left:0px;}
	
}
</style>
<div id="pluginwrap">
	<?php
    global $wpdb;	
	if (DonationCategory::request_handler()) { print "</div>"; return;}	
    if (DonorTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>	
	<h1>Settings</h1>
    <?php    
    DonationCategory::list();
    DonorTemplate::list();
    CustomVariables::form();
    ?>
   

</div>