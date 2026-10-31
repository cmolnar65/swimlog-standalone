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
      try { await page.getByRole('button', {name:'Continue to swimlog.example.test', exact:true}).waitFor({timeout:10000}); } catch (error) {
        console.error(JSON.stringify(navigation));
        console.error((await page.locator('body').innerText()).slice(0,300));
        throw error;
      }
      assert.equal(posts[0].origin, 'https://swimlog.example.test');
      await page.getByRole('button', {name:'Continue to swimlog.example.test', exact:true}).click();
      await page.getByRole('button', {name:'Open Swim Log', exact:true}).click();
      await page.getByRole('heading', {name:'Welcome, <script>synthetic</script>', exact:true}).waitFor();
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
        const entry=page.locator('.calendar-entry').first();
        if (width<=390) await entry.locator('summary').tap(); else await entry.locator('summary').click();
        assert(await entry.evaluate(el=>el.open));
        await entry.locator('summary').click();
      }
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
      console.log(`${name}: actual browser form login, handoff, host-only cookie and logout passed.`);
      await context.close();
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error.message); process.exitCode=1; });
