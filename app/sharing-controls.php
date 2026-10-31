<?php use SwimlogStandalone\Sharing;$state=$editorItem['visibility']; ?>
<section class="sharing-controls" aria-labelledby="sharing-title" data-sharing-controls>
<h3 id="sharing-title">Public workout sharing</h3>
<?php if(is_wp_error($state)): ?><p role="status"><?php echo esc_html($state->get_error_message()); ?></p><button type="button" disabled>Copy Public Link</button>
<?php else:$granted=in_array($state['visibility'],array('public','legacy_public'),true);$operation='visibility';require __DIR__.'/workout-editor-form.php'; ?>
<input type="hidden" name="visibility_nonce" value="<?php echo esc_attr(wp_create_nonce('swimlog_workout_visibility_'.$w->id)); ?>">
<input type="hidden" name="visibility_revision" value="<?php echo esc_attr((string)$state['revision']); ?>">
<label class="setting-check"><input type="checkbox" name="make_public" value="1" <?php checked($granted); ?> data-public-checkbox>Make Workout Public</label>
<label class="setting-check"><input type="checkbox" name="share_route" value="1" <?php checked($state['route_public']); ?> <?php disabled(!$granted); ?> data-route-checkbox>Share route publicly</label>
<p>Public access is read-only. Notes, photos, location names, source files, device data, heart rate, cadence and measurements are excluded. Turning sharing off also clears route sharing.</p>
<button>Save workout sharing</button></form>
<p role="status" data-effective-sharing><?php echo $state['effective_public']?'This workout is publicly viewable.':'This workout is not publicly viewable.'; ?></p>
<?php if($state['blocked_reason']==='public_results_disabled'): ?><p>Your swimmer-wide Public Results permission blocks public viewing. Review <a href="<?php echo esc_url($config['wordpress_origin'].'/wp-admin/admin.php?page=swimlog-privacy'); ?>">Swimmer Configuration in WordPress</a>; saving this form does not enable it.</p><?php endif; ?>
<p>Effective public route permission: <?php echo $state['effective_route_public']?'permitted (usable GPS may be absent).':'not permitted.'; ?> Route sharing requires both workout approval and applicable owner route consent. Old open-water route consent covers swimming only; all-sport route consent is needed for running/walking. <a href="<?php echo esc_url($config['wordpress_origin'].'/wp-admin/admin.php?page=swimlog-privacy'); ?>">Review owner-wide sharing permissions in WordPress</a>.</p>
<?php if($state['pending_standalone_preference']): ?><p>A previous standalone sharing request is pending. It has not granted public access. Confirm it explicitly to approve this workout with routes off.</p>
<?php $operation='adopt';require __DIR__.'/workout-editor-form.php'; ?><input type="hidden" name="visibility_nonce" value="<?php echo esc_attr(wp_create_nonce('swimlog_workout_visibility_'.$w->id)); ?>"><input type="hidden" name="visibility_revision" value="<?php echo esc_attr((string)$state['revision']); ?>"><button>Confirm previous sharing request</button></form><?php endif; ?>
<?php $publicUrl=$state['effective_public']?Sharing::url($config['app_origin'],$w->id):''; ?>
<button type="button" data-copy-public data-copy-id="<?php echo esc_attr($w->id); ?>" data-copy-nonce="<?php echo esc_attr(wp_create_nonce('standalone_workout_link_'.$w->id)); ?>" data-copy-revision="<?php echo esc_attr($editorItem['revision']); ?>" <?php disabled(!$state['effective_public']); ?>>Copy Public Link</button>
<?php if($state['effective_public']): ?><div class="plan-field"><label for="public-workout-link">Public workout link</label><input id="public-workout-link" type="url" readonly value="<?php echo esc_attr($publicUrl); ?>" data-public-link><small>Select this link to copy it manually if clipboard access is unavailable.</small></div><?php endif; ?>
<p role="status" aria-live="polite" data-copy-status></p><p>Copied links are rechecked on every visit. Revocation blocks future app views; copies or previews already retained by social platforms cannot be recalled.</p>
<?php endif; ?>
</section><script src="/assets/sharing.js" defer></script>
