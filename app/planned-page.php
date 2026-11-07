<?php use SwimlogStandalone\PlannedDetails as PD; ?>
<section class="calendar-panel workout-page"><div class="workout-page-heading"><h2>Planned workout details</h2><a class="control" href="<?php echo esc_url($returnUrl); ?>">Back to <?php echo $screen==='log'?'workout log':'calendar'; ?></a></div>
<?php if(is_wp_error($plannedDetail)): ?><p role="alert"><?php echo esc_html($plannedDetail->get_error_message()); ?></p><?php else: echo PD::render($plannedDetail,$returnUrl); endif; ?>
</section>
