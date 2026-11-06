import hmac, hashlib, json, time, urllib.parse, urllib.request, sys
TOKEN='123456:TEST-TOKEN'; BASE='http://127.0.0.1:8000/api'
def init(uid, start=None, age=0, token=TOKEN):
    f={'auth_date':str(int(time.time())-age),'query_id':'AAH','user':json.dumps({'id':uid,'first_name':'Ник','username':'nik%d'%uid},ensure_ascii=False)}
    if start: f['start_param']=start
    dcs='\n'.join('%s=%s'%(k,f[k]) for k in sorted(f))
    sec=hmac.new(b'WebAppData',token.encode(),hashlib.sha256).digest()
    f['hash']=hmac.new(sec,dcs.encode(),hashlib.sha256).hexdigest()
    return urllib.parse.urlencode(f)
def call(m,path,body=None,auth=None,raw=None):
    h={'Accept':'application/json'}
    if auth: h['Authorization']='tma '+auth
    data=None
    if body is not None: data=json.dumps(body).encode(); h['Content-Type']='application/json'
    if raw is not None: data=raw
    r=urllib.request.Request(BASE+path,data=data,method=m,headers=h)
    try:
        with urllib.request.urlopen(r) as x: return x.status,json.loads(x.read() or b'null')
    except urllib.error.HTTPError as e:
        return e.code,json.loads(e.read() or b'null')
def ok(c,msg):
    print(('PASS ' if c else 'FAIL ')+msg)
    if not c: ok.bad+=1
ok.bad=0
a=init(1001)
s,_=call('GET','/me'); ok(s==401,'no header -> 401')
s,_=call('GET','/me',auth=a[:-3]+'000'); ok(s==401,'bad hash -> 401')
s,_=call('GET','/me',auth=init(1001,age=999999)); ok(s==401,'expired -> 401')
s,_=call('GET','/me',auth=init(1001,token='1:OTHER')); ok(s==401,'foreign token -> 401')
s,me=call('POST','/auth/telegram',auth=a); print(s,me.get('balance'));ok(s==200 and me['balance']==50,'auth creates user with signup bonus '+str(me)[:120])
s,b=call('GET','/bootstrap',auth=a); ok(s==200 and len(b['models']['image'])==4 and len(b['photosets'])==6,'bootstrap')
# daily bonus
s,d=call('POST','/bonus/daily',auth=a); ok(s==200 and d['granted'],'daily bonus'); s,d2=call('POST','/bonus/daily',auth=a); ok(not d2['granted'],'daily bonus only once')
# generation
s,g=call('POST','/generations',{'kind':'image','prompt':'кот','model':'auto'},auth=a); ok(s==201,'create gen '+str(g)[:150]); gid=g['data'][0]['id'] if s==201 else 0
time.sleep(4)
s,g=call('GET','/generations/%d'%gid,auth=a); ok(g['data']['status']=='done' and g['data']['result_url'],'gen done by worker '+str(g)[:200])
s,g=call('POST','/generations',{'kind':'image','prompt':'кот','model':'photo'},auth=a); ok(s==403 and g['code']=='model_locked','locked model 403')
s,g=call('POST','/generations',{'kind':'video','prompt':'x'},auth=a); ok(s==403,'video needs pro')
s,g=call('POST','/generations',{'kind':'image','prompt':''},auth=a); ok(s==422,'empty prompt 422')
s,u=call('GET','/me',auth=a); bal=u['balance']; ok(bal==50+5-8,'balance after gen = %d'%bal)
for i in range(6): call('POST','/generations',{'kind':'image','prompt':'a','model':'auto'},auth=a)
s,g=call('POST','/generations',{'kind':'image','prompt':'a','model':'auto'},auth=a); ok(s==402 and g['code']=='insufficient_balance','402 when broke: %s'%str(g)[:100])
# chat
s,c=call('POST','/chats/messages',{'message':'привет'},auth=a); ok(s in(200,402),'chat '+str(c)[:100])
# payments
a2=init(2002, start='ref_1001')
s,_=call('POST','/auth/telegram',auth=a2)
s,p=call('POST','/payments/topup',{'tokens':500},auth=a2); ok(s==201 and p['data']['confirmation_url'].startswith('https://yoomoney'),'topup created '+str(p)[:120]); pid=p['data']['id']
s,p=call('POST','/payments/topup',{'tokens':777},auth=a2); ok(s==422,'bad pack 422')
s,x=call('GET','/payments/%d'%pid,auth=a2); ok(x['data']['status']=='pending','pending')
urllib.request.urlopen('http://127.0.0.1:9001/admin/succeed/pay-1').read()
wh={'type':'notification','event':'payment.succeeded','object':{'id':'pay-1','status':'succeeded'}}
s,_=call('POST','/payments/yookassa/webhook',wh); ok(s==200,'webhook 200')
s,_=call('POST','/payments/yookassa/webhook',wh); ok(s==200,'webhook repeat 200')
s,u=call('GET','/me',auth=a2); ok(u['balance']==50+500,'credited exactly once: %d'%u['balance'])
s,t=call('GET','/transactions',auth=a2); print([ (r['type'],r['amount']) for r in t['data']])
# plan
s,p=call('POST','/payments/plan',{'plan':'plus','period':'month'},auth=a2); ok(s==201 and p['data']['amount']==780,'plan payment 780')
urllib.request.urlopen('http://127.0.0.1:9001/admin/succeed/pay-2').read()
s,x=call('GET','/payments/%d'%p['data']['id'],auth=a2); ok(x['data']['status']=='succeeded' and x['user']['plan']=='plus','plan activated via poll '+str(x['user'])[:200])
# referral: friend does first generation
s,g=call('POST','/generations',{'kind':'image','prompt':'a','model':'auto'},auth=a2); time.sleep(4)
s,u=call('GET','/me',auth=a2); print('friend balance',u['balance'])
s,u=call('GET','/me',auth=a); print('referrer balance',u['balance'])
# photoset upload (multipart)
print('FAILED' if ok.bad else 'ALL OK', ok.bad)
