"""Private session analysis HTTP checks; no browser engine."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-session.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(screen='pace',q='',cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen='+screen+q,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Session analysis HTTP '+str(checks))
q='&swim_id='+str(f['ids']['imported'])
for screen in ('pace','repeats'):
 s,h,b=req(screen,q,f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('PRIVATE_' not in b and 'id="session-analysis-title"' in b);check('<svg ' in b and '<table>' in b);check('id="session-analysis-title"' not in req(screen,q)[2]);check(req(screen,q,f['cookie'],'POST')[0]==404);check(req(screen,q,f['manager'])[0]==404)
 for bad in ('&swim_id[]=1','&analysis_page=0','&swim_id=0'):check(req(screen,bad,f['cookie'])[0]==400)
 for key in ('foreign','land'):check(req(screen,'&swim_id='+str(f[key]),f['cookie'])[0]==404)
check('9.09%' in req('pace',q,f['cookie'])[2]);b=req('repeats',q,f['cookie'])[2];check('Candidate repeated lap group' in b and 'Unknown' in b and 'may include in-lap rest' in b)
q='&swim_id='+str(f['ids']['manual']);b=req('repeats',q,f['cookie'])[2];check('Manual set 1' in b and '+10.00' in b and 'Explicitly measured repetition' in b and 'Entered rest' in b);b=req('pace',q,f['cookie'])[2];check('No timed samples' in b and 'at least two timed lengths' in b)
print('Swim session analysis HTTP: '+str(checks)+' assertions passed.')
