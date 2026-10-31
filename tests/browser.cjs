const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium, firefox, webkit } = require('playwright');
(async () => {
  const fixture = JSON.parse(fs.readFileSync(process.env.SWIMLOG_WEB_FIXTURE, 'utf8'));
  const selected = process.env.SWIMLOG_BROWSER || 'chromium';
  const engines = {[selected]:{chromium, firefox, webkit}[selected]};
  for (const [name, engine] of Object.entries(engines)) {
    const browser = await engine.launch({headless: true});
    try {
      const context = await browser.newContext({ignoreHTTPSErrors:true, hasTouch:true, proxy:{server:'http://127.0.0.1:8088'}});
      await context.addCookies([{name:fixture.name, value:fixture.cookie, url:'https://wordpress.example.test/', secure:true, httpOnly:true, sameSite:'Lax'}]);
      const posts = []; const navigation = [];
      const page = await context.newPage();
      page.on('request', request => {
        const url=new URL(request.url());
        if (request.method()==='POST') posts.push({host:url.host, path:url.search, origin:request.headers().origin});
      });
      page.on('response', response => {
        if (response.request().isNavigationRequest()) navigation.push({host:new URL(response.url()).host, status:response.status()});
      });
      await page.goto('https://swimlog.example.test/');
      await page.getByRole('button', {name:'Sign in with wordpress.example.test', exact:true}).click();
      try { await page.getByRole('heading', {name:'Welcome, <script>synthetic</script>', exact:true}).waitFor({timeout:15000}); } catch (error) {
        console.error(JSON.stringify(navigation));
        console.error((await page.locator('body').innerText()).slice(0,300));
        throw error;
      }
      assert.equal(posts[0].origin, 'https://swimlog.example.test');
      assert.equal(posts[1].origin, 'https://wordpress.example.test');
      assert.equal(posts[2].origin, 'https://wordpress.example.test');
      const cookies = await context.cookies('https://swimlog.example.test/');
      const auth = cookies.find(c=>c.name===fixture.name);
      assert(auth && auth.domain==='swimlog.example.test' && auth.secure && auth.httpOnly);
      await page.locator('#calendar-title').waitFor();
      assert.equal(await page.locator('[role=alert]').count(),0);
      assert((await page.locator('.calendar-panel').innerText()).includes('Synthetic meet'));
      assert(!(await page.locator('body').innerText()).includes('FOREIGN_PRIVATE_EVENT'));
      assert(!(await page.locator('body').innerText()).includes('PRIVATE_NOTES_CANARY'));
      for (const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        const overflow=await page.evaluate(()=>Array.from(document.querySelectorAll('body *')).filter(el=>el.getBoundingClientRect().right>innerWidth+1).map(el=>({tag:el.tagName,class:el.className,right:Math.round(el.getBoundingClientRect().right)})).slice(0,8));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1), `${name}: horizontal overflow at ${width}: ${JSON.stringify(overflow)}`);
        const display=await page.locator('.calendar-grid').first().evaluate(el=>getComputedStyle(el).display);
        assert.equal(display,width<=700?'block':'grid');
        const image=page.locator('.brand img');
        assert(await image.evaluate(el=>el.complete && el.naturalWidth===256));
        const entry=page.locator('a[data-workout-id="'+fixture.workouts.pool+'"]').first();
        await entry.scrollIntoViewIfNeeded();
        const scrollBefore=await page.evaluate(()=>scrollY);
        if (width<=390) await entry.tap(); else await entry.click();
        await page.locator('#workout-dialog .workout-content').waitFor();
        assert(await page.locator('#workout-dialog').evaluate(el=>el.open));
        assert((await page.locator('#workout-dialog').innerText()).includes('Lengths and rest'));
        const restLap=page.getByRole('region',{name:'Workout laps',exact:true}).locator('tbody tr').last();
        assert.equal(await restLap.locator('td').last().innerText(),'Rest');
        assert((await restLap.innerText()).includes('45.00'));
        assert.equal(await page.locator('#workout-dialog img').count(),0);
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        for(let tab=0;tab<6;tab++) { await page.keyboard.press('Tab'); assert(await page.evaluate(()=>!!document.activeElement.closest('#workout-dialog'))); }
        await page.locator('.workout-dialog-body').evaluate(el=>el.scrollTop=400);
        if(width===1440) await page.keyboard.press('Escape');
        else if(width===768) await page.getByRole('button',{name:'Close workout details',exact:true}).click();
        else await page.touchscreen.tap(3,3);
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open && document.querySelector('[data-workout-content]').childElementCount===0);
        assert.equal(await entry.getAttribute('aria-expanded'),'false');
        assert(await entry.evaluate(el=>document.activeElement===el));
        assert(Math.abs((await page.evaluate(()=>scrollY))-scrollBefore)<=3);

      }
      // Reopening, route/no-GPS states, keyboard activation and retry.
      for(const type of ['open_water','running','walking']) {
        await page.locator('a[data-workout-id="'+fixture.workouts[type]+'"]').tap();
        await page.locator('#workout-dialog .workout-content').waitFor();
        if(type==='walking') assert((await page.locator('#workout-dialog').innerText()).includes('No usable GPS route'));
        else assert.equal(await page.locator('#workout-dialog polyline').count(),2);
        await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open);
      }
      const pool=page.locator('a[data-workout-id="'+fixture.workouts.pool+'"]').first();
      await pool.focus(); await page.keyboard.press('Enter');
      await page.locator('#workout-dialog .workout-content').waitFor();
      await page.keyboard.press('Escape');
      const matchDetail=url=>url.searchParams.get('action')==='workout_detail' && url.searchParams.get('id')===String(fixture.workouts.pool);
      let failures=0;
      await page.route(matchDetail, route=> failures++===0 ? route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Synthetic temporary failure'})}) : route.continue());
      await pool.tap(); await page.getByRole('button',{name:'Retry',exact:true}).tap();
      await page.locator('#workout-dialog .workout-content').waitFor();
      await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
      await page.unroute(matchDetail);
      // A slow response must not reappear after tap-off and a different workout.
      await page.route(matchDetail, async route=>{
        const response=await route.fetch(); await new Promise(resolve=>setTimeout(resolve,400));
        try { await route.fulfill({response}); } catch(_) { /* closed request was aborted */ }
      });
      await pool.tap(); await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
      await page.locator('a[data-workout-id="'+fixture.workouts.walking+'"]').tap();
      await page.locator('#workout-dialog .workout-content').waitFor();
      await page.waitForTimeout(500);
      assert.equal(await page.locator('#workout-dialog [data-workout-id]').getAttribute('data-workout-id'),String(fixture.workouts.walking));
      await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
      await page.unroute(matchDetail);
      console.log(`${name}: desktop pop-up, phone tap-on/off, Escape/Close/outside dismissal, focus/scroll restore, routes, retry and stale-response isolation passed.`);
      await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Week',exact:true}).tap();
      await page.waitForURL(/view=week/);
      assert.equal(await page.locator('.calendar-day').count(),7);
      await page.getByRole('link',{name:'Next week',exact:true}).tap();
      await page.getByRole('link',{name:'Today',exact:true}).tap();
      await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Month',exact:true}).tap();
      await page.waitForURL(/view=month/);
      assert((await page.locator('.calendar-day').count())>=28);
      await page.locator('.tools-menu>summary').tap();
      assert((await page.getByRole('link',{name:'Workout log',exact:true}).getAttribute('href')).startsWith('https://wordpress.example.test/'));
      await page.locator('.tools-menu>summary').tap();
      console.log(`${name}: branded calendar, owner privacy, desktop/phone widths, touch details, month/week navigation passed.`);
      await page.getByRole('button', {name:'Log out', exact:true}).click();
      await page.getByText('You have been signed out.', {exact:true}).waitFor();
      assert.equal(posts[3].origin, 'https://swimlog.example.test');
      console.log(`${name}: automatic login-to-calendar handoff, host-only cookie and logout passed.`);
      // Exercise actual WordPress credentials, failure return, and successful retry.
      await page.getByRole('button', {name:'Sign in with wordpress.example.test', exact:true}).click();
      await page.locator('#user_login').fill('synthetic_swimmer');
      await page.locator('#user_pass').fill('incorrect');
      await page.locator('#wp-submit').click();
      await page.getByRole('alert').filter({hasText:'Sign-in could not be completed'}).waitFor();
      assert.equal(new URL(page.url()).hostname,'swimlog.example.test');
      await page.getByRole('button', {name:'Sign in with wordpress.example.test', exact:true}).click();
      await page.locator('#user_login').fill('synthetic_swimmer');
      await page.locator('#user_pass').fill('synthetic-login-only');
      await page.locator('#wp-submit').click();
      await page.locator('#calendar-title').waitFor();
      assert.equal(await page.getByRole('button',{name:'Continue to Swim Log',exact:true}).count(),0);
      await page.getByRole('button', {name:'Log out', exact:true}).click();
      await page.getByText('You have been signed out.', {exact:true}).waitFor();
      console.log(`${name}: failed WordPress login returns to sign-in; correct retry opens calendar automatically.`);
      await context.close();
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error.message); process.exitCode=1; });
