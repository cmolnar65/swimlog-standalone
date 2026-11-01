<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Manual_Workout as Manual;
use SwimLogEvaluation\Importer;
/** Update an existing authoritative manual document, never convert imported records. */
final class ManualEditor {
    private static function seconds($ms){return $ms===null?'':rtrim(rtrim(number_format($ms/1000,3,'.',''),'0'),'.');}
    public static function open($owner,$id){
        require_once __DIR__.'/WorkoutEditor.php';$item=WorkoutEditor::load($owner,$id);if(is_wp_error($item))return $item;
        $w=$item['workout'];if($w->source_type!=='manual'||$w->workout_type!=='pool')return WorkoutEntry::error('Manual workout not found.',404);
        $doc=Manual::get_document((int)$id,$owner);if(is_wp_error($doc))return $doc;
        if(count($doc['segments'])>100)return WorkoutEntry::error('This manual document exceeds the standalone editor limit. Use WordPress to edit it.',422);
        $source=$doc['workout'];$sets=array();
        foreach($doc['segments']as$s){$row=WorkoutEntry::blankSet();foreach(array('type'=>'type','repetitions'=>'repetitions','distance'=>'distance_per_rep','stroke'=>'stroke','timing'=>'timing_type','notes'=>'notes')as$key=>$from)$row[$key]=$s[$from]===null?'':(string)$s[$from];foreach(array('set_time'=>'set_time_ms','interval'=>'interval_ms','rest'=>'rest_time_ms')as$key=>$from)$row[$key]=self::seconds($s[$from]);$row['rep_times']=implode(', ',array_map(array(__CLASS__,'seconds'),$s['rep_times_ms']));$sets[]=$row;}
        return array('id'=>(string)$id,'revision'=>hash_hmac('sha256',serialize(array($item['revision'],$doc)),wp_salt('nonce')),'token'=>'','values'=>array('workout_date'=>substr($w->workout_start,0,10),'workout_time'=>substr($w->workout_start,11,5),'location_id'=>(string)(int)$w->location_id,'pool_length'=>(string)$source['original_pool_length'],'course_unit'=>$source['pool_length_unit'],'total_distance'=>$source['original_distance']===null?'':(string)$source['original_distance'],'duration'=>self::seconds($source['elapsed_time_ms']),'notes'=>(string)$w->notes),'sets'=>$sets?:array(WorkoutEntry::blankSet()),'error'=>null,'start'=>$w->workout_start);
    }
    public static function submit($owner,$input,$files,$origin,$appOrigin){
        $id=$input['manual_id']??null;$f=self::open($owner,$id);if(is_wp_error($f))return $f;
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_manual_edit_'.$id))return WorkoutEntry::error('This manual edit expired or is invalid. Reload the workout.',403);
        if($files||array_diff(array_keys($input),array_merge(WorkoutEntry::FIELDS,array('manual_id','nonce','revision','sets','payload_complete'))))return WorkoutEntry::error('Unsupported manual workout field.');
        if(($input['payload_complete']??null)!=='1')return WorkoutEntry::error('The manual form was truncated. Nothing was saved.');
        $lock=Importer::owner_lock($owner);if(is_wp_error($lock))return WorkoutEntry::error('Another workout operation is running.',409);
        try{
            $fresh=self::open($owner,$id);if(is_wp_error($fresh))return $fresh;
            if(!is_string($input['revision']??null)||!hash_equals($fresh['revision'],$input['revision']))return WorkoutEntry::error('This workout changed. Reload and review it before saving.',409);
            $v=array_intersect_key($input,array_flip(WorkoutEntry::FIELDS));$sets=$input['sets']??array();$doc=WorkoutEntry::document($owner,$v,$sets);
            if(!is_wp_error($doc)&&$v['workout_date']===$fresh['values']['workout_date']&&$v['workout_time']===$fresh['values']['workout_time'])$doc['workout']['workout_start']=$fresh['start'];
            $out=is_wp_error($doc)?$doc:Manual::save($owner,$doc,(int)$id);
            if(is_wp_error($out)){$fresh['values']=$v;$fresh['sets']=is_array($sets)?$sets:array();$fresh['error']=$out;return array('form'=>$fresh);}
            return array('url'=>'/?workout='.(int)$id.'&entry_saved=manual');
        }finally{Importer::owner_lock($owner,true);}
    }
}
