const fs=require('node:fs'),assert=require('node:assert/strict'),engines=require('playwright');
(async()=>{
 const f=JSON.parse(fs.readFileSync('/tmp/swimlog-sharing.json','utf8')),name=process.env.SWIMLOG_BROWSER||'chromium',browser=await engines[name].launch({headless:true});
 try{
 const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});
 await context.addCookies([{name:f.name,value:f.cookie,url:'https://swimlog.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage();await page.goto('https://swimlog.example.test/?edit_workout='+f.ids.pool);
 const copy=page.getByRole('button',{name:'Copy Public Link',exact:true});assert(await copy.isEnabled());
 await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.syntheticClipboard=text;}}}));
 await copy.click();await page.getByText('Public link copied.',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.syntheticClipboard),'https://swimlog.example.test/?public_workout='+f.ids.pool);
 await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:undefined}));
 await copy.click();await page.getByText('Clipboard access is unavailable. The public link is selected; copy it manually.',{exact:true}).waitFor();
 assert.equal(await page.locator('[data-public-link]').evaluate(el=>el.selectionEnd-el.selectionStart),('https://swimlog.example.test/?public_workout='+f.ids.pool).length);
 for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'sharing editor overflow '+width);}
 await page.getByLabel('Make Workout Public',{exact:true}).uncheck();assert(await copy.isDisabled());assert(await page.getByLabel('Share route publicly',{exact:true}).isDisabled());
 await page.getByRole('button',{name:'Save workout sharing',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('workout_saved')==='visibility');assert(await page.getByRole('button',{name:'Copy Public Link',exact:true}).isDisabled());
 const anonymous=await browser.newContext({ignoreHTTPSErrors:true,proxy:{server:'http://127.0.0.1:8088'}}),publicPage=await anonymous.newPage();
 let r=await publicPage.goto('https://swimlog.example.test/?public_workout='+f.ids.pool);assert.equal(r.status(),404);
 await page.getByLabel('Make Workout Public',{exact:true}).check();assert(await page.getByRole('button',{name:'Copy Public Link',exact:true}).isDisabled());
 await page.getByRole('button',{name:'Save workout sharing',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('workout_saved')==='visibility');assert(await page.getByRole('button',{name:'Copy Public Link',exact:true}).isEnabled());
 r=await publicPage.goto('https://swimlog.example.test/?public_workout='+f.ids.pool);assert.equal(r.status(),200);assert((await r.headerValue('cache-control')).includes('no-store'));
 assert.equal(await publicPage.locator('form').count(),0);assert(!(await publicPage.content()).includes('PRIVATE_'));
 for(const width of [1440,768,390,320]){await publicPage.setViewportSize({width,height:900});assert(await publicPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'public overflow '+width);}
 // Another tab revokes the grant; stale copy must be rejected before touching clipboard.
 const other=await context.newPage();await other.goto('https://swimlog.example.test/?edit_workout='+f.ids.pool);await other.getByLabel('Make Workout Public',{exact:true}).uncheck();await other.getByRole('button',{name:'Save workout sharing',exact:true}).click();await other.waitForURL(u=>u.searchParams.get('workout_saved')==='visibility');
 await page.getByRole('button',{name:'Copy Public Link',exact:true}).click();await page.getByText('Sharing changed. Reload and review the current settings.',{exact:true}).waitFor();assert(await page.getByRole('button',{name:'Copy Public Link',exact:true}).isDisabled());
 r=await publicPage.reload();assert.equal(r.status(),404);
 console.log(name+': canonical sharing save/revoke, dirty/stale copy denial, clipboard success/fallback, anonymous whitelist and desktop/mobile layouts passed.');
 await anonymous.close();await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
