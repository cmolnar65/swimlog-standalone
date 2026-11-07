"""Synthetic efficiency and explicit-match evaluation checks; no browser."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-advanced-evaluation.json'));a=json.load(open('/tmp/swimlog-analytics.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(screen,q='',cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen='+screen+q,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Advanced evaluation HTTP '+str(checks))
s,h,b=req('efficiency','&months=12',a['cookie']);check(s==200 and 'id="efficiency-title"' in b);check('no-store' in h['Cache-Control']);check('PRIVATE_' not in b);check('<circle ' in b and '<table>' in b);check('Stroke-count samples' in b and 'SWOLF samples' in b);check('id="efficiency-title"' not in req('efficiency')[2]);check(req('efficiency','',a['cookie'],'POST')[0]==404)
for q in ('&months=0','&course=running','&stroke[]=FR'):check(req('efficiency',q,a['cookie'])[0]==400)
q='&evaluation_plan='+str(f['plans']['matched']);s,h,b=req('plan_evaluation',q,f['cookie']);check(s==200 and 'id="plan-evaluation-title"' in b);check('no-store' in h['Cache-Control']);check('PRIVATE_' not in b);check('365.76 m' in b and '274.32 m' in b);check('5 documented manual repetitions' in b and '2 expanded swim steps' in b);check('Pool course/length differs' in b);check('<rect ' in b);check('id="plan-evaluation-title"' not in req('plan_evaluation',q)[2]);check(req('plan_evaluation',q,f['cookie'],'POST')[0]==404);check(req('plan_evaluation',q,f['manager'])[0]==404)
check('No recorded swim is matched' in req('plan_evaluation','&evaluation_plan='+str(f['plans']['unmatched']),f['cookie'])[2]);check('no linked structured prescription' in req('plan_evaluation','&evaluation_plan='+str(f['plans']['free']),f['cookie'])[2])
for q in ('&evaluation_plan[]=1','&evaluation_page=0'):check(req('plan_evaluation',q,f['cookie'])[0]==400)
b=req('efficiency','&months=12',a['cookie'])[2];check('data-chart-tooltip=' in b and 'Month:' in b and 'Samples:' in b and '/assets/chart-tooltips.js' in b);check('SWOLF:' in b and 'Strokes per length:' in b and 'Pace:' in b and '/ 100 m' in b)
print('Advanced evaluation HTTP: '+str(checks)+' assertions passed.')
