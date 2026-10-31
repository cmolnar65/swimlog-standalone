<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Swim Log</title><link rel="stylesheet" href="/assets/app.css"></head>
<body><main><p class="eyebrow">SWIM LOG</p>
<?php if ($signedIn): ?>
<h1>Welcome, <?php echo esc_html(wp_get_current_user()->display_name); ?></h1>
<p>Your dedicated Swim Log is taking shape.</p><p>Workout features will appear here as development continues.</p>
<form method="post" action="/?action=logout"><input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('swimlog_standalone_logout')); ?>"><button>Log out</button></form>
<?php else: ?>
<h1>Your swimming, in one place.</h1>
<p><?php echo isset($_GET['signed_out']) ? 'You have been signed out.' : 'Sign in using your existing cmolnar.com WordPress account.'; ?></p>
<form method="post" action="/?action=login"><button>Sign in with cmolnar.com</button></form>
<?php endif; ?>
</main></body></html>
