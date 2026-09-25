class DesignComponent extends DCLogic {
  F = ['#23343f','#4a3b31','#33413a','#3d3447','#5a4a30','#27403f','#4a2f33','#30384a','#41392c','#2d3b2d'];
  ST = {Active:['oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Completed:['oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Published:['oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Paid:['oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Processing:['oklch(0.45 0.13 250)','oklch(0.95 0.03 250)'],Pending:['oklch(0.5 0.13 70)','oklch(0.95 0.05 85)'],Refunded:['oklch(0.5 0.13 70)','oklch(0.95 0.05 85)'],Failed:['oklch(0.5 0.18 25)','oklch(0.95 0.035 25)'],Suspended:['oklch(0.5 0.18 25)','oklch(0.95 0.035 25)'],Draft:['#55575c','#efefec']};
  USERS = [['John Doe','john@acme.co',248,2480,'Professional','Active','Jan 12, 2026'],['Maya Chen','maya@northwind.io',131,860,'Agency','Active','Feb 03, 2026'],['Leo Martins','leo@brightpath.com',42,0,'Starter','Active','Mar 21, 2026'],['Aisha Bello','aisha.b@gmail.com',9,120,'Free','Pending','Sep 22, 2026'],['Tomás Ruiz','tomas@kinetic.es',87,1540,'Professional','Active','Apr 08, 2026'],['Hannah Klein','h.klein@studio-k.de',315,9800,'Agency','Active','Nov 30, 2025'],['Ryan Patel','ryan@growthlab.co',3,40,'Free','Suspended','Aug 14, 2026'],['Emma Laurent','emma@maisonbleu.fr',56,310,'Starter','Active','Jun 02, 2026'],['Kenji Watanabe','kenji@tokyoreels.jp',174,4200,'Professional','Active','Dec 19, 2025'],['Sara Ahmed','sara@clearview.ae',22,0,'Starter','Active','Jul 27, 2026']].map((u,i)=>({id:i,name:u[0],email:u[1],videos:u[2],credits:u[3],plan:u[4],status:u[5],joined:u[6],c:this.F[(i*3+1)%10]}));
  PROV = [
    ['or','OpenRouter','OR','Text','Free + paid models','connected','llama-3.3-70b:free','184,210','$41.20'],
    ['gem','Google Gemini','GG','Text','Free tier','connected','gemini-2.0-flash','92,044','$0.00'],
    ['groq','Groq','GQ','Text','Free tier','rate','llama-3.1-70b','61,380','$0.00'],
    ['cf','Cloudflare Workers AI','CF','Image','Free daily quota','connected','flux-1-schnell','38,902','$12.60'],
    ['hf','Hugging Face','HF','Image','Free inference','connected','stable-diffusion-xl','12,448','$0.00'],
    ['fal','Fal.ai','FA','Video','Pay as you go','connected','ltx-video','4,120','$386.40'],
    ['el','ElevenLabs','EL','Voice','Paid · voice cloning','error','multilingual-v2','8,221','$172.20'],
    ['edge','Edge TTS','ET','Voice','Free · no key','connected','en-US-Neural','29,870','$0.00'],
    ['custom','Custom OpenAI-compatible','{ }','Text','Self-hosted vLLM','disabled','qwen2.5-32b','0','$0.00'],
    ['whisper','Groq Whisper','GW','Speech','Speech-to-text · free tier','connected','whisper-large-v3','3,410','$0.00'],
    ['dg','Deepgram','DG','Speech','Speech-to-text · paid','off','nova-3','0','$0.00'],
    ['suno','Music API (custom)','MU','Audio','Background music · paid','off','music-v1','0','$0.00']
  ].map(p=>({id:p[0],name:p[1],mono:p[2],cat:p[3],tier:p[4],status:p[5],model:p[6],req:p[7],cost:p[8],url:{or:'https://openrouter.ai/api/v1',gem:'https://generativelanguage.googleapis.com',groq:'https://api.groq.com/openai/v1',cf:'https://api.cloudflare.com/client/v4',hf:'https://api-inference.huggingface.co',fal:'https://fal.run',el:'https://api.elevenlabs.io/v1',edge:'—',custom:'http://10.0.0.12:8000/v1'}[p[0]]}));
  MODELS = [['llama-3.3-70b-instruct:free','OpenRouter','Text','Free','$0.00 / 1M tok',0,true],['deepseek-chat-v3:free','OpenRouter','Text','Free','$0.00 / 1M tok',0,false],['gpt-4o-mini','OpenRouter','Text','Paid','$0.60 / 1M tok',2,false],['gemini-2.0-flash','Google Gemini','Text','Free','$0.00 / 1M tok',0,false],['llama-3.1-70b','Groq','Text','Free','$0.00 / 1M tok',0,false],['flux-1-schnell','Cloudflare Workers AI','Image','Free','$0.00 / image',1,true],['stable-diffusion-xl','Hugging Face','Image','Free','$0.00 / image',1,false],['flux-1.1-pro','Replicate','Image','Paid','$0.04 / image',4,false],['ltx-video','Fal.ai','Video','Paid','$0.02 / sec',3,true],['wan-2.1-t2v','Fal.ai','Video','Paid','$0.05 / sec',6,false],['en-US-Neural','Edge TTS','Voice','Free','$0.00 / min',0,true],['multilingual-v2','ElevenLabs','Voice','Paid','$0.18 / min',8,false]].map((m,i)=>({id:m[0],prov:m[1],type:m[2],tier:m[3],cost:m[4],credits:m[5],def:m[6],on:i!==7,idx:i}));
  PAGES = [['Home','/','Published','Create professional AI videos with your own AI providers','Idea to script to finished video in minutes. No mandatory platform subscription.'],['About','/about','Published','About Acme Video Cloud','We help businesses publish more video with less effort.'],['Pricing','/pricing','Published','Pricing — Acme Video Cloud','Simple plans that scale with your content.'],['Terms','/terms','Published','Terms of Service','These terms govern your use of Acme Video Cloud.'],['Privacy','/privacy','Draft','Privacy Policy','How we collect, use and protect your data.'],['Contact','/contact','Published','Contact us','Questions about plans, billing or enterprise? We reply within one business day.']];

  state = {
    w: typeof window!=='undefined'?window.innerWidth:1400,
    screen:(typeof localStorage!=='undefined'&&localStorage.getItem('rs_admin_screen'))||this.props.startScreen||'overview',
    drawer:false, range:'30d', uq:'', uTab:'All', users:this.USERS, userId:null, adjMode:'Add', adjAmt:'500',
    vTab:'All', prov:this.PROV, pTab:'All', provEdit:null, provAdd:false, preset:'OpenRouter', provTest:null, provResult:null,
    models:this.MODELS, mTab:'All', tpls:null, confirm:null, toasts:[],
    rules:[['Script generation','type','$0.001',2],['AI image','image','$0.004',1],['AI video (per second)','clapperboard','$0.035',3],['Voiceover (per minute)','mic','$0.09',6],['Render (per minute)','film','$0.002',2],['Caption generation','captions','$0.000',0]],
    planEdit:null, planFeats:{}, gw:{stripe:true,paypal:true,razorpay:false,paystack:false,bank:false},
    keys:[['Production','rsk_live_••••8f2a','John Doe','48,210','120 / min','2 min ago'],['Zapier integration','rsk_live_••••11cd','Maya Chen','9,114','60 / min','1 hour ago'],['Staging','rsk_test_••••a09e','Admin','1,202','30 / min','Sep 18, 2026'],['Old mobile app','rsk_live_••••77b1','Admin','112,880','120 / min','Jun 02, 2026']].map((k,i)=>({id:i,name:k[0],key:k[1],owner:k[2],req:k[3],rate:k[4],last:k[5],revoked:i===3})),
    newKey:null, ep:0,
    wl:{appName:'Acme Video Cloud', company:'Acme Media Ltd.', domain:'video.acme.co', primary:'#2f6fed', secondary:'#0f1b33', loginHeadline:'Publish a week of video content in one afternoon.', support:'help@acme.co', website:'https://acme.co', footer:'© 2026 Acme Media Ltd. · Terms · Privacy', emailHeader:'#0f1b33', emailFooter:'Acme Media Ltd. · 12 Harbour St, London · You receive this because you have an account.', twitter:'@acmevideo', linkedin:'acme-media', favicon:'favicon-acme.png', logo:'acme-logo.svg', termVideo:'Video', termCredits:'Credits', termWorkflows:'Automations', termWorkspace:'Studio', font:'Geist'},
    wlSaved:null, wlTab:'App', page:0, setTab:'General', setToggles:{reg:true,verify:true,queue:true,fallback:true,twofa:true,watermarkFree:true,s3:true,smtpTls:true,cronAlert:true,ffhw:false}, logLvl:'All'
  };

  componentDidMount(){ this.onR=()=>this.setState({w:window.innerWidth}); window.addEventListener('resize',this.onR); this.setState({wlSaved:JSON.stringify(this.state.wl), tpls:this.tplData()}); }
  componentWillUnmount(){ window.removeEventListener('resize',this.onR); }
  tplData(){ return [['Hook · Story · Offer','TikTok','9:16','0:30',6,'2,418','Published'],['Product Spotlight','Product Ads','9:16','0:20',4,'1,902','Published'],['Listing Walkthrough','Real Estate','16:9','1:00',8,'644','Published'],['Feature Launch','SaaS Ads','16:9','0:45',6,'1,120','Published'],['Top 5 Countdown','Faceless Content','9:16','0:58',7,'3,305','Published'],['Holiday Gift Guide','Ecommerce','4:5','0:30',5,'0','Draft'],['Mini Lesson','Education','16:9','1:30',9,'512','Published']].map((t,i)=>({id:i,name:t[0],cat:t[1],ratio:t[2],dur:t[3],scenes:t[4],uses:t[5],status:t[6],by:i===5?'Admin':'Reelsmith',c:this.F[(i*3)%10]})); }

  go=k=>{this.setState({screen:k,drawer:false}); try{localStorage.setItem('rs_admin_screen',k)}catch(e){} window.scrollTo(0,0);};
  toast=(msg,kind)=>{const id=Math.random(); const m={ok:['icon-circle-check','oklch(0.75 0.14 150)'],err:['icon-circle-x','oklch(0.72 0.17 25)'],info:['icon-info','oklch(0.78 0.1 250)']}[kind||'ok']; this.setState(s=>({toasts:[...s.toasts,{id,msg,icon:m[0],c:m[1]}]})); setTimeout(()=>this.setState(s=>({toasts:s.toasts.filter(t=>t.id!==id)})),3000);};
  ask=(c)=>this.setState({confirm:c});
  chips(list,cur,fn){ return list.map(l=>{const a=l===cur; return {label:l,on:()=>fn(l),segBg:a?'#fff':'transparent',segSh:a?'0 1px 2px rgba(0,0,0,.08)':'none'};}); }
  tog(on){ return {trk:on?'#17181a':'#d6d4ce',knob:on?'19px':'3px'}; }
  setWl=(k,v)=>this.setState(s=>({wl:{...s.wl,[k]:v}}));

  renderVals(){
    const s=this.state, isMobile=s.w<860, isNarrow=s.w<1180;
    const N=(k,label,icon,dot)=>({label,icon:'icon-'+icon,go:()=>this.go(k),bg:s.screen===k?'#1f2126':'transparent',fg:s.screen===k?'#fff':'#b7b8bd',hasDot:!!dot,dot});
    const provIssue = s.prov.some(p=>p.status==='error'||p.status==='rate');
    const navGroups=[
      {title:'Platform',items:[N('overview','Overview','layout-dashboard'),N('users','Users','users'),N('projects','Projects','folder'),N('videos','Videos','film')]},
      {title:'AI',items:[N('providers','AI Providers','plug',provIssue?'oklch(0.75 0.15 70)':null),N('routing','Smart Routing','route'),N('models','Models','boxes'),N('templates','Templates','layout-template'),N('media','Media','images')]},
      {title:'Monetize',items:[N('credits','Credits','coins'),N('plans','Plans','layers'),N('payments','Payments','credit-card'),N('api','API','code')]},
      {title:'Brand & system',items:[N('whitelabel','White Label','palette'),N('pages','Pages','file-text'),N('settings','Settings','settings'),N('logs','Logs','scroll-text')]}
    ];
    const titles={overview:'Overview',users:'Users',projects:'Projects',videos:'Videos',providers:'AI Providers',routing:'Smart Routing',models:'Models',templates:'Templates',media:'Media',credits:'Credits',plans:'Plans',payments:'Payments',api:'API',whitelabel:'White Label',pages:'Pages',settings:'Settings',logs:'Logs'};
    const scr=s.screen==='projects'?'videos':s.screen;
    const sf={}; ['overview','users','videos','providers','routing','models','templates','media','credits','plans','payments','api','whitelabel','pages','settings','logs'].forEach(k=>sf[k]=scr===k);
    const g={}; Object.keys(titles).forEach(k=>g[k]=()=>this.go(k));

    // overview
    const days = s.range==='7d'?7:s.range==='90d'?90:30;
    const chart = Array.from({length:Math.min(days,45)},(_,i)=>{const v=0.45+0.35*Math.sin(i*0.45)+0.2*Math.sin(i*1.7+1)+i/90; const f=0.03+0.03*Math.abs(Math.sin(i*2.3)); return {h:Math.max(8,v*80)+'%',f:(f*100)+'%',tip:Math.round(v*1800)+' videos'};});
    const d0=new Date(2026,8,24-days); const chartStart=d0.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    const kpis=[['Total Users','12,840','users','+4.2% this month',1],['Active Users','3,112','activity','+9.8%',1],['Videos Generated','48,210','film','+1,824 this week',1],['AI Generations','391k','sparkles','+12.4%',1],['Credits Used','1.24M','coins','of 2.1M issued',0],['Storage','1.84 TB','hard-drive','46% of 4 TB',0],['Revenue (MRR)','$38,420','trending-up','+18.2%',1]].map(k=>({label:k[0],value:k[1],icon:'icon-'+k[2],delta:k[3],dc:k[4]?'oklch(0.45 0.12 150)':'#8a8c91'}));
    const sys=[['Queue workers','4 / 4 running','ok'],['Render queue','12 jobs · 3 active','ok'],['FFmpeg','6.1.1 · NVENC off','ok'],['Cron','last run 32s ago','ok'],['Storage · R2','healthy','ok'],['Groq','rate limited · resets 4m','warn'],['ElevenLabs','401 · invalid key','err']].map(x=>({label:x[0],val:x[1],c:x[2]==='ok'?'oklch(0.62 0.14 150)':x[2]==='warn'?'oklch(0.75 0.15 70)':'oklch(0.6 0.2 25)',tc:x[2]==='ok'?'#6b6d72':x[2]==='warn'?'oklch(0.5 0.13 70)':'oklch(0.5 0.18 25)',anim:x[2]==='ok'?'none':'rs-pulse 1.4s ease-in-out infinite'}));
    const usage=[['OpenRouter','184k req','$41.20',100,'#17181a'],['Google Gemini','92k req','$0.00',50,'#17181a'],['Groq','61k req','$0.00',33,'#17181a'],['Fal.ai','4.1k req','$386.40',22,'oklch(0.58 0.19 35)'],['ElevenLabs','8.2k req','$172.20',12,'oklch(0.58 0.19 35)']].map(u=>({name:u[0],req:u[1],cost:u[2],pct:u[3]+'%',c:u[4]}));
    const rev=['O','N','D','J','F','M','A','M','J','J','A','S'].map((m,i)=>({m,h:(28+i*6+(i%3)*4)+'%',c:i===11?'oklch(0.58 0.19 35)':'#d9d7d1'}));
    const activity=[['user-plus','Aisha Bello signed up on the Free plan','4 min ago','#f3f2ef','#17181a'],['circle-alert','ElevenLabs returned 401 for 14 voice jobs','11 min ago','oklch(0.95 0.035 25)','oklch(0.5 0.18 25)'],['credit-card','Maya Chen upgraded to Agency · $99','32 min ago','oklch(0.95 0.04 150)','oklch(0.42 0.12 150)'],['gauge','Groq rate limit hit, jobs routed to Gemini','48 min ago','oklch(0.95 0.05 85)','oklch(0.5 0.13 70)'],['film','1,000th video rendered this week','2 hours ago','#f3f2ef','#17181a'],['key-round','API key “Zapier integration” created','3 hours ago','#f3f2ef','#17181a']].map(a=>({icon:'icon-'+a[0],text:a[1],time:a[2],bg:a[3],fg:a[4]}));

    // users
    const uStatus={All:null,Active:'Active',Pending:'Pending',Suspended:'Suspended'};
    const mkUser=u=>({...u,init:u.name.split(' ').map(x=>x[0]).join(''),sfg:this.ST[u.status][0],sbg:this.ST[u.status][1],creditsLabel:u.credits===0?'0 · empty':u.credits.toLocaleString(),cc:u.credits===0?'oklch(0.5 0.18 25)':'#17181a',
      open:()=>this.setState({userId:u.id}), impersonate:()=>this.toast('Signed in as '+u.name+'. Exit from the banner in Studio.','info'),
      suspendLabel:u.status==='Suspended'?'Reactivate':'Suspend',
      suspend:()=>u.status==='Suspended'?(this.setState(st=>({users:st.users.map(x=>x.id===u.id?{...x,status:'Active'}:x)})),this.toast(u.name+' reactivated')):this.ask({title:'Suspend '+u.name+'?',body:'They will be signed out and blocked from creating videos. Their projects and credits are kept.',okLabel:'Suspend user',danger:false,icon:'icon-ban',ok:()=>{this.setState(st=>({users:st.users.map(x=>x.id===u.id?{...x,status:'Suspended'}:x),confirm:null})); this.toast(u.name+' suspended');}}),
      del:()=>this.ask({title:'Delete '+u.name+'?',body:'This permanently deletes the account, '+u.videos+' videos and all media. This cannot be undone.',okLabel:'Delete permanently',danger:true,icon:'icon-trash-2',ok:()=>{this.setState(st=>({users:st.users.filter(x=>x.id!==u.id),confirm:null,userId:null})); this.toast(u.name+' deleted');}})});
    const users=s.users.filter(u=>(!uStatus[s.uTab]||u.status===uStatus[s.uTab])&&(u.name+u.email).toLowerCase().includes(s.uq.toLowerCase())).map(mkUser);
    const cuRaw=s.users.find(u=>u.id===s.userId);

    // videos
    const vdata=[['Spring Collection Launch','John Doe','Completed','OR · CF · ET',36,'Sep 23','0:45'],['Smart Bottle — Hydration Tips','John Doe','Processing','OR · FA · ET',42,'Sep 23','0:30'],['Onboarding Explainer v2','Maya Chen','Failed','OR · CF · EL',12,'Sep 23','1:40'],['Open House: 42 Elm Street','Tomás Ruiz','Completed','GG · HF · ET',28,'Sep 22','1:05'],['Customer Story: Northwind','Maya Chen','Completed','OR · FA · EL',88,'Sep 22','1:20'],['Black Friday Teaser','Hannah Klein','Draft','—',0,'Sep 21','0:15'],['Top 5 Coffee Myths','Kenji Watanabe','Completed','GQ · CF · ET',31,'Sep 21','0:58'],['Launch Week Recap','Leo Martins','Failed','OR · FA · ET',0,'Sep 20','0:40']];
    const vStat={All:null,Completed:'Completed',Processing:'Processing',Failed:'Failed',Drafts:'Draft'};
    const vids=vdata.filter(v=>!vStat[s.vTab]||v[2]===vStat[s.vTab]).map((v,i)=>({name:v[0],owner:v[1],status:v[2],provs:v[3],credits:v[4],date:v[5]+', 2026',dur:v[6],c:this.F[i%10],sfg:this.ST[v[2]][0],sbg:this.ST[v[2]][1],
      sub:v[2]==='Failed'?(i===2?'Voice: ElevenLabs 401 invalid key':'Insufficient credits (0)'):s.screen==='projects'?(4+i%4)+' scenes · '+v[6]:'1080×1920 · '+v[6],subc:v[2]==='Failed'?'oklch(0.5 0.18 25)':'#6b6d72',
      del:()=>this.ask({title:'Delete “'+v[0]+'”?',body:'The video file and its project will be removed from storage.',okLabel:'Delete video',danger:true,icon:'icon-trash-2',ok:()=>{this.setState({confirm:null}); this.toast('Video deleted');}})}));

    // providers
    const pm={connected:['Connected','oklch(0.42 0.12 150)','oklch(0.95 0.04 150)','icon-circle-check','Healthy · 380 ms'],rate:['Rate limited','oklch(0.5 0.13 70)','oklch(0.95 0.05 85)','icon-gauge','Resets in 4m · falling back to Gemini'],error:['Disconnected','oklch(0.5 0.18 25)','oklch(0.95 0.035 25)','icon-circle-x','401 · invalid API key'],disabled:['Disabled','#6b6d72','#f1f0ed','icon-circle-pause','Not receiving jobs'],off:['Not configured','#6b6d72','#f1f0ed','icon-circle-dashed','Add an API key to enable'],testing:['Testing…','oklch(0.45 0.13 250)','oklch(0.95 0.03 250)','icon-loader-circle','Sending test request']};
    const provRows=s.prov.filter(p=>s.pTab==='All'||p.cat===s.pTab).map(p=>{const st=s.provTest===p.id?'testing':p.status, m=pm[st]; const on=p.status!=='disabled'&&p.status!=='off';
      return {...p,statusLabel:m[0],sfg:m[1],sbg:m[2],sIcon:m[3],statusSub:m[4],sAnim:st==='testing'?'rs-spin 1s linear infinite':'none',monoBg:p.status==='connected'?'#17181a':p.status==='error'?'oklch(0.55 0.18 25)':'#8a8c91',...this.tog(on),
        toggle:()=>{ if(p.status==='off'){ this.setState({provEdit:p.id,provAdd:false,provResult:null}); return; } this.setState(x=>({prov:x.prov.map(q=>q.id===p.id?{...q,status:q.status==='disabled'?'connected':'disabled'}:q)})); this.toast(p.name+(on?' disabled':' enabled'));},
        test:()=>{ if(p.status==='off'){ this.toast(p.name+': no API key configured','err'); return; } this.setState({provTest:p.id}); setTimeout(()=>{ if(p.status==='error'){this.setState({provTest:null}); this.toast(p.name+': 401 Unauthorized. Check the API key.','err');} else if(p.status==='rate'){this.setState({provTest:null}); this.toast(p.name+': 429 Too Many Requests. Retry in 4 minutes.','err');} else {this.setState({provTest:null}); this.toast(p.name+' responded in '+(280+Math.floor(Math.random()*200))+' ms');}},1200);},
        edit:()=>this.setState({provEdit:p.id,provAdd:false,provResult:null})};});
    const pTabs=this.chips(['All','Text','Image','Video','Voice','Audio','Speech'],s.pTab,v=>this.setState({pTab:v})).map(c=>({...c,count:s.prov.filter(p=>c.label==='All'||p.cat===c.label).length}));
    const pSummary=[['Connected',s.prov.filter(p=>p.status==='connected').length,'#17181a'],['Rate limited',s.prov.filter(p=>p.status==='rate').length,'oklch(0.5 0.13 70)'],['Disconnected',s.prov.filter(p=>p.status==='error').length,'oklch(0.5 0.18 25)'],['AI spend · 30d','$612.40','#17181a']].map(x=>({label:x[0],value:x[1],c:x[2]}));
    const pe=s.prov.find(p=>p.id===s.provEdit);
    const presets=[['OpenRouter','OR','Free models'],['Google Gemini','GG','Free tier'],['Groq','GQ','Free tier'],['Cloudflare','CF','Free quota'],['Hugging Face','HF','Free inference'],['Custom API','{ }','OpenAI-compatible']].map(p=>({name:p[0],mono:p[1],tier:p[2],tc:'oklch(0.45 0.12 150)',bd:s.preset===p[0]?'#17181a':'#e8e7e3',on:()=>this.setState({preset:p[0]})}));
    const pdTesting=s.provResult==='testing';
    const pd={title:s.provAdd?'Add provider':'Edit '+(pe?pe.name:''),isAdd:s.provAdd,key:s.provAdd?'':'••••••••••••••••',url:s.provAdd?({'OpenRouter':'https://openrouter.ai/api/v1','Google Gemini':'https://generativelanguage.googleapis.com','Groq':'https://api.groq.com/openai/v1','Cloudflare':'https://api.cloudflare.com/client/v4','Hugging Face':'https://api-inference.huggingface.co','Custom API':'https://your-endpoint/v1'}[s.preset]):(pe?pe.url:''),
      models:this.MODELS.filter(m=>pe?m.prov===pe.name:m.prov==='OpenRouter').concat(pe&&!this.MODELS.some(m=>m.prov===pe.name)?[{id:pe.model,tier:'Custom',on:true}]:[]).map(m=>({id:m.id,tier:m.tier,on:m.on!==false})),
      keyErr:pe&&pe.status==='error'&&s.provResult!=='ok', keyBd:pe&&pe.status==='error'&&s.provResult!=='ok'?'oklch(0.7 0.15 25)':'#e1e0dc',
      showResult:s.provResult==='ok'||s.provResult==='err', rbg:s.provResult==='ok'?'oklch(0.96 0.04 150)':'oklch(0.96 0.03 25)', rfg:s.provResult==='ok'?'oklch(0.38 0.1 150)':'oklch(0.45 0.16 25)', ricon:s.provResult==='ok'?'icon-circle-check':'icon-circle-x',
      rmsg:s.provResult==='ok'?'Connection successful · 342 ms · 3 models available':'Connection failed · 401 Unauthorized. The provider rejected this API key.',
      tIcon:pdTesting?'icon-loader-circle':'icon-activity', tAnim:pdTesting?'rs-spin 1s linear infinite':'none', tLabel:pdTesting?'Testing…':'Test connection',
      test:()=>{this.setState({provResult:'testing'}); setTimeout(()=>this.setState({provResult:pe&&pe.status==='error'&&!this.fixed?'err':'ok'}),1100); if(pe&&pe.status==='error') this.fixed=true;},
      save:()=>{ if(pe) this.setState(x=>({prov:x.prov.map(q=>q.id===pe.id?{...q,status:'connected'}:q)})); else this.setState(x=>({prov:[...x.prov,{id:'n'+Date.now(),name:s.preset,mono:presets.find(p=>p.name===s.preset).mono,cat:'Text',tier:'Just added',status:'connected',model:'default',req:'0',cost:'$0.00',url:''}]})); this.setState({provEdit:null,provAdd:false,provResult:null}); this.toast('Provider saved');}};

    // models
    const models=s.models.filter(m=>s.mTab==='All'||m.type===s.mTab).map(m=>({...m,fbg:m.tier==='Free'?'oklch(0.95 0.04 150)':'#f1f0ed',ffg:m.tier==='Free'?'oklch(0.42 0.12 150)':'#3a3c40',
      st:m.prov==='ElevenLabs'?'Provider down':m.prov==='Groq'?'Rate limited':'Operational',stc:m.prov==='ElevenLabs'?'oklch(0.5 0.18 25)':m.prov==='Groq'?'oklch(0.5 0.13 70)':'oklch(0.45 0.12 150)',...this.tog(m.on),
      toggle:()=>this.setState(x=>({models:x.models.map(q=>q.idx===m.idx?{...q,on:!q.on}:q)}))}));

    // templates
    const tpls=(s.tpls||[]).map(t=>({...t,sfg:this.ST[t.status][0],sbg:this.ST[t.status][1],
      dup:()=>{this.setState(x=>({tpls:[...x.tpls,{...t,id:Date.now(),name:t.name+' (copy)',status:'Draft',uses:'0',by:'Admin'}]})); this.toast('Template duplicated');},
      del:()=>this.ask({title:'Delete “'+t.name+'”?',body:'Users will no longer see this template. Videos already created from it are not affected.',okLabel:'Delete template',danger:true,icon:'icon-trash-2',ok:()=>{this.setState(x=>({tpls:x.tpls.filter(q=>q.id!==t.id),confirm:null})); this.toast('Template deleted');}})}));

    // credits
    const rules=s.rules.map((r,i)=>{const costN=parseFloat(r[2].slice(1)); const rev=r[3]*0.02; const mg=costN===0?'∞':Math.round((rev-costN)/Math.max(rev,0.0001)*100)+'%'; const neg=rev<costN;
      return {label:r[0],icon:'icon-'+r[1],cost:r[2],v:r[3],margin:(neg?'':'')+mg+' margin',mbg:neg?'oklch(0.95 0.035 25)':'oklch(0.95 0.04 150)',mfg:neg?'oklch(0.5 0.18 25)':'oklch(0.42 0.12 150)',set:e=>{const v=+e.target.value||0; this.setState(x=>({rules:x.rules.map((q,j)=>j===i?[q[0],q[1],q[2],v]:q)}));}};});

    // plans
    const planData=[['Free','$0','50','3 / mo','1 GB','—',['Free AI models only','720p export','Watermark on videos'],'1,204'],['Starter','$19','1,500','30 / mo','10 GB','—',['All AI models','1080p export','No watermark','Brand kit'],'2,318'],['Professional','$49','5,000','Unlimited','50 GB','Yes',['Everything in Starter','Custom voices','Priority rendering','API access'],'1,902'],['Agency','$99','15,000','Unlimited','200 GB','Yes',['Everything in Pro','5 team seats','Client workspaces','White-label exports'],'412']];
    const plans=planData.map((p,i)=>{const pop=i===2; return {name:p[0],price:p[1],credits:p[2],videos:p[3],storage:p[4],api:p[5],features:p[6],subs:p[7],popular:pop,bg:pop?'#111214':'#fff',fg:pop?'#fff':'#17181a',bd:pop?'#111214':'#e8e7e3',tile:pop?'#1d1f23':'#f6f5f2',btnBd:pop?'#3a3c42':'#e1e0dc',
      edit:()=>this.setState({planEdit:i}),del:()=>this.ask({title:'Delete the '+p[0]+' plan?',body:p[7]+' subscribers will stay on it until their next renewal, then move to Free.',okLabel:'Delete plan',danger:true,icon:'icon-trash-2',ok:()=>{this.setState({confirm:null}); this.toast(p[0]+' plan deleted');}})};});
    const pl=s.planEdit==='new'?['New plan','','','','',[]]:planData[s.planEdit]||planData[0];
    const featList=['Paid AI models','Remove watermark','Custom voices','API access','Priority rendering','Team seats','White-label exports'];
    const peV={title:s.planEdit==='new'?'Create plan':'Edit '+pl[0],name:s.planEdit==='new'?'':pl[0],price:(pl[1]||'').replace('$',''),credits:pl[2],videos:pl[3],storage:pl[4],
      feats:featList.map((f,j)=>{const k=s.planEdit+':'+j; const on=s.planFeats[k]!==undefined?s.planFeats[k]:(s.planEdit!=='new'&&j<=(s.planEdit||0)+1); return {label:f,...this.tog(on),toggle:()=>this.setState(x=>({planFeats:{...x.planFeats,[k]:!on}}))};})};

    // payments
    const gateways=[['stripe','Stripe','ST','Live mode · cards, Apple Pay'],['paypal','PayPal','PP','Live mode'],['razorpay','Razorpay','RZ','Not configured'],['paystack','Paystack','PS','Not configured'],['bank','Bank transfer','BT','Manual approval']].map(x=>{const on=s.gw[x[0]]; return {name:x[1],mono:x[2],mode:x[3],mbg:on?'#17181a':'#8a8c91',...this.tog(on),toggle:()=>this.setState(st=>({gw:{...st.gw,[x[0]]:!on}}))};});
    const txns=[['txn_8Kq2','Maya Chen','Agency · monthly','Stripe','$99.00','Paid','Sep 24'],['txn_8Kp9','Tomás Ruiz','5,000 credit pack','PayPal','$80.00','Paid','Sep 24'],['txn_8Kn1','Leo Martins','Starter · monthly','Stripe','$19.00','Failed','Sep 23'],['txn_8Km7','Kenji Watanabe','Professional · yearly','Stripe','$470.00','Paid','Sep 22'],['txn_8Kk3','Emma Laurent','Starter · monthly','Stripe','$19.00','Refunded','Sep 21'],['txn_8Kj0','Sara Ahmed','1,000 credit pack','Bank transfer','$18.00','Pending','Sep 21']].map(t=>({id:t[0],user:t[1],item:t[2],gw:t[3],amt:t[4],st:t[5],date:t[6]+', 2026',sfg:this.ST[t[5]][0],sbg:this.ST[t[5]][1]}));

    // api
    const eps=[['POST','/v1/videos','Create a video from a topic or script. Returns a job you can poll.',`curl -X POST https://video.acme.co/api/v1/videos \\
  -H "Authorization: Bearer rsk_live_..." \\
  -H "Content-Type: application/json" \\
  -d '{
    "topic": "5 ways Hydra keeps you hydrated",
    "platform": "tiktok",
    "duration": 30,
    "aspect_ratio": "9:16",
    "voice": "theo",
    "template_id": "hook-story-offer"
  }'

# 202 Accepted
{ "id": "vid_9f2k", "status": "queued", "credits_reserved": 38 }`],['GET','/v1/videos/{id}','Retrieve a video and its render status.',`curl https://video.acme.co/api/v1/videos/vid_9f2k \\
  -H "Authorization: Bearer rsk_live_..."

{ "id": "vid_9f2k", "status": "completed",
  "duration": 30, "url": "https://cdn.acme.co/v/vid_9f2k.mp4" }`],['GET','/v1/templates','List templates available to the key owner.',`curl https://video.acme.co/api/v1/templates?platform=tiktok \\
  -H "Authorization: Bearer rsk_live_..."`],['POST','/v1/scripts','Generate a script only, without rendering.',`curl -X POST https://video.acme.co/api/v1/scripts \\
  -H "Authorization: Bearer rsk_live_..." \\
  -d '{ "topic": "History of coffee", "duration": 60 }'`],['GET','/v1/credits','Current credit balance and usage.',`curl https://video.acme.co/api/v1/credits \\
  -H "Authorization: Bearer rsk_live_..."

{ "balance": 2480, "used_this_period": 2520 }`],['POST','/v1/webhooks','Register a URL to receive render events.',`{ "url": "https://example.com/hooks/video",
  "events": ["video.completed", "video.failed"] }`]];
    const mc={GET:'oklch(0.5 0.13 250)',POST:'oklch(0.48 0.13 150)'};
    const endpoints=eps.map((e,i)=>({m:e[0],path:e[1],mc:mc[e[0]],bg:s.ep===i?'#f3f2ef':'transparent',on:()=>this.setState({ep:i})}));
    const E=eps[s.ep];

    // white label
    const wl=s.wl, dirty=s.wlSaved&&s.wlSaved!==JSON.stringify(wl);
    const F=(label,k,type,span)=>({label,v:wl[k],isText:!type||type==='text',isColor:type==='color',isFile:type==='file',span:span||'auto',set:e=>this.setWl(k,e.target.value)});
    const wlGroups=[
      {title:'Application',fields:[F('Application name','appName'),F('Domain','domain'),F('Logo','logo','file'),F('Favicon','favicon','file')]},
      {title:'Colors',fields:[F('Primary color','primary','color'),F('Secondary color','secondary','color'),F('Email header color','emailHeader','color')]},
      {title:'Login & email branding',fields:[F('Login headline','loginHeadline','text','1 / -1'),F('Email footer','emailFooter','text','1 / -1'),F('App footer','footer','text','1 / -1')]},
      {title:'Terminology & navigation',fields:[F('Product noun (“Video”)','termVideo'),F('Currency noun (“Credits”)','termCredits'),F('“Workflows” label','termWorkflows'),F('“Workspace” label','termWorkspace'),F('Typography','font')]},
      {title:'Company & support',fields:[F('Company name','company'),F('Website','website'),F('Support email','support'),F('X / Twitter','twitter'),F('LinkedIn','linkedin')]}
    ];

    // pages
    const P=this.PAGES[s.page];
    const pages=this.PAGES.map((p,i)=>({name:p[0],slug:p[1],st:p[2],sc:p[2]==='Draft'?'#8a8c91':'oklch(0.45 0.12 150)',bg:s.page===i?'#f3f2ef':'transparent',on:()=>this.setState({page:i})}));

    // settings
    const T=(id)=>({isToggle:true,...this.tog(s.setToggles[id]),toggle:()=>this.setState(x=>({setToggles:{...x.setToggles,[id]:!x.setToggles[id]}}))});
    const tx=(v,mono,type)=>({isText:true,v,ff:mono?"'Geist Mono',monospace":'inherit',inputType:type||'text'});
    const sel=(v,options)=>({isSelect:true,v,options});
    const stt=(v,ok)=>({isStatus:true,v,sc:ok?'oklch(0.45 0.12 150)':'oklch(0.5 0.18 25)'});
    const SG={
      General:[{title:'General',desc:'Basics for your installation',fields:[{label:'Site name',hint:'Shown in titles and emails',...tx(wl.appName)},{label:'Default language',hint:'For new users',...sel('English',['English','Español','Deutsch','Français','Português'])},{label:'Timezone',hint:'Used for reports and cron',...sel('UTC',['UTC','Europe/London','America/New_York','Asia/Kolkata'])},{label:'Allow registrations',hint:'Anyone can sign up',...T('reg')},{label:'Require email verification',hint:'Before first video',...T('verify')}]}],
      AI:[{title:'AI routing',desc:'How jobs choose a provider',fields:[{label:'Automatic fallback',hint:'Retry with next provider on failure or rate limit',...T('fallback')},{label:'Default text model',hint:'Used when a user doesn’t choose',...sel('llama-3.3-70b-instruct:free',['llama-3.3-70b-instruct:free','gemini-2.0-flash','gpt-4o-mini'])},{label:'Max retries',hint:'Per generation step',...tx('3',true)},{label:'Request timeout',hint:'Seconds',...tx('60',true)}]}],
      Video:[{title:'Rendering',desc:'FFmpeg output defaults',fields:[{label:'Default resolution',hint:'Plans can override',...sel('1080p',['720p','1080p','4K'])},{label:'Codec',hint:'',...sel('H.264',['H.264','H.265','VP9'])},{label:'Hardware acceleration',hint:'NVENC / QSV if available',...T('ffhw')},{label:'Watermark free-plan videos',hint:'',...T('watermarkFree')},{label:'Max video length',hint:'Seconds',...tx('600',true)}]}],
      Storage:[{title:'Storage',desc:'Where media and renders are stored',fields:[{label:'Driver',hint:'',...sel('S3-compatible',['Local disk','S3-compatible','Wasabi','Backblaze B2'])},{label:'Endpoint',hint:'',...tx('https://acc.r2.cloudflarestorage.com',true)},{label:'Bucket',hint:'',...tx('acme-video-prod',true)},{label:'Access key',hint:'',...tx('AKIA••••••••R2',true,'password')},{label:'Connection',hint:'',...stt('Healthy · 41 ms',true)}]}],
      Email:[{title:'Email (SMTP)',desc:'Transactional emails',fields:[{label:'SMTP host',hint:'',...tx('smtp.postmarkapp.com',true)},{label:'Port',hint:'',...tx('587',true)},{label:'From address',hint:'',...tx('no-reply@acme.co',true)},{label:'Use TLS',hint:'',...T('smtpTls')}]}],
      Payments:[{title:'Payments',desc:'Currency and tax',fields:[{label:'Currency',hint:'',...sel('USD',['USD','EUR','GBP','INR','NGN'])},{label:'Tax rate',hint:'Applied at checkout',...tx('0%',true)},{label:'Invoice prefix',hint:'',...tx('ACME-',true)}]}],
      Security:[{title:'Security',desc:'Access and sessions',fields:[{label:'Require 2FA for admins',hint:'',...T('twofa')},{label:'Session lifetime',hint:'Minutes',...tx('120',true)},{label:'reCAPTCHA on sign-up',hint:'',...sel('Enabled',['Enabled','Disabled'])},{label:'Allowed admin IPs',hint:'Comma-separated, blank for any',...tx('',true)}]}],
      'Queue & Cron':[{title:'Queue workers',desc:'Background jobs for generation and rendering',fields:[{label:'Queue driver',hint:'',...sel('Redis',['Database','Redis'])},{label:'Workers',hint:'',...stt('4 running',true)},{label:'Failed jobs (24h)',hint:'',...stt('17 failed',false)},{label:'Alert when queue stalls',hint:'Email the admin',...T('cronAlert')}]},{title:'Cron',desc:'Add this to your server crontab',fields:[{label:'Command',hint:'',...tx('* * * * * php /var/www/artisan schedule:run',true)},{label:'Last run',hint:'',...stt('32 seconds ago',true)}]}]
    };
    const setIcons={General:'sliders-horizontal',AI:'cpu',Video:'film',Storage:'hard-drive',Email:'mail',Payments:'credit-card',Security:'shield',"Queue & Cron":'timer'};
    const setTabs=Object.keys(SG).map(k=>({label:k,icon:'icon-'+setIcons[k],bg:s.setTab===k?'#f3f2ef':'transparent',fw:s.setTab===k?600:500,on:()=>this.setState({setTab:k})}));

    // logs
    const logsAll=[['09:41:12','INFO','queue','Render job vid_9f2k completed in 38.2s'],['09:41:09','INFO','ffmpeg','Encoding 1080x1920 H.264 · 30.0s · 18.2 MB'],['09:40:55','WARNING','ai','Groq 429 Too Many Requests → fallback gemini-2.0-flash'],['09:40:31','ERROR','ai','ElevenLabs 401 Unauthorized (job vid_8c1a, scene 3)'],['09:40:30','INFO','credits','Reserved 38 credits for user #1 (John Doe)'],['09:39:58','INFO','auth','Admin login from 81.2.69.160'],['09:39:12','INFO','ai','OpenRouter llama-3.3-70b:free · 612 tokens · 1.4s'],['09:38:44','ERROR','queue','Job vid_77aa failed: insufficient credits (user #3)'],['09:38:02','INFO','storage','Uploaded renders/vid_71b0.mp4 to r2 (22.4 MB)'],['09:37:40','WARNING','cron','Schedule ran 12s late'],['09:37:11','INFO','payments','Stripe webhook invoice.paid txn_8Kq2'],['09:36:59','DEBUG','ai','Cloudflare flux-1-schnell seed=48211 steps=4']];
    const lc={INFO:'oklch(0.75 0.1 250)',WARNING:'oklch(0.8 0.13 75)',ERROR:'oklch(0.72 0.17 25)',DEBUG:'#8a8c91'};
    const logs=logsAll.filter(l=>s.logLvl==='All'||l[1]===s.logLvl.toUpperCase()).map(l=>({t:'2026-09-24 '+l[0],lvl:l[1],ch:l[2],msg:l[3],c:lc[l[1]]}));

    // routing
    const rtDefs=[['Text','type','Scripts, copy, agents',['or','gem','groq','custom'],'$0.002 / call','60s'],['Image','image','Scene stills, thumbnails, storyboards',['cf','hf'],'$0.04 / image','90s'],['Video','clapperboard','Clips and cinematic shots',['fal'],'$1.50 / clip','300s'],['Voice','mic','Narration and character voices',['edge','el'],'$0.20 / min','60s'],['Audio','audio-lines','Music and sound effects',['suno'],'$0.10 / track','120s'],['Speech','ear','Transcription for repurposing',['whisper','dg'],'$0.01 / min','180s']];
    const ruleNames=['Free first','Cheapest','Fastest','Best quality','Specific model','Manual'];
    const ruleHints={'Free first':'Free models are used until quota or rate limits hit, then the next provider takes over.','Cheapest':'Lowest cost per request among healthy providers.','Fastest':'Lowest median latency over the last hour.','Best quality':'Top-ranked model you allow. Costs more.','Specific model':'Always the first provider. No automatic fallback.','Manual':'Users choose the provider for each job.'};
    const stDot={connected:'oklch(0.62 0.14 150)',rate:'oklch(0.75 0.15 70)',error:'oklch(0.6 0.2 25)',disabled:'#b3b4b8',off:'#d6d4ce'};
    const rtCaps=rtDefs.map(d=>{const cap=d[0]; const rule=(s.rtRule||{})[cap]||'Free first'; const order=(s.rtOrder||{})[cap]||d[3];
      const move=(i,dir)=>this.setState(x=>{const o=[...(((x.rtOrder||{})[cap])||d[3])]; const j=i+dir; if(j<0||j>=o.length) return {}; [o[i],o[j]]=[o[j],o[i]]; return {rtOrder:{...(x.rtOrder||{}),[cap]:o}};});
      return {t:cap,icon:'icon-'+d[1],d:d[2],cap:d[4],to:d[5],hint:ruleHints[rule],
        rules:ruleNames.map(r=>({label:r,on:()=>this.setState(x=>({rtRule:{...(x.rtRule||{}),[cap]:r}})),bg:r===rule?'#17181a':'#fff',fg:r===rule?'#fff':'#3a3c40',bd:r===rule?'#17181a':'#e1e0dc'})),
        chain:order.map((id,i)=>{const p=s.prov.find(q=>q.id===id)||{name:id,mono:'?',status:'off',model:'',cost:''}; return {n:i+1,name:p.name,mono:p.mono,meta:p.model+(p.status==='rate'?' · rate limited':p.status==='error'?' · down':''),dot:stDot[p.status],bd:i===0?'#17181a':'#e8e7e3',bg:p.status==='off'||p.status==='disabled'?'#faf9f7':'#fff',mbg:p.status==='connected'?'#17181a':'#8a8c91',arrow:i<order.length-1,up:()=>move(i,-1),down:()=>move(i,1)};})};});
    const rtKpis=[['Jobs routed · 24h','41,208'],['Fallbacks · 24h','612'],['Saved vs. paid-only','$214.80'],['Free-model share','78%']].map(k=>({k:k[0],v:k[1]}));

    const cf=s.confirm;
    return {
      isMobile, notMobile:!isMobile, drawerOverlay:isMobile&&s.drawer, openDrawer:()=>this.setState({drawer:true}), closeDrawer:()=>this.setState({drawer:false}),
      sb:{pos:isMobile?'fixed':'sticky',tf:isMobile&&!s.drawer?'translateX(-100%)':'none'}, navGroups, pageTitle:titles[s.screen], hdrPad:isMobile?'16px':'32px', pad:isMobile?'20px 16px 60px':'28px 32px 60px',
      s:sf, go:g, cols2:isNarrow?'minmax(0,1fr)':'minmax(0,1fr) 340px', cols3:isNarrow?'minmax(0,1fr)':'repeat(3,minmax(0,1fr))', apiCols:s.w<1000?'minmax(0,1fr)':'260px minmax(0,1fr)', wlCols:s.w<1100?'minmax(0,1fr)':'minmax(0,1fr) 440px',
      ranges:this.chips(['7d','30d','90d'],s.range,v=>this.setState({range:v})), rangeDays:days, chart, chartStart, kpis, sys, usage, rev, activity,
      uq:s.uq, setUq:e=>this.setState({uq:e.target.value}), uTabs:this.chips(['All','Active','Pending','Suspended'],s.uTab,v=>this.setState({uTab:v})), users, usersEmpty:users.length===0, usersCount:users.length,
      userOpen:!!cuRaw, cu:cuRaw?mkUser(cuRaw):{}, closeUser:()=>this.setState({userId:null}),
      adjModes:this.chips(['Add','Remove','Set'],s.adjMode,v=>this.setState({adjMode:v})), adjAmt:s.adjAmt, setAdjAmt:e=>this.setState({adjAmt:e.target.value}),
      applyAdj:()=>{const n=parseInt(s.adjAmt)||0; this.setState(x=>({users:x.users.map(u=>u.id===x.userId?{...u,credits:Math.max(0,x.adjMode==='Add'?u.credits+n:x.adjMode==='Remove'?u.credits-n:n)}:u)})); this.toast('Credits updated for '+cuRaw.name);},
      vidTitle:s.screen==='projects'?'Projects':'Videos', vidSub:s.screen==='projects'?'All user projects, including drafts':'48,210 videos generated across all users', vTabs:this.chips(['All','Completed','Processing','Failed','Drafts'],s.vTab,v=>this.setState({vTab:v})), vids,
      pTabs, pSummary, provRows, openAdd:()=>this.setState({provAdd:true,provEdit:null,provResult:null}), provOpen:s.provAdd||!!pe, closeProv:()=>this.setState({provAdd:false,provEdit:null,provResult:null}), pd, presets,
      mTabs:this.chips(['All','Text','Image','Video','Voice'],s.mTab,v=>this.setState({mTab:v})), models, tpls,
      storage:[['Renders','980 GB',53,'#17181a'],['Uploads','412 GB',22,'oklch(0.58 0.19 35)'],['AI images','296 GB',16,'oklch(0.7 0.1 250)'],['Audio','152 GB',9,'oklch(0.75 0.1 150)']].map(x=>({label:x[0],size:x[1],pct:x[2]+'%',c:x[3]})),
      mediaFiles:Array.from({length:12},(_,i)=>({c:this.F[(i*7)%10],icon:['icon-film','icon-image','icon-music','icon-image'][i%4],name:['vid_9f2k.mp4','scene-03.png','bg-uplifting.mp3','product-hero.jpg','vid_71b0.mp4','scene-01.png','vo-theo.wav','logo-hydra.png','vid_66ea.mp4','scene-05.png','bg-soft.mp3','listing-02.jpg'][i],size:['18.2 MB','1.4 MB','3.1 MB','842 KB','22.4 MB','1.2 MB','960 KB','48 KB','9.8 MB','1.6 MB','2.7 MB','1.1 MB'][i],user:['John','John','Maya','Tomás','Kenji','John','John','John','Hannah','Maya','Leo','Tomás'][i]})),
      creditKpis:[['Credits issued (30d)','2.1M','across all plans','#6b6d72'],['Credits used (30d)','1.24M','59% utilisation','#6b6d72'],['Credit revenue','$24,800','at $0.02 / credit','#6b6d72'],['AI cost to you','$612.40','97.5% gross margin','oklch(0.45 0.12 150)']].map(k=>({label:k[0],value:k[1],sub:k[2],sc:k[3]})),
      rules, limits:[['Free credits on sign-up','50'],['Daily credit limit per user','500'],['Max concurrent renders per user','2'],['Credit expiry (days)','90']].map(l=>({label:l[0],v:l[1]})),
      adjustments:[['Maya Chen','Refund for failed render','+88','oklch(0.45 0.12 150)'],['Leo Martins','Promo: launch week','+500','oklch(0.45 0.12 150)'],['Ryan Patel','Abuse: suspended','−40','oklch(0.5 0.18 25)'],['Sara Ahmed','Bank transfer approved','+1,000','oklch(0.45 0.12 150)']].map(a=>({user:a[0],reason:a[1],amt:a[2],c:a[3]})),
      toastSaved:()=>this.toast('Changes saved'),
      plans, newPlan:()=>this.setState({planEdit:'new'}), planOpen:s.planEdit!==null, pe:peV, closePlan:()=>this.setState({planEdit:null}), savePlan:()=>{this.setState({planEdit:null}); this.toast('Plan saved');},
      gateways, txns,
      keys:s.keys.map(k=>({...k,active:!k.revoked,op:k.revoked?0.5:1,revoke:()=>this.ask({title:'Revoke “'+k.name+'”?',body:'Any integration using this key will immediately stop working.',okLabel:'Revoke key',danger:true,icon:'icon-key-round',ok:()=>{this.setState(x=>({keys:x.keys.map(q=>q.id===k.id?{...q,revoked:true}:q),confirm:null})); this.toast('Key revoked');}})})),
      newKey:s.newKey, createKey:()=>{const k='rsk_live_'+Math.random().toString(36).slice(2,14)+Math.random().toString(36).slice(2,14); this.setState(x=>({newKey:k,keys:[{id:Date.now(),name:'New key',key:k.slice(0,9)+'••••'+k.slice(-4),owner:'Admin',req:'0',rate:'60 / min',last:'Never',revoked:false},...x.keys]}));},
      copyKey:()=>{try{navigator.clipboard.writeText(s.newKey)}catch(e){} this.toast('Key copied to clipboard');},
      endpoints, ep:{m:E[0],path:E[1],desc:E[2],code:E[3],mc:mc[E[0]]},
      wl, wlInit:(wl.appName||'?')[0].toUpperCase(), wlGroups, wlTabs:this.chips(['App','Login','Email'],s.wlTab,v=>this.setState({wlTab:v})), wlTab:{app:s.wlTab==='App',login:s.wlTab==='Login',email:s.wlTab==='Email'},
      dirtyBar:sf.whitelabel&&dirty, discardWl:()=>this.setState({wl:JSON.parse(s.wlSaved)}), saveWl:()=>{this.setState({wlSaved:JSON.stringify(wl)}); this.toast('White-label settings published');}, previewWl:()=>this.toast('Preview opened for '+wl.domain,'info'),
      pages, page:{h:P[3],body:P[4]+'\n\nEdit this content with the toolbar above. Changes publish to your public site when you save.',meta:P[3],desc:P[4],slug:P[1]}, rteTools:['icon-heading-1','icon-heading-2','icon-bold','icon-italic','icon-link','icon-list','icon-list-ordered','icon-quote','icon-image','icon-code'],
      rtCaps, rtKpis,
      setTabs, setGroups:SG[s.setTab], setDir:s.w<1000?'row':'column',
      logTabs:this.chips(['All','Info','Warning','Error','Debug'],s.logLvl,v=>this.setState({logLvl:v})), logs,
      confirmOpen:!!cf, cf:cf?{...cf,ibg:cf.danger?'oklch(0.95 0.035 25)':'oklch(0.95 0.05 85)',ifg:cf.danger?'oklch(0.5 0.18 25)':'oklch(0.5 0.13 70)',btn:cf.danger?'oklch(0.55 0.2 25)':'#17181a'}:{}, closeConfirm:()=>this.setState({confirm:null}), stop:e=>e.stopPropagation(),
      toasts:s.toasts
    };
  }
}
