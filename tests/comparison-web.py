"""Synthetic pool comparison HTTP checks; no browser execution."""
import json,urllib.request,urllib.error
from urllib.parse import urlencode
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
 if not v:raise AssertionError('Comparison HTTP '+str(checks))
def url(a,b):return '/?'+urlencode({'screen':'compare','compare[]':[str(a),str(b)]},doseq=True)
path=url(f['ids']['pool'],f['ids']['previous']);s,h,b=req(path,f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('id="comparison-title"' in b and 'Freestyle' in b and 'Breaststroke' in b);check('PRIVATE_' not in b);check('comparison-chart' in b and '<rect ' in b);check('sampled / 2 active lengths' in b);check('1:60' not in b);check('Session distances differ' in b);check('id="comparison-title"' not in req(path)[2]);check(req(path,f['cookie'],'POST')[0]==404);check(req(path,f['manager'])[0]==404)
for query in ('','&compare[]=1','&compare[]=1&compare[]=1','&compare=1','&compare[][]=1&compare[]=2'):check(req('/?screen=compare'+query,f['cookie'])[0]==400)
for kind in ('running','walking','open_water'):check(req(url(f['ids']['pool'],f['ids'][kind]),f['cookie'])[0]==404)
s,h,b=req(url(f['ids']['pool'],f['ids']['yard']),f['cookie']);check(s==200 and 'Course units differ' in b);check('<rect ' not in b);check('Not comparable' in b)
s,h,b=req('/?screen=log&from=&to=&type=all',f['cookie']);check(s==200 and 'id="swim-comparison-selection"' in b);check('data-compare-button disabled' in b);check('name="compare[]" value="'+str(f['ids']['pool'])+'"' in b);check('name="compare[]" value="'+str(f['ids']['running'])+'"' not in b)
check(req('/assets/swim-comparison.js')[0]==200)
print('Swim comparison HTTP: '+str(checks)+' assertions passed.')
