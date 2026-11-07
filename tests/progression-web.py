"""The PB page must display actual historical improvements, not a compatibility notice."""
import json,urllib.request,urllib.error
f=json.load(open('/tmp/swimlog-pb-comparison.json'));checks=0
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def req(q='',cookie=None):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099/?screen=bests&period=lifetime&history_distance=100&history_stroke=FR'+q,headers=h))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Progression HTTP '+str(checks))
s,h,b=req(cookie=f['cookie']);check(s==200 and 'no-store' in h['Cache-Control']);check('id="pb-progression-table"' in b);check('Progression history is unavailable' not in b);table=b.split('id="pb-progression-table"')[1].split('</table>')[0];check(table.count('<tr>')==5);check(all(t in table for t in ('1:20.00','1:15.00','1:12.00','1:10.00')));check('1:05.00' not in table and '1:00.00' not in table);check('PRIVATE_' not in b);check('history_distance=100' in table);check('id="pb-progression-table"' not in req()[2]);check('id="pb-progression-table"' not in req('&user_id='+str(f['owner']),f['manager'])[2])
print('PB progression HTTP: '+str(checks)+' assertions passed.')
