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

    const variables_manual=["DefaultQBItemId"];
    const partialTables = [
        ['TABLE'=>'posts','WHERE'=>"post_type='donortemplate'",'COLUMN_IGNORE'=>'ID'],
        ['TABLE'=>'options','WHERE'=>"option_name LIKE 'donation_%'",'COLUMN_IGNORE'=>'option_id']
    ];

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

    static public function form(){
        $wpdb=self::db();  
        $vals=self::get_custom_variables(); 
        //dump($vals);     
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
                            Note: <code><?php print site_url()?>/wp-admin/admin.php?redirect=donor_quickBooks_redirectUrl</code> must be entered into the developer app as a Redirect URL.
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
                if (Quickbooks::is_setup()){                
                    $qb=new QuickBooks();
                    if ($items=$qb->item_list("",false)){
                        $var="DefaultQBItemId";
                        $fullVal=self::base."_".$var;
                        $val=$vals->$fullVal?$vals->$fullVal->option_value:"";                            
                        ?>  
                  
                        <tr><td align="right">Default Quickbooks Item Id</td><td><select name="DefaultQBItemId"><option value="0">[--None--]</option><?php               
                        foreach($items as $item){
                            print '<option value="'.$item->Id.'"'.($item->Id==$val?" selected":"").'>'.$item->FullyQualifiedName.'</option>';
                        }
                        ?></select>
                        <input type="hidden" name="<?php print $var?>_id" value="<?php print $vals->$fullVal?$vals->$fullVal->option_id:""?>"/>

                        <input type="hidden" name="<?php print $var?>_was" value="<?php print $val?>"/> </td></tr>
                    <?php
                    } 
            } ?>
                
            </table>           
            
            <button type="submit" class="primary" name="Function" value="Save">Save</button>
        </form>
        <?php
        if (CustomVariables::get_option('QuickbooksClientId',true)){
            self::display_notice("Allow Redirect access in the <a target='quickbooks' href='https://developer.intuit.com/app/developer/dashboard'>QuickBook API</a> for: ".QuickBooks::redirect_url());
        }
        print "<div><strong>Plugin base dir:</strong> ".dn_plugin_base_dir()."</div>";       
        
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

    static function restore($file,$chunksize=500){ 
        global $wpdb;
        $version="";
        if ($lines = file($file)){                   
            foreach($lines as $line){
                $json=json_decode($line);
                $json->TABLE=strtolower($json->TABLE);
                if ($json->PLUGIN && $json->VERSION){
                    $version=$json->VERSION;
                }elseif ($json->TABLE && sizeof($json->RECORDS)>0){
                    print "<h2>Restoring to ".$wpdb->prefix.$json->TABLE." ".sizeof($json->RECORDS)." records.</h2>";
                    
                    $chunk=array_chunk($json->RECORDS,$chunksize); //insert 500 rows at a time                    
                    foreach($chunk as $rows){
                         //todo in the future check $version against upgrade table, and shift columns/fields if necessary.
                        $iSQL="INSERT INTO ".$wpdb->prefix.$json->TABLE." (".implode(",",$json->COLUMNS).") VALUES \r\n";
                        $r=0;
                        foreach($rows as $row){
                            $c=0;
                            if ($r>0) $iSQL.=",\r\n";                            
                            $iSQL.="(";
                            foreach($row as $col){
                                if ($c>0) $iSQL.=", ";
                                $iSQL.="'".self::mysql_escape_mimic($col)."'";
                                $c++;
                            }
                            $iSQL.=")";
                            $r++;
                        }
                        print "<div>Table: ".$wpdb->prefix.$json->TABLE." - Chunk size: ".sizeof($rows)."</div>";//<pre>".$iSQL."</pre>";
                        $wpdb->query($iSQL);

                    }
                }                          
            }
        }
    }
    
    static function get_org(){
        $org=self::get_option('Organization');
        if (!$org) $org=get_bloginfo('name');
        return $org;
    }

    static function backup($download=false){               
        //Backups up all donor related tables and partial tables on posts and options   
        global $wpdb;    
        global $donor_press_db_version;
        $org= mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', self::get_org());
        $fileName="DonorPressBackup-".str_replace(" ","_",$org).date("YmdHis").".json";
        $filePath=Donor::upload_dir().$fileName;
        $file = fopen($filePath, "w");

        fwrite($file, json_encode(["PLUGIN"=>"DonorPress","DBPREFIX"=>$wpdb->prefix,"VERSION"=>$donor_press_db_version,"ORG"=>self::get_org(),"URL"=>get_bloginfo('url')])."\n");
        foreach(donor_press_tables() as $table){
            $records=[];           
            $SQL="Select * FROM ".$table::get_table_name();
            if(!$download) print "Backing up TABLE: ".$table::get_table_name()."<br>";           
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){ 
                $c=(array)$r;                
                $cols=array_keys($c);               
                $records[]=array_values($c) ;  
            }
            fwrite($file, json_encode(["TABLE"=>$table::get_base_table(),"TABLE_SRC"=>$table::get_table_name(),'COLUMNS'=>$cols,'RECORDS'=>$records])."\n");
        }    
       
        foreach(self::partialTables as $a){
            $records=[];
            $SQL="Select * FROM ".$wpdb->prefix.$a['TABLE']." WHERE ".($a['WHERE']?$a['WHERE']:1);
            if(!$download)print "Backing up QUERY: ". $SQL."<br>";
            $results=$wpdb->get_results($SQL);
            foreach ($results as $r){
                $c=(array)$r;
                if ($a['COLUMN_IGNORE']){                    
                    unset($c[$a['COLUMN_IGNORE']]); //currently only used for id field, but can be modified if multiple fields need supported.
                }
                $a['COLUMNS']=array_keys($c);
                $a['RECORDS'][]=array_values($c);
            }                      
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

    static function mysql_escape_mimic($inp) { //https://www.php.net/manual/en/function.mysql-real-escape-string.php
        if(is_array($inp)) return array_map(__METHOD__, $inp);   
         if(!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);    
        }    
        return $inp;    
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
                $SQL="DELETE FROM ".self::db()->prefix.$a['TABLE']." WHERE ".($a['WHERE']?$a['WHERE']:1);
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
                //dd($_POST,$_FILES);
                if ($_FILES["fileToUpload"]["tmp_name"]){
                    //self::backup(); //backup current first.                    
                    nuke(); //clear out current files
                    self::restore($_FILES["fileToUpload"]["tmp_name"]);  
                    print self::display_notice("Restore Complete");
                    return true;
                    break;
                }elseif($_FILES["fileToUpload"]["error"]){                    
                    self::display_error("Error on file upload. If the file is over ".ini_get("upload_max_filesize")." then you will have to update your server upload limit.");
                }
                break;
            case 'NukeDonorPress':
                self::nuke_confirm();
                return true;
                break;
            case 'NukeIt':
               self::nuke_it($_POST);
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

            foreach(self::variables_manual as $var){
                if ($_POST[$var]!=$_POST[$var.'_was']){
                    if ($_POST[$var.'_id']){
                        //update
                        print "update ".$var."<br>";
                        update_option( self::base."_".$var, $_POST[$var], true);
                    }else{
                        print "insert ".$var." <br>";
                        //insert
                        //dump(self::base."_".$var, $_POST[$var]);
                        add_option( self::base."_".$var, $_POST[$var]);
                    }
                }   
            }
        }
    }
}