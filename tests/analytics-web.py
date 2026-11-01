"""Synthetic owner-only swimming report HTTP checks."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-analytics.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(path,cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099'+path,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Analytics HTTP '+str(checks))
for screen in ('trends','pool'):
 path='/?screen='+screen;s,h,b=req(path,f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('id="analytics-title"' in b and 'PRIVATE_' not in b and '999,999' not in b);check('id="analytics-title"' not in req(path)[2]);check(req(path,f['cookie'],'POST')[0]==404)
 for q in ('months=0','months=25','months[]=6'):check(req(path+'&'+q,f['cookie'])[0]==400)
 check('1,097.86 m' not in req(path+'&user_id='+str(f['owner']),f['manager'])[2])
s,h,b=req('/?screen=pool&additional_metrics=1',f['cookie']);check('50%' in b and '40.0' in b);check('No qualifying active length data' in req('/?screen=pool&stroke=FLY',f['cookie'])[2]);check('Yards' in req('/?screen=pool&course=yd',f['cookie'])[2]);check('1,097.86 m' in req('/?screen=trends',f['cookie'])[2]);check('<rect ' in req('/?screen=trends',f['cookie'])[2])
for q in ('course=running','stroke=RUN','additional_metrics[]=1'):check(req('/?screen=pool&'+q,f['cookie'])[0]==400)
print('Swimming analytics HTTP: '+str(checks)+' assertions passed.')
