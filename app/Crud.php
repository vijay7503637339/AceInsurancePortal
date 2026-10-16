<?php
declare(strict_types=1);
namespace AcePortal;
final class Crud {
 public const ENTITIES = [
 'insureds'=>['label'=>'Insured Customers','table'=>'insureds','fields'=>[
 'legal_name'=>['Legal Name','text',true],'dba_name'=>['DBA Name','text',false],'dot_number'=>['DOT Number','text',true],'email'=>['Email','email',false],'phone'=>['Phone','text',false],'address_line1'=>['Address','text',false],'city'=>['City','text',false],'state'=>['State','text',false],'postal_code'=>['ZIP','text',false],'status'=>['Status','select:active,inactive',true]]],
 'vehicles'=>['label'=>'Vehicles','table'=>'vehicles','fields'=>[
 'insured_id'=>['Insured Customer','insured_select',true],'vin'=>['VIN','text',true],'unit_number'=>['Unit Number','text',false],'vehicle_type'=>['Vehicle Type','text',false],'make'=>['Make','text',false],'model'=>['Model','text',false],'model_year'=>['Model Year','number',false],'value_amount'=>['Value','decimal',false],'status'=>['Status','select:active,inactive',true]]],
 'policies'=>['label'=>'Policies','table'=>'policies','fields'=>[
 'insured_id'=>['Insured Customer','insured_select',true],'policy_number'=>['Policy Number','text',true],'carrier_name'=>['Carrier','text',false],'business_line'=>['Business Line','text',false],'broker_name'=>['Broker','text',false],'finance_company'=>['Finance Company','text',false],'effective_date'=>['Effective Date','date',false],'expiration_date'=>['Expiration Date','date',false],'written_premium'=>['Written Premium','decimal',false],'estimated_fee'=>['Estimated Fee','decimal',false],'estimated_tax'=>['Estimated Tax','decimal',false],'total_premium'=>['Total Premium','decimal',false],'status'=>['Status','select:draft,active,expired,cancelled',true]]],
 'drivers'=>['label'=>'Drivers','table'=>'drivers','fields'=>[
 'insured_id'=>['Insured Customer','insured_select',true],'full_name'=>['Driver Name','text',true],'license_number'=>['License Number','text',false],'license_state'=>['License State','text',false],'date_of_birth'=>['Date of Birth','date',false],'is_excluded'=>['Excluded','select:0,1',true],'status'=>['Status','select:active,inactive',true]]]
 ];
 public static function entity(string $key):array{if(!isset(self::ENTITIES[$key]))throw new InvalidArgumentException('Unknown entity');return self::ENTITIES[$key];}
 public static function rows(string $key):array{$e=self::entity($key);return \db()->query('SELECT * FROM `'.$e['table'].'` ORDER BY id DESC LIMIT 500')->fetchAll();}
 public static function find(string $key,int $id):?array{$e=self::entity($key);$s=\db()->prepare('SELECT * FROM `'.$e['table'].'` WHERE id=:id');$s->execute(['id'=>$id]);return $s->fetch()?:null;}
 public static function save(string $key,array $input,?int $id,int $actorId):int{
  $e=self::entity($key);$data=[];
  foreach($e['fields'] as $f=>$m){
   $v=$input[$f]??null;$v=is_string($v)?trim($v):$v;if($v==='')$v=null;
   if($m[2]&&$v===null)throw new InvalidArgumentException($m[0].' is required.');
   if($m[1]==='email'&&$v!==null&&!filter_var($v,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
   if(in_array($m[1],['number','decimal'],true)&&$v!==null&&!is_numeric($v))throw new InvalidArgumentException($m[0].' must be numeric.');
   if(str_starts_with($m[1],'select:')&&$v!==null&&!in_array((string)$v,explode(',',substr($m[1],7)),true))throw new InvalidArgumentException('Invalid '.$m[0]);
   if($f==='dot_number'&&$v!==null)$v=preg_replace('/\s+/','',(string)$v);
   $data[$f]=$v;
  }
  $data['created_by']=$actorId;$table=$e['table'];
  if($id!==null){unset($data['created_by']);$set=[];foreach(array_keys($data) as $f)$set[]="`$f`=:$f";$data['id']=$id;$s=\db()->prepare('UPDATE `'.$table.'` SET '.implode(',',$set).' WHERE id=:id');$s->execute($data);\audit('update',$table,$id,'Updated '.$e['label'].' record');return $id;}
  $cols=array_keys($data);$quoted=array_map(static fn($c)=>"`$c`",$cols);$params=array_map(static fn($c)=>':'.$c,$cols);
  $s=\db()->prepare('INSERT INTO `'.$table.'` ('.implode(',',$quoted).') VALUES ('.implode(',',$params).')');$s->execute($data);$new=(int)\db()->lastInsertId();\audit('create',$table,$new,'Created '.$e['label'].' record');return $new;
 }
 public static function delete(string $key,int $id):void{$e=self::entity($key);$s=\db()->prepare('DELETE FROM `'.$e['table'].'` WHERE id=:id');$s->execute(['id'=>$id]);\audit('delete',$e['table'],$id,'Deleted '.$e['label'].' record');}
}
