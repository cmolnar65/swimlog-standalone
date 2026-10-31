<?php use SwimlogStandalone\PublicWorkout as P;
$labels=array('pool'=>'Pool swim','open_water'=>'Open-water swim','running'=>'Running','walking'=>'Walking');
$title=$labels[$public['workout_type']].' · '.$public['date']; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,noarchive"><title><?php echo esc_html($title); ?> · Swim Log</title>
<link rel="stylesheet" href="/assets/app.css"><link rel="icon" href="/assets/brand/icon.png"><link rel="canonical" href="<?php echo esc_url($url); ?>">
<meta property="og:title" content="<?php echo esc_attr($title); ?>"><meta property="og:type" content="website"><meta property="og:url" content="<?php echo esc_url($url); ?>"><meta property="og:description" content="A publicly shared, read-only workout."></head>
<body><header class="site-header"><a class="nav-link" href="/">Swim Log</a></header><main class="site-main"><section class="calendar-panel workout-page" aria-labelledby="public-title">
<p class="eyebrow">SHARED WORKOUT · READ ONLY</p><h1 id="public-title"><?php echo esc_html($title); ?></h1>
<dl class="workout-metrics"><?php
$metrics=array('Activity'=>$labels[$public['workout_type']],'Date'=>$public['date'],'Distance (m)'=>P::number($public['total_distance_m'],' m'),'Elapsed time'=>P::duration($public['elapsed_time_ms']),'Moving time'=>P::duration($public['moving_time_ms']));
if(in_array($public['workout_type'],array('pool','open_water'),true))$metrics['Recorded distance']=P::number($public['original_distance'],' '.($public['original_distance_unit']??''));
if($public['workout_type']==='pool')$metrics+=array('Pool length'=>P::number($public['original_pool_length'],' '.($public['pool_length_unit']??'')),'Primary stroke'=>$public['primary_stroke']??'—','Average SWOLF'=>P::number($public['avg_swolf']),'Equivalent steps'=>P::number($public['equivalent_steps']));
foreach($metrics as$label=>$value): ?><div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd></div><?php endforeach; ?></dl>
<?php
$tables=array(
 'lengths'=>array('Active lengths',array('sequence_no'=>'Length','distance_m'=>'Distance (m)','elapsed_time_ms'=>'Elapsed','stroke'=>'Stroke','stroke_count'=>'Stroke count','swolf_score'=>'SWOLF')),
 'splits'=>array('Recorded splits',array('sequence_no'=>'Split','distance_m'=>'Distance (m)','elapsed_time_ms'=>'Elapsed','moving_time_ms'=>'Moving')),
 'performances'=>array('Recorded swim performances',array('distance_value'=>'Distance','course_unit'=>'Course','stroke'=>'Stroke','duration_ms'=>'Duration')));
foreach($tables as$key=>$definition):if(empty($public[$key]))continue; ?><h2><?php echo esc_html($definition[0]); ?></h2><div class="entry-compare" role="region" tabindex="0" aria-label="<?php echo esc_attr($definition[0]); ?>"><table><thead><tr><?php foreach($definition[1]as$label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($public[$key]as$row):$row=(array)$row; ?><tr><?php foreach($definition[1]as$field=>$label): ?><td><?php echo esc_html(substr($field,-3)==='_ms'?P::duration($row[$field]??null):($row[$field]??'—')); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endforeach; ?>
<?php if($public['route']!==null):$geometry=P::geometry($public['route']);if($geometry!==''): ?><h2>Shared route</h2><?php echo $geometry; ?><p>Only approved route geometry is shown. Recording gaps remain separate.</p><?php else: ?><p>No usable public route geometry is available.</p><?php endif;endif; ?>
<p>Public viewing does not grant editing or management access.</p></section></main><script src="/assets/public-workout.js" defer></script></body></html>
