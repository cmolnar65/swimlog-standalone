<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Training_Plan_PDF as PDF;
use SwimLogEvaluation\Training_Plan_Parser as Parser;
use SwimLogEvaluation\Training_Plan_Bulk_Import as Bulk;
use SwimLogEvaluation\Importer;
/** Private preview and explicit review transport; the plugin owns parsing and persistence. */
final class TrainingPlanImport {
    public static function ready($owner){
        require_once __DIR__.'/StructuredWorkouts.php';require_once __DIR__.'/WorkoutEntry.php';
        $r=StructuredWorkouts::ready($owner);if(is_wp_error($r))return $r;
        foreach(array('importer','training-plan-pdf','training-plan-parser','training-plan-review','training-plan-bulk-import')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return WorkoutEntry::error('Training plan import requires an updated plugin.',503);require_once $path;}
        return true;
    }
    private static function key($owner,$token){return 'standalone_pdf_'.hash('sha256',$owner.'|'.wp_get_session_token().'|'.$token);}
    public static function preview($owner,$token){
        $r=self::ready($owner);if(is_wp_error($r))return $r;
        if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return WorkoutEntry::error('PDF preview not found or expired.',404);
        $data=get_transient(self::key($owner,$token));if(!is_array($data))return WorkoutEntry::error('PDF preview not found or expired. Upload it again.',404);
        return $data;
    }
    public static function submit($owner,$op,$input,$files,$origin,$appOrigin){
        $r=self::ready($owner);if(is_wp_error($r))return $r;
        if(!in_array($op,array('upload','import'),true))return WorkoutEntry::error('Unknown PDF operation.',400);
        $token=$op==='upload'?'upload':($input['token']??null);
        if(!is_string($token)||$origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_pdf_'.$op.'_'.$token))return WorkoutEntry::error('This PDF form expired or is invalid. Reload before trying again.',403);
        if($op==='upload'){
            if(array_diff(array_keys($input),array('nonce','acknowledge'))||($input['acknowledge']??null)!=='1'||array_keys($files)!==array('training_pdf'))return WorkoutEntry::error('Choose a PDF and acknowledge the import notice.');
            $out=PDF::extract($files['training_pdf']);if(is_wp_error($out))return $out;
            $parsed=Parser::parse($out['text']);if(is_wp_error($parsed))return $parsed;
            $token=bin2hex(random_bytes(24));$out['parsed']=$parsed;$out['revision']=hash_hmac('sha256',serialize($out),wp_salt('nonce'));
            if(!set_transient(self::key($owner,$token),$out,DAY_IN_SECONDS))return WorkoutEntry::error('The private preview could not be saved.',503);
            return array('url'=>'/?screen=training_pdf&preview='.$token);
        }
        if($files||array_diff(array_keys($input),array('nonce','token','revision','name','swimlog_pdf_selected','reviews','payload_complete'))||($input['payload_complete']??null)!=='1')return WorkoutEntry::error('The PDF review was truncated or invalid. Nothing was imported.');
        if(!is_string($input['name']??null)||strlen($input['name'])>120||!is_array($input['swimlog_pdf_selected']??null)||!is_array($input['reviews']??null))return WorkoutEntry::error('Enter a private plan name and select reviewed workouts.');
        $lock=Importer::owner_lock($owner);if(is_wp_error($lock))return WorkoutEntry::error('Another workout operation is running. Retry shortly.',409);
        try{
            $data=self::preview($owner,$token);if(is_wp_error($data))return $data;
            if(!is_string($input['revision']??null)||!hash_equals($data['revision'],$input['revision']))return WorkoutEntry::error('This PDF preview changed. Reload and review it.',409);
            if(isset($data['done']))return array('url'=>$data['done']);
            $out=Bulk::run($owner,$input['name'],$data['parsed'],$input['swimlog_pdf_selected'],$data['sha256'],$input['reviews']);if(is_wp_error($out))return $out;
            $url='/?screen=structures&pdf_imported='.(int)$out['count'];
            // Remove extracted private text from this preview after successful import.
            set_transient(self::key($owner,$token),array('done'=>$url,'revision'=>$data['revision']),DAY_IN_SECONDS);
            return array('url'=>$url);
        }finally{Importer::owner_lock($owner,true);}
    }
}
