const fs=require('node:fs'),assert=require('node:assert/strict'),engines=require('playwright');
(async()=>{
 const f=JSON.parse(fs.readFileSync('/tmp/swimlog-analytics.json','utf8')),name=process.env.SWIMLOG_BROWSER||'chromium',browser=await engines[name].launch({headless:true});
 try{
 const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});await context.addCookies([{name:f.name,value:f.cookie,url:'https://swimlog.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);const page=await context.newPage();
 console.log(name+': analytics browser started');
 for(const screen of ['trends','pool']){
 console.log(name+': loading '+screen);
 const r=await page.goto('https://swimlog.example.test/?screen='+screen);assert.equal(r.status(),200);
 for(const width of [1440,768,390,320]){await page.setViewportSize({width,height:900});await page.evaluate(()=>Promise.race([new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Analytics layout frames did not settle')),5000))]));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),screen+' overflow '+width);}
 await page.getByLabel('Period (months)',{exact:true}).fill('3');await page.getByRole('button',{name:'Apply filters',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('months')==='3');await page.waitForLoadState('load');
 }
 console.log(name+': testing additional metrics');
 await page.getByLabel('Show additional metrics',{exact:true}).check();await page.getByRole('button',{name:'Apply filters',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('additional_metrics')==='1');await page.waitForLoadState('load');await page.getByRole('columnheader',{name:'Lengths with stroke counts (%)',exact:true}).waitFor();
 await page.getByLabel('Course',{exact:true}).selectOption('yd');await page.getByRole('button',{name:'Apply filters',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('course')==='yd');await page.waitForLoadState('load');assert((await page.content()).includes('25.00 yd'));
 await page.getByLabel('Stroke',{exact:true}).selectOption('FLY');await page.getByRole('button',{name:'Apply filters',exact:true}).click();await page.getByText('No qualifying active length data are available for these filters.',{exact:true}).waitFor();
 console.log(name+': testing related-report navigation');
 await page.getByRole('navigation',{name:'Related swimming reports',exact:true}).getByRole('link',{name:'Training Trends',exact:true}).click();await page.locator('.swim-trend-chart').waitFor();
 console.log(name+': private swim trends and pool analytics, metrics/help, report links, filters and desktop/mobile widths passed.');await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
