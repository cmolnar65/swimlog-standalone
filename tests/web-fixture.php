<?php
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') throw new RuntimeException('Disposable test flag required.');
$owner = get_user_by('login', 'synthetic_swimmer');
$other = get_user_by('login', 'synthetic_other');
global $wpdb;
$wpdb->update($wpdb->users, array('display_name' => '<script>synthetic</script>'), array('ID' => $owner->ID));
clean_user_cache($owner->ID);
$expiry = time() + 3600;
$cookie = wp_generate_auth_cookie($owner->ID, $expiry, 'logged_in');
$otherCookie = wp_generate_auth_cookie($other->ID, $expiry, 'logged_in');
file_put_contents(getenv('SWIMLOG_WEB_FIXTURE'), json_encode(array('name' => LOGGED_IN_COOKIE, 'cookie' => $cookie, 'other' => $otherCookie)));
chmod(getenv('SWIMLOG_WEB_FIXTURE'), 0600);
