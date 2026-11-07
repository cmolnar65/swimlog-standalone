<?php use SwimlogStandalone\Goals as G; ?>
<section class="goals-panel" aria-labelledby="goals-title">
<p class="eyebrow">YOUR TRAINING TARGETS</p>
<h2 id="goals-title">Goals</h2>
<?php if(isset($goalError)): ?><p role="alert"><?php echo esc_html($goalError); ?></p><?php else: ?>
<p>Track one-time swim performances and date-range swimming totals. Meter and yard goals and progress stay separate.</p>
<nav class="goal-nav" aria-label="Goals navigation"><a class="control" href="#set-goal">Set Goal</a><a class="control" href="#list-goals">List Goals</a></nav>
<h3 id="set-goal">Set Goal</h3>
<div class="goal-forms">
<section class="goal-card"><h4>One-time swim goal</h4>
<p>Choose a target distance, a target pace per 100, or both. A recorded evaluated performance must meet the selected target by the due date. Any stroke can qualify.</p>
<form method="post" action="/?action=goal_save" class="goal-form">
<input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('standalone_goal_save')); ?>"><input type="hidden" name="goal_type" value="single_performance">
<label>Goal name (optional)<input name="title" type="text" maxlength="191"></label>
<label>Course<select name="course_unit"><option value="m" <?php selected($goals['default_unit'],'m'); ?>>Meters</option><option value="yd" <?php selected($goals['default_unit'],'yd'); ?>>Yards</option></select></label>
<label>Distance (optional)<select name="target_distance_value"><option value="">Any evaluated distance</option><?php foreach(\SwimLogEvaluation\Evaluator::DISTANCES as $distance): ?><option value="<?php echo esc_attr($distance); ?>"><?php echo esc_html($distance); ?></option><?php endforeach; ?></select></label>
<label>Target pace per 100 (optional)<input name="target_pace" type="text" inputmode="decimal" pattern="[0-9]{1,3}:[0-5][0-9](\.[0-9]{1,2})?" placeholder="e.g. 2:00.50"><small>Minutes:seconds in the selected course unit. Enter distance, pace, or both.</small></label>
<label>Do it by<input name="end_date" type="date" min="<?php echo esc_attr(wp_date('Y-m-d',null,wp_timezone())); ?>" value="<?php echo esc_attr(wp_date('Y-m-d',null,wp_timezone())); ?>" required></label>
<button type="submit">Set One-time Goal</button>
</form></section>
<section class="goal-card"><h4>Date-range distance goal</h4>
<p>Includes pool and open-water swims across all strokes. Dates include both the first and last day; distances without a known stored distance are not counted.</p>
<form method="post" action="/?action=goal_save" class="goal-form">
<input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('standalone_goal_save')); ?>"><input type="hidden" name="goal_type" value="period_distance">
<label>Goal name (optional)<input name="title" type="text" maxlength="191"></label>
<label>Total distance<input name="target_distance" type="number" min="0.001" max="100000000" step="0.001" required></label>
<label>Course<select name="course_unit"><option value="m" <?php selected($goals['default_unit'],'m'); ?>>Meters</option><option value="yd" <?php selected($goals['default_unit'],'yd'); ?>>Yards</option></select></label>
<label class="goal-single-workout"><input name="single_workout" type="checkbox" value="1"<?php if(empty($goals['single_workout_supported'])): ?> disabled<?php endif; ?>><span><strong>Single Workout</strong><small>Require one pool or open-water workout in this date range to meet the target. Progress shows the longest individual workout until one reaches the target.</small><?php if(empty($goals['single_workout_supported'])): ?><small>This option requires an updated WordPress Swim Log plugin.</small><?php endif; ?></span></label>
<label>First day<input name="start_date" type="date" value="<?php echo esc_attr(wp_date('Y-m-d',null,wp_timezone())); ?>" required></label>
<label>Last day<input name="end_date" type="date" value="<?php echo esc_attr(wp_date('Y-m-d',null,wp_timezone())); ?>" required></label>
<button type="submit">Set Date-range Goal</button>
</form></section></div>
<h3 id="list-goals">List Goals</h3>
<?php if(!$goals['goals']): ?><p>No goals have been set.</p><?php else: ?><div class="goal-list" role="list">
<?php foreach($goals['goals'] as $goal): $isSingleWorkout=$goal['goal_type']==='single_workout_distance'; $isPeriod=in_array($goal['goal_type'],array('period_distance','single_workout_distance'),true); $percent=min(100,max(0,(float)($goal['progress_percent']??0))); ?>
<article class="goal-card goal-item" role="listitem">
<div class="goal-heading"><div><p class="eyebrow"><?php echo $isSingleWorkout?'SINGLE-WORKOUT DISTANCE':($isPeriod?'DATE-RANGE DISTANCE':'ONE-TIME PERFORMANCE'); ?></p><h4><?php echo esc_html($goal['title']); ?></h4></div><div class="goal-status-block"><span class="goal-status status-<?php echo esc_attr($goal['status']); ?>"><?php echo esc_html(G::statusLabel($goal['status'])); ?></span><?php if($goal['status']==='achieved'&&!empty($goal['completion_date'])): ?><span class="goal-achieved-date"><span class="screen-reader-text">Achieved </span><?php echo esc_html(G::formatCompletionDate($goal['completion_date'])); ?></span><?php endif; ?></div></div>
<p class="goal-dates"><?php echo $isPeriod?'Inclusive dates: '.esc_html($goal['start_date']).' – '.esc_html($goal['end_date']):'Due '.esc_html($goal['end_date']); ?></p>
<?php if($isPeriod): $target=G::periodTarget($goal); $current=(float)($goal['progress_native']??0); ?>
<p><strong>Target:</strong> <?php echo esc_html(G::formatNumber($target).' '.$goal['course_unit']); ?></p><progress max="100" value="<?php echo esc_attr($percent); ?>" aria-label="<?php echo esc_attr('Progress for '.$goal['title']); ?>"></progress><p class="goal-progress"><?php echo $isSingleWorkout?'Longest workout: ':''; ?><?php echo esc_html(G::formatNumber($current).' / '.G::formatNumber($target).' '.$goal['course_unit'].' · '.number_format_i18n((float)($goal['progress_percent']??0),1).'%'); ?></p>
<?php else: ?><p><strong>Target:</strong> <?php echo esc_html(G::singleTarget($goal)); ?></p>
<?php if($goal['best_pace_ms']!==null): ?><p class="goal-progress"><strong>Best qualifying pace:</strong> <?php echo esc_html(G::formatPace($goal['best_pace_ms']).' / 100 '.$goal['course_unit']); ?><?php if($goal['progress_distance_value']!==null): ?> at <?php echo esc_html((int)$goal['progress_distance_value'].' '.$goal['course_unit']); ?><?php endif; ?><?php if($goal['achieved_at']): ?> · <?php echo esc_html(wp_date('M j, Y',strtotime($goal['achieved_at']),wp_timezone())); ?><?php endif; ?></p><?php else: ?><p class="goal-progress">No qualifying evaluated performance yet.</p><?php endif; ?>
<?php if($goal['target_pace_ms']!==null): ?><progress max="100" value="<?php echo esc_attr($percent); ?>" aria-label="<?php echo esc_attr('Progress for '.$goal['title']); ?>"></progress><?php endif; ?>
<?php endif; ?>
<form method="post" action="/?action=goal_delete"><input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('standalone_goal_delete')); ?>"><input type="hidden" name="goal_id" value="<?php echo esc_attr($goal['id']); ?>"><button class="button-link-delete" type="submit">Delete goal</button></form>
</article><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
</section>
