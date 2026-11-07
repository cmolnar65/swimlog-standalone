<?php
namespace SwimlogStandalone;
/** Accessible point markup shared by the private SVG report charts. */
final class ChartPoints {
    public static function point($x,$y,$text,$radius=4){return '<g data-chart-point data-chart-tooltip="'.esc_attr($text).'" tabindex="0" role="button" aria-label="'.esc_attr($text).'"><title>'.esc_html($text).'</title><circle cx="'.esc_attr($x).'" cy="'.esc_attr($y).'" r="12" fill="transparent"/><circle class="chart-point-dot" cx="'.esc_attr($x).'" cy="'.esc_attr($y).'" r="'.esc_attr($radius).'" fill="#245c9c"/></g>';}
}
