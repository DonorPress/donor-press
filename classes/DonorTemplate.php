<?php

class DonorTemplate extends ModelLite {

    protected $table = 'posts';
	protected $primaryKey = 'ID';
	### Fields that can be passed 
    protected $fillable = ["ID","post_type","post_content","post_title","post_name","post_date","post_author"];	    
	### Default Values
	protected $attributes = [        
        'post_type' => 'donortemplate',
        'post_status'=> 'private',
        'comment_status'=>'closed',
        'ping_status'=>'closed'
    ];    
    
	const CREATED_AT = 'post_date';
	const UPDATED_AT = 'post_modified'; 

    static public function get_by_name($name){
        $temp=self::get(array("post_name='".$name."'","post_type='donortemplate'"));
        return $temp[0];
    }

    public function edit(){
        $primaryKey=$this->primaryKey;
        if ($this->ID){            
        ?><h2>Editing Donor Template #<?php print $this->ID?></h2>
        <?php 
        }else{
            ?><h2>New Donor Template <?php print $_GET['CopyDonorTemplateId']?" Copy of #".$_GET['CopyDonorTemplateId']:""?></h2>
        <?php
        }?>
        <form method="post" action="?page=<?php print $_GET['page']?>&<?php print $primaryKey?>=<?php print $this->$primaryKey?>&tab=<?php print $_GET['tab']?>">
		<input type="hidden" name="table" value="<?php print $this->table?>"/>
		<input type="hidden" name="<?php print $primaryKey?>" value="<?php print $this->$primaryKey?>"/>
        <input type="hidden" name="post_type" value="donortemplate"/>
        <input type="hidden" name="post_author" value="<?php print $this->post_author?$this->post_author:get_current_user_id()?>"/>
        <input type="hidden" name="post_date" value="<?php print $this->post_date?$this->post_date:date("Y-m-d H:i:s")?>"/>

        
        <table>
            <tr><td align="right"><strong>Template Title</strong></td><td><input style="width: 300px" type="text" name="post_name" value="<?php print $this->post_name?>"> <em>Example: donor-default</td></tr>
            <tr><td align="right"><strong>Subject</strong></td><td><input style="width: 600px" type="text" name="post_title" value="<?php print $this->post_title?>"> <em>Appears in the subject of an e-mail, but does not print on .pdf letter export</td></tr>
            <tr><td  colspan="2"><div><strong>Message:</strong></div><?php            
            wp_editor($this->post_content, 'post_content',array("media_buttons" => false,"wpautop"=>false));
            ?></td></tr>
            
            <tr><td colspan="2">
                <button type="submit" class="primary" name="Function" value="Save">Save</button>
                <button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate="">Cancel</button>
                <?php if ($this->$primaryKey){ ?>
                <button type="submit" name="Function" value="Delete">Delete</button>
                <?php }?>
                </td></tr>
		    </table>
        <?php        
    }

    static public function request_handler(){        
        $wpdb=self::db();  
        if ($_POST['Function'] == 'Save' && $_POST['table']=="posts" && $_POST['post_type'] == 'donortemplate'){
            
            $template=new self($_POST);
            $template->post_modified=time();
            if ($template->save()){
                self::display_notice("Template #".$template->ID." ".$template->post_name." saved.");
               // $template->full_view();
                //return true;
            }
     

        }elseif ($_GET['DonorTemplateId']){
            $t=self::get_by_id($_GET['DonorTemplateId']);
            if ($_GET['edit']=="t"){  
                //self::dump($t) ;                     
                $t->edit();
            }else{
                $t->view();
            }           
            return true;
        }elseif($_GET['CopyDonorTemplateId']){
            $t=self::get_by_id($_GET['CopyDonorTemplateId']);
            $t->ID='';
            $t->post_name.='-copy'.$_GET['CopyDonorTemplateId'];
            $t->edit();           
            return true;
        }    
    }
  
    static function list(){       
        $wpdb=self::db();          
        $SQL="SELECT * FROM ".self::get_table_name()." WHERE post_type='donortemplate' AND post_parent=0 Order BY post_name,post_title";
        $results = $wpdb->get_results($SQL);        
        ?><h2>Template List</h2>
        <table class="dp"><tr><th>Template</th><th>Subject</th><th>Body</th><th></th></tr>
        <?php
        foreach ($results as $r){ 
            ?><tr><td><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&DonorTemplateId=<?php print $r->ID?>&edit=t"><?php print $r->post_name?></a></td>
                <td><?php print $r->post_title;?></td>
                <td><?php print substr(strip_tags($r->post_content,array('<p>','<br>')),0,160)?>...</td>
                <td><a href="?page=<?php print $_GET['page']?>&tab=<?php print $_GET['tab']?>&CopyDonorTemplateId=<?php print $r->ID?>&edit=t">Copy</a></td>
            </tr><?php
        }
        ?></table><?php
    }
}