const fs=require('node:fs'),assert=require('node:assert/strict'),engines=require('playwright');
(async()=>{
 const f=JSON.parse(fs.readFileSync('/tmp/swimlog-associations.json','utf8')),name=process.env.SWIMLOG_BROWSER||'chromium',browser=await engines[name].launch({headless:true});
 try{
 const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});await context.addCookies([{name:f.name,value:f.cookie,url:'https://swimlog.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);const page=await context.newPage(),a=f.pairs.pool,b=f.pairs.open_water;
 await page.goto('https://swimlog.example.test/?date=2026-01-01&view=week');await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
 await page.locator('a[data-workout-id="'+a.workout+'"]').first().click();await page.locator('#workout-dialog .association-controls').waitFor();
 const dialog=page.locator('#workout-dialog');await dialog.getByLabel('Confirm removing the structured link',{exact:true}).check();await dialog.getByRole('button',{name:'Unlink structured workout',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('association_unlinked')==='structure');await page.waitForLoadState('load');
 assert.equal(await page.getByRole('button',{name:'Unlink structured workout',exact:true}).count(),0);
 for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'association controls overflow '+width);}
 await page.getByLabel('Confirm removing the planned match',{exact:true}).check();await page.getByRole('button',{name:'Unlink planned workout',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('association_unlinked')==='match');
 await page.goto('https://swimlog.example.test/?plan='+a.plan);await page.getByText('No recorded workout is matched to this plan.',{exact:true}).waitFor();
 await page.goto('https://swimlog.example.test/?plan='+b.plan);await page.getByLabel('Confirm removing the planned match',{exact:true}).check();await page.getByRole('button',{name:'Unlink planned workout',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('association_unlinked')==='match');await page.waitForLoadState('load');
 await page.getByLabel('Confirm removing the structured link',{exact:true}).check();await page.getByRole('button',{name:'Unlink structured workout',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('association_unlinked')==='structure');
 await page.goto('https://swimlog.example.test/?screen=structures&edit_structure='+b.structure);assert((await page.content()).includes('locked'));
 console.log(name+': recorded pop-up and planned-page unlink controls, both removal orders, preserved records/history lock and phone widths passed.');await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
