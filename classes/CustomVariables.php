<?php

/* Utilizes Wordpresses Built in custom variables typically in the wp_options table.
* Use wordpress funcitons to edit these
* all custom variables shoudl ahve a "base" of donation, example donation_Organization
*/

class CustomVariables extends ModelLite
{  
    const base = 'donation';
    const variables = ["Organization","ContactName","ContactTitle","ContactEmail","FederalId","PaypalLastSyncDate","DefaultCountry","QuickbooksBase"];	
    const variables_protected = ["PaypalClientId","PaypalSecret","QuickbooksClientId","QuickbooksSecret"];
    const partialTables = [
        ['TABLE'=>'posts','WHERE'=>"post_type='donortemplate'",'COLUMN_IGNORE'=>'ID'],
        ['TABLE'=>'options','WHERE'=>"option_name LIKE 'donation_%'",'COLUMN_IGNORE'=>'option_id']
    ];

    static public function form(){
        $wpdb=self::db();  
        $vals=self::get_custom_variables();      
        ?>
        <h2>Edit Donor Variables</h2>
        <form method="post">
        <input type="hidden" name="table" value="CustomVariables"/>
            <table>
                <?php
                foreach(self::variables as $var){                    
                    $fullVal=self::base."_".$var;
                    //$c->$var=get_option($fullVal);
                    ?>
                    <tr><td><input type="hidden" name="<?php print $var?>_id" value="<?php print $vals->$fullVal?$vals->$fullVal->option_id:""?>"/><?php print $var?></td>
                    <td>
                    <?php 
                    switch($var){
                        case "QuickbooksBase":
                            if (!$vals->$fullVal) $vals->$fullVal=new stdClass();
                            ?>
                            <label><input type="radio" name="<?php print $var?>" value="Production"<?php print $vals->$fullVal->option_value=="Production"?" checked":""?>> Production </label>
                            <label><input type="radio" name="<?php print $var?>" value="Development"<?php print $vals->$fullVal->option_value!="Production"?" checked":""?>> Development </label>
                            <?php
                            break;
                        default:?>
                            <input name="<?php print $var?>" value="<?php print $vals->$fullVal?$vals->$fullVal->option_value:""?>"/>
                        <?php
                        }
                        ?>
                        <input type="hidden" name="<?php print $var?>_was" value="<?php print $vals->$fullVal?$vals->$fullVal->option_value:""?>"/>
                    </td></tr>
                    <?php
                }
                ?>                
                </table>
                <h3>Protected Variables (encoded)</h3>
                <div>By entering a value, it will override what is currently there. Values are encrypted on the database.</div>
                <table>
                <?php
                foreach(self::variables_protected as $var){                    
                    $fullVal=self::base."_".$var;
                    //$c->$var=get_option($fullVal);
                    ?>
                    <tr><td><input type="hidden" name="<?php print $var?>_id" value="<?php print $vals->$fullVal?$vals->$fullVal->option_id:""?>"/><?php print $var?></td><td><input name="<?php print $var?>" value=""/>
                    <?php print $vals->$fullVal?"<span style='color:green;'> - set</span> ":" <span style='color:red;'>- not set</span>";
                    ?></td></tr>
                  <?php
                } 
                ?>
                
            </table>           
            
            <button type="submit" class="primary" name="Function" value="Save">Save</button>
        </form>
        <?php
        if (CustomVariables::get_option('QuickbooksClientId',true)){
            self::display_notice("Allow Redirect access in the <a target='quickbooks' href='https://developer.intuit.com/app/developer/dashboard'>QuickBook API</a> for: ".QuickBooks::redirect_url());
        }
    }
    static function get_option($option,$decode=false){
        $result=get_option(self::base."_".$option);
        if($decode){
            return self::decode($result);
        }else{
            return $result;
        }
    }

    static function set_option($var,$value,$encode=false){
        update_option(self::base."_".$var, $encode? self::encode($value):$value, true);       
    }

    static function encode($value){
        return base64_encode($value);
    }

    static function decode($value){
        return base64_decode($value);
    }

    static function get_custom_variables(){
        $wpdb=self::db();  
        $results=$wpdb->get_results("SELECT `option_id`, `option_name`, `option_value`, `autoload` FROM `".$wpdb->prefix."options` WHERE `option_name` LIKE '".self::base."_%'");
        $c=new self();
        foreach($results as $r){
            $field=$r->option_name;
            $c->$field=$r;
        }
        return $c;
    }

    static function restore($file){
        global $wpdb; 
        $version="";
        if ($lines = file($file)){                   
            foreach($lines as $line){
                $json=json_decode($line);
                if ($json->PLUGIN && $json->VERSION){
                    $version=$json->VERSION;
                }elseif ($json->TABLE && sizeof($json->RECORDS)>0){
                    print "<div>Restoring to ".$wpdb->prefix.$json->TABLE." ".sizeof($json->RECORDS)." records.</div>";
                    foreach($json->RECORDS as $data){
                        //todo in the future check $version against upgrade table, and shift fields if necessary.
                        $wpdb->insert($wpdb->prefix.$json->TABLE,(array)$data);
                    }
                }                          
            }
        }
    }

    static function backup($download=false){               
        //Backups up all donor related tables and partial tables on posts and options   
        global $wpdb;    
        global $donor_press_db_version;
        $fileName="DonorPressBackup".date("YmdHis").".json";
        $filePath=Donor::upload_dir().$fileName;
        $file = fopen($filePath, "w");

        fwrite($file, json_encode(["PLUGIN"=>"DonorPress","VERSION"=>$donor_press_db_version])."\n");
        foreach(donor_press_tables() as $table){
            $records=[];           
            $SQL="Select * FROM ".$table::get_table_name();
            if(!$download) print "Backing up TABLE: ".$table::get_table_name()."<br>";           
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){ 
                $c=clone($r); 
                ### cleanup backup to not add "null" or blank values               
                foreach($c as $field=>$value){
                    if (!$value){
                        unset($r->$field);
                    }
                }              
                $records[]=$r;
            }
            fwrite($file, json_encode(["TABLE"=>$table::get_base_table(),'RECORDS'=>$records])."\n");
        }    
       
        foreach(self::partialTables as $a){
            $records=[];
            $SQL="Select * FROM ".$wpdb->prefix.$a['TABLE']." WHERE ".($a['WHERE']?$a['WHERE']:1);
            if(!$download)print "Backing up QUERY: ". $SQL."<br>";
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){
                if ($a['COLUMN_IGNORE']){
                    $colIgnore=$a['COLUMN_IGNORE'];
                    unset($r->$colIgnore);
                }
                $records[]=$r;
            } 
            $a['RECORDS']=$records;           
            fwrite($file, json_encode($a)."\n");
        }
        fclose($file);

        if ($download){           
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename='.basename($filePath));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            header("Content-Type: text/plain");
            readfile($filePath);
            exit();
        }else{
            self::display_notice($fileName." backup created");
        }
        
    }

    static public function nuke_confirm(){
        ?><h2>You are about to remove all donor/donations records from the database</h2>
        <div style='color:red;'>This will be permanent. Please back up anything important before proceeding.</div>
        <form method="post" action="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>">
            <label><input type="checkbox" name="backup" value="t" checked>Backup Existing Donor Press Records to a flat file</label><br>  
            <label><input type="checkbox" name="droptable" value="t" checked>Drop All Tables Data and Structures (Donor, Donation, Categories)</label><br>           
            <label><input type="checkbox" name="dropfields" value="t" checked>Drop All Custom Fields (Letter Templates, settings)</label><br>
            <label><input type="checkbox" name="rebuild" value="true" checked>Recreate Tables (will be blank)</input></label><br>

            <button name="Function" value="NukeIt">Nuke It</button>
            <button>Cancel</button>
        </form>
        <?php
    }

    static public function nuke_it($post){
        if ($post['backup']) self::backup(); //back it up to flat file before nuke
        if ($post['droptable']){
            foreach(donor_press_tables() as $table){
                $SQL= "DROP TABLE IF EXISTS ".$table::get_table_name();
                print $SQL."<br>";
                self::db()->query($SQL);
            }
        }
        if($post['dropfields']){
            foreach(self::partialTables as $a){   
                $SQL="DELETE FROM ".$wpdb->prefix.$a['TABLE']." WHERE ".($a['TABLE']?$a['TABLE']:1); 
                print $SQL."<br>";       
                self::db()->query($SQL);
            }
        }

        if ($post['rebuild']){ 
            donor_plugin_create_tables();
            print "TABLES Rebuilt";    
        }

    }


    static public function request_handler(){        
        $wpdb=self::db();         
        switch($_POST['Function']){
            case 'BackupDonorPress': 
                self::backup();
                break;
            case 'RestoreDonorPress':
                if ($_FILES["fileToUpload"]["tmp_name"]){
                    self::backup(); //backup current first.                    
                    nuke(); //clear out current files
                    self::restore($_FILES["fileToUpload"]["tmp_name"]);  
                }
                break;
            case 'NukeDonorPress':
                self::nuke_confirm();
                return true;
                break;            
        }		

		
        if ($_POST['Function'] == 'Save' && $_POST['table']=="CustomVariables"){
            foreach(self::variables as $var){
                if ($_POST[$var]!=$_POST[$var.'_was']){
                    if ($_POST[$var.'_id']){
                        //update
                        print "update ".$var."<br>";
                        update_option( self::base."_".$var, $_POST[$var], true);
                    }else{
                        print "insert ".$var." <br>";
                        //insert
                        add_option( self::base."_".$var, $_POST[$var]);
                    }
                }   
            }
            foreach(self::variables_protected as $var){
                if ($_POST[$var]!=""){
                    if ($_POST[$var.'_id']){
                        //update
                        print "update ".$var."<br>";
                        update_option( self::base."_".$var, self::encode($_POST[$var]), true);
                    }else{
                        print "insert ".$var." <br>";
                        //insert
                        add_option( self::base."_".$var, self::encode($_POST[$var]));
                    }
                }
            }
        }
    }
}