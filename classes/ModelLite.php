<?php
/*****
Model Lite Class to make saves/inserts/updates/forms a tad easier to develop

Requires the following constants as seen in this example:	
	protected $table = 'Donor';
	protected $primaryKey = 'DonorId';
	### Fields that can be passed 
    protected $fillable = ["Name","Name2","Email","Phone","Address1","Address2","City","Region","PostalCode","Country"];	    
	### Default Values
	protected $attributes = [        
        'Country' => 'USA',
	];
	public $incrementing = true;
	const CREATED_AT = 'CreatedAt';
	const UPDATED_AT = 'UpdatedAt'; 
*****/

class ModelLite{
	public function __construct($attributes = []){
        $this->fill((array)$attributes);
	}
	
	public function fill(array $attributes){
		//self::dump($attributes);
		$fields=$this->getViewableFields();		
		
		if (is_array($attributes)){ ### flip array to object
			$attributes=(object)$attributes;
		}

		if (is_array($fields)){
			foreach ($fields as $field){
				//I'm not positive why we have to strip slashes... but it fixes the issue.
				$this->$field=stripslashes(isset($attributes->$field)?$attributes->$field:$this->attributes[$field]);
			}
		}	
	}

	public function getViewableFields(){
		$fields=$this->fillable;
		$primaryKey=$this->primaryKey;
		
		### Add Primary Key to results
		if (is_array($primaryKey)){
			$fields=array_merge($primaryKey,$fields);
		}else{
			$fields=array_merge(array($primaryKey),$fields);
		}
		return $fields;
	}

	public function getTable(){
        global $wpdb;
        return $wpdb->prefix.strtolower(($this->table ?? class_basename($this)));
	}
	
	static public function getTableName(){	
        return self::s()->getTable();
    }
	static public function getFillable(){
		return self::s()->fillable;
	}

	public function save(){ //both insert and update routine. Creates new if no ReworkId passed in.
		global $wpdb;
		$wpdb->show_errors();
		$keyField=$this->primaryKey;
		foreach ($this->fillable as $field){
			if ($field!=$keyField){
				$data[$field]=$this->$field;
			}
		}		
		if (defined (static::UPDATED_AT)){
			$data[static::UPDATED_AT]= time();
		}

		if ($this->$keyField>0){
			$wpdb->update($this->getTable(),$data,array($keyField=>$this->$keyField));
		}else{
			if (defined (static::CREATED_AT) && !$data[static::CREATED_AT]){
				$data[static::CREATED_AT]= time();
			}		 	
			$result=$wpdb->insert($this->getTable(),$data);	
			
			$this->$keyField=$wpdb->insert_id;
			// self::dump($this->getTable());
			// self::dump($data);
			// self::dd(array($keyField=>$this->$keyField));
			$insert=true;
		}
		return $this;

	}

	public function delete(){
		global $wpdb;
		$keyField=$this->primaryKey;
		if ($this->$keyField){
			 $wpdb->delete($this->getTable(),array($keyField=>$this->$keyField));
			 return true;
		}
		return false;
	}

	public function view(){ //single entry->View fields
		//print "varview";
		$this->varView();
		//print_r($this);
	}

	public function varView(){
		?><table border=1><?
		$fields=$this->getViewableFields();
		foreach($fields as $f){			
			if ($this->$f){
				?><tr><td><?php print $f?></td><td><?php print $this->showfield($f)?></td></tr><?
			}
		}
		?></table>
		<?
	}

	static public function getKey($row){
		$key=[];
		foreach(self::s()->duplicateCheck as $field){
			$value=$row->$field;
			switch($field){
				case "Gross":
					$value*=1;
				break;
				default:
					$value=strtoupper($value);
				break;
			}
			$key[]=$value;
		}
		return implode("|",$key);
	}

	static public function replaceIntoList($q){
		### Adds a check to avoid duplicate rows basd on 
		global $wpdb;
		### Cache all existing entries. If DB gets to big, this might use to much memory, and may want to do individual queries.
		$dupFieldsCheck=self::s()->duplicateCheck;		
        $result= $wpdb->get_results("Select ".implode(", ",$dupFieldsCheck).", Count(*) as C FROM ".self::s()->getTable()." Group By  ".implode(", ",$dupFieldsCheck));
        foreach ($result as $row){
			$existingEntries[self::getKey($row)]=$row->C;
		}
		//self::dump($existingEntries);

        if (sizeof($q)>0){		
			$iSQL=[];
			$skipped=0;
            foreach ($q as $row){
				if ($existingEntries[self::getKey($row)]){ //skip entry already exists
					$skipped++;
				}else{
					$items=[];
					foreach ($row->fillable as $f){				
						$items[]="'".($row->$f?addslashes($row->$f):"")."'";
					}
					$iSQL[]="(".implode(", ",$items).")";
				}
            }
			if (sizeof($iSQL)>0){
				$SQL="INSERT INTO ".self::s()->getTable()." (`".implode("`,`",$row->fillable)."`) VALUES ".implode(", ",$iSQL);
				//print self::getKey($row)."::".$SQL."<hr>";
				$result= $wpdb->query($SQL);
			}
			return array('inserted'=>sizeof($iSQL),'skipped'=>$skipped,'insertResult'=>$result);
        }
    }

	public static function s(){
		// A way to retreive protectic variables from a static call.
		// example: self::s()->getTable()
        $instance = new static;
        return $instance;
	}
	
	static public function dump($obj){
		print "<pre>"; print_r($obj); print "</pre>";
	}

	public static function dd($obj){
		self::dump($obj);
		exit();
	}

	public static function getById($id,$settings=false){
		global $wpdb;
		$where[]=self::s()->primaryKey."='".$id."'";
		$SQL="SELECT * FROM ".self::s()->getTable()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"");			
		return  new static((array)$wpdb->get_row($SQL));
	}
	
	public static function get($where=array(),$orderby="",$settings=false){
		global $wpdb;
		$SQL="SELECT * FROM ".self::s()->getTable()." ".(sizeof($where)>0?" WHERE ".implode(" AND ",$where):"").($orderby?" ORDER BY ".$orderby:"");
		//print $SQL;
		$all=$wpdb->get_results($SQL);
		foreach($all as $r){
			$obj=new static($r,$settings);
			if ($settings['key']){
				$primaryField=$obj->primaryKey;
				$return[$r->$primaryField]=$obj; //untested, but conceptial.
			}else{
				$return[]=$obj;
			}
		}
		
		return $return;
	}

	public function keyFlat(){
		//return key value. If key is mutiple fields, then it seperates by pipes "|". 
		$keyField=$this->primaryKey;
		//dump($keyField);
		if (is_array($keyField)){
			$key="";
			foreach($keyField as $kf){
				$key.=($key?"|":"").$this->$kf;
			}
		}else $key=$this->$keyField;
		return $key;
	}

	public static function showResults($results,$noResultMessage="No Results Found."){		
		$fields=self::s()->getViewableFields();	
		if (!$results || sizeof($results)==0){
			return "<div><em>".$noResultMessage."</em></div>"; 
		}
		ob_start(); 
		?><table border=1><?
			$i=0;
			foreach($results as $r){
				if ($i==0){
					?><tr><?
					foreach ($fields as $field){?><th><?php print $field?></th><? }
					?></tr><?
				}
				?><tr><?
				foreach ($fields as $field){?><td><?php print $r->showfield($field)?></td><? }
				?></tr><?
				$i++;
			}
		?></table><?
		return ob_get_clean(); 
	}
	
	public function showfield($fieldName,$idShow=true){
		$v=$this->$fieldName;
		switch($fieldName){
			case "Gross":
			case "Fee":
			case "Net":
				return $v?number_format($v,2):"";
			break;
			case "DonationId":
				return '<a href="?page='.$_GET['page'].'&DonationId='.$v.'">'.$v.'</a>';
			break;
			case "MergedId":
				return '<a href="?page='.$_GET['page'].'&DonorId='.$v.'">'.$v.'</a>';
			case "DonorId":
				return '<a href="?page='.$_GET['page'].'&DonorId='.$v.'">'.$v.'</a> <a href="?page='.$_GET['page'].'&DonorId='.$v.'&f=AddDonation">+ Donation</a>';
			break;
			case "FromEmailAddress":
			case "ToEmailAddress":
			case "Email":
				return $this->displayEmail($fieldName); //'<a href="mailto:'.$v.'?Subject=">'.$v.'</a>';
			break;
			case "CategoryId":
				$label="";
				if ($this->table=='DonationCategory') {}
				else{
					global $cache_ModelLite_showfield;
					if (isset($cache_ModelLite_showfield[$fieldName][$v])){
						$label=$cache_ModelLite_showfield[$fieldName][$v];
					}elseif($v){
						$dCat=DonationCategory::getById($v); //need to cache this..
						if ($dCat){
							$cache_ModelLite_showfield[$fieldName][$v]=$dCat->Category;
							$label=$dCat->Category;
						} 
					}
					
										
				}
				return ($idShow?'<a href="?page='.$_GET['page'].'&CategoryId='.$v.'">'.$v.'</a> - ':"").$label;
			break;
			default:
				if ($this->tinyIntDescriptions[$fieldName]){
					$label=self::s()->tinyIntDescriptions[$fieldName][$v];								
					if ($label){	
						return $v." - ".$label;
					}elseif ($fieldName=="Type" && $this->TypeOther){
							return ($idShow?$v." - ":"").$this->TypeOther;						
					}
				}
				return $v;				
			break;
		}
	}

	static public function getTinyDescription($fieldName,$v){
		return self::s()->tinyIntDescriptions[$fieldName][$v];
	}

	public function displayKey(){
		$primaryKey=$this->primaryKey;
		return '<a href="?page='.$_GET['page'].'&'.$primaryKey.'='.$this->$primaryKey.'">'.$this->$primaryKey."</a> ";
	}

	public function displayEmail($fieldName='Email'){
		if (trim($this->$fieldName)){
			$emails=explode(";",$this->$fieldName);
			$count=0;
			foreach($emails as $email){
				$email=trim($email);
				if ($count>0) $return.="; ";
				if ($this->EmailStatus<0 || !filter_var($email, FILTER_VALIDATE_EMAIL)){
					$return.= '<span style="text-decoration:line-through; color:red;">'.$email.'</span>';
				}else{
					$return.= '<a href="mailto:'.$email.'">'.$email.'</a>';
				}
				$count++;
			}	
			return $return;		
		}		
	}

	
	public function editForm(){
		$primaryKey=$this->primaryKey;
		?><form method="post" action="?page=<?php print $_GET['page']?>&<?php print $primaryKey?>=<?php print $this->$primaryKey?>">
		<input type="hidden" name="table" value="<?php print $this->table?>"/>
		<input type="hidden" name="<?php print $primaryKey?>" value="<?php print $this->$primaryKey?>"/>
	
		<table><?
		foreach($this->fillable as $field){
			$type="text";
            if (strpos($field,"Date")>-1){
                $type="date";
			}
			if ($field=="Date") $this->Date=substr($this->$field,0,10); //$type="datetime-local";
			?><tr><td align=right><?php print $field?></td><td><?
			if ($this->tinyIntDescriptions[$field]){
				?><select name="<?php print $field?>"><?
					foreach($this->tinyIntDescriptions[$field] as $key=>$label){
						?><option value="<?php print $key?>"<?php print $key==$this->$field?" selected":""?>><?php print $key." - ".$label?></option><?
					}
					if (!$this->tinyIntDescriptions[$field][$this->$field]){
						?><option value="<?php print $this->$field?>" selected><?php print $this->$field." - Not Set"?></option><?
					}
					?></select><?
			}else{
				?><input type="<?php print $type?>" name="<?php print $field?>" value="<?php print $this->$field?>"/><?
			}	
		
			
			?></td></tr><?
			}
		?><tr><tr><td colspan=2>
		<button type="submit" class="primary" name="Function" value="Save">Save</button>
		<button type="submit" name="Function" class="secondary" value="Cancel" formnovalidate>Cancel</button>
        <?php 
        if ($this->$primaryKey){
            ?> <button type="submit" name="Function" value="Delete">Delete</button><?
        }
        ?>
		</td></tr>
		</table>
		</form><?
	}

	static public function DisplayNotice($html){
		print "<div class=\"notice notice-success is-dismissible\">".$html."</div>";
	}

	static public function DisplayWarning($html){
		print "<div class=\"notice notice-warning is-dismissible\">".$html."</div>";
	}

	static public function DisplayError($html){
		print "<div class=\"notice notice-error is-dismissible\">".$html."</div>";
	}


	// public static function compare($old, $new){
	// 	if (sizeof($old)==0){
	// 		return false;
	// 	}
	// 	foreach (static::fields as $field){
	// 		if ($old->$field!=$new->$field){
	// 			$was[$field]=$old->$field;
	// 		}
	// 	}
	// 	return $was;
	// }

}
?>
