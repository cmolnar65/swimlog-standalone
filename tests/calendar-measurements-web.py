"""Private measurement calendar and full-day transport checks; synthetic data only."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-calendar-health.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(q='',cookie=None):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?date=2026-11-01'+q,headers=h))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Calendar measurements HTTP '+str(checks))
for q in ('','&view=week','&day=2026-11-01'):
 s,h,b=req(q,f['cookie']);check(s==200 and 'no-store' in h['Cache-Control']);check('Private measurements' in b and '82.5 kg' in b and '96.4 %' in b);check('Hours slept' in b and '10.00 hours' in b);check('Hourly average' in b and 'Provider summary' in b and 'Recorded reading' in b);check('Conflicting source readings' in b);check('PRIVATE_HEALTH_SOURCE' not in b and 'provenance_json' not in b);check('2026-11-01 07:00:00 (source local)' in b)
check('82.5 kg' not in req()[2]);check('82.5 kg' not in req('&user_id='+str(f['owner']),f['manager'])[2]);check('82.5 kg' not in req('&day=2026-10-31',f['cookie'])[2])
print('Calendar measurements HTTP: '+str(checks)+' assertions passed.')
