"""Synthetic association transport and owner checks."""
import json,re,html,urllib.request,urllib.error
from urllib.parse import urlencode
f=json.load(open('/tmp/swimlog-associations.json'));checks=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
def req(path,data=None,cookie=None,origin='https://swimlog.example.test'):
 h={'Host':'swimlog.example.test'}
 if cookie:h['Cookie']=f['name']+'='+cookie
 if data is not None:h.update({'Origin':origin,'Content-Type':'application/x-www-form-urlencoded'})
 try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=None if data is None else urlencode(data).encode(),headers=h))
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def check(v):
 global checks
 checks+=1
 if not v:raise AssertionError('Association HTTP assertion '+str(checks))
def form(path,op):
 status,_,body=req(path,cookie=f['cookie']);check(status==200)
 for tag in re.findall(r'<form\b.*?</form>',body,re.S):
  if 'action="/?action=association_unlink"' in tag and 'value="'+op+'"' in tag:
   return {k:html.unescape(v)for k,v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"',tag)}|{'confirm':'1'}
 raise AssertionError('Missing association form')
a=f['pairs']['pool'];url='/?workout='+str(a['workout']);data=form(url,'structure')
check(req('/?action=association_unlink')[0]==405)
check(req('/?action=association_unlink',data)[0]==401)
check(req('/?action=association_unlink',data,f['manager'])[0] in (403,404))
check(req('/?action=association_unlink',data,f['cookie'],'https://evil.example.test')[0]==403)
check(req('/?action=association_unlink',data|{'nonce':'bad'},f['cookie'])[0]==403)
check(req('/?action=association_unlink',data|{'revision':'bad'},f['cookie'])[0]==409)
check(req('/?action=association_unlink',data|{'confirm':'0'},f['cookie'])[0]==422)
check(req('/?action=association_unlink',data,f['cookie'])[0]==303)
check(req('/?action=association_unlink',data,f['cookie'])[0]==409)
data=form(url,'match');check(req('/?action=association_unlink',data,f['cookie'])[0]==303)
body=req('/?plan='+str(a['plan']),cookie=f['cookie'])[2];check('Planned' in body and 'No recorded workout is matched' in body)
# Planned page permits both unlink actions as well, preserving the locked prescription.
b=f['pairs']['open_water'];url='/?plan='+str(b['plan']);data=form(url,'match');check(req('/?action=association_unlink',data,f['cookie'])[0]==303)
data=form(url,'structure');check(req('/?action=association_unlink',data,f['cookie'])[0]==303)
check(req('/?workout='+str(b['workout']),cookie=f['cookie'])[0]==200)
print('Workout association HTTP: '+str(checks)+' assertions passed.')
