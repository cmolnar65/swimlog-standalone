"""Synthetic private volume and deletion transport checks."""
import json,re,html,urllib.request,urllib.error
from urllib.parse import urlencode
f=json.load(open('/tmp/swimlog-volume.json'));a=json.load(open('/tmp/swimlog-associations.json'));checks=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
def req(path,cookie=None,data=None):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 if data is not None:h.update({'Origin':'https://swimlog.example.test','Content-Type':'application/x-www-form-urlencoded'})
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099'+path,headers=h,data=None if data is None else urlencode(data).encode()))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Volume/delete HTTP '+str(checks))
s,h,b=req('/?screen=volume',f['cookie']);check(s==200 and 'no-store' in h['Cache-Control']);check('50.00 m' in b and '25.00 yd' in b and '999,999' not in b)
check('id="volume-title"' not in req('/?screen=volume')[2])
for q in ('months=25','months[]=3','course=run','stroke=RUN'):check(req('/?screen=volume&'+q,f['cookie'])[0]==400)
check('No active pool lengths match' in req('/?screen=volume&stroke=FLY',f['cookie'])[2])
plan=a['pairs']['pool']['plan'];p='/?plan='+str(plan);body=req(p,a['cookie'])[2];check('action="/?action=plan_delete"' not in body)
# Unlink with the existing owner-only form, then delete the eligible plan.
def form(body,action,op):
 for tag in re.findall(r'<form\b.*?</form>',body,re.S):
  if 'action="/?action='+action+'"' in tag and 'value="'+op+'"' in tag:return {k:html.unescape(v)for k,v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"',tag)}|{'confirm':'1'}
 raise AssertionError('Missing '+action+' form')
data=form(body,'association_unlink','match');check(req('/?action=association_unlink',a['cookie'],data)[0]==303)
data=form(req(p,a['cookie'])[2],'plan_delete','delete');check(req('/?action=plan_delete',data=data)[0]==401);check(req('/?action=plan_delete',a['manager'],data)[0] in (403,404));check(req('/?action=plan_delete',a['cookie'],data|{'nonce':'bad'})[0]==403);check(req('/?action=plan_delete',a['cookie'],data|{'revision':'bad'})[0]==409);check(req('/?action=plan_delete')[0]==405)
check(req('/?action=plan_delete',a['cookie'],data)[0]==303);check(req(p,a['cookie'])[0]==404)
check(req('/?workout='+str(a['pairs']['pool']['workout']),a['cookie'])[0]==200)
print('Training Volume/plan deletion HTTP: '+str(checks)+' assertions passed.')
