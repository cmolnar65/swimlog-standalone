"""Canonical sharing transport checks. All records and coordinates are synthetic."""
import os,json,re,html,urllib.request,urllib.error
from urllib.parse import urlencode
f=json.load(open('/tmp/swimlog-sharing.json'))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect());checks=0
def check(v):
    global checks
    checks+=1
    if not v:raise AssertionError('Sharing HTTP assertion '+str(checks))
def req(path,data=None,cookie=None,origin='https://swimlog.example.test',method=None):
    headers={'Host':'swimlog.example.test'}
    if cookie:headers['Cookie']=f['name']+'='+cookie
    if data is not None:headers.update({'Origin':origin,'Content-Type':'application/x-www-form-urlencoded'})
    try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=None if data is None else urlencode(data).encode(),headers=headers,method=method))
    except urllib.error.HTTPError as e:r=e
    return r.status,r.headers,r.read().decode()
def form(id,op='visibility'):
    status,_,body=req('/?edit_workout='+str(id),cookie=f['cookie']);check(status==200)
    for tag in re.findall(r'<form\b.*?</form>',body,re.S):
        if 'value="'+op+'"' in tag:return {k:html.unescape(v)for k,v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"',tag)}
    raise AssertionError('Missing sharing form '+op)
id=f['ids']['pool'];url='/?public_workout='+str(id)
for who in (None,f['cookie'],f['manager']):
    status,headers,body=req(url,cookie=who);check(status==200 and 'private' in headers['Cache-Control'] and 'no-store' in headers['Cache-Control'])
    for forbidden in ('PRIVATE_','location_name','raw_metadata','heart_rate','cadence','Photo 1','Edit Workout','workout_write','swimlog_workout_visibility_','08:12:34','is_personal_best'):check(forbidden not in body)
    check(req('/?public_workout='+str(f['ids']['private']),cookie=who)[0]==404)
check(req('/?public_workout=999999999')[2]==req('/?public_workout='+str(f['ids']['private']))[2])
check(req('/?public_workout='+str(f['ids']['cycling']))[0]==404)
check(req(url,{},method='POST')[0]==405)
check(req(url+'&action=workout_write',{},cookie=f['cookie'])[0]==405)
check(req(url+'&edit_workout='+str(id),cookie=f['cookie'])[0]==404)
data=form(id);saved=data.copy()
check(req('/?action=workout_write',data,cookie=f['cookie'],origin='https://evil.example.test')[0]==403)
check(req('/?action=workout_write',data|{'nonce':'bad'},cookie=f['cookie'])[0]==403)
check(req('/?action=workout_write',data|{'visibility_nonce':'bad'},cookie=f['cookie'])[0]==403)
for revision in ('-1','1.2','999999999999999999999999999',''):
    check(req('/?action=workout_write',data|{'visibility_revision':revision},cookie=f['cookie'])[0]==400)
for op in ('visibility','adopt','metadata','post','convert','rebuild','delete'):
    payload=saved|{'operation':op,'confirm':'1'}
    check(req('/?action=workout_write',payload)[0]==401)
    check(req('/?action=workout_write',payload,cookie=f['manager'])[0]==404)
# A public page does not authorize the private detail/management routes.
for action in ('workout_photo','workout_public_link'):
    check(req('/?action='+action+'&workout_id='+str(id),{},cookie=f['manager'])[0]==404)
check(req('/?action=workout_write',data,cookie=f['cookie'])[0]==303)
check(req(url)[0]==404)
status,_,body=req('/?edit_workout='+str(id),cookie=f['cookie']);check(status==200 and re.search(r'<button[^>]*data-copy-public[^>]*disabled',body))
check(req('/?action=workout_write',saved|{'make_public':'1'},cookie=f['cookie'])[0]==409)
check('Reload workout and review current settings' in req('/?action=workout_write',saved,cookie=f['cookie'])[2])
check(req('/?action=workout_write',form(id)|{'make_public':'1'},cookie=f['cookie'])[0]==303)
check(req(url)[0]==200)
pending=f['ids']['pending'];body=req('/?edit_workout='+str(pending),cookie=f['cookie'])[2]
check('Confirm previous sharing request' in body and re.search(r'name="make_public"[^>]*checked',body) is None)
check(req('/?public_workout='+str(pending))[0]==404)
check(req('/?action=workout_write',form(pending,'adopt'),cookie=f['cookie'])[0]==303)
check(req('/?public_workout='+str(pending))[0]==200)
print('Canonical sharing HTTP passed: '+str(checks)+' assertions.')
