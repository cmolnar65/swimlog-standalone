"""Real WordPress HTTP boundary checks; no private fixtures or production requests."""
import html, http.cookies, json, os, re, urllib.request, urllib.error
from urllib.parse import urlencode

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
fixture = json.load(open(os.environ['SWIMLOG_WEB_FIXTURE']))
count = 0

def check(value):
    global count
    count += 1
    if not value: raise AssertionError(f'HTTP assertion {count} failed')

def request(host, path='/', data=None, cookie='', origin=None, extra=None):
    headers = {'Host': host}
    if cookie: headers['Cookie'] = cookie
    if origin: headers['Origin'] = origin
    headers.update(extra or {})
    req = urllib.request.Request('http://127.0.0.1:8099' + path,
        data=None if data is None else urlencode(data).encode(), headers=headers)
    try: response = opener.open(req)
    except urllib.error.HTTPError as error: response = error
    return response.status, response.headers, response.read().decode()

def login_failure(result):
    status, headers, body = result
    check(status == 303 and headers.get('Location') == 'https://swimlog.example.test/?login_error=1')
    check(not any(value.startswith(fixture['name'] + '=') for value in headers.get_all('Set-Cookie', [])))

def form_value(body, name):
    return html.unescape(re.search(r'name="' + name + r'"[^>]*\bvalue="([^"]*)"', body)[1])

def editor_value(body, name):
    form = re.search(r'<form class="plan-editor"[^>]*>(.*?)</form>', body, re.S)[1]
    return form_value(form, name)

app = 'swimlog.example.test'
main = 'wordpress.example.test'
app_origin = 'https://' + app
main_origin = 'https://' + main
main_cookie = fixture['name'] + '=' + fixture['cookie']
status, headers, body = request(app)
check(status == 200 and 'Sign in with wordpress.example.test' in body and 'Welcome,' not in body)
check('no-store' in headers['Cache-Control'])
check(headers['Referrer-Policy'] == 'strict-origin-when-cross-origin')
check(main_origin in headers['Content-Security-Policy'])
check(request(app, '/?action=login')[0] == 405)
check(request(app, '/?action=login', {}, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=login', {}, origin='null')[0] == 403)
check(request(app, '/?action=login', {})[0] == 403)
check(request(app, extra={'X-Synthetic-Insecure': '1'})[0] == 303)
status, headers, body = request(app, '/?action=login', {}, origin=app_origin)
check(status == 303)
state = http.cookies.SimpleCookie()
state.load(headers['Set-Cookie'])
verifier = state['__Host-swimlog-state'].value
check(state['__Host-swimlog-state']['secure'] and state['__Host-swimlog-state']['httponly'] and not state['__Host-swimlog-state']['domain'])
endpoint = headers['Location'].removeprefix(main_origin)
check(endpoint.startswith('/?swimlog_standalone_auth=1&challenge='))
status, headers, body = request(main, endpoint)
check(status == 302 and '/wp-login.php' in headers['Location'])
status, headers, body = request(main, endpoint, cookie=main_cookie)
check(status == 200 and 'Signing you in' in body)
script_nonce = re.search(r'<script nonce="([^"]+)"', body)[1]
check("'nonce-" + script_nonce + "'" in headers['Content-Security-Policy'])
check("'unsafe-inline'" not in headers['Content-Security-Policy'])
nonce = form_value(body, 'nonce')
challenge = form_value(body, 'challenge')
login_failure(request(main, endpoint, {'challenge': challenge}, cookie=main_cookie, origin=main_origin))
status, headers, body = request(main, endpoint, {'challenge': challenge, 'nonce': nonce}, cookie=main_cookie, origin=main_origin)
check(status == 200)
code = form_value(body, 'code')
check(fixture['cookie'] not in body)
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin='https://evil.example.test'))
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + '0'*64, origin=main_origin))
status, headers, body = request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin=main_origin)
check(status == 303)
cookies = headers.get_all('Set-Cookie')
auth = next(c for c in cookies if c.startswith(fixture['name'] + '='))
check('secure' in auth.lower() and 'httponly' in auth.lower() and 'samesite=lax' in auth.lower() and 'domain=' not in auth.lower())
check('expires=' not in auth.lower())
login_failure(request(app, '/?action=callback', {'code': code}, cookie='__Host-swimlog-state=' + verifier, origin=main_origin))
status, headers, body = request(app, cookie=main_cookie)
check(status == 200 and 'Welcome,' in body and '&lt;script&gt;' in body and '<script>synthetic</script>' not in body)
check(request(app, cookie=fixture['name'] + '=' + fixture['other'])[0] == 403)
detail_nonce = re.search(r'data-detail-nonce="([^"]+)"', body)[1]
ids = fixture['workouts']
extra = {'X-Swimlog-Nonce':detail_nonce}
status, headers, body = request(app, '/?action=workout_detail&id='+str(ids['pool']), cookie=main_cookie, extra=extra)
check(status == 200 and 'no-store' in headers['Cache-Control'])
payload = json.loads(body)
check('Lengths and rest' in payload['html'] and 'PRIVATE_RAW_CANARY' not in body and 'PRIVATE_NOTES_CANARY' not in body)
check(request(app, '/?action=workout_detail&id='+str(ids['pool']), extra=extra)[0] == 401)
check(request(app, '/?action=workout_detail&id='+str(ids['pool']), cookie=main_cookie)[0] == 403)
check(request(app, '/?action=workout_detail&id='+str(ids['pool']), cookie=fixture['name']+'='+fixture['other'], extra=extra)[0] == 403)
foreign = request(app, '/?action=workout_detail&id='+str(ids['foreign']), cookie=main_cookie, extra=extra)
missing = request(app, '/?action=workout_detail&id=99999999', cookie=main_cookie, extra=extra)
check(foreign[0] == missing[0] == 404 and foreign[2] == missing[2])
check(request(app, '/?action=workout_detail&id[]=bad', cookie=main_cookie, extra=extra)[0] == 400)
check(request(app, '/?action=workout_detail&id='+str(ids['pool']), {}, cookie=main_cookie, extra=extra)[0] == 405)
status, headers, fallback = request(app, '/?workout='+str(ids['pool'])+'&date=2026-10-30&view=week', cookie=main_cookie)
check(status == 200 and 'Back to calendar' in fallback and 'date=2026-10-30&view=week' in html.unescape(fallback))
status, headers, log_body = request(app, '/?screen=log', cookie=main_cookie)
check(status == 200 and 'no-store' in headers['Cache-Control'] and '31 matching workouts' in log_body)
check('10,841.44 m' in log_body and 'PRIVATE_LOG_CANARY' not in log_body and 'PRIVATE_NOTES_CANARY' not in log_body)
check('data-log-row="'+str(ids['foreign'])+'"' not in log_body)
check('data-log-row' not in request(app, '/?screen=log')[2])
check(request(app, '/?screen=log', cookie=fixture['name']+'='+fixture['other'])[0] == 403)
status, headers, page_two = request(app, '/?screen=log&log_page=2', cookie=main_cookie)
check(status == 200 and 'Page 2 of 2' in page_two and '10,841.44 m' in page_two)
status, headers, invalid = request(app, '/?screen=log&from[]=bad', cookie=main_cookie)
check(status == 200 and 'Choose valid dates' in invalid and 'data-log-row' not in invalid)
status, headers, fallback = request(app, '/?screen=log&from=1900-01-01&to=2199-12-31&type=pool&log_page=2&workout='+str(ids['pool']), cookie=main_cookie)
check(status == 200 and 'Back to workout log' in fallback and 'screen=log&from=1900-01-01&to=2199-12-31&type=pool&log_page=2' in html.unescape(fallback))
check(request(app, '/?screen=unknown', cookie=main_cookie)[0] == 400)
status, headers, calendar_body = request(app, cookie=main_cookie)
plan_nonce = re.search(r'data-plan-nonce="([^\"]+)"', calendar_body)[1]
plans = fixture['plans']
plan_extra = {'X-Swimlog-Nonce':plan_nonce}
status, headers, planned = request(app, '/?action=planned_detail&id='+str(plans['plan']), cookie=main_cookie, extra=plan_extra)
check(status == 200 and 'no-store' in headers['Cache-Control'])
check('Synthetic yard prescription' in planned and 'PRIVATE_PLAN_NOTES_CANARY' not in planned and 'FOREIGN_STRUCTURE_CANARY' not in planned)
check(request(app, '/?action=planned_detail&id='+str(plans['plan']), extra=plan_extra)[0] == 401)
check(request(app, '/?action=planned_detail&id='+str(plans['plan']), cookie=main_cookie)[0] == 403)
check(request(app, '/?action=planned_detail&id='+str(plans['plan']), cookie=main_cookie, extra=extra)[0] == 403)
check(request(app, '/?action=planned_detail&id='+str(plans['plan']), cookie=fixture['name']+'='+fixture['other'], extra=plan_extra)[0] == 403)
foreign = request(app, '/?action=planned_detail&id='+str(plans['foreign']), cookie=main_cookie, extra=plan_extra)
missing = request(app, '/?action=planned_detail&id=99999999', cookie=main_cookie, extra=plan_extra)
check(foreign[0] == missing[0] == 404 and foreign[2] == missing[2])
check(request(app, '/?action=planned_detail&id[]=bad', cookie=main_cookie, extra=plan_extra)[0] == 400)
check(request(app, '/?action=planned_detail&id='+str(plans['plan']), {}, cookie=main_cookie, extra=plan_extra)[0] == 405)
status, headers, fallback = request(app, '/?plan='+str(plans['plan'])+'&date=2028-02-15&view=week', cookie=main_cookie)
check(status == 200 and 'Back to calendar' in fallback and 'date=2028-02-15&view=week' in html.unescape(fallback) and 'Synthetic yard prescription' in fallback)
check('Synthetic yard prescription' not in request(app, '/?plan='+str(plans['plan']))[2])
check(request(app, '/?plan='+str(plans['foreign']), cookie=main_cookie)[0] == 404)
check(request(app, '/?plan='+str(plans['plan'])+'&workout='+str(ids['pool']), cookie=main_cookie)[0] == 400)
# Real POST creation/editing boundary: no REST writes or owner IDs accepted.
status, headers, editor = request(app, '/?edit_plan=new&view=week', cookie=main_cookie)
check(status == 200 and 'no-store' in headers['Cache-Control'] and 'Save planned workout' in editor)
check(request(app, '/?action=plan_save', cookie=main_cookie)[0] == 405)
check(request(app, '/?action=plan_save', {}, origin=app_origin)[0] == 401)
fields = {name:editor_value(editor,name) for name in ['plan_id','nonce','revision','date','view']}
fields.update({'title':'Synthetic HTTP create','planned_date':editor_value(editor,'planned_date'),'planned_start_time':'07:30','workout_type':'pool','planned_distance':'500','planned_distance_unit':'yd','planned_duration':'20:30.125','pool_length_native':'25','pool_course':'yd','primary_stroke':'FR','workout_instructions':'Synthetic HTTP instructions'})
check(request(app, '/?action=plan_save', fields, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=plan_save', {**fields,'nonce':'bad'}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=plan_save', fields, cookie=main_cookie)[0] == 403)
status, headers, invalid = request(app, '/?action=plan_save', {**fields,'planned_duration':'10:99'}, cookie=main_cookie, origin=app_origin)
check(status == 422 and 'Synthetic HTTP create' in invalid and 'Seconds must be 00' in invalid)
status, headers, invalid = request(app, '/?action=plan_save', {**fields,'user_id':999}, cookie=main_cookie, origin=app_origin)
check(status == 422 and 'cannot change ownership' in invalid)
status, headers, created = request(app, '/?action=plan_save', fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and headers['Location'].startswith(app_origin+'/?') and 'saved=1' in headers['Location'] and 'view=week' in headers['Location'])
created_id = re.search(r'[?&]plan=(\d+)', headers['Location'])[1]
status, headers, editor = request(app, '/?edit_plan='+created_id+'&view=week', cookie=main_cookie)
check(status == 200 and 'Synthetic HTTP create' in editor and editor_value(editor,'planned_distance') == '500' and editor_value(editor,'planned_duration') == '20:30.125')
edit_fields = {**fields, **{name:editor_value(editor,name) for name in ['plan_id','nonce','revision','date','view']}, 'title':'Synthetic HTTP edited'}
status, headers, body = request(app, '/?action=plan_save', edit_fields, cookie=main_cookie, origin=app_origin)
check(status == 303)
status, headers, stale = request(app, '/?action=plan_save', edit_fields, cookie=main_cookie, origin=app_origin)
check(status == 409 and 'changed after the form was opened' in stale)
check(request(app, '/?edit_plan='+str(plans['matched']), cookie=main_cookie)[0] == 409)
check(request(app, '/?edit_plan='+str(plans['foreign']), cookie=main_cookie)[0] == 404)
check('Save planned workout' not in request(app, '/?edit_plan=new')[2])
status, headers, editor = request(app, '/?edit_plan='+str(plans['plan']), cookie=main_cookie)
check(status == 200 and 'PRIVATE_PLAN_NOTES_CANARY' not in editor)
# Move/copy forms use their own action-bound nonces and preserve calendar view.
status, headers, transfer = request(app, '/?plan_action=move&source_plan='+created_id+'&view=week', cookie=main_cookie)
check(status == 200 and 'Move planned workout' in transfer and 'no-store' in headers['Cache-Control'])
def transfer_value(body, name):
    form = re.search(r'<form class="plan-action-editor plan-editor"[^>]*>(.*?)</form>', body, re.S)[1]
    return form_value(form, name)
move_fields = {name:transfer_value(transfer,name) for name in ['plan_id','operation','nonce','revision','date','view','target_date']}
from datetime import date, timedelta
future = (date.fromisoformat(move_fields['target_date'])+timedelta(days=7)).isoformat()
drag_nonce = re.search(r'data-drag-nonce="([^\"]+)"', request(app, cookie=main_cookie)[2])[1]
drag_extra = {'X-Swimlog-Nonce':drag_nonce}
context_path = '/?action=plan_drag_context&id='+created_id+'&view=week'
status, headers, drag_context = request(app, context_path, cookie=main_cookie, extra=drag_extra)
check(status == 200 and 'no-store' in headers['Cache-Control'])
context = json.loads(drag_context)
check(context['operation'] == 'move' and str(context['plan_id']) == created_id and set(context) == {'plan_id','operation','nonce','revision','date','view'})
check(request(app, context_path, extra=drag_extra)[0] == 401)
check(request(app, context_path, cookie=main_cookie)[0] == 403)
check(request(app, context_path, cookie=main_cookie, extra=extra)[0] == 403)
check(request(app, context_path, {}, cookie=main_cookie, extra=drag_extra)[0] == 405)
foreign_drag = request(app, '/?action=plan_drag_context&id='+str(plans['foreign']), cookie=main_cookie, extra=drag_extra)
missing_drag = request(app, '/?action=plan_drag_context&id=99999999', cookie=main_cookie, extra=drag_extra)
check(foreign_drag[0] == missing_drag[0] == 404 and foreign_drag[2] == missing_drag[2])
check(request(app, '/?action=plan_drag_context&id='+str(plans['matched']), cookie=main_cookie, extra=drag_extra)[0] == 409)
move_fields = {**context, 'target_date':future, 'return_to':'calendar'}
move_fields['target_date'] = future
check(request(app, '/?action=plan_transfer', cookie=main_cookie)[0] == 405)
check(request(app, '/?action=plan_transfer', move_fields, origin=app_origin)[0] == 401)
check(request(app, '/?action=plan_transfer', move_fields, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=plan_transfer', {**move_fields,'nonce':'bad'}, cookie=main_cookie, origin=app_origin)[0] == 403)
status, headers, invalid = request(app, '/?action=plan_transfer', {**move_fields,'target_date':'1900-01-01'}, cookie=main_cookie, origin=app_origin)
check(status == 422 and '1900-01-01' in invalid and 'Choose today or a future date' in invalid)
check(request(app, '/?action=plan_transfer', {**move_fields,'user_id':'99'}, cookie=main_cookie, origin=app_origin)[0] == 422)
status, headers, body = request(app, '/?action=plan_transfer', move_fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and re.search(r'[?&]plan=',headers['Location']) is None and 'plan_result=moved' in headers['Location'] and 'date='+future in headers['Location'] and 'view=week' in headers['Location'])
check(request(app, '/?action=plan_transfer', move_fields, cookie=main_cookie, origin=app_origin)[0] == 409)
check(request(app, '/?plan_action=move&source_plan='+str(plans['matched']), cookie=main_cookie)[0] == 409)
check(request(app, '/?plan_action=copy&source_plan='+str(plans['foreign']), cookie=main_cookie)[0] == 404)
status, headers, transfer = request(app, '/?plan_action=copy&source_plan='+created_id+'&view=week', cookie=main_cookie)
copy_fields = {name:transfer_value(transfer,name) for name in ['plan_id','operation','nonce','revision','date','view','target_date']}
copy_fields['target_date']=future
status, headers, body = request(app, '/?action=plan_transfer', copy_fields, cookie=main_cookie, origin=app_origin)
copied_id = re.search(r'[?&]plan=(\d+)', headers.get('Location',''))[1]
check(status == 303 and copied_id != created_id and 'plan_result=copied' in headers['Location'])
check('Synthetic HTTP edited' in request(app, '/?plan='+created_id, cookie=main_cookie)[2])
# Explicit matching: owner/type filtering, confirmation and unchanged existing matches.
match_create = {**fields,'title':'Synthetic match HTTP','workout_type':'open_water','pool_length_native':'','pool_course':'','planned_distance':'2500','planned_distance_unit':'m'}
status, headers, body = request(app, '/?action=plan_save', match_create, cookie=main_cookie, origin=app_origin)
check(status == 303)
match_plan = re.search(r'[?&]plan=(\d+)', headers['Location'])[1]
status, headers, match_body = request(app, '/?match_plan='+match_plan+'&view=week', cookie=main_cookie)
check(status == 200 and 'no-store' in headers['Cache-Control'] and 'Confirm match' in match_body)
check('data-candidate-id="'+str(ids['open_water'])+'"' in match_body and 'data-candidate-id="'+str(ids['running'])+'"' not in match_body and 'PRIVATE_NOTES_CANARY' not in match_body)
def match_value(body, name):
    form = re.search(r'<form class="plan-match-editor"[^>]*>(.*?)</form>', body, re.S)[1]
    return form_value(form, name)
match_fields = {name:match_value(match_body,name) for name in ['plan_id','nonce','revision','date','view','recorded_date','match_page','recorded_choice']}
check(request(app, '/?action=plan_match', cookie=main_cookie)[0] == 405)
check(request(app, '/?action=plan_match', match_fields, origin=app_origin)[0] == 401)
check(request(app, '/?action=plan_match', match_fields, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=plan_match', {**match_fields,'nonce':'bad'}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=plan_match', {**match_fields,'recorded_choice':''}, cookie=main_cookie, origin=app_origin)[0] == 422)
check(request(app, '/?action=plan_match', {**match_fields,'recorded_choice':str(ids['foreign'])+':'+('a'*64)}, cookie=main_cookie, origin=app_origin)[0] == 422)
check(request(app, '/?action=plan_match', {**match_fields,'user_id':'999'}, cookie=main_cookie, origin=app_origin)[0] == 422)
check(request(app, '/?match_plan='+str(plans['foreign']), cookie=main_cookie)[0] == 404)
check(request(app, '/?match_plan='+str(plans['matched']), cookie=main_cookie)[0] == 409)
check('Confirm match' not in request(app, '/?match_plan='+match_plan)[2])
status, headers, body = request(app, '/?action=plan_match', match_fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and 'plan_result=matched' in headers['Location'] and 'view=week' in headers['Location'])
check(request(app, '/?action=plan_match', match_fields, cookie=main_cookie, origin=app_origin)[0] == 409)
status, headers, matched_body = request(app, '/?plan='+match_plan, cookie=main_cookie)
check('View matched recorded workout' in matched_body and 'Match recorded workout</a>' not in matched_body and 'Edit planned workout</a>' not in matched_body)
status, headers, calendar_matched = request(app, cookie=main_cookie)
check(re.search(r'class="[^"]*is-matched[^\"]*"[^>]*data-workout-id="'+str(ids['open_water'])+'"',calendar_matched) is not None)
# Events use their own capability, nonce, metadata and result boundaries.
events = fixture['events']
status, headers, event_calendar = request(app, cookie=main_cookie)
event_nonce = re.search(r'data-event-nonce="([^\"]+)"', event_calendar)[1]
event_extra = {'X-Swimlog-Nonce':event_nonce}
status, headers, event_payload = request(app, '/?action=event_detail&id='+str(events['own']), cookie=main_cookie, extra=event_extra)
check(status == 200 and 'no-store' in headers['Cache-Control'] and '1:23.456' in event_payload and 'PRIVATE_EVENT_NOTES_CANARY' in event_payload)
check('PRIVATE_LOCATION_NOTES_CANARY' not in event_payload and 'FOREIGN_EVENT_LOCATION' not in event_payload)
check(request(app, '/?action=event_detail&id='+str(events['own']), extra=event_extra)[0] == 401)
check(request(app, '/?action=event_detail&id='+str(events['own']), cookie=main_cookie)[0] == 403)
foreign_event = request(app, '/?action=event_detail&id='+str(events['foreign']), cookie=main_cookie, extra=event_extra)
missing_event = request(app, '/?action=event_detail&id=99999999', cookie=main_cookie, extra=event_extra)
check(foreign_event[0] == missing_event[0] == 404 and foreign_event[2] == missing_event[2])
check(request(app, '/?action=event_detail&id[]=bad', cookie=main_cookie, extra=event_extra)[0] == 400)
check(request(app, '/?action=event_detail&id='+str(events['own']), {}, cookie=main_cookie, extra=event_extra)[0] == 405)
status, headers, fallback_event = request(app, '/?event='+str(events['own'])+'&date=2028-02-15&view=week', cookie=main_cookie)
check(status == 200 and 'Event details' in fallback_event and 'date=2028-02-15&view=week' in html.unescape(fallback_event))
status, headers, event_form = request(app, '/?edit_event=new&view=week', cookie=main_cookie)
def event_value(body,name):
    form = re.search(r'<form class="event-editor plan-editor"[^>]*>(.*?)</form>', body, re.S)[1]
    return form_value(form,name)
event_fields = {name:event_value(event_form,name) for name in ['event_id','operation','nonce','revision','date','view']}
event_fields.update({'event_name':'Synthetic HTTP event','event_date':event_value(event_form,'event_date'),'event_time':'07:30','location_id':str(events['location']),'distance_value':'100','course_unit':'yd','stroke':'BR','notes':'Synthetic HTTP event notes'})
check(request(app, '/?action=event_save', cookie=main_cookie)[0] == 405)
check(request(app, '/?action=event_save', event_fields, origin=app_origin)[0] == 401)
check(request(app, '/?action=event_save', event_fields, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=event_save', {**event_fields,'nonce':'bad'}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=event_save', {**event_fields,'location_id':str(events['foreign_location'])}, cookie=main_cookie, origin=app_origin)[0] == 422)
status, headers, event_invalid = request(app, '/?action=event_save', {**event_fields,'event_date':'2026-02-30'}, cookie=main_cookie, origin=app_origin)
check(status == 422 and 'Synthetic HTTP event' in event_invalid)
status, headers, body = request(app, '/?action=event_save', event_fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and 'event_saved=event' in headers['Location'] and 'view=week' in headers['Location'])
new_event_id = re.search(r'[?&]event=(\d+)',headers['Location'])[1]
status, headers, result_form = request(app, '/?event_result='+new_event_id+'&view=week', cookie=main_cookie)
result_fields = {name:event_value(result_form,name) for name in ['event_id','operation','nonce','revision','date','view']}
result_fields.update({'result_time':'1:23.456','placement':'2','notes':'Synthetic result notes'})
check(request(app, '/?action=event_save', {**result_fields,'result_time':'1:99'}, cookie=main_cookie, origin=app_origin)[0] == 422)
check(request(app, '/?action=event_save', {**result_fields,'user_id':'999'}, cookie=main_cookie, origin=app_origin)[0] == 422)
status, headers, body = request(app, '/?action=event_save', result_fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and 'event_saved=result' in headers['Location'])
check(request(app, '/?action=event_save', result_fields, cookie=main_cookie, origin=app_origin)[0] == 409)
status, headers, event_form = request(app, '/?edit_event='+new_event_id, cookie=main_cookie)
edit_event_fields = {**event_fields,**{name:event_value(event_form,name) for name in ['event_id','operation','nonce','revision','date','view']},'event_name':'Synthetic HTTP event edited','notes':'Synthetic result notes'}
check(request(app, '/?action=event_save', edit_event_fields, cookie=main_cookie, origin=app_origin)[0] == 303)
status, headers, event_detail = request(app, '/?event='+new_event_id, cookie=main_cookie)
check('1:23.456' in event_detail and 'Synthetic result notes' in event_detail and 'Synthetic HTTP event edited' in event_detail)
check(request(app, '/?event_result='+str(events['foreign']), cookie=main_cookie)[0] == 404)
check(request(app, '/?event='+str(events['own'])+'&edit_event=new', cookie=main_cookie)[0] == 400)
check('Record race result' not in request(app, '/?event='+str(events['own']))[2])
status, headers, listing = request(app, '/?screen=events&event_view=all', cookie=main_cookie)
check(status == 200 and 'id="events-title"' in listing and 'Synthetic meet' in listing)
check('PRIVATE_EVENT_NOTES_CANARY' not in listing and 'FOREIGN_PRIVATE_EVENT' not in listing and 'PRIVATE_LOCATION_NOTES_CANARY' not in listing)
check('List Events' in listing and 'Add Event' in listing)
check('id="events-title"' not in request(app, '/?screen=events')[2])
check('Choose Upcoming' in request(app, '/?screen=events&event_view=invalid', cookie=main_cookie)[2])
check('Choose a valid event page' in request(app, '/?screen=events&event_page[]=1', cookie=main_cookie)[2])
status, headers, calendar_body = request(app, cookie=main_cookie)
day = re.search(r'class="calendar-day is-today[^>]*', calendar_body)
date = re.search(r'<time datetime="([^"]+)"',calendar_body[day.start():])[1]
status, headers, day_body = request(app, '/?day='+date, cookie=main_cookie)
check(status == 200 and day_body.count('class="calendar-day') == 1 and 'Back to calendar' in day_body)
check(request(app, '/?day=2026-02-30', cookie=main_cookie)[0] == 400)
check(request(app, '/?day='+date+'&event=1', cookie=main_cookie)[0] == 400)
# Structured workout pages, editor assets and write/export boundaries.
check(request(app, '/?screen=structures')[0] == 401)
check(request(app, '/?action=structured_asset&kind=js')[0] == 401)
status, headers, structure_list = request(app, '/?screen=structures', cookie=main_cookie)
check(status == 200 and 'Structured Workouts' in structure_list and 'FOREIGN_STRUCTURE_CANARY' not in structure_list)
check(request(app, '/?action=structured_asset&kind=../../wp-config.php', cookie=main_cookie)[0] == 404)
status, headers, editor_js = request(app, '/?action=structured_asset&kind=js', cookie=main_cookie)
check(status == 200 and 'javascript' in headers['Content-Type'] and 'const initialize' in editor_js)
check(request(app, '/?action=structured_write', cookie=main_cookie)[0] == 405)
status, headers, structure_editor = request(app, '/?screen=structures&edit_structure=new', cookie=main_cookie)
check(status == 200 and 'swimlog-structure-editor' in structure_editor)
structure_fields = {name:form_value(structure_editor,name) for name in ['structure_id','operation','revision','document']}
structure_fields['nonce'] = re.search(r'name="nonce" value="([^"]+)"',re.search(r'<form class="structure-editor".*?</form>', structure_editor,re.S)[0])[1]
structure_fields['title'] = 'Synthetic HTTP standalone structure'
check(request(app, '/?action=structured_write', structure_fields, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=structured_write', {**structure_fields,'nonce':'bad'}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=structured_write', {**structure_fields,'user_id':'999'}, cookie=main_cookie, origin=app_origin)[0] == 422)
check(request(app, '/?action=structured_write', {**structure_fields,'document':'{}'}, cookie=main_cookie, origin=app_origin)[0] == 422)
status, headers, body = request(app, '/?action=structured_write', structure_fields, cookie=main_cookie, origin=app_origin)
check(status == 303 and 'structure_saved=save' in headers['Location'])
structure_id = re.search(r'[?&]structure=(\d+)',headers['Location'])[1]
status, headers, structure_detail = request(app, '/?screen=structures&structure='+structure_id, cookie=main_cookie)
check(status == 200 and 'Download FIT' in structure_detail and 'Confirm permanent deletion' in structure_detail)
check(request(app, '/?action=structured_download&id='+structure_id+'&format=txt&nonce=bad', cookie=main_cookie)[0] == 403)
check(request(app, '/?screen=structures&structure='+structure_id+'&edit_structure=new', cookie=main_cookie)[0] == 400)
status, headers, body = request(app, cookie=main_cookie)
logout_nonce = form_value(body, 'nonce')
check(request(app, '/?action=logout', {}, cookie=main_cookie, origin=app_origin)[0] == 403)
check(request(app, '/?action=logout', {'nonce': logout_nonce}, cookie=main_cookie, origin='https://evil.example.test')[0] == 403)
check(request(app, '/?action=logout', {'nonce': logout_nonce}, cookie=main_cookie, origin=app_origin)[0] == 303)
check('Welcome,' not in request(app, cookie=main_cookie)[2])
check(request(app, '/?action=workout_detail&id='+str(ids['pool']), cookie=main_cookie, extra=extra)[0] == 401)
check(request(main, endpoint, cookie=main_cookie)[0] == 302)
for username, password in [('synthetic_swimmer','incorrect'),('','')]:
    login_failure(request(main, '/wp-login.php', {'log':username,'pwd':password,'redirect_to':main_origin+endpoint,'wp-submit':'Log In'}, origin=main_origin))
status, headers, body = request(app, '/?login_error=1')
check(status == 200 and 'Sign-in could not be completed' in body and 'data-swimlog-login' in body and 'Welcome,' not in body)
status, headers, body = request(main, '/wp-login.php', {'log':'synthetic_swimmer','pwd':'incorrect','redirect_to':main_origin+'/wp-admin/','wp-submit':'Log In'}, origin=main_origin)
check(status == 200 and headers.get('Location') is None)
print(f'HTTP authentication passed: {count} assertions.')
