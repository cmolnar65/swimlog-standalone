const fs=require('node:fs'),assert=require('node:assert/strict'),engines=require('playwright');
(async()=>{
 const f=JSON.parse(fs.readFileSync(process.env.SWIMLOG_WEB_FIXTURE,'utf8')),name=process.env.SWIMLOG_BROWSER||'chromium',browser=await engines[name].launch({headless:true});
 try{
 const context=await browser.newContext({ignoreHTTPSErrors:true,hasTouch:true,proxy:{server:'http://127.0.0.1:8088'}});
 await context.addCookies([{name:f.name,value:f.settings_cookie,url:'https://wordpress.example.test/',secure:true,httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage();await page.goto('https://swimlog.example.test/');await page.getByRole('button',{name:'Sign in with wordpress.example.test',exact:true}).click();await page.locator('#calendar-title').waitFor();
 await page.goto('https://swimlog.example.test/?screen=garmin_setup');assert.equal(await page.locator('.garmin-menu').count(),0);
 await page.getByLabel('Garmin Personal API key',{exact:true}).fill('synthetic-settings-key');await page.getByRole('button',{name:'Save API key',exact:true}).click();await page.getByRole('button',{name:'Verify connection',exact:true}).click();await page.getByText('Connection: Verified',{exact:true}).waitFor();
 assert.equal(await page.getByLabel('Garmin Personal API key',{exact:true}).inputValue(),'');
 for(const width of [1440,1024,768,390,320]){
 await page.setViewportSize({width,height:900});
 for(const selector of ['.settings-menu','.garmin-menu','.wordpress-menu']){
 const menu=page.locator(selector);await menu.locator(':scope > summary').click();
 await page.waitForFunction(()=>document.querySelectorAll('.top-nav > details[open]').length===1);
 assert(await menu.evaluate(el=>el.open));await page.waitForTimeout(60);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' overflow '+width);
 if(width>700){const panel=await menu.locator(':scope > .tools-links').boundingBox(),button=await menu.locator(':scope > summary').boundingBox();assert(Math.abs(panel.x-Math.max(12,Math.min(button.x,width-12-panel.width)))<=2);}
 }
 await page.keyboard.press('Escape');
 }
 const tools=page.locator('.wordpress-menu');assert.equal(await tools.locator('a').count(),1);assert.equal(await tools.locator('a').textContent(),'Personal bests');
 await page.goto('https://swimlog.example.test/?screen=swimmer');await page.getByLabel('First day of calendar week',{exact:true}).selectOption('monday');await page.getByRole('button',{name:'Save swimmer configuration',exact:true}).click();await page.getByText('Settings saved.',{exact:true}).waitFor();
 await page.goto('https://swimlog.example.test/?screen=locations');await page.getByLabel('Location name',{exact:true}).fill('Synthetic '+name+' pool');await page.getByLabel('Pool length (optional)',{exact:true}).fill('25');await page.getByLabel('Pool unit',{exact:true}).selectOption('m');await page.getByRole('button',{name:'Save location',exact:true}).click();await page.getByText('Settings saved.',{exact:true}).waitFor();
 await page.goto('https://swimlog.example.test/?screen=garmin_activities');await page.getByRole('button',{name:'Find activities',exact:true}).click();await page.getByText(/Synthetic Garmin pool/).waitFor();
 assert(!(await page.content()).includes('PRIVATE_GARMIN_ROUTE_CANARY'));
 await page.goto('https://swimlog.example.test/?screen=garmin_measurements');await page.getByLabel('Weight',{exact:true}).check();await page.getByRole('button',{name:'Import measurements',exact:true}).click();await page.locator('.entry-compare tbody tr').waitFor();
 await page.goto('https://swimlog.example.test/?screen=garmin_setup');await page.getByLabel('Confirm removal of the saved API key',{exact:true}).check();await page.getByRole('button',{name:'Remove API key',exact:true}).click();await page.getByText('Connection: Not configured',{exact:true}).waitFor();assert.equal(await page.locator('.garmin-menu').count(),0);
 console.log(name+': Settings, private locations, verified Garmin gating, provider projection, measurements, exclusive anchored menus and phone widths passed.');await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1});
