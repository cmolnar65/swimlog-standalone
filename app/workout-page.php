<?php use SwimlogStandalone\WorkoutDetails as WD; use SwimlogStandalone\CalendarView as CV; ?>
<section class="calendar-panel workout-page"><div class="workout-page-heading"><h2>Workout details</h2><a class="control" href="<?php echo esc_url(is_wp_error($calendar)?'/':CV::url($calendar['date'],$calendar['view'])); ?>">Back to calendar</a></div>
<?php if(is_wp_error($workoutDetail)): ?><p role="alert"><?php echo esc_html($workoutDetail->get_error_message()); ?></p><?php else: echo WD::render($workoutDetail); endif; ?>
</section>
