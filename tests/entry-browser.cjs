const fs=require('node:fs');const assert=require('node:assert/strict');const engines=require('playwright');
(async()=>{
  const fixture=JSON.parse(fs.readFileSync(process.env.SWIMLOG_WEB_FIXTURE,'utf8'));const name=process.env.SWIMLOG_BROWSER||'chromium';const browser=await engines[name].launch({headless:true});
  try {
    const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});
    await context.addCookies([{name:fixture.name,value:fixture.entry_cookie,url:'https://wordpress.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);
    const page=await context.newPage();await page.goto('https://swimlog.example.test/');await page.getByRole('button',{name:'Sign in with wordpress.example.test',exact:true}).click();await page.locator('#calendar-title').waitFor();
    for(const width of [1440,1024,768,390,320]) {
      await page.setViewportSize({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
      const events=page.locator('.events-menu'),workouts=page.locator('.workouts-menu'),tools=page.locator('.wordpress-menu');
      const anchored=async tree=>{
        await page.waitForFunction(()=>Array.from(document.querySelectorAll('.top-nav > details[open]')).every(tree=>{
          const panel=tree.querySelector(':scope > .tools-links').getBoundingClientRect(),button=tree.querySelector(':scope > summary').getBoundingClientRect(),nav=document.querySelector('.top-nav').getBoundingClientRect();
          if(innerWidth<=700)return panel.right<=innerWidth+1;
          const expected=Math.max(12,Math.min(button.left,innerWidth-12-panel.width));
          return Math.abs(panel.left-expected)<=2&&panel.top>=nav.bottom&&panel.right<=innerWidth-10;
        }));
      };
      await events.locator(':scope > summary').click();assert(await events.evaluate(el=>el.open));await anchored(events);
      await workouts.locator(':scope > summary').click();assert(await workouts.evaluate(el=>el.open));assert(!await events.evaluate(el=>el.open));await anchored(workouts);
      await page.locator('.structured-submenu>summary').click();assert(await page.locator('.structured-submenu').evaluate(el=>el.open));await anchored(workouts);
      await tools.locator(':scope > summary').click();assert(await tools.evaluate(el=>el.open));await anchored(tools);assert(!await workouts.evaluate(el=>el.open));assert(!await page.locator('.structured-submenu').evaluate(el=>el.open));
      assert.equal(await tools.getByRole('link',{name:'Manage workouts',exact:true}).count(),0);
      assert.equal(await tools.getByRole('link',{name:'Planned workouts',exact:true}).count(),0);
      assert.equal(await tools.getByRole('link',{name:'Structured workouts',exact:true}).count(),0);
      assert.equal(await tools.getByRole('link',{name:'Personal bests',exact:true}).count(),1);
      await tools.locator(':scope > summary').focus();await page.keyboard.press('Escape');assert(!await tools.evaluate(el=>el.open));
      await page.keyboard.press('Enter');assert(await tools.evaluate(el=>el.open));await anchored(tools);
      await page.keyboard.press('Escape');assert(!await tools.evaluate(el=>el.open));
    }
    await page.locator('.workouts-menu>summary').click();await page.getByRole('link',{name:'Add Workout',exact:true}).click();await page.locator('[data-manual-entry]').waitFor();
    await page.getByLabel('Workout date',{exact:true}).fill('2001-03-04');await page.getByLabel('Start time (optional)',{exact:true}).fill('08:00');
    await page.getByLabel('Pool length',{exact:true}).fill('25');await page.getByLabel('Total distance (pool unit)',{exact:true}).fill('100');await page.getByLabel('Workout duration (optional)',{exact:true}).fill('2:00');
    await page.getByLabel('Workout notes (optional)',{exact:true}).fill('Synthetic '+name+' manual entry');
    await page.getByText('Workout sets (optional)',{exact:true}).click();
    await page.getByRole('button',{name:'Add set',exact:true}).click();assert.equal(await page.locator('[data-set-list] [data-manual-set]').count(),2);
    await page.getByRole('button',{name:'Move set up',exact:true}).last().click();await page.getByRole('button',{name:'Remove set',exact:true}).last().click();
    await page.getByLabel('Set 1: Repetitions',{exact:true}).fill('1');await page.getByLabel('Set 1: Distance per repetition (pool unit)',{exact:true}).fill('100');
    await page.getByLabel('Set 1: Stroke',{exact:true}).selectOption('FR');await page.getByLabel('Set 1: Timing mode',{exact:true}).selectOption('measured_rep');
    await page.getByLabel('Set 1: Measured repetition times (comma separated)',{exact:true}).fill('2:00');
    for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': manual overflow '+width);}
    await page.getByRole('button',{name:'Save workout',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('entry_saved')==='manual');
    await page.getByText('Manual workout saved.',{exact:true}).waitFor();assert((await page.locator('.workout-page').textContent()).includes('100 m'));
    await page.locator('.workouts-menu>summary').click();await page.getByRole('link',{name:'Import Workout',exact:true}).click();await page.locator('.workout-upload').waitFor();
    const payload=Buffer.from('Swim Date,Start,End,Pool Size,Dist M\n2001-03-04,08:00:10,08:02:10,25 m,100\nSwim Date,Swim Time,Length M,Move Time,Strk\n'+('2001-03-04,08:00:10,25,31,FR\n'.repeat(4)));
    for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': import overflow '+width);}
    await page.getByLabel('Workout files',{exact:true}).setInputFiles({name:'synthetic-'+name+'.csv',mimeType:'text/csv',buffer:payload});
    await page.getByRole('button',{name:'Upload and import',exact:true}).click();await page.waitForURL(url=>url.searchParams.has('receipt'));
    await page.locator('.review-form').waitFor();assert((await page.locator('.import-review').textContent()).includes('Possible matching workout'));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': review overflow');
    await page.getByRole('button',{name:'Import as separate workout',exact:true}).click();await page.getByText('Reviewed source: Workout imported.',{exact:false}).waitFor();
    assert.equal(await page.locator('.review-form').count(),0);
    const editId=new URL(await page.locator('.workout-page a[href^="/?workout="]').first().getAttribute('href'),'https://swimlog.example.test').searchParams.get('workout');
    await page.goto('https://swimlog.example.test/?edit_workout='+editId);await page.getByLabel('Notes',{exact:true}).fill('Synthetic browser editor '+name);
    await page.getByLabel('Make Workout Public',{exact:true}).check();
    await page.getByLabel('Photo 1',{exact:true}).setInputFiles({name:'synthetic.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();await page.getByText('Workout action completed.',{exact:true}).waitFor();assert(await page.getByLabel('Make Workout Public',{exact:true}).isChecked());assert.equal(await page.locator('.workout-photo-preview').count(),1);
    for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': workout editor overflow '+width);}
    await page.getByLabel('Delete photo 1',{exact:true}).check();await page.getByLabel('Make Workout Public',{exact:true}).uncheck();await page.getByRole('button',{name:'Save Changes',exact:true}).click();await page.getByText('Workout action completed.',{exact:true}).waitFor();assert.equal(await page.locator('.workout-photo-preview').count(),0);assert(!await page.getByLabel('Make Workout Public',{exact:true}).isChecked());
    await page.getByRole('link',{name:'Create structured prescription from this swim',exact:true}).click();await page.getByText('Review recorded-swim conversion',{exact:true}).waitFor();await page.getByLabel('Confirm creation of a new private prescription; preserve the recorded swim and personal bests',{exact:true}).check();await page.getByRole('button',{name:'Confirm and create private prescription',exact:true}).click();await page.waitForURL(u=>u.searchParams.has('structure'));
    await page.goto('https://swimlog.example.test/?edit_workout='+editId);await page.getByLabel('Confirm rebuild from preserved sources',{exact:true}).check();await page.getByRole('button',{name:'Rebuild from Preserved Sources',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('workout_saved')==='rebuild');
    await page.getByLabel('Confirm permanent deletion of this workout',{exact:true}).check();await page.getByRole('button',{name:'Delete Workout',exact:true}).click();await page.waitForURL(u=>u.searchParams.has('workout_deleted'));
    console.log(name+': workout editor, photo upload/removal, saved public preference, conversion, rebuild, confirmed deletion and phone widths passed.');
    console.log(name+': exclusive menu trees, manual sets/timing/save, genuine multipart import, pending comparison/separate resolution and phone widths passed.');await context.close();
  } finally {await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
