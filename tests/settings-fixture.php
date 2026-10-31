<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
$user=get_user_by('login','synthetic_settings');if(!$user){$id=wp_create_user('synthetic_settings','synthetic-settings-login','settings@example.test');if(is_wp_error($id))throw new RuntimeException('Settings account failed.');$user=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts','swimlog_upload_workouts','swimlog_manage_own_locations')as$cap)$user->add_cap($cap);
