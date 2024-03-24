<?php
### Manages the Type of Donor

class DonorType extends ModelLite
{
    protected $table = 'donor_type';
	protected $primaryKey = 'TypeId';
	### Fields that can be passed 
    protected $fillable = ["Title","QBItemId"];	  
    //add "table" + "Field"? and make it more flexible for any field?

    static public function request_handler(){
        $wpdb=self::db();  
        if (self::input('TypeId','post') && self::input('Function','post')=="Delete" && self::input('table','post')=="donor_type"){
            $donorType=new self(self::input_model('post'));
            if ($donorType->delete()){
                self::display_notice("Donor Type '".$donorType->Title."' deleted."); 
            }
        }elseif (self::input('TypeId','post') && self::input('Function','post')=="DonorTypeMergeTo" && self::input('table','post')=="donor_type"){
            $donorType=new self(self::input_model('post'));
            $mergeTo=self::get(self::input('MergeTo','post'));
            if (!$mergeTo->TypeId){
                self::display_error("Could not find Merge to Donor Type: ".self::input('MergeTo','post'));
                return;
            }
            $old=new self(self::input_model('post'));
            $old->donor_count();
            
            $wpdb->update(Donor::get_table_name(),array("TypeId"=>self::input('MergeTo','post')),array('TypeId'=> $old->TypeId));
            self::display_notice($old->donor_count." donors changed from Type '". $old->Title."' to ".$mergeTo->show_field("TypeId")." - ".$mergeTo->Title); 

            if ($donorType->delete()){                
                self::display_notice("Donor Type '".$donorType->Title."' deleted."); 
            }        
        }elseif (self::input('TypeId','get')&&self::input('tab','get')=="type"){	
            if (self::input('Function','post')=="Save" && self::input('table','post')=="donor_type"){
                $donorType=new self(self::input_model('post'));
                if ($donorType->save()){
                    self::display_notice("Donor Typey #".$donorType->show_field("TypeId")." - ".$donorType->Title." saved.");
                }
            }
            if (self::input('TypeId','request')=="new"){
                $donorType=new self();
            }else{
                $donorType=self::find(self::input('TypeId','request'));
            }           
            ?>
            <div id="pluginwrap">
                <div><a href="<?php print esc_url('?page='.self::input('page','get'))?>">Return</a></div>
                <h1>Type #<?php print $donorType->TypeId?$donorType->TypeId:"NEW"?></h1><?php 
                if (self::input('edit','request')){
                    $donorType->edit_form();
                }else{
                    ?><div><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&TypeId='.$donorType->TypeId.'&edit=t')?>">Edit Type</a></div><?php
                    $donorType->view(); 
                }
            ?></div><?php
            return true;
        }elseif(self::input('TypeId','request')=="new" && self::input('tab','request')=='type'){
            $donorType=new self(['Title'=>self::input('Title','post'),'QBItemId'=>self::input('QBItemId','post')]);
            $donorType->save();
            self::display_notice("Donor Type #".$donorType->show_field("TypeId")." ".$donorType->Type." created.");
        }else{
            return false;
        }
    }

    function edit_form(){
        $wpdb=self::db();         
        $primaryKey=$this->primaryKey;
		?><form method="post" action="<?php print esc_url('?page='.self::input('page','get').(self::input('tab','get')?'&tab='.self::input('tab','get'):"").($this->$primaryKey?"&".$primaryKey."=".$this->$primaryKey:""))?>">
		<input type="hidden" name="table" value="<?php print esc_attr($this->table)?>"/>
		<input type="hidden" name="<?php print esc_attr($primaryKey)?>" value="<?php print esc_attr($this->$primaryKey?$this->$primaryKey:"new")?>"/>
        <table>
            <tr><td align="right">Title</td><td><input style="width: 300px" type="text" name="Title" value="<?php print esc_attr($this->Title)?>"></td></tr>
            <?php 
            if (Quickbooks::is_setup()){                
                $qb=new QuickBooks();
                $items=$qb->item_list();               
                ?>
                <tr><td align="right">Quickbooks Item</td><td><select name="QBItemId"><option value="0">[--None--]</option><?php               
                foreach($items as $item){
                    print '<option value="'.$item->Id.'"'.($item->Id==$this->QBItemId?" selected":"").'>'.$item->FullyQualifiedName.'</option>';
                }
                ?></select></td></tr>
            <?php 
            }else{ 
                ?><input type="hidden" name="QBItemId" value="<?php print esc_attr($this->QBItemId)?>"/><?php  
            }                   
            
            ?>
            <tr><td colspan="2">
                <button type="submit" class="primary" name="Function" value="Save">Save</button>
                <button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate="">Cancel</button>
                <?php if ($this->$primaryKey && $this->donor_count()==0){ ?>
                <button type="submit" name="Function" value="Delete">Delete</button>
                <?php }?>
                </td></tr>
		    </table>
            <?php 
            if ($this->donor_count()>0){                
                ?><div>Donors designated this type: <?php $this->donor_count()?></div>
                Merge To: <select name="MergeTo"><option value="0">[--None--]</option><?php
             $results = $wpdb->get_results("SELECT * FROM ".self::get_table_name()." WHERE TypeId<>'".$this->TypeId."' Order BY Title");
             foreach($results as $r){
                ?><option value="<?php print esc_attr($r->TypeId)?>"<?php print ($r->TypeId==$this->TypeId?" selected":"")?>><?php
                print $r->Title." (".$r->TypeId.")";?></option><?php
             }?></select> <button type="submit" name="Function" value="DonorTypeMergeTo">Merge</button>
            <?php }?>           
		</form><?php

    }
    public function donor_count(){
        $wpdb=self::db();   
        if (!$this->donor_count){ 
            $results = $wpdb->get_results("Select COUNT(*) as C FROM ".Donor::get_table_name()." Where TypeId=".$this->TypeId);
            $this->donor_count=$results[0]->C?$results[0]->C:0;
        }
        return $this->donor_count;
    }

    public function view(){ 
		$this->var_view();	
	}

    static public function list(){                
        $SQL= "SELECT *,(Select COUNT(*) FROM ".Donor::get_table_name()." Where TypeId=C.TypeId) as donor_count FROM ".self::get_table_name()." C Order BY Title";       
        $results = self::db()->get_results($SQL);        
        ?>
        <h2>Donor Types</h2>
        <div><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get'))?>&TypeId=new&edit=t">Add Donor Type</a>
        <table border="1"><tr><th>Id</th><th>Title</th><?php if (Quickbooks::is_setup()) print "<th>QuickBook Item</th>"; ?><th>Total</th></tr><?php
        foreach ( $results as $r){
            ?><tr>
                <td><a href="<?php print esc_url('?page='.self::input('page','get').'&tab='.self::input('tab','get').'&TypeId='.$r->TypeId)?>&edit=t"><?php print esc_html($r->TypeId)?></a></td>
                <td><?php print esc_html($r->Title)?></td>
                <?php if (Quickbooks::is_setup()) print  "<td>".$r->QBItemId."</td>";?>
                <td><?php print esc_html($r->donor_count)?></td>
            </tr>
            <?php
            self::show_children($r->TypeId,$parent,$level+1);
        }
        ?></table>	
        <?php
    }

    static function show_children($parentId,$parent,$level=0){
        if (!$parent[$parentId]) return;
        
    }

    static function show_options($parentId,$parent,$level=0,$selected=""){
        if (!$parent[$parentId]) return;
        foreach ($parent[$parentId] as $r){
            $return.='<option value="'.$r->TypeId.'"'.(in_array($r->TypeId,$selected)?' selected':"").'>';
            for($i=0;$i<$level;$i++){
                $return.="--";
            }
            $return.=$r->Title." (x".$r->donor_count.")</option>";
            $return.=self::show_options($r->TypeId,$parent,$level+1,$selected);
        }
        return $return;
    }

    static public function select($settings=[]){
        $return='<select name="'.($settings['Name']?$settings['Name']:"TypeId").'"';
        if ($settings["Multiple"]) $return.=" multiple";
        $return.='><option></option>';
        $SQL= "SELECT *,(Select COUNT(*) FROM ".Donor::get_table_name()." Where TypeId=C.TypeId) as donor_count FROM ".self::get_table_name()." C Order BY Title";       
        $results = self::db()->get_results($SQL);
        foreach ($results as $r){ 
            $parent[$r->ParentId?$r->ParentId:0][]=$r;
        }
        if (!isset($settings['selected'])) $selected=self::input($settings['Name']?$settings['Name']:"TypeId");
        else $selected=$settings['selected'];

        if (!is_array($selected)) $selected=array($selected);        
        $return.=self::show_options(0,$parent,0,$selected);
        $return.="</select>";
        return $return;
    }

    static public function list_array(){     
        $results = self::db()->get_results("SELECT * FROM ".self::get_table_name()." C Order BY Title");
        foreach ($results as $r){ 
            $return[$r->TypeId]=$r->Title;
        }
        return $return;
    }
   
    static public function create_table(){
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php'); 
    	$wpdb=self::db();  
        //$charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `".self::get_table_name()."` ( 
                `TypeId` INT NOT NULL AUTO_INCREMENT , 
                `Title` VARCHAR(60) NOT NULL , 
                `QBItemId` INT NOT NULL ,
                `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, 
                PRIMARY KEY (`TypeId`)
            )";         
        
                 
        dbDelta( $sql );
    }
}