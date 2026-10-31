<?php
use SwimlogStandalone\Sharing;
use SwimlogStandalone\PublicWorkout;
require_once __DIR__.'/PublicWorkout.php';
// This route has no identity-derived owner model, management dispatch or public cache.
header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, noarchive');
if(!in_array($method,array('GET','HEAD'),true)){http_response_code(405);header('Allow: GET, HEAD');exit('Read-only page.');}
if(count($_GET)!==1){http_response_code(404);exit('Workout not found.');}
$public=Sharing::publicWorkout($_GET['public_workout']);
if(is_wp_error($public)){http_response_code(404);exit('Workout not found.');}
header('Content-Type: text/html; charset=UTF-8');
if($method!=='HEAD')echo PublicWorkout::render($public,Sharing::url($config['app_origin'],$public['id']));
exit;
