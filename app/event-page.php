<?php use SwimlogStandalone\EventWorkflow as EW; ?>
<section class="calendar-panel workout-page"><div class="workout-page-heading"><h2>Event details</h2><a class="control" href="<?php echo esc_url($returnUrl); ?>"><?php echo $screen==='events'?'Back to event list':'Back to calendar'; ?></a></div>
<?php if(is_wp_error($eventDetail)): ?><p role="alert"><?php echo esc_html($eventDetail->get_error_message()); ?></p><?php else: echo EW::render($eventDetail,$returnUrl); endif; ?></section>
