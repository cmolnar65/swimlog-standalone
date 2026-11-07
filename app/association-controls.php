<?php if(!empty($association)&&!is_wp_error($association)): ?>
<section class="association-controls"><h3>Workout associations</h3>
<p>Planned workout: <?php echo esc_html($association['title']); ?>. Both records are kept when an association is removed.</p>
<?php foreach(array('match'=>'Unlink planned workout','structure'=>'Unlink structured workout')as$unlinkOp=>$unlinkLabel):if(($unlinkOp==='match'&&!$association['workout_id'])||($unlinkOp==='structure'&&!$association['structure']))continue; ?>
<form method="post" action="/?action=association_unlink">
<?php foreach(array('plan_id'=>(string)$association['plan_id'],'workout_id'=>(string)$association['workout_id'],'operation'=>$unlinkOp,'revision'=>$association['revision'],'return_to'=>$associationReturn,'nonce'=>wp_create_nonce('standalone_unlink_'.$unlinkOp.'_'.$association['plan_id']))as$key=>$value): ?><input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>"><?php endforeach; ?>
<p><?php echo $unlinkOp==='match'?'Removing the recorded match returns the planned workout to Planned.':'Removing the structured link keeps the prescription and its recorded-history lock.'; ?></p>
<label><input type="checkbox" name="confirm" value="1" required> Confirm <?php echo $unlinkOp==='match'?'removing the planned match':'removing the structured link'; ?></label> <button><?php echo esc_html($unlinkLabel); ?></button></form>
<?php endforeach; ?></section>
<?php elseif(is_wp_error($association??null)): ?><p role="status"><?php echo esc_html($association->get_error_message()); ?></p><?php endif; ?>
