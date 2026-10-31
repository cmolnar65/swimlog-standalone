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
      const context = await browser.newContext({ignoreHTTPSErrors:true, proxy:{server:'http://127.0.0.1:8088'}});
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
      await page.getByRole('button', {name:'Sign in with cmolnar.com', exact:true}).click();
      try { await page.getByRole('button', {name:'Continue to swimlog.cmolnar.com', exact:true}).waitFor({timeout:10000}); } catch (error) {
        console.error(JSON.stringify(navigation));
        console.error((await page.locator('body').innerText()).slice(0,300));
        throw error;
      }
      assert.equal(posts[0].origin, 'https://swimlog.example.test');
      await page.getByRole('button', {name:'Continue to swimlog.cmolnar.com', exact:true}).click();
      await page.getByRole('button', {name:'Open Swim Log', exact:true}).click();
      await page.getByRole('heading', {name:'Welcome, <script>synthetic</script>', exact:true}).waitFor();
      assert.equal(posts[1].origin, 'https://wordpress.example.test');
      assert.equal(posts[2].origin, 'https://wordpress.example.test');
      const cookies = await context.cookies('https://swimlog.example.test/');
      const auth = cookies.find(c=>c.name===fixture.name);
      assert(auth && auth.domain==='swimlog.example.test' && auth.secure && auth.httpOnly);
      await page.getByRole('button', {name:'Log out', exact:true}).click();
      await page.getByText('You have been signed out.', {exact:true}).waitFor();
      assert.equal(posts[3].origin, 'https://swimlog.example.test');
      console.log(`${name}: actual browser form login, handoff, host-only cookie and logout passed.`);
      await context.close();
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error.message); process.exitCode=1; });
