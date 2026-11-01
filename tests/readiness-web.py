"""Synthetic authenticated evaluation, isolation and HTML checks; no browser."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-readiness.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(query='',cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen=readiness'+query,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Readiness HTTP '+str(checks))
s,h,b=req(cookie=f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('id="evaluation-title"' in b);check('PRIVATE_' not in b);check('200.00 m' in b and '2 exact-distance performances' in b);check('does not predict' in b);check('Next event page' in b);check('data-workout-id="'+str(f['ids']['workouts']['pb'])+'"' in b)
check('id="evaluation-title"' not in req()[2]);check(req(cookie=f['cookie'],method='POST')[0]==404)
for q in ('&lookback_days=0','&lookback_days=366','&lookback_days[]=42','&event_id[]=1','&evaluation_page[]=1'):check(req(q,f['cookie'])[0]==400)
for id in (f['ids']['past'],999999999):check(req('&event_id='+str(id),f['cookie'])[0]==404)
check(req('&event_id='+str(f['ids']['events'][0]),f['manager'])[0]==404);check('No upcoming swim events' in req('&user_id='+str(f['owner']),f['manager'])[2]);check('No matching recent training' in req('&lookback_days=1',f['cookie'])[2]);check('Previous event page' in req('&evaluation_page=2',f['cookie'])[2]);check('Workout details' in req('&workout='+str(f['ids']['workouts']['pb']),f['cookie'])[2]);check('id="evaluation-title"' not in req('&workout='+str(f['ids']['workouts']['pb']),f['cookie'])[2]);check('Back to Swim Evaluation' in req('&workout='+str(f['ids']['workouts']['pb']),f['cookie'])[2])
print('Swim Evaluation HTTP: '+str(checks)+' assertions passed.')
