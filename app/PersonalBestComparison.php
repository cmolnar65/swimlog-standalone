<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Age_Group;
use SwimLogEvaluation\Evaluator;
/** Owner-scoped period selection over plugin-evaluated performance records. */
final class PersonalBestComparison {
    const LIMIT=5000;
    public static function error($text,$status=400){return new \WP_Error('pb_comparison',$text,array('status'=>$status));}
    private static function date($value){if(!is_string($value)||!preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/D',$value))return false;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());return $d&&$d->format('Y-m-d')===$value;}
    public static function period($owner,$prefix,$query){
        $year=(int)wp_date('Y')-($prefix==='a'?1:0);$v=array('mode'=>'year','year'=>(string)$year,'month'=>wp_date('Y-m'),'from'=>wp_date('Y-01-01'),'to'=>wp_date('Y-m-d'),'min_age'=>$prefix==='a'?'55':'60','max_age'=>$prefix==='a'?'59':'64');
        foreach($v as$key=>$default)if(array_key_exists($prefix.'_'.$key,$query)){if(!is_string($query[$prefix.'_'.$key]))return self::error('Choose valid period fields.');$v[$key]=$query[$prefix.'_'.$key];}
        if(!in_array($v['mode'],array('year','month','dates','age'),true))return self::error('Choose year, month, dates or an age range.');
        $from='1900-01-01';$to=wp_date('Y-m-d');
        if($v['mode']==='year'){if(!preg_match('/^(19|20|21)\d{2}$/D',$v['year']))return self::error('Enter a year between 1900 and 2199.');$from=$v['year'].'-01-01';$to=$v['year'].'-12-31';$label=$v['year'];}
        elseif($v['mode']==='month'){if(!self::date($v['month'].'-01'))return self::error('Choose a valid calendar month.');$from=$v['month'].'-01';$to=(new \DateTimeImmutable($from,wp_timezone()))->format('Y-m-t');$label=$v['month'];}
        elseif($v['mode']==='dates'){if(!self::date($v['from'])||!self::date($v['to'])||$v['from']>$v['to'])return self::error('Choose a valid inclusive date range.');$from=$v['from'];$to=$v['to'];$label=$from.' to '.$to;}
        else{foreach(array('min_age','max_age')as$key)if(!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$v[$key])||(int)$v[$key]>150)return self::error('Enter whole ages from 0 to 150.');if((int)$v['min_age']>(int)$v['max_age'])return self::error('The youngest age must not exceed the oldest.');if(!Age_Group::for_user($owner))return self::error('Age comparisons need a valid birth date in Settings → Swimmer Configuration.',422);$label='Ages '.$v['min_age'].'–'.$v['max_age'];}
        return array('values'=>$v,'from'=>$from,'to'=>min($to,wp_date('Y-m-d')),'label'=>$label);
    }
    public static function load($owner,array $query){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return self::error('Personal Best comparison access denied.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The installed Swim Log plugin must be ready.',503);
        foreach(array('workout','age-group','evaluator')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return self::error('Personal Best comparison requires an updated plugin.',503);require_once $path;}
        $course=$query['course']??'m';if(!is_string($course)||!in_array($course,array('m','yd'),true))return self::error('Choose meters or yards.');
        $a=self::period($owner,'a',$query);if(is_wp_error($a))return $a;$b=self::period($owner,'b',$query);if(is_wp_error($b))return $b;
        $filters=array('workout_type'=>'pool','course_unit'=>$course,'date_from'=>min($a['from'],$b['from']),'date_to'=>max($a['to'],$b['to']));
        $batch=Workout::query_for_user($owner,$filters,1,500);if($batch['total']>self::LIMIT)return self::error('More than 5,000 pool workouts match. Narrow the periods before comparing; no partial bests were calculated.',413);
        $periods=array($a,$b);$maps=array(array(),array());$count=0;
        for($page=1;$page<=max(1,$batch['pages']);$page++){
            if($page>1)$batch=Workout::query_for_user($owner,$filters,$page,500);
            foreach($batch['rows']as$w){
                $date=substr($w->workout_start,0,10);$matches=array();
                foreach($periods as$i=>$p){$match=$date>=$p['from']&&$date<=$p['to'];if($match&&$p['values']['mode']==='age'){$age=Age_Group::for_user($owner,$date);$match=$age&&$age['age']>=(int)$p['values']['min_age']&&$age['age']<=(int)$p['values']['max_age'];}$matches[$i]=(bool)$match;}
                if(!in_array(true,$matches,true))continue;
                foreach(Workout::performances((int)$w->id,$owner)as$r){if(++$count>100000)return self::error('Too many performance records. Narrow the periods; no partial results were calculated.',413);
                    if($r->course_unit!==$course||!in_array((int)$r->distance_value,Evaluator::DISTANCES,true)||!in_array($r->stroke,array('FR','BR','BACK','FLY','MIXED'),true)||(int)$r->duration_ms<=0)continue;
                    $key=sprintf('%05d|%s',(int)$r->distance_value,$r->stroke);$entry=array('distance'=>(int)$r->distance_value,'stroke'=>$r->stroke,'ms'=>(int)$r->duration_ms,'date'=>$date,'workout_id'=>(int)$w->id,'performance_id'=>(int)$r->id);
                    foreach($matches as$i=>$match)if($match){$old=$maps[$i][$key]??null;if(!$old||array($entry['ms'],$entry['date'],$entry['performance_id'])<array($old['ms'],$old['date'],$old['performance_id']))$maps[$i][$key]=$entry;}
                }
            }
        }
        $keys=array_unique(array_merge(array_keys($maps[0]),array_keys($maps[1])));sort($keys);$rows=array();foreach($keys as$key)$rows[]=array('a'=>$maps[0][$key]??null,'b'=>$maps[1][$key]??null);
        $overlap=max($a['from'],$b['from'])<=min($a['to'],$b['to']);if($a['values']['mode']==='age'&&$b['values']['mode']==='age')$overlap=max((int)$a['values']['min_age'],(int)$b['values']['min_age'])<=min((int)$a['values']['max_age'],(int)$b['values']['max_age']);
        return array('course'=>$course,'periods'=>$periods,'rows'=>$rows,'overlap'=>$overlap);
    }
}
