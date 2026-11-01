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
# Create then edit a synthetic authoritative manual record through real forms.
s,h,b=request();check(s==200);v={k:v for k,v in values(b,'manual-entry').items() if not k.startswith('sets[')};v.update(workout_date='2003-04-05',workout_time='12:00',pool_length='25',course_unit='m',total_distance='100',duration='60.123',notes='SYNTHETIC_MANUAL_EDIT',location_id='0',payload_complete='1')
s,h,b=request('/?action=manual_save',v);check(s==303);id=re.search(r'workout=(\d+)',h['Location'])[1]
s,h,b=request('/?screen=manual&edit_manual='+id);check(s==200 and 'Edit Manual Workout' in b);v={k:v for k,v in values(b,'manual-entry').items() if not k.startswith('sets[')};v.update(course_unit='yd',location_id='0',notes='UPDATED_SYNTHETIC',total_distance='200');s,h,b=request('/?action=manual_update',v);check(s==303);check(request('/?action=manual_update',v)[0]==409);check(request('/?action=manual_update',v,origin='https://evil.test')[0]==403);check(request('/?screen=manual&edit_manual='+id,auth=False)[0]==401)
# Build a small, selectable-text PDF with synthetic instructions (no external fixture).
def pdf(lines):
 content='BT /F1 12 Tf 40 750 Td '+ ' '.join(('0 -18 Td ' if i else '')+'('+line+') Tj' for i,line in enumerate(lines))+' ET'
 objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '+str(len(content))+' >>\nstream\n'+content+'\nendstream'];out=b'%PDF-1.4\n';offsets=[0]
 for i,obj in enumerate(objects,1):offsets.append(len(out));out+=(str(i)+' 0 obj\n'+obj+'\nendobj\n').encode()
 xref=len(out);out+=('xref\n0 6\n0000000000 65535 f \n'+''.join('%010d 00000 n \n'%i for i in offsets[1:])+'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+str(xref)+'\n%%EOF').encode();return out
payload=pdf(['Workout 1','4 x 100 yards free','Total: 400 yards','Workout 2','100 meters free','Total: 100 meters'])
s,h,b=request('/?screen=training_pdf');check(s==200 and 'Import Training Plan PDF' in b);v=values(b,'settings-form');v['acknowledge']='1'
check(request('/?action=pdf_upload',v,[('training_pdf','wrong.txt',payload)])[0]==422);check(request('/?action=pdf_upload',v,[('training_pdf','synthetic.pdf',b'not a pdf')])[0]==422)
s,h,b=request('/?action=pdf_upload',v,[('training_pdf','synthetic.pdf',payload)]);check(s==303);path=follow(h);s,h,b=request(path);check(s==200 and 'Private extracted text preview' in b and '4 x 100 yards free' in b);check('no-store' in h['Cache-Control']);check(request(path,auth=False)[0]==401)
v=values(b,'pdf-review');v.update(name='Synthetic standalone PDF plan');doc=json.loads(v['reviews[1][document]']);check(doc['unit']=='yd');v.update({'swimlog_pdf_selected[]':'1','reviews[1][total]':'400','reviews[1][unit]':'yd','reviews[1][reviewed]':'1','payload_complete':'1'})
check(request('/?action=pdf_import',dict(v,nonce='bad'))[0]==403);check(request('/?action=pdf_import',dict(v,revision='stale'))[0]==409);bad=v.copy();bad.pop('payload_complete');check(request('/?action=pdf_import',bad)[0]==422);bad=v.copy();bad.pop('reviews[1][reviewed]');check(request('/?action=pdf_import',bad)[0]==422);bad=v.copy();bad['reviews[1][total]']='999';check(request('/?action=pdf_import',bad)[0]==422)
s,h,b=request('/?action=pdf_import',v);check(s==303 and 'pdf_imported=1' in h['Location']);check(request('/?action=pdf_import',v)[0]==303);s,h,b=request(follow(h));check(s==200 and 'Training plan imported' in b);check('PRIVATE_' not in b)
# Same source reupload must not create another collection.
s,h,b=request('/?screen=training_pdf');u=values(b,'settings-form');u['acknowledge']='1';s,h,b=request('/?action=pdf_upload',u,[('training_pdf','synthetic.pdf',payload)]);s,h,b=request(follow(h));fresh=values(b,'pdf-review');v.update(token=fresh['token'],nonce=fresh['nonce'],revision=fresh['revision']);s,h,b=request('/?action=pdf_import',v);check(s==422 and 'already been imported' in b)
print('Manual editing/PDF HTTP: '+str(count)+' assertions passed.')
