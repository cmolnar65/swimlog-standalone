<?php if(!empty($association)&&!is_wp_error($association)&&!empty($association['deletable'])): ?>
<section class="association-controls"><h3>Delete Planned Workout</h3><p>Permanently delete this unmatched plan. Recorded workouts and structured prescriptions are kept. Prescriptions with recorded history remain locked.</p>
<form method="post" action="/?action=plan_delete">
<?php foreach(array('plan_id'=>(string)$association['plan_id'],'workout_id'=>'0','operation'=>'delete','revision'=>$association['revision'],'return_to'=>'plan','nonce'=>wp_create_nonce('standalone_unlink_delete_'.$association['plan_id']))as$key=>$value): ?><input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>"><?php endforeach; ?>
<label><input type="checkbox" name="confirm" value="1" required> Confirm permanent deletion of this planned workout</label> <button>Delete Planned Workout</button></form></section>
<?php endif; ?>
