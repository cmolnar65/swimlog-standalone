"""Real multipart PHP uploads, synthetic swim files only; production never contacted."""
import os,json,re,html,urllib.request,urllib.error,uuid,gzip,zipfile,io
from urllib.parse import urlencode
fixture=json.load(open(os.environ['SWIMLOG_WEB_FIXTURE']))
base='http://127.0.0.1:8099'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
count=0
cookie=fixture['name']+'='+fixture['entry_cookie']
def check(v):
    global count
    count+=1
    if not v: raise AssertionError('Entry HTTP assertion '+str(count)+' failed')
def request(path='/?screen=manual',data=None,files=None,auth=True,origin='https://swimlog.example.test'):
    headers={'Host':'swimlog.example.test'}
    if auth: headers['Cookie']=cookie
    body=None
    if data is not None:
        headers['Origin']=origin
        if files is None:
            body=urlencode(data).encode();headers['Content-Type']='application/x-www-form-urlencoded'
        else:
            boundary='swimlog'+uuid.uuid4().hex;parts=[]
            for k,v in data.items():parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+str(v)+'\r\n').encode())
            for upload in files:
                field,name,payload=('workout_files[]',*upload) if len(upload)==2 else upload
                parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+name+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+payload+b'\r\n')
            parts.append(('--'+boundary+'--\r\n').encode());body=b''.join(parts);headers['Content-Type']='multipart/form-data; boundary='+boundary
    try:r=opener.open(urllib.request.Request(base+path,data=body,headers=headers))
    except urllib.error.HTTPError as e:r=e
    return r.status,r.headers,r.read().decode()
def values(body,form_class):
    form=re.search(r'<form[^>]*class="'+form_class+r'[^>]*>.*?</form>',body,re.S)[0]
    return {k:html.unescape(v)for k,v in re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"',form)}
def import_form():
    status,headers,body=request('/?screen=import');check(status==200)
    return values(body,'workout-upload')|{'location_id':'0'}
def follow(headers):return headers['Location'].replace('https://swimlog.example.test','')
check(request(auth=False)[0]==401)
check(request('/?action=manual_save')[0]==405)
status,headers,body=request();check(status==200 and 'Add Workout' in body)
f=values(body,'manual-entry');f={k:v for k,v in f.items()if not k.startswith('sets[')}
f.update({'workout_date':'2002-01-02','workout_time':'08:00','pool_length':'25','course_unit':'m','location_id':'0','total_distance':'200','duration':'4:00.123','notes':'SYNTHETIC_PRIVATE_ENTRY'})
check(request('/?action=manual_save',f,origin='https://evil.example.test')[0]==403)
check(request('/?action=manual_save',{**f,'nonce':'bad'})[0]==403)
check(request('/?action=manual_save',{**f,'workout_id':'1'})[0]==422)
check(request('/?action=manual_save',{k:v for k,v in f.items()if k!='payload_complete'})[0]==422)
status,headers,bad=request('/?action=manual_save',{**f,'workout_date':'2026-02-30'});check(status==422 and 'SYNTHETIC_PRIVATE_ENTRY' in bad)
status,headers,body=request('/?action=manual_save',f);check(status==303);manual_url=follow(headers)
check(request('/?action=manual_save',f)[1]['Location']==headers['Location'])
check('Manual workout saved.' in request(manual_url)[2])
# A small complete synthetic FORM CSV is uploaded via a genuine PHP multipart request.
def csv(date='2001-02-03',start='08:00:00',lengths=4,seconds=30):
    return ('Swim Date,Start,End,Pool Size,Dist M\n'+date+','+start+',08:02:00,25 m,'+str(lengths*25)+'\nSwim Date,Swim Time,Length M,Move Time,Strk\n'+''.join(date+','+start+',25,'+str(seconds)+',FR\n' for _ in range(lengths))).encode()
payload=csv();upload=import_form()
check(request('/?action=workout_upload',upload,[('synthetic.csv',payload)],origin='https://evil.example.test')[0]==403)
check(request('/?action=workout_upload',{**upload,'nonce':'bad'},[('synthetic.csv',payload)])[0]==403)
status,headers,body=request('/?action=workout_upload',upload,[('synthetic.csv',payload)]);check(status==303)
receipt_url=follow(headers);status,_,body=request(receipt_url);check(status==200 and 'Workout imported.' in body and 'View workout' in body)
check(request('/?action=workout_upload',upload,[('synthetic.csv',payload)])[1]['Location']==headers['Location'])
dup=import_form();status,headers,_=request('/?action=workout_upload',dup,[('same.csv',payload)]);check(status==303 and 'already been imported' in request(follow(headers))[2])
# Nearby elapsed/distance evidence stays pending until a specific attach/separate decision.
near=csv(start='08:00:10',seconds=31);status,headers,_=request('/?action=workout_upload',import_form(),[('possible.csv',near)]);check(status==303)
status,_,review=request(follow(headers));check(status==200 and 'Possible matching workout' in review and 'Attach to existing workout' in review)
review_values=values(review,'review-form')|{'location_id':'0','choice':'attach'}
check(request('/?action=workout_upload',{**review_values,'revision':'stale'})[0]==409)
# Another owner cannot access the pending import token or source.
saved_cookie=cookie;cookie=fixture['name']+'='+fixture['cookie'];check('Possible matching workout' not in request('/?screen=import')[2]);cookie=saved_cookie
status,headers,_=request('/?action=workout_upload',review_values);check(status==303 and 'Source attached to matching workout.' in request(follow(headers))[2])
# Gzip and ZIP remain delegated to the same plugin importer and duplicate rules.
for name,compressed in [('compressed.csv.gz',gzip.compress(payload)),('archive.zip',None)]:
    if compressed is None:
        buf=io.BytesIO()
        with zipfile.ZipFile(buf,'w')as z:z.writestr('one.csv',payload)
        compressed=buf.getvalue()
    status,headers,_=request('/?action=workout_upload',import_form(),[(name,compressed)]);check(status==303);text=request(follow(headers))[2];check('Source attached' in text or 'already been imported' in text or 'Possible match preserved' in text)
status,headers,_=request('/?action=workout_upload',import_form(),[('bad.txt',b'unsupported')]);check(status==303 and 'role="alert"' in request(follow(headers))[2])
print('Workout entry HTTP passed: '+str(count)+' assertions.')

# Workout editor uses genuine multipart media uploads and the existing import/rebuild services.
import base64
source_body=request(receipt_url)[2]
workout_id=re.search(r'href="/\?workout=([0-9]+)',source_body)[1]
edit_url='/?edit_workout='+workout_id
def edit_form():
    status,_,body=request(edit_url);check(status==200)
    data=values(body,'settings-form workout-edit-form')
    return {k:v for k,v in data.items()if k in ('workout_id','operation','nonce','revision')}
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
data=edit_form()|{'location_id':'0','notes':'SYNTHETIC_EDITOR_NOTES','make_public':'1'}
check(request('/?action=workout_write',data,[('workout_photo_0','synthetic.png',png)])[0]==303)
status,_,body=request(edit_url);check(status==200 and 'SYNTHETIC_EDITOR_NOTES' in body and 'workout-photo-preview' in body)
check(re.search(r'name="make_public"[^>]*checked',body) is not None)
photo=re.search(r'<img class="workout-photo-preview" src="([^"]+)',body)[1]
response=opener.open(urllib.request.Request(base+html.unescape(photo),headers={'Host':'swimlog.example.test','Cookie':cookie}))
check(response.status==200 and response.headers['Content-Type']=='image/png' and response.read()==png)
check(request('/?action=workout_write',edit_form()|{'location_id':'0','notes':'bad change'},[('workout_photo_1','bad.svg',b'<svg/>')])[0]==422)
check('SYNTHETIC_EDITOR_NOTES' in request(edit_url)[2])
check(request('/?action=workout_write',edit_form()|{'location_id':'0','notes':'SYNTHETIC_EDITOR_NOTES'},[('workout_photo_0','replacement.png',png)])[0]==303)
check(request('/?action=workout_write',edit_form()|{'location_id':'0','notes':'SYNTHETIC_EDITOR_NOTES','delete_photo[]':'0'})[0]==303)
body=request(edit_url)[2];check('workout-photo-preview' not in body and re.search(r'name="make_public"[^>]*checked',body) is None)
saved_cookie=cookie;cookie=fixture['name']+'='+fixture['cookie'];check(request(edit_url)[0]==404);cookie=saved_cookie
print('Workout editor multipart HTTP checks passed.')
