const fs=require('node:fs'),assert=require('node:assert/strict'),engines=require('playwright');
(async()=>{
 const f=JSON.parse(fs.readFileSync('/tmp/swimlog-sharing.json','utf8')),name=process.env.SWIMLOG_BROWSER||'chromium',browser=await engines[name].launch({headless:true});
 try {
 const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});
 await context.addCookies([{name:f.name,value:f.cookie,url:'https://swimlog.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage();let r=await page.goto('https://swimlog.example.test/?screen=bests');assert.equal(r.status(),200);
 assert.equal(await page.locator('.wordpress-menu').count(),0);assert.equal(await page.locator('.main-site-link').getAttribute('href'),'https://wordpress.example.test/');
 for(const width of [1440,1024,768,390,320]) {
 await page.setViewportSize({width,height:900});await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'PB overflow '+width);
 const menu=page.locator('.evaluation-menu');await menu.locator('summary').click();assert(await menu.evaluate(el=>el.open));
 await menu.getByRole('link',{name:'Personal Bests',exact:true}).click();await page.locator('#personal-bests-title').waitFor();
 }
 await page.getByLabel('Course',{exact:true}).selectOption('yd');await page.getByRole('button',{name:'View records',exact:true}).click();assert.equal(new URL(page.url()).searchParams.get('course'),'yd');
 await page.getByText('No personal bests are available for this record period and course.',{exact:true}).waitFor();
 await page.getByLabel('Course',{exact:true}).selectOption('m');await page.getByRole('button',{name:'View records',exact:true}).click();
 const record=page.locator('[aria-label="Swimming personal bests"] a').first();await record.click();await page.locator('#workout-dialog').waitFor({state:'visible'});await page.locator('[data-workout-close]').click();
 console.log(name+': private Personal Bests filters, workout pop-up, navigation and desktop/mobile widths passed.');await context.close();
 } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
