<?php
use DonorPress\Donor;
use DonorPress\DonorType;
use DonorPress\DonationCategory;
use DonorPress\DonorTemplate;
use DonorPress\CustomVariables; 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
?>
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
<?php
$tabs=['cv'=>'Site Variables','email'=>'Email Templates','cat'=>'Donation Categories','type'=>'Donor Types','bak'=>'Backup/Restore'];
$active_tab=Donor::show_tabs($tabs);

?>
<div id="pluginwrap">
	<?php
    if (DonationCategory::request_handler()) { print "</div>"; return;}	
    if (DonorType::request_handler()) { print "</div>"; return;}	
    if (DonorTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>
    <h1>Settings: <?php print esc_html($tabs[$active_tab])?></h1><?php
    switch($active_tab){  
        case "type":  DonorType::list(); break;     
        case "cat":  DonationCategory::list(); break;
        case "email": DonorTemplate::list(); break;
        case "bak":
            ?><form method="post" enctype="multipart/form-data">
                <h2>Backup</h2>
                <div>
                    <button name="Function" value="BackupDonorPress">Backup Donor Press Tables/Settings</button>
                </div>                                
                <hr>
                <h2>Restore</h2>                
                <div>
                <input type="file" name="fileToUpload" accept=".json">
                <button name="Function" value="RestoreDonorPress">Restore from File</button>
                Server Upload Limit: <?php print esc_html(ini_get("upload_max_filesize"));?>  <em>Caution - will remove current Donor Press Data</em>
                </div>
                <hr>
                <h2>Nuke Site</h2>
                <button name="Function" value="NukeDonorPress">Clear Out Donor Press Files</button> - Useful for uninstalls or during testing.
                <h2>Load Test Data</h2>
                <button name="Function" value="LoadTestData">Load Test Records</button>  Records: <input type="number" name="records" value="20"/> - Useful for testing the plugin.
            </form><?php
            break;
        case "cv":  
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>