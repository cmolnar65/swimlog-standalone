<?php
use SwimlogStandalone\TrainingPlanImport as TP;
require_once __DIR__.'/TrainingPlanImport.php';
if(!$owner){http_response_code(401);exit('Sign in to import a training plan.');}
$r=TP::ready($owner);if(is_wp_error($r)){http_response_code((int)($r->get_error_data()['status']??503));exit(esc_html($r->get_error_message()));}
$signedIn=true;$screen='training_pdf';$pdfError=null;$pdfPreview=null;$pdfInput=array();$pdfToken=$_GET['preview']??null;
if(in_array($action,array('pdf_upload','pdf_import'),true)){
 if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
 $pdfInput=wp_unslash($_POST);$pdfToken=$pdfInput['token']??null;
 $out=!$_POST&&(int)($_SERVER['CONTENT_LENGTH']??0)>0?new WP_Error('pdf_limit','The request exceeded PHP upload limits.',array('status'=>413)):TP::submit($owner,$action==='pdf_upload'?'upload':'import',$pdfInput,$_FILES,$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
 if(is_wp_error($out)){$pdfError=$out;http_response_code((int)($out->get_error_data()['status']??422));}else{header('Location: '.$config['app_origin'].$out['url'],true,303);exit;}
}elseif($action!==''||!in_array($method,array('GET','HEAD'),true)){http_response_code(404);exit('Not found.');}
if($pdfToken!==null){$pdfPreview=TP::preview($owner,$pdfToken);if(is_wp_error($pdfPreview)){$pdfError=$pdfPreview;$pdfPreview=null;http_response_code((int)($pdfError->get_error_data()['status']??404));}}
require __DIR__.'/home.php';exit;
