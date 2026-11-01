"""Private period PB HTTP checks using synthetic performances only."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-pb-comparison.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(q='',cookie=None,method='GET'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen=compare_bests'+q,headers=h,method=method))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('PB comparison HTTP '+str(checks))
q='&a_year=2019&b_year=2020';s,h,b=req(q,f['cookie']);check(s==200);check('no-store' in h['Cache-Control']);check('id="pb-comparison-title"' in b);check('PRIVATE_' not in b and '1960-06-15' not in b);check('Freestyle' in b and 'Breaststroke' in b);check('1:15.00' in b and '1:10.00' in b and '-6.67%' in b);check('<rect ' in b);check('No eligible result' in b);check('id="pb-comparison-title"' not in req(q)[2]);check(req(q,f['cookie'],'POST')[0]==404);check('No eligible performances' in req(q+'&user_id='+str(f['owner']),f['manager'])[2]);check('2019-06-15' in b)
for q in ('&a_mode[]=age','&a_year=abc','&course[]=m','&a_mode=month&a_month=2020-99','&a_mode=age&a_min_age=70&a_max_age=50'):check(req(q,f['cookie'])[0]==400)
s,h,b=req('&a_mode=age&a_min_age=55&a_max_age=59&b_mode=age&b_min_age=60&b_max_age=64',f['cookie']);check(s==200 and '1:12.00' in b and '1:10.00' in b);check('calendar spans overlap' not in b);check('No eligible performances' in req('&a_year=1900&b_year=1901',f['cookie'])[2]);check('1:05.00' in req('&course=yd&a_year=2019&b_year=2020',f['cookie'])[2])
print('Personal Best comparison HTTP: '+str(checks)+' assertions passed.')
