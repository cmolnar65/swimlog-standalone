"""Owner-isolated synthetic settings/Garmin HTTP regression checks."""
import os,json,re,html,urllib.request,urllib.error
from urllib.parse import urlencode,urlsplit
f=json.load(open(os.environ['SWIMLOG_WEB_FIXTURE']))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
cookie=f['name']+'='+f['settings_cookie'];checks=0
def check(ok):
    global checks
    checks+=1
    if not ok:raise AssertionError('Settings HTTP assertion '+str(checks))
def req(path,data=None,origin='https://swimlog.example.test',auth=True):
    headers={'Host':'swimlog.example.test'}
    if auth:headers['Cookie']=cookie
    if data is not None:headers.update({'Origin':origin,'Content-Type':'application/x-www-form-urlencoded'})
    try:r=opener.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=None if data is None else urlencode(data,doseq=True).encode(),headers=headers))
    except urllib.error.HTTPError as e:r=e
    return r.status,r.headers,r.read().decode()
def form(screen,operation):
    status,_,body=req('/?screen='+screen);check(status==200)
    for tag in re.findall(r'<form\b.*?</form>',body,re.S):
        if 'value="'+operation+'"' in tag:
            return {k:html.unescape(v)for k,v in re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"',tag)if k in ('operation','nonce','revision','location_id','job')}
    raise AssertionError('Missing form '+operation)
def post(screen,operation,extra=None):
    data=form(screen,operation);data.update(extra or {})
    return req('/?action='+('settings_write' if operation in ('key_save','key_test','key_remove','activity_sync','measurement_sync','swimmer_save','location_save','location_delete') else 'garmin_write'),data)
check(req('/?screen=garmin_setup',auth=False)[0]==401)
check(req('/?screen=garmin_activities')[0]==403)
body=req('/?screen=garmin_setup')[2]
check('settings-menu' in body and 'garmin-menu' not in body)
check('Preferences</a>' not in body and 'Garmin Connect</a>' not in body)
data=form('garmin_setup','key_save')|{'api_key':'synthetic-settings-key'}
check(req('/?action=settings_write',data,origin='https://evil.example.test')[0]==403)
check(req('/?action=settings_write',data|{'nonce':'invalid'})[0]==403)
check(req('/?action=settings_write',data|{'user_id':'1'})[0]==422)
check(req('/?action=settings_write',data)[0]==303)
check('garmin-menu' not in req('/?screen=garmin_setup')[2])
check(post('garmin_setup','key_test')[0]==303)
body=req('/?screen=garmin_setup')[2]
check('garmin-menu' in body and 'Connection: Verified' in body and 'synthetic-settings-key' not in body)
check(post('garmin_setup','activity_sync',{'enabled':'1','types[]':['lap_swimming']})[0]==303)
check(post('garmin_setup','measurement_sync',{'categories[]':['weight']})[0]==303)
check(post('swimmer','swimmer_save',{'birthdate':'1965-01-02','calendar_first_day':'monday','training_volume_months':'6'})[0]==303)
check(post('locations','location_save',{'location_id':'new','name':'Synthetic HTTP pool','pool_length':'25','pool_unit':'m','latitude':'','longitude':'','notes':''})[0]==303)
# No raw provider routes or credentials enter the rendered activity list.
status,_,body=post('garmin_activities','activities_browse',{'start':'2026-10-01','end':'2026-10-01','types[]':['lap_swimming']})
check(status==200 and 'Synthetic Garmin pool' in body and 'PRIVATE_GARMIN_ROUTE_CANARY' not in body)
from datetime import date
today=date.today().isoformat()
check(post('garmin_measurements','measurements_import',{'start':today,'end':today,'categories[]':['weight']})[0]==200)
status,headers,body=post('garmin_history','history_start',{'start':today,'end':today,'categories[]':['weight']})
check(status==303);jobpath=urlsplit(headers['Location']).query;job=re.search(r'job=([a-f0-9]{32})',jobpath)[1]
for action in ['pause','resume','cancel']:
    check(post('garmin_history&job='+job,'history_'+action)[0]==303)
check(post('garmin_setup','activity_sync',{'types[]':['lap_swimming']})[0]==303)
check(post('garmin_setup','measurement_sync')[0]==303)
check(post('garmin_setup','key_remove',{'confirm_remove':'1'})[0]==303)
check(req('/?screen=garmin_history&job='+job)[0]==403)
print('Settings/Garmin HTTP passed: '+str(checks)+' assertions.')
