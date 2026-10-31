"""Real WordPress HTTP boundary checks; no private fixtures or production requests."""
import html, http.cookies, json, os, re, urllib.request, urllib.error
from urllib.parse import urlencode

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
fixture = json.load(open(os.environ['SWIMLOG_WEB_FIXTURE']))
count = 0

def check(value):
    global count
    count += 1
    if not value: raise AssertionError(f'HTTP assertion {count} failed')

def request(host, path='/', data=None, cookie='', origin=None, extra=None):
    headers = {'Host': host}
    if cookie: headers['Cookie'] = cookie
    if origin: headers['Origin'] = origin
    headers.update(extra or {})
    req = urllib.request.Request('http://127.0.0.1:8099' + path,
        data=None if data is None else urlencode(data).encode(), headers=headers)
    try: response = opener.open(req)
    except urllib.error.HTTPError as error: response = error
    return response.status, response.headers, response.read().decode()

def login_failure(result):
    status, headers, body = result
    check(status == 303 and headers.get('Location') == 'https://swimlog.example.test/?login_error=1')
    check(not any(value.startswith(fixture['name'] + '=') for value in headers.get_all('Set-Cookie', [])))

def form_value(body, name):
    return html.unescape(re.search(r'name="' + name + r'" value="([^"]+)"', body)[1])

app = 'swimlog.example.test'
main = 'wordpress.example.test'
app_origin = 'https://' + app
main_origin = 'https://' + main
main_cookie = fixture['name'] + '=' + fixture['cookie']
status, headers, body = request(app)
check(status == 200 and 'Sign in with wordpress.example.test' in body and 'Welcome,' not in body)
check('no-store' in headers['Cache-Control'])
check(headers['Referrer-Policy'] == 'strict-origin-when-cross-origin')
check(main_origin in headers['Content-Security-Policy'])
check(request(app, '/?action=login')[0] == 405)
check(request(app, '/?action=login', {}, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=login', {}, origin='null')[0] == 403)
check(request(app, '/?action=login', {})[0] == 403)
check(request(app, extra={'X-Synthetic-Insecure': '1'})[0] == 303)
status, headers, body = request(app, '/?action=login', {}, origin=app_origin)
check(status == 303)
state = http.cookies.SimpleCookie()
state.load(headers['Set-Cookie'])
verifier = state['__Host-swimlog-state'].value
check(state['__Host-swimlog-state']['secure'] and state['__Host-swimlog-state']['httponly'] and not state['__Host-swimlog-state']['domain'])
endpoint = headers['Location'].removeprefix(main_origin)
check(endpoint.startswith('/?swimlog_standalone_auth=1&challenge='))
status, headers, body = request(main, endpoint)
check(status == 302 and '/wp-login.php' in headers['Location'])
status, headers, body = request(main, endpoint, cookie=main_cookie)
check(status == 200 and 'Signing you in' in body)
script_nonce = re.search(r'<script nonce="([^"]+)"', body)[1]
check("'nonce-" + script_nonce + "'" in headers['Content-Security-Policy'])
check("'unsafe-inline'" not in headers['Content-Security-Policy'])
nonce = form_value(body, 'nonce')
challenge = form_value(body, 'challenge')
login_failure(request(main, endpoint, {'challenge': challenge}, cookie=main_cookie, origin=main_origin))
status, headers, body = request(main, endpoint, {'challenge': challenge, 'nonce': nonce}, cookie=main_cookie, origin=main_origin)
check(status == 200)
code = form_value(body, 'code')
check(fixture['cookie'] not in body)
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin='https://evil.example.test'))
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + '0'*64, origin=main_origin))
status, headers, body = request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin=main_origin)
check(status == 303)
cookies = headers.get_all('Set-Cookie')
auth = next(c for c in cookies if c.startswith(fixture['name'] + '='))
check('secure' in auth.lower() and 'httponly' in auth.lower() and 'samesite=lax' in auth.lower() and 'domain=' not in auth.lower())
check('expires=' not in auth.lower())
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin=main_origin))
status, headers, body = request(app, cookie=main_cookie)
check(status == 200 and 'Welcome,' in body and '&lt;script&gt;' in body and '<script>synthetic</script>' not in body)
check(request(app, cookie=fixture['name'] + '=' + fixture['other'])[0] == 403)
logout_nonce = form_value(body, 'nonce')
check(request(app, '/?action=logout', {}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=logout', {'nonce': logout_nonce}, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=logout', {'nonce': logout_nonce}, cookie=main_cookie, origin=app_origin)[0] == 303)
check('Welcome,' not in request(app, cookie=main_cookie)[2])
check(request(main, endpoint, cookie=main_cookie)[0] == 302)
for username, password in [('synthetic_swimmer','incorrect'),('','')]:
    login_failure(request(main, '/wp-login.php', {'log':username,'pwd':password,'redirect_to':main_origin+endpoint,'wp-submit':'Log In'}, origin=main_origin))
status, headers, body = request(app, '/?login_error=1')
check(status == 200 and 'Sign-in could not be completed' in body and 'data-swimlog-login' in body and 'Welcome,' not in body)
status, headers, body = request(main, '/wp-login.php', {'log':'synthetic_swimmer','pwd':'incorrect','redirect_to':main_origin+'/wp-admin/','wp-submit':'Log In'}, origin=main_origin)
check(status == 200 and headers.get('Location') is None)
print(f'HTTP authentication passed: {count} assertions.')
