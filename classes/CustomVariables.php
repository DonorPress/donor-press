<?php

/* Utilizes Wordpresses Built in custom variables typically in the wp_options table.
* Use wordpress funcitons to edit these
* all custom variables shoudl ahve a "base" of donation, example donation_Organization
*/
class CustomVariables extends ModelLite
{  
    const base = 'donation';
    const variables = ["Organization","ContactName","ContactTitle","ContactEmail","FederalId"];	
    
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
                    <tr><td><input type="hidden" name="<?php print $var?>_id" value="<?php print $vals->$fullVal?$vals->$fullVal->option_id:""?>"/><?php print $var?></td><td><input name="<?php print $var?>" value="<?php print $vals->$fullVal?$vals->$fullVal->option_value:""?>"/>
                    <input type="hidden" name="<?php print $var?>_was" value="<?php print $vals->$fullVal?$vals->$fullVal->option_value:""?>"/></td></tr>
                    <?php
                }?>
            </table>
            <button type="submit" class="primary" name="Function" value="Save">Save</button>
        </form>
        <?php
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

    static public function request_handler(){        
        $wpdb=self::db();  
        if ($_POST['Function'] == 'Save' && $_POST['table']=="CustomVariables"){
            foreach(self::variables as $var){
                if ($_POST[$var]!=$_POST[$var.'_was']){
                    if ($_POST[$var.'_id']){
                        //update
                        print "update ".$var."<br>";
                        $wpdb->update_option( self::base."_".$var, $_POST[$var], true);
                    }else{
                        print "insert ".$var." <br>";
                        //insert
                        $wpdb->add_option( self::base."_".$var, $_POST[$var]);
                    }
                }   
            }
        }
    }

}