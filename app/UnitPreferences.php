<?php
namespace SwimlogStandalone;

/** Display-only adapter for the installed plugin's owner-scoped unit preferences. */
final class UnitPreferences {
    private static function service() {
        if (!defined('SWIMLOG_EVALUATION_DIR')) return false;
        $class='SwimLogEvaluation\\Unit_Preferences';
        if (!class_exists($class, false)) {
            $path=SWIMLOG_EVALUATION_DIR.'includes/class-unit-preferences.php';
            if (!is_readable($path)) return false;
            require_once $path;
        }
        return class_exists($class, false);
    }

    /** Land activities use canonical meters as input to the plugin formatter. */
    public static function landDistance($meters,$activity,$owner,$fallbackValue=null,$fallbackUnit='m') {
        if ($meters!==null && $meters!=='' && is_numeric($meters) && self::service() && is_callable(array('SwimLogEvaluation\\Unit_Preferences','distance'))) {
            return \SwimLogEvaluation\Unit_Preferences::distance($meters,$activity,$owner);
        }
        if ($fallbackValue!==null && $fallbackValue!=='' && is_numeric($fallbackValue)) return CalendarView::distance($fallbackValue,$fallbackUnit);
        return CalendarView::distance($meters);
    }

    /** Convert canonical kilograms only for mass fields in private measurements. */
    public static function measurementValue($key,$value,$unit,$owner) {
        if ($unit==='kg' && in_array($key,array('weight','bone_mass','muscle_mass'),true) && self::service() && is_callable(array('SwimLogEvaluation\\Unit_Preferences','weight'))) {
            return \SwimLogEvaluation\Unit_Preferences::weight($value,$owner);
        }
        return $value.' '.($unit==='kg/m2'?'kg/m²':$unit);
    }
}
