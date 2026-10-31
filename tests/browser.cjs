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
      const context = await browser.newContext();
      await context.addCookies([{name:fixture.name, value:fixture.cookie, url:'https://wordpress.example.test/', secure:true, httpOnly:true, sameSite:'Lax'}]);
      const posts = [];
      await context.route('https://**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        assert(['swimlog.example.test', 'wordpress.example.test'].includes(url.hostname));
        const headers = {...await request.allHeaders(), host:url.host};
        if (request.method() === 'POST') posts.push({host:url.host, path:url.search, origin:headers.origin});
        const response = await context.request.fetch('http://127.0.0.1:8099' + url.pathname + url.search, {
          method:request.method(), headers, data:request.postDataBuffer() || undefined, maxRedirects:0
        });
        await route.fulfill({response});
      });
      const page = await context.newPage();
      await page.goto('https://swimlog.example.test/');
      await page.getByRole('button', {name:'Sign in with cmolnar.com', exact:true}).click();
      await page.getByRole('button', {name:'Continue to swimlog.cmolnar.com', exact:true}).waitFor({timeout:10000});
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
