"""Private swim PB transport checks against synthetic canonical-sharing fixtures."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-sharing.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(query='',cookie=None,method='GET'):
    headers={'Host':'swimlog.example.test'}
    if cookie:headers['Cookie']=f['name']+'='+cookie
    try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen=bests'+query,headers=headers,method=method))
    except urllib.error.HTTPError as e:r=e
    return r.status,r.headers,r.read().decode()
def check(value):
    global checks
    checks+=1
    if not value:raise AssertionError('Personal Bests HTTP assertion '+str(checks))
status,headers,body=req(cookie=f['cookie'])
check(status==200);check('no-store' in headers['Cache-Control'])
check('id="personal-bests-title"' in body and '00:30' in body)
check('evaluation-menu' in body and 'wordpress-menu' not in body and 'WordPress tools' not in body)
check('class="nav-link main-site-link" href="https://wordpress.example.test/"' in body)
check('PRIVATE_' not in body and 'heart_rate' not in body and 'latitude' not in body)
check('id="personal-bests-title"' not in req()[2])
for query in ('&course=bogus','&course[]=m','&period[]=lifetime','&period=bad','&history_distance=100x','&history_stroke=RUN','&age_group[]=a','&period=historical&age_group=bad'):
    check(req(query,f['cookie'])[0]==400)
check(req('&course=yd',f['cookie'])[0]==200)
check(req('&period=current',f['cookie'])[0]==200)
check(req('&history_distance=100&history_stroke=FR',f['cookie'])[0]==200)
check(req(cookie=f['cookie'],method='POST')[0]==404)
# Swimmer selector input never switches this owner-only screen to another user's records.
check(req('&swimmer_id='+str(f['owner'])+'&user_id=1',f['manager'])[0]==200)
check('00:30' not in req(cookie=f['manager'])[2])
print('Personal Bests HTTP: '+str(checks)+' assertions passed.')
