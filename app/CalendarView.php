<?php
namespace SwimlogStandalone;

/** Read-only adapter; the installed plugin remains the data/calculation authority. */
final class CalendarView {
    public static function load($owner, $query) {
        if (!Auth::requireCapability($owner) || Auth::cookieUser() !== $owner) return new \WP_Error('calendar_forbidden', 'Calendar access denied.');
        if (!defined('SWIMLOG_EVALUATION_DIR') || !defined('SWIMLOG_EVALUATION_DB_VERSION')) return new \WP_Error('calendar_plugin', 'Activate Chris’s Swim Training Progress and Evaluation to view your calendar.');
        if (version_compare((string)get_option('swimlog_db_version', '0'), SWIMLOG_EVALUATION_DB_VERSION, '<')) return new \WP_Error('calendar_schema', 'The Swim Log installation needs its normal administrator update before this calendar can be opened.');
        require_once SWIMLOG_EVALUATION_DIR . 'includes/class-calendar.php';
        $today = new \DateTimeImmutable('today', wp_timezone());
        $value = $query['date'] ?? $today->format('Y-m-d');
        if (!is_string($value) || !preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/D', $value)) return new \WP_Error('calendar_date', 'Choose a valid calendar date between 1900 and 2199.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        if (!$date || $date->format('Y-m-d') !== $value) return new \WP_Error('calendar_date', 'Choose a valid calendar date.');
        $view = $query['view'] ?? 'month';
        if (!is_string($view) || !in_array($view, array('month', 'week'), true)) return new \WP_Error('calendar_view', 'Choose Month or Week.');
        $first = \SwimLogEvaluation\Calendar::first_day_for_user($owner);
        $offset = array('sunday'=>0, 'monday'=>1, 'saturday'=>6)[$first];
        $anchor = $view === 'month' ? $date->modify('first day of this month') : $date;
        $start = $anchor->modify('-' . (((int)$anchor->format('w') - $offset + 7) % 7) . ' days');
        $last = $view === 'month' ? $date->modify('last day of this month') : $start->modify('+6 days');
        $end = $last->modify('+' . ((6 - ((int)$last->format('w') - $offset + 7) % 7)) . ' days');
        $includeLand = class_exists('SwimLogEvaluation\\Land_Activity') && \SwimLogEvaluation\Land_Activity::authorized($owner);
        $data = \SwimLogEvaluation\Calendar::for_user($owner, $start->format('Y-m-d'), $end->format('Y-m-d'), $includeLand);
        if (is_wp_error($data)) return $data;
        // A confirmed match remains visible when plan and swim lie in different views.
        // Resolve only this owner's explicit links; never infer from dates or distances.
        $matched=array();
        if(!empty($data['workouts'])) {
            foreach(\SwimLogEvaluation\Planned_Workout::all_for_user($owner)as$plan)if(!empty($plan->completed_workout_id))$matched[(int)$plan->completed_workout_id]=true;
            foreach($data['workouts']as&$row)if(in_array($row['workout_type'],array('pool','open_water'),true)&&isset($matched[(int)$row['id']]))$row['matched_to_plan']=true;
            unset($row);
        }
        $days = array();
        for ($cursor=$start; $cursor <= $end; $cursor=$cursor->modify('+1 day')) $days[$cursor->format('Y-m-d')] = array('date'=>$cursor, 'items'=>array());
        foreach (array('workouts','planned_workouts','events') as $kind) {
            foreach ($data[$kind] ?? array() as $row) {
                $key = $kind === 'workouts' ? substr($row['workout_start'],0,10) : ($kind === 'events' ? $row['event_date'] : $row['planned_date']);
                if (isset($days[$key])) $days[$key]['items'][] = self::item($row, $kind, $owner);
            }
        }
        require_once __DIR__.'/CalendarMeasurements.php';
        $measurements=CalendarMeasurements::load($owner,$start->format('Y-m-d'),$end->format('Y-m-d'));$measurementError=null;
        if(is_wp_error($measurements))$measurementError=$measurements->get_error_message();
        else foreach($measurements as$key=>$measurement)if(isset($days[$key]))$days[$key]['items'][]=array('type'=>'measurement','measurement'=>$measurement);
        $previous = $view === 'month' ? $date->modify('first day of previous month') : $date->modify('-7 days');
        $next = $view === 'month' ? $date->modify('first day of next month') : $date->modify('+7 days');
        return array('measurement_error'=>$measurementError,'days'=>$days, 'weeks'=>$data['weeks'], 'date'=>$date, 'view'=>$view, 'today'=>$today, 'previous'=>$previous, 'next'=>$next, 'start'=>$start, 'end'=>$end);
    }
    public static function url($date, $view) { return '/?' . http_build_query(array('date'=>$date->format('Y-m-d'), 'view'=>$view)); }
    public static function distance($value, $unit='m') { return $value === null ? 'Distance unavailable' : number_format_i18n((float)$value, (float)$value == floor((float)$value) ? 0 : 1) . ' ' . $unit; }
    private static function item($row, $kind, $owner) {
        $labels = array('pool'=>'Pool swim','open_water'=>'Open-water swim','running'=>'Run','walking'=>'Walk');
        if ($kind === 'events') return array('id'=>(int)$row['id'],'label'=>$row['event_name'], 'type'=>'event', 'distance'=>self::distance($row['distance_value'], $row['course_unit'] ?: 'm'), 'time'=>$row['event_time'], 'location'=>$row['location'], 'pb'=>false, 'matched'=>false, 'status'=>'Event', 'instructions'=>'');
        $plan = $kind === 'planned_workouts';
        $type = $row['workout_type'];
        if ($plan) $distance=self::distance($row['planned_distance_m']);
        elseif (in_array($type,array('running','walking'),true)) {
            require_once __DIR__.'/UnitPreferences.php';
            $distance=UnitPreferences::landDistance($row['total_distance_m']??null,$type,$owner,$row['original_distance']??null,$row['original_distance_unit']??'m');
        } else {
            // Swim records retain their recorded meter/yard course; no preference conversion is applied.
            $distance=isset($row['original_distance'])&&!empty($row['original_distance_unit'])?self::distance($row['original_distance'],$row['original_distance_unit']):self::distance($row['total_distance_m']);
        }
        $duration = $plan ? $row['planned_duration_ms'] : $row['elapsed_time_ms'];
        return array('id'=>(int)$row['id'],'recorded'=>!$plan,'move_candidate'=>$plan&&($row['status']??'')==='planned'&&empty($row['completed_workout_id']),'label'=>$plan ? ($row['title'] ?: 'Planned swim') : ($labels[$type] ?? 'Workout'), 'type'=>$plan ? 'plan' : $type, 'distance'=>$distance, 'time'=>$duration !== null ? \SwimLogEvaluation\Workout::format_duration($duration) : null, 'location'=>$plan ? null : $row['location'], 'pb'=>!empty($row['has_personal_best']), 'matched'=>!empty($row['matched_to_plan']), 'status'=>$plan ? ucfirst($row['status']) : 'Recorded', 'instructions'=>$plan ? $row['workout_instructions'] : '');
    }
}
