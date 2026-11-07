<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
$user=get_user_by('login','synthetic_entry');if(!$user){$id=wp_create_user('synthetic_entry','synthetic-entry-login','entry@example.test');if(is_wp_error($id))throw new RuntimeException('Entry test account failed.');$user=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts','swimlog_upload_workouts')as$cap)$user->add_cap($cap);
$dir=sys_get_temp_dir().'/swimlog-entry-private';if(!wp_mkdir_p($dir))throw new RuntimeException('Synthetic private storage unavailable.');update_option('swimlog_private_storage_dir',$dir);
