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
      async function closeDay() {
        if(await page.locator('#calendar-day-dialog[open]').count()) {
          await page.locator('[data-day-close]').click();
          await page.waitForFunction(()=>!document.getElementById('calendar-day-dialog').open&&!document.querySelector('[data-day-items]').childElementCount);
        }
      }
      async function reveal(entry) {
        // Overflow budgeting runs in a deferred script; do not race its first measure.
        await page.waitForLoadState('load');
        await entry.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        if(await entry.evaluate(el=>!!el.closest('#calendar-day-dialog'))) return;
        await closeDay();
        if(!await entry.isVisible()) {
          const date=await entry.evaluate(el=>el.closest('.calendar-day')?.dataset.date);
          assert(date,'Hidden entry must belong to a calendar day');
          await page.locator('.calendar-day[data-date="'+date+'"] [data-day-open]').click();
          assert(await entry.isVisible());
        }
      }
      async function tapEntry(entry) { await reveal(entry);try {await entry.tap();}catch(error){console.error(JSON.stringify(await entry.evaluate(el=>{const path=[];for(let p=el;p;p=p.parentElement)path.push({tag:p.tagName,id:p.id,hidden:p.hidden,open:p.open,rect:p.getBoundingClientRect().toJSON()});return path;})));throw error;} }

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
      assert((await page.locator('.calendar-panel').textContent()).includes('Synthetic meet'));
      assert(!(await page.locator('body').innerText()).includes('FOREIGN_PRIVATE_EVENT'));
      assert(!(await page.locator('body').innerText()).includes('PRIVATE_NOTES_CANARY'));
      for (const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        const overflow=await page.evaluate(()=>Array.from(document.querySelectorAll('body *')).filter(el=>el.getBoundingClientRect().right>innerWidth+1).map(el=>({tag:el.tagName,class:el.className,right:Math.round(el.getBoundingClientRect().right)})).slice(0,8));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1), `${name}: horizontal overflow at ${width}: ${JSON.stringify(overflow)}`);
        const display=await page.locator('.calendar-grid').first().evaluate(el=>getComputedStyle(el).display);
        assert.equal(display,width<=700?'block':'grid');
        const image=page.locator('.brand img');
        assert(await image.evaluate(el=>el.complete && el.naturalWidth===256));
        const entry=page.locator('a[data-workout-id="'+fixture.workouts.pool+'"]').first();
        await reveal(entry);await entry.scrollIntoViewIfNeeded();
        const scrollBefore=await page.evaluate(()=>scrollY);
        if (width<=390) await entry.tap(); else await entry.click();
        await page.locator('#workout-dialog .workout-content').waitFor();
        assert(await page.locator('#workout-dialog').evaluate(el=>el.open));
        assert((await page.locator('#workout-dialog').innerText()).includes('Lengths and rest'));
        assert.equal(await page.locator('#workout-dialog .workout-metrics>div').filter({has:page.locator('dt',{hasText:/^Total rest time$/})}).locator('dd').innerText(),'45.00');
        assert.equal(await page.locator('#workout-dialog .workout-metrics>div').filter({has:page.locator('dt',{hasText:/^Calculated moving time$/})}).locator('dd').innerText(),'29:15.00');
        const restLap=page.getByRole('region',{name:'Workout laps',exact:true}).locator('tbody tr').last();
        assert.equal(await restLap.locator('td').last().innerText(),'Rest');
        assert((await restLap.innerText()).includes('45.00'));
        assert.equal(await page.locator('#workout-dialog img').count(),0);
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        for(let tab=0;tab<6;tab++) { await page.keyboard.press('Tab'); assert(await page.evaluate(()=>!!document.activeElement.closest('#workout-dialog'))); }
        await page.locator('#workout-dialog .workout-dialog-body').evaluate(el=>el.scrollTop=400);
        if(width===1440) await page.keyboard.press('Escape');
        else if(width===768) await page.getByRole('button',{name:'Close workout details',exact:true}).click();
        else await page.touchscreen.tap(3,3);
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open && document.querySelector('[data-workout-content]').childElementCount===0);
        assert.equal(await entry.getAttribute('aria-expanded'),'false');
        assert(await entry.evaluate(el=>document.activeElement===el));
        assert(Math.abs((await page.evaluate(()=>scrollY))-scrollBefore)<=3);await closeDay();

      }
      await closeDay();
      // Planned swims share the desktop dialog and touch dismissal behavior.
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        const entry=page.locator('a[data-plan-id="'+fixture.plans.plan+'"]').first();
        await reveal(entry);await entry.scrollIntoViewIfNeeded();
        const before=await page.evaluate(()=>scrollY);
        if(width<=390)await entry.tap();else await entry.click();
        await page.locator('#workout-dialog .planned-content').waitFor();
        assert.equal(await page.locator('#workout-dialog-title').innerText(),'Planned workout details');
        assert((await page.locator('#workout-dialog').innerText()).includes('Rest between sets'));
        assert((await page.locator('#workout-dialog').innerText()).includes('Total: 100 yd'));
        assert(!(await page.locator('#workout-dialog').innerText()).includes('PRIVATE_PLAN_NOTES_CANARY'));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        for(let tab=0;tab<3;tab++) {await page.keyboard.press('Tab');assert(await page.evaluate(()=>!!document.activeElement.closest('#workout-dialog')));}
        if(width<=390)await page.touchscreen.tap(3,3);else await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open && document.querySelector('[data-workout-content]').childElementCount===0);
        assert(await entry.evaluate(el=>el===document.activeElement));
        assert(Math.abs(await page.evaluate(()=>scrollY)-before)<=3);await closeDay();
      }
      for(const key of ['empty','broken','matched']) {
        await tapEntry(page.locator('a[data-plan-id="'+fixture.plans[key]+'"]'));
        await page.locator('#workout-dialog .planned-content').waitFor();
        const text=await page.locator('#workout-dialog').innerText();
        if(key==='empty')assert(text.includes('No instructions have been added.'));
        if(key==='broken')assert(text.includes('linked recorded workout is unavailable')&&!text.includes('FOREIGN_STRUCTURE_CANARY'));
        if(key==='matched') {
          const link=page.getByRole('link',{name:'View matched recorded workout',exact:true});
          assert((await link.getAttribute('href')).includes('workout='+fixture.workouts.pool));
          await link.tap();await page.locator('.workout-page .workout-content').waitFor();
          await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
        } else {
          await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
          await page.waitForFunction(()=>!document.getElementById('workout-dialog').open);
        }
      }
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Week',exact:true}).tap();
      await page.waitForURL(/view=week/);
      await tapEntry(page.locator('a[data-plan-id="'+fixture.plans.matched+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      const matchedLink=page.getByRole('link',{name:'View matched recorded workout',exact:true});
      assert((await matchedLink.getAttribute('href')).includes('view=week'));
      await matchedLink.tap();await page.locator('.workout-page .workout-content').waitFor();
      await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
      await page.waitForURL(/view=week/);
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Month',exact:true}).tap();
      await page.waitForURL(/view=month/);
      await page.waitForLoadState('load');
      console.log(`${name}: planned instructions/structure/match, empty/private states, desktop/mobile dialog and matched session navigation passed.`);
      const today=await page.locator('.calendar-day.is-today time').getAttribute('datetime');
      assert(await page.locator('.calendar-day.is-today .plan-day-link').count()===1);
      assert(await page.locator('.calendar-day').evaluateAll((days,today)=>days.every(day=>day.querySelector('time').dateTime>=today||!day.querySelector('.plan-day-link')),today));
      await closeDay();await page.locator('.workouts-menu > summary').tap();await page.getByRole('link',{name:'Plan Workout',exact:true}).tap();
      await page.locator('#plan-form-title').waitFor();
      const originalTitle=`Synthetic ${name} browser plan`;
      await page.getByLabel('Title',{exact:true}).fill(originalTitle);
      await page.getByLabel('Planned distance (optional)',{exact:true}).fill('500');
      await page.getByLabel('Distance unit',{exact:true}).selectOption('yd');
      await page.getByLabel('Pool length (optional)',{exact:true}).fill('25');
      await page.getByLabel('Pool course',{exact:true}).selectOption('yd');
      await page.getByLabel('Workout instructions (optional)',{exact:true}).fill('Synthetic instructions with a quote " and backslash \\');
      await page.getByLabel('Planned duration (optional)',{exact:true}).fill('10:99');
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: plan form overflow at ${width}: ${await page.evaluate(()=>Array.from(document.querySelectorAll('body *')).filter(el=>el.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(el=>({tag:el.tagName,cls:el.className,right:el.getBoundingClientRect().right})))}`);
      }
      await page.getByRole('button',{name:'Save planned workout',exact:true}).tap();
      await page.getByRole('alert').filter({hasText:'Seconds must be 00'}).waitFor();
      assert.equal(await page.getByLabel('Title',{exact:true}).inputValue(),originalTitle);
      await page.getByLabel('Planned duration (optional)',{exact:true}).fill('45:12.345');
      await page.getByRole('button',{name:'Save planned workout',exact:true}).tap();
      await page.waitForURL(/saved=1/);
      const createdPlanId=new URL(page.url()).searchParams.get('plan');
      await page.getByRole('status').filter({hasText:'Planned workout saved'}).waitFor();
      await page.getByRole('link',{name:'Edit planned workout',exact:true}).tap();
      await page.locator('#plan-form-title').waitFor();
      assert.equal(await page.getByLabel('Planned distance (optional)',{exact:true}).inputValue(),'500');
      assert.equal(await page.getByLabel('Planned duration (optional)',{exact:true}).inputValue(),'45:12.345');
      await page.getByLabel('Title',{exact:true}).fill(originalTitle+' edited');
      await page.getByRole('button',{name:'Save planned workout',exact:true}).tap();
      await page.waitForURL(/saved=1/);
      await page.getByRole('heading',{name:originalTitle+' edited',exact:true}).waitFor();
      await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
      await tapEntry(page.locator('a[data-plan-id="'+createdPlanId+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      const edit=page.getByRole('link',{name:'Edit planned workout',exact:true});
      await edit.tap();await page.locator('#plan-form-title').waitFor();
      await page.getByLabel('Title',{exact:true}).fill('Unsaved cancellation');
      await page.getByRole('link',{name:'Cancel',exact:true}).tap();
      await page.waitForURL(url=>!url.searchParams.has('edit_plan'));
      await page.locator('#calendar-title').waitFor();
      assert((await page.locator('.calendar-panel').textContent()).includes(originalTitle+' edited'));
      assert(!(await page.locator('.calendar-panel').textContent()).includes('Unsaved cancellation'));
      console.log(`${name}: create/edit plan, unit/duration round trip, retained invalid input, cancel and responsive form passed.`);
      // Dedicated Move/Copy controls: date confirmation, cancel, and target calendar.
      const targetDate=new Date(today+'T12:00:00Z');targetDate.setUTCDate(targetDate.getUTCDate()+2);
      const target=targetDate.toISOString().slice(0,10);
      await tapEntry(page.locator('a[data-plan-id="'+createdPlanId+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      await page.getByRole('link',{name:'Move planned workout',exact:true}).tap();
      await page.locator('#plan-action-title').waitFor();
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: move form overflow at ${width}`);
      }
      await page.getByLabel('Target date',{exact:true}).fill(target);
      await page.getByRole('button',{name:'Move workout',exact:true}).tap();
      await page.waitForURL(/plan_result=moved/);
      assert.equal(new URL(page.url()).searchParams.get('plan'),createdPlanId);
      assert.equal(new URL(page.url()).searchParams.get('date'),target);
      await page.getByRole('link',{name:'Copy planned workout',exact:true}).tap();
      await page.locator('#plan-action-title').waitFor();
      await page.getByRole('button',{name:'Copy workout',exact:true}).tap();
      await page.waitForURL(/plan_result=copied/);
      const copiedPlanId=new URL(page.url()).searchParams.get('plan');
      assert(copiedPlanId!==createdPlanId);
      await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      assert.equal(await page.locator('a[data-plan-id="'+createdPlanId+'"]').count(),1);
      assert.equal(await page.locator('a[data-plan-id="'+copiedPlanId+'"]').count(),1);
      await tapEntry(page.locator('a[data-plan-id="'+copiedPlanId+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      await page.getByRole('link',{name:'Move planned workout',exact:true}).tap();
      await page.locator('#plan-action-title').waitFor();
      await page.getByRole('link',{name:'Cancel',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      await closeDay();await page.getByRole('link',{name:'Today',exact:true}).tap();
      await page.waitForURL(url=>url.searchParams.get('date')===today);
      await page.locator('#calendar-title').waitFor();
      console.log(`${name}: move/copy forms, target date navigation, independent plan IDs, cancel and phone widths passed.`);
      // Native HTML drag/drop reschedules through the protected move handler.
      await page.setViewportSize({width:1440,height:1000});
      const dragDate=new Date(today+'T12:00:00Z');dragDate.setUTCDate(21);
      if(dragDate.toISOString().slice(0,10)<today)dragDate.setUTCMonth(dragDate.getUTCMonth()+1);
      dragDate.setUTCMonth(dragDate.getUTCMonth()+['chromium','firefox','webkit'].indexOf(name)*2);
      const from=dragDate.toISOString().slice(0,10);dragDate.setUTCDate(dragDate.getUTCDate()+1);const to=dragDate.toISOString().slice(0,10);
      await closeDay();await page.locator('.workouts-menu > summary').click();await page.getByRole('link',{name:'Plan Workout',exact:true}).click();
      await page.locator('#plan-form-title').waitFor();
      await page.getByLabel('Title',{exact:true}).fill('Synthetic drag '+name);
      await page.getByLabel('Planned date',{exact:true}).fill(from);
      await page.getByRole('button',{name:'Save planned workout',exact:true}).click();
      await page.waitForURL(/saved=1/);const dragId=new URL(page.url()).searchParams.get('plan');
      await page.getByRole('link',{name:'Back to calendar',exact:true}).click();
      await page.locator('#calendar-title').waitFor();
      await page.getByLabel('Go to date',{exact:true}).fill(from);
      await page.getByRole('button',{name:'Go',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('date')===from);
      let draggable=page.locator('a[data-plan-id="'+dragId+'"]').first();
      assert.equal(await draggable.getAttribute('draggable'),'true');
      assert.equal(await page.locator('a[data-plan-id="'+fixture.plans.matched+'"]').count() ? await page.locator('a[data-plan-id="'+fixture.plans.matched+'"]').getAttribute('draggable') : 'false','false');
      await draggable.dragTo(page.locator('.calendar-day[data-date="'+to+'"]'));
      await page.waitForURL(url=>url.searchParams.get('date')===to&&url.searchParams.get('plan_result')==='moved');
      assert.equal(new URL(page.url()).searchParams.has('plan'),false);
      assert.equal(await page.locator('.calendar-day[data-date="'+to+'"] a[data-plan-id="'+dragId+'"]').count(),1);
      assert.equal(await page.locator('#workout-dialog').evaluate(el=>el.open),false);
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Week',exact:true}).click();
      await page.waitForURL(/view=week/);
      const weekDays=await page.locator('.calendar-day').evaluateAll(days=>days.map(day=>day.dataset.date));
      const weekTarget=weekDays.find(date=>date>=today&&date!==to);
      draggable=page.locator('a[data-plan-id="'+dragId+'"]').first();
      const blocked=url=>url.searchParams.get('action')==='plan_drag_context'&&url.searchParams.get('id')===dragId;
      await page.route(blocked,route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Synthetic move preparation failed'})}));
      const beforeFailure=page.url();
      await draggable.dragTo(page.locator('.calendar-day[data-date="'+weekTarget+'"]'));
      await page.locator('[data-plan-drag-status]').filter({hasText:'Synthetic move preparation failed'}).waitFor();
      assert.equal(page.url(),beforeFailure);
      assert.equal(await page.locator('.calendar-day[data-date="'+to+'"] a[data-plan-id="'+dragId+'"]').count(),1);
      await page.unroute(blocked);
      await draggable.dragTo(page.locator('.calendar-day[data-date="'+weekTarget+'"]'));
      await page.waitForURL(url=>url.searchParams.get('date')===weekTarget&&url.searchParams.get('view')==='week'&&url.searchParams.get('plan_result')==='moved');
      assert.equal(await page.locator('.calendar-day[data-date="'+weekTarget+'"] a[data-plan-id="'+dragId+'"]').count(),1);
      await page.setViewportSize({width:320,height:900});
      await page.waitForFunction(()=>Array.from(document.querySelectorAll('a[data-plan-drag]')).every(el=>!el.draggable));
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Month',exact:true}).tap();
      await page.waitForURL(/view=month/);
      await closeDay();await page.getByRole('link',{name:'Today',exact:true}).tap();
      await page.waitForURL(url=>url.searchParams.get('date')===today);
      console.log(`${name}: native month/week drag/drop, failed preparation without writes, matched restrictions and phone fallback passed.`);



      // Reopening, route/no-GPS states, keyboard activation and retry.
      for(const type of ['open_water','running','walking']) {
        await tapEntry(page.locator('a[data-workout-id="'+fixture.workouts[type]+'"]'));
        await page.locator('#workout-dialog .workout-content').waitFor();
        if(type==='walking') assert((await page.locator('#workout-dialog').innerText()).includes('No usable GPS route'));
        else assert.equal(await page.locator('#workout-dialog polyline').count(),2);
        await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open);
      }
      await closeDay();
      const pool=page.locator('a[data-workout-id="'+fixture.workouts.pool+'"]').first();
      await reveal(pool);await pool.focus(); await page.keyboard.press('Enter');
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
      await tapEntry(page.locator('a[data-workout-id="'+fixture.workouts.walking+'"]'));
      await page.locator('#workout-dialog .workout-content').waitFor();
      await page.waitForTimeout(500);
      assert.equal(await page.locator('#workout-dialog [data-workout-id]').getAttribute('data-workout-id'),String(fixture.workouts.walking));
      await page.getByRole('button',{name:'Close workout details',exact:true}).tap();
      await page.unroute(matchDetail);
      await closeDay();
      console.log(`${name}: desktop pop-up, phone tap-on/off, Escape/Close/outside dismissal, focus/scroll restore, routes, retry and stale-response isolation passed.`);
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Week',exact:true}).tap();
      await page.waitForURL(/view=week/);
      assert.equal(await page.locator('.calendar-day').count(),7);
      await closeDay();await page.getByRole('link',{name:'Next week',exact:true}).tap();
      await closeDay();await page.getByRole('link',{name:'Today',exact:true}).tap();
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Month',exact:true}).tap();
      await page.waitForURL(/view=month/);
      assert((await page.locator('.calendar-day').count())>=28);
      await page.locator('.evaluation-menu>summary').tap();
      assert.equal(await page.getByRole('link',{name:'Personal Bests',exact:true}).getAttribute('href'),'/?screen=bests');
      await page.locator('.evaluation-menu>summary').tap();
      console.log(`${name}: branded calendar, owner privacy, desktop/phone widths, touch details, month/week navigation passed.`);
      await closeDay();await page.locator('.workouts-menu>summary').tap();
      await closeDay();await page.getByRole('navigation',{name:'Main navigation'}).getByRole('link',{name:'Workout Log',exact:true}).tap();
      await page.locator('#log-title').waitFor();
      assert((await page.locator('.log-count').innerText()).includes('31 matching workouts'));
      assert.equal(await page.locator('[data-log-row]').count(),20);
      const totals=await page.locator('.log-totals').innerText();
      assert(totals.includes('10,841.44 m') && totals.includes('Distance unavailable'));
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: workout log overflow at ${width}`);
        const entry=page.locator('a[data-workout-id="'+fixture.workouts.pool+'"]').first();
        await reveal(entry);await entry.scrollIntoViewIfNeeded();
        const url=page.url(),before=await page.evaluate(()=>scrollY);
        if(width<=390)await entry.tap();else await entry.click();
        await page.locator('#workout-dialog .workout-content').waitFor();
        if(width<=390)await page.touchscreen.tap(3,3);else await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open);
        assert.equal(page.url(),url);
        assert(Math.abs(await page.evaluate(()=>scrollY)-before)<=3);await closeDay();
        await page.locator('.evaluation-menu>summary').click();
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        await page.locator('.evaluation-menu>summary').click();
      }
      await page.getByRole('link',{name:'Next page →',exact:true}).tap();
      await page.waitForURL(/log_page=2/);
      assert.equal(await page.locator('[data-log-row]').count(),11);
      assert.equal(await page.locator('.log-totals').innerText(),totals);
      await page.getByLabel('Activity',{exact:true}).selectOption('running');
      await page.getByRole('button',{name:'Apply filters',exact:true}).tap();
      await page.waitForURL(/type=running/);
      assert.equal(await page.locator('[data-log-row]').count(),2);
      assert((await page.locator('.workout-log-table').innerText()).includes('0 m'));
      await page.getByLabel('From',{exact:true}).fill('1900-01-01');
      await page.getByLabel('To',{exact:true}).fill('1900-01-02');
      await page.getByRole('button',{name:'Apply filters',exact:true}).tap();
      await page.getByRole('heading',{name:'No workouts match these filters',exact:true}).waitFor();
      await page.getByRole('link',{name:'All history',exact:true}).tap();
      await page.waitForURL(/from=&to=&type=all/);
      assert((await page.locator('.log-count').innerText()).includes('31 matching workouts'));
      console.log(`${name}: workout log filters, all-page totals, pagination, empty state, desktop/mobile layout and tap-off details passed.`);
      // Match a planned open-water swim with explicit confirmation from the calendar.
      await page.getByRole('navigation',{name:'Main navigation'}).getByRole('link',{name:'Calendar',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      await closeDay();await page.getByRole('navigation',{name:'Calendar view'}).getByRole('link',{name:'Week',exact:true}).tap();
      await page.waitForURL(/view=week/);
      await closeDay();await page.locator('.workouts-menu > summary').tap();await page.getByRole('link',{name:'Plan Workout',exact:true}).tap();
      await page.locator('#plan-form-title').waitFor();
      await page.getByLabel('Title',{exact:true}).fill('Synthetic match '+name);
      await page.getByLabel('Swim type',{exact:true}).selectOption('open_water');
      await page.getByRole('button',{name:'Save planned workout',exact:true}).tap();
      await page.waitForURL(/saved=1/);const matchPlanId=new URL(page.url()).searchParams.get('plan');
      await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      await tapEntry(page.locator('a[data-plan-id="'+matchPlanId+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      const matchLink=page.getByRole('link',{name:'Match recorded workout',exact:true});
      assert((await matchLink.getAttribute('href')).includes('view=week'));
      await matchLink.tap();await page.locator('#plan-match-title').waitFor();
      assert.equal(await page.locator('[data-candidate-id]').count(),1);
      assert.equal(await page.locator('input[name=recorded_choice]:checked').count(),0);
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: match form overflow at ${width}`);
      }
      await page.getByLabel('Recorded date',{exact:true}).fill('1900-01-01');
      await page.getByRole('button',{name:'Show swims',exact:true}).tap();
      await page.locator('.match-empty').waitFor();
      await page.getByLabel('Recorded date',{exact:true}).fill(today);
      await page.getByRole('button',{name:'Show swims',exact:true}).tap();
      await page.locator('[data-candidate-id="'+fixture.workouts.open_water+'"]').waitFor();
      await page.getByRole('link',{name:'Cancel',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      assert(!(await page.locator('a[data-plan-id="'+matchPlanId+'"]').getAttribute('class')).includes('is-matched'));
      await tapEntry(page.locator('a[data-plan-id="'+matchPlanId+'"]'));
      await page.locator('#workout-dialog .planned-content').waitFor();
      await page.getByRole('link',{name:'Match recorded workout',exact:true}).tap();
      await page.locator('#plan-match-title').waitFor();
      await page.locator('[data-candidate-id="'+fixture.workouts.open_water+'"] input').check();
      await page.getByRole('button',{name:'Confirm match',exact:true}).tap();
      await page.waitForURL(/plan_result=matched/);
      assert.equal(await page.getByRole('link',{name:'Edit planned workout',exact:true}).count(),0);
      assert.equal(await page.getByRole('link',{name:'Match recorded workout',exact:true}).count(),0);
      assert((await page.getByRole('link',{name:'View matched recorded workout',exact:true}).getAttribute('href')).includes('workout='+fixture.workouts.open_water));
      await page.getByRole('link',{name:'Back to calendar',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      assert((await page.locator('a[data-plan-id="'+matchPlanId+'"]').getAttribute('class')).includes('is-matched'));
      assert((await page.locator('a[data-workout-id="'+fixture.workouts.open_water+'"]').getAttribute('class')).includes('is-matched'));
      assert.equal(await page.locator('a[data-plan-id="'+matchPlanId+'"]').getAttribute('draggable'),'false');
      console.log(`${name}: matching from calendar, owner/type candidates, empty date, cancellation, explicit confirmation and completed highlighting passed.`);
      // Events share the same desktop pop-up and mobile tap-off behavior.
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        const entry=page.locator('a[data-event-id="'+fixture.events.own+'"]').first();
        await reveal(entry);await entry.scrollIntoViewIfNeeded();const before=await page.evaluate(()=>scrollY);
        if(width<=390)await entry.tap();else await entry.click();
        await page.locator('#workout-dialog .event-content').waitFor();
        assert.equal(await page.locator('#workout-dialog-title').innerText(),'Event details');
        assert((await page.locator('#workout-dialog').innerText()).includes('1:23.456'));
        assert((await page.locator('#workout-dialog').innerText()).includes('PRIVATE_EVENT_NOTES_CANARY'));
        assert(!(await page.locator('#workout-dialog').innerText()).includes('PRIVATE_LOCATION_NOTES_CANARY'));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        if(width<=390)await page.touchscreen.tap(3,3);else await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.getElementById('workout-dialog').open&&document.querySelector('[data-workout-content]').childElementCount===0);
        assert(await entry.evaluate(el=>document.activeElement===el));
        assert(Math.abs(await page.evaluate(()=>scrollY)-before)<=3);await closeDay();
      }
      await closeDay();
      await page.locator('.events-menu>summary').click();
      await page.getByRole('link',{name:'List Events',exact:true}).click();
      await page.locator('#events-title').waitFor();
      await page.getByRole('link',{name:'All',exact:true}).click();
      assert((await page.locator('.event-list').textContent()).includes('Synthetic meet'));
      assert(!(await page.locator('.event-list').textContent()).includes('PRIVATE_EVENT_NOTES_CANARY'));
      assert(!(await page.locator('.event-list').textContent()).includes('FOREIGN_PRIVATE_EVENT'));
      await tapEntry(page.locator('.event-list a[data-event-id="'+fixture.events.own+'"]'));
      await page.locator('#workout-dialog .event-content').waitFor();
      await page.getByRole('button',{name:'Close event details',exact:true}).click();
      await page.getByRole('link',{name:'Calendar',exact:true}).click();
      await page.locator('#calendar-title').waitFor();
      await page.locator('[data-day-open]:visible').first().waitFor();
      assert(await page.locator('[data-day-open]:visible').count()>0);
      await page.locator('[data-day-open]:visible').first().click();
      assert(await page.locator('#calendar-day-dialog [data-day-items] .calendar-entry').count()>1);
      await closeDay();
      await page.locator('.events-menu > summary').tap();await page.getByRole('link',{name:'Add Event',exact:true}).tap();
      await page.locator('#event-form-title').waitFor();
      const eventName='Synthetic browser event '+name;
      await page.getByLabel('Event name',{exact:true}).fill(eventName);
      await page.getByLabel('Event distance',{exact:true}).selectOption('100');
      await page.getByLabel('Event course',{exact:true}).selectOption('yd');
      await page.getByLabel('Event stroke',{exact:true}).selectOption('BR');
      await page.getByLabel('Event location',{exact:true}).selectOption(String(fixture.events.location));
      await page.getByLabel('Event notes (optional)',{exact:true}).fill('Synthetic event notes with a quote " and backslash \\');
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}: event editor overflow at ${width}`);
      }
      await page.getByRole('button',{name:'Save event',exact:true}).tap();
      await page.waitForURL(/event_saved=event/);const eventId=new URL(page.url()).searchParams.get('event');
      await page.getByRole('link',{name:'Record race result',exact:true}).tap();
      await page.locator('#event-form-title').waitFor();
      await page.getByLabel('Race time (optional)',{exact:true}).fill('1:99');
      await page.getByRole('button',{name:'Save race result',exact:true}).tap();
      await page.getByRole('alert').filter({hasText:'Enter a race time'}).waitFor();
      assert.equal(await page.getByLabel('Race time (optional)',{exact:true}).inputValue(),'1:99');
      await page.getByLabel('Race time (optional)',{exact:true}).fill('1:23.456');
      await page.getByLabel('Placement (optional)',{exact:true}).fill('2');
      await page.getByLabel('Event notes (optional)',{exact:true}).fill('Synthetic race notes');
      await page.getByRole('button',{name:'Save race result',exact:true}).tap();
      await page.waitForURL(/event_saved=result/);
      assert((await page.locator('.event-content').innerText()).includes('1:23.456'));
      await page.getByRole('link',{name:'Edit event',exact:true}).tap();
      await page.locator('#event-form-title').waitFor();
      await page.getByLabel('Event name',{exact:true}).fill(eventName+' edited');
      await page.getByRole('button',{name:'Save event',exact:true}).tap();
      await page.waitForURL(/event_saved=event/);
      assert((await page.locator('.event-content').innerText()).includes('1:23.456'));
      assert((await page.locator('.event-content').innerText()).includes('Synthetic race notes'));
      await page.getByRole('link',{name:'Record race result',exact:true}).tap();
      await page.locator('#event-form-title').waitFor();
      assert.equal(await page.getByLabel('Race time (optional)',{exact:true}).inputValue(),'1:23.456');
      await page.getByRole('link',{name:'Cancel',exact:true}).tap();
      await page.locator('#calendar-title').waitFor();
      await tapEntry(page.locator('a[data-event-id="'+eventId+'"]'));
      await page.locator('#workout-dialog .event-content').waitFor();
      assert((await page.locator('#workout-dialog').innerText()).includes(eventName+' edited'));
      await page.getByRole('button',{name:'Close event details',exact:true}).tap();
      await page.waitForFunction(()=>!document.getElementById('workout-dialog').open);
      console.log(`${name}: event pop-ups, phone dismissal, create/edit, exact race times, notes preservation, validation and cancellation passed.`);
      await page.goto('https://swimlog.example.test/?screen=structures');
      await page.locator('#structures-title').waitFor();
      assert(!(await page.locator('.structured-page').textContent()).includes('FOREIGN_STRUCTURE_CANARY'));
      await page.locator('.workouts-menu>summary').click();
      await page.locator('.structured-submenu>summary').click();
      await page.locator('.structured-submenu').getByRole('link',{name:'Add Structured Workout',exact:true}).click();
      await page.locator('#swimlog-structure-editor [data-swimlog-preview]').waitFor();
      const structureTitle='Synthetic standalone structure '+name;
      await page.getByLabel('Workout title',{exact:true}).fill(structureTitle);
      await page.locator('.swimlog-structure-unit select').selectOption('yd');
      await page.getByRole('button',{name:'+ Repeat',exact:true}).first().click();
      for(const width of [1440,768,390,320]) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': structured editor overflow '+width);
      }
      await page.getByRole('button',{name:'Save structured workout',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('structure_saved')==='save');
      await page.getByRole('heading',{name:structureTitle,exact:true}).waitFor();
      assert((await page.locator('.swimlog-prescription-preview').textContent()).includes('yd'));
      await page.getByRole('link',{name:'Edit structured workout',exact:true}).click();
      await page.locator('#swimlog-structure-editor [data-swimlog-preview]').waitFor();
      await page.getByLabel('Workout title',{exact:true}).fill(structureTitle+' edited');
      await page.getByRole('button',{name:'Save structured workout',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('structure_saved')==='save');
      await page.getByRole('button',{name:'Duplicate workout',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('structure_saved')==='duplicate');
      await page.getByRole('heading',{name:structureTitle+' edited (copy)',exact:true}).waitFor();
      await page.getByRole('button',{name:'Hide workout',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('visibility')==='hidden');
      await page.getByRole('link',{name:structureTitle+' edited (copy)',exact:true}).click();
      await page.getByRole('button',{name:'Unhide workout',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('structure_saved')==='unhide');
      await page.getByRole('link',{name:structureTitle+' edited (copy)',exact:true}).click();
      const textLink=page.getByRole('link',{name:'Download text',exact:true});
      if(name==='webkit') {
        // Verify the authenticated attachment in the browser; native download events
        // are unreliable for WebKit through this disposable self-signed TLS proxy.
        const attachment=await textLink.evaluate(async el=>{const r=await fetch(el.href,{credentials:'same-origin',cache:'no-store'});return {status:r.status,type:r.headers.get('Content-Type'),disposition:r.headers.get('Content-Disposition'),body:await r.text()};});
        assert.equal(attachment.status,200);assert(attachment.type.includes('text/plain'));assert(attachment.disposition.includes('attachment;'));assert(attachment.body.includes(structureTitle+' edited (copy)'));
      } else {
        const downloadPromise=page.waitForEvent('download');await textLink.click();
        const download=await downloadPromise;assert(download.suggestedFilename().endsWith('.txt'));
      }
      await page.getByText('Delete unused workout',{exact:true}).click();
      await page.getByLabel('Confirm permanent deletion',{exact:true}).check();
      await page.getByRole('button',{name:'Delete permanently',exact:true}).click();
      await page.waitForURL(url=>url.searchParams.get('structure_saved')==='delete');
      assert(!(await page.locator('.structured-page').textContent()).includes(structureTitle+' edited (copy)'));
      console.log(name+': structured nested menu, plugin editor, create/edit/copy, visibility, text export, confirmed deletion and phone widths passed.');
      await closeDay();await page.getByRole('button', {name:'Log out', exact:true}).click();
      await page.getByText('You have been signed out.', {exact:true}).waitFor();
      assert.equal(posts.filter(post=>post.path==='?action=logout').slice(-1)[0].origin, 'https://swimlog.example.test');
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
      await closeDay();await page.getByRole('button', {name:'Log out', exact:true}).click();
      await page.getByText('You have been signed out.', {exact:true}).waitFor();
      console.log(`${name}: failed WordPress login returns to sign-in; correct retry opens calendar automatically.`);
      await context.close();
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error.stack); process.exitCode=1; });
