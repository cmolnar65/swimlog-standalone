"""Owner-only training balance HTTP checks; synthetic swim sources only."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-balance.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(q='',cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen=balance'+q,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Balance HTTP '+str(checks))
s,h,b=req('&months=6',f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('id="balance-title"' in b);check('PRIVATE_' not in b and '999,999' not in b);check('<rect ' in b and '<table>' in b);check('Freestyle' in b and 'Breaststroke' in b and '50.0%' in b);check('200.00 yd' in b and '500.00 yd' in b);check('Documented manual repetitions' in b and 'Recorded imported laps' in b);check('Sessions without distance' in b);check('not confirmed repetitions or continuous bouts' in b);check('id="balance-title"' not in req()[2]);check(req('',f['cookie'],'POST')[0]==404)
for bad in ('&months=0','&months=25','&months[]=6'):check(req(bad,f['cookie'])[0]==400)
s,h,b=req('&user_id='+str(f['owner']),f['manager']);check(s==200 and 'No swimming workouts' in b);check('500.00 yd' not in b)
print('Training balance HTTP: '+str(checks)+' assertions passed.')
