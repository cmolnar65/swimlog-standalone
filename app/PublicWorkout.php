<?php
namespace SwimlogStandalone;
require_once __DIR__.'/Sharing.php';
/** Presentation of the canonical DTO only. Never reads Workout or private trackpoints. */
final class PublicWorkout {
    public static function number($value,$suffix=''){return is_numeric($value)?number_format_i18n((float)$value,2).$suffix:'—';}
    public static function duration($ms){
        if(!is_numeric($ms)||$ms<0)return '—';
        $ms=(int)$ms;$seconds=(int)floor($ms/1000);return (int)floor($seconds/60).':'.str_pad((string)($seconds%60),2,'0',STR_PAD_LEFT).sprintf('.%03d',$ms%1000);
    }
    public static function geometry($route){
        if(!is_array($route)||!isset($route['segments'])||!is_array($route['segments']))return '';
        $segments=array();$all=array();
        foreach($route['segments']as$segment){$points=array();foreach($segment as$p){
            if(!is_array($p)||!is_numeric($p['latitude']??null)||!is_numeric($p['longitude']??null))continue;
            $lat=(float)$p['latitude'];$lon=(float)$p['longitude'];if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180)continue;
            $points[]=array($lat,$lon);$all[]=array($lat,$lon);
        }if($points)$segments[]=$points;}
        if(!$all)return '';
        $lat=array_column($all,0);$lon=array_column($all,1);$minLat=min($lat);$minLon=min($lon);$dx=max($lon)-$minLon;$dy=max($lat)-$minLat;$scale=560/max($dx,$dy,0.000001);
        $svg='<svg viewBox="0 0 600 600" role="img" aria-label="Public route segments" class="public-route">';
        foreach($segments as$segment){$pts=array();foreach($segment as$p)$pts[]=sprintf('%.3F,%.3F',20+($p[1]-$minLon)*$scale,580-($p[0]-$minLat)*$scale);
            // Independent paths preserve all gaps; there are no map requests or sensor attributes.
            if(count($pts)>1)$svg.='<polyline points="'.esc_attr(implode(' ',$pts)).'" fill="none" stroke="#4264a5" stroke-width="4"/>';
            else{$xy=explode(',',$pts[0]);$svg.='<circle cx="'.$xy[0].'" cy="'.$xy[1].'" r="4" fill="#4264a5"/>';}
        }
        return $svg.'</svg>';
    }
    public static function render($public,$url){ob_start();require __DIR__.'/public-workout.php';return ob_get_clean();}
}
