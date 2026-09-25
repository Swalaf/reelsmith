class DesignComponent extends DCLogic {
  F = ['#23343f','#4a3b31','#33413a','#3d3447','#5a4a30','#27403f','#4a2f33','#30384a','#41392c','#2d3b2d'];
  CAT = {trigger:'oklch(0.68 0.14 75)',ai:'oklch(0.58 0.19 35)',logic:'oklch(0.55 0.12 300)',action:'oklch(0.55 0.13 250)',data:'oklch(0.52 0.11 150)'};
  NT = {
    trigger:['Trigger','zap','trigger'],webhook:['Webhook','webhook','trigger'],schedule:['Schedule','calendar-clock','trigger'],
    aitext:['AI Text','type','ai'],aiimage:['AI Image','image','ai'],aivideo:['AI Video','clapperboard','ai'],aiaudio:['AI Audio','audio-lines','ai'],voice:['Voice','mic','ai'],script:['Script','scroll-text','ai'],scene:['Scene','layout-list','ai'],character:['Character','user-round','ai'],
    condition:['Condition','git-branch','logic'],delay:['Delay','timer','logic'],loop:['Loop','repeat','logic'],transform:['Transform','braces','logic'],
    render:['Render','film','data'],file:['File','file','data'],storage:['Storage','hard-drive','data'],
    http:['HTTP Request','globe','action'],email:['Email','mail','action'],social:['Social Media','share-2','action'],output:['Output','log-out','action']
  };
  state = {
    w: typeof window!=='undefined'?window.innerWidth:1400,
    screen:(typeof localStorage!=='undefined'&&localStorage.getItem('rs_plat_screen'))||this.props.startScreen||'home',
    mode:'Pro', drawer:false, toasts:[],
    nodes:[
      {id:1,t:'trigger',title:'New product added',sub:'shopify · product.created',x:30,y:40},
      {id:2,t:'aitext',title:'Generate marketing copy',sub:'auto · free models first',x:300,y:40},
      {id:3,t:'aiimage',title:'Generate product images',sub:'×4 · flux-1-schnell',x:570,y:40},
      {id:4,t:'aivideo',title:'Generate video',sub:'9:16 · 30s · ltx-video',x:840,y:40},
      {id:5,t:'voice',title:'Generate voiceover',sub:'edge-tts · Theo',x:840,y:250},
      {id:6,t:'render',title:'Render',sub:'ffmpeg · 1080×1920',x:570,y:250},
      {id:7,t:'email',title:'Send to client',sub:'to: {client.email}',x:300,y:250},
      {id:8,t:'condition',title:'Client approved?',sub:'reply contains "approve"',x:300,y:450},
      {id:9,t:'social',title:'Post to Instagram',sub:'reels · scheduled 9:00',x:570,y:450}
    ],
    edges:[[1,2],[2,3],[3,4],[4,5],[5,6],[6,7],[7,8],[8,9]], nextId:10, selNode:2, nq:'', route:'Free first',
    wfRun:null, runLog:[], live:true,
    runFilter:'All', runSel:0,
    cTab:'Scenes & Shots', prodTitle:'The Last Lighthouse', prodT:{consistency:true,style:true,autoShots:true,sound:false},
    sceneSel:2, shotSel:0, shots:null, cam:{}, style:'Cinematic',
    agentSel:null, agTools:{web:true,brand:true,files:false,images:false,workflows:true,api:false}, agPerms:{run:true,publish:false,spend:true},
    rpSel:{yt:true,shorts:true,reels:true,tiktok:true,li:true,x:true,blog:true,cap:true,thumb:true}, rpProg:null,
    apiTab:'Webhooks', ep:0, brief:null, briefItem:null
  };
  componentDidMount(){
    this.onR=()=>this.setState({w:window.innerWidth}); window.addEventListener('resize',this.onR);
    this.setState({shots:this.initShots()});
    this.iv=setInterval(()=>{ const s=this.state; const up={};
      if(s.shots&&s.shots.some(x=>x.st==='loading')) up.shots=s.shots.map(x=>x.st!=='loading'?x:(x.p>=100?{...x,st:'done'}:{...x,p:Math.min(100,x.p+5)}));
      if(s.rpProg) { const np={}; let any=false; Object.keys(s.rpProg).forEach(k=>{const v=s.rpProg[k]; np[k]=v>=100?100:v+(2+((k.length*7)%5)); if(np[k]<100) any=true;}); up.rpProg=np; if(!any) setTimeout(()=>this.toast('All outputs ready'),0); }
      if(Object.keys(up).length) this.setState(up); },120);
  }
  componentWillUnmount(){ window.removeEventListener('resize',this.onR); clearInterval(this.iv); }
  initShots(){ return [
    {sc:2,cam:'Wide shot',move:'Static',lens:'24mm',light:'Storm light',tod:'Night',wx:'Heavy rain',st:'done',p:100,dur:'4s',c:'#27403f'},
    {sc:2,cam:'Medium shot',move:'Dolly',lens:'35mm',light:'Lantern glow',tod:'Night',wx:'Heavy rain',st:'done',p:100,dur:'5s',c:'#3d3447'},
    {sc:2,cam:'Close-up',move:'Static',lens:'85mm',light:'Lantern glow',tod:'Night',wx:'Heavy rain',st:'none',p:0,dur:'3s',c:'#4a3b31'},
    {sc:2,cam:'Over-the-shoulder',move:'Handheld',lens:'50mm',light:'Lantern glow',tod:'Night',wx:'Heavy rain',st:'none',p:0,dur:'4s',c:'#30384a'},
    {sc:1,cam:'Aerial',move:'Crane',lens:'16mm',light:'Storm light',tod:'Dusk',wx:'Storm',st:'done',p:100,dur:'6s',c:'#23343f'},
    {sc:1,cam:'Establishing shot',move:'Pan',lens:'24mm',light:'Storm light',tod:'Dusk',wx:'Storm',st:'done',p:100,dur:'5s',c:'#2d3b2d'},
    {sc:3,cam:'Tracking shot',move:'Tracking',lens:'35mm',light:'Moonlight',tod:'Night',wx:'Clearing',st:'none',p:0,dur:'6s',c:'#41392c'},
    {sc:4,cam:'Wide shot',move:'Static',lens:'24mm',light:'Golden hour',tod:'Dawn',wx:'Clear',st:'none',p:0,dur:'7s',c:'#5a4a30'}
  ].map((x,i)=>({...x,id:i})); }
  go=k=>{ this.setState({screen:k,drawer:false}); try{localStorage.setItem('rs_plat_screen',k)}catch(e){} window.scrollTo(0,0); };
  toast=(msg,kind)=>{const id=Math.random(); const m={ok:['icon-circle-check','oklch(0.75 0.14 150)'],err:['icon-circle-x','oklch(0.72 0.17 25)'],info:['icon-info','oklch(0.78 0.1 250)']}[kind||'ok']; this.setState(s=>({toasts:[...s.toasts,{id,msg,icon:m[0],c:m[1]}]})); setTimeout(()=>this.setState(s=>({toasts:s.toasts.filter(t=>t.id!==id)})),3000);};
  chips(list,cur,fn){ return list.map(l=>{const a=l===cur; return {label:l,on:()=>fn(l),segBg:a?'#fff':'transparent',segSh:a?'0 1px 2px rgba(0,0,0,.08)':'none',bg:a?'#17181a':'#fff',fg:a?'#fff':'#3a3c40',bd:a?'#17181a':'#e1e0dc'};}); }
  tabs(list,cur,fn){ return list.map(l=>{const a=l===cur; return {label:l,on:()=>fn(l),bd:a?'#17181a':'transparent',fw:a?600:500,fg:a?'#17181a':'#6b6d72'};}); }
  tog(on){ return {trk:on?'#17181a':'#d6d4ce',knob:on?'19px':'3px'}; }
  runWorkflow=()=>{
    const order=[1,2,3,4,5,6,7,8,9].filter(id=>this.state.nodes.some(n=>n.id===id)).concat(this.state.nodes.filter(n=>n.id>9).map(n=>n.id));
    const meta={1:['webhook received · product "Hydra Bottle"','0.1s','0'],2:['OpenRouter llama-3.3-70b:free · 412 tokens','1.8s','0'],3:['Cloudflare flux-1-schnell · 4 images','6.2s','4'],4:['Fal.ai ltx-video · 30s clip','38.4s','24'],5:['Edge TTS · Theo · 29.6s audio','2.1s','0'],6:['FFmpeg · 1080×1920 · 18.4 MB','9.7s','2'],7:['sent to maya@northwind.io','0.4s','0'],8:['waiting for reply → true (demo)','0.2s','0'],9:['Instagram Graph API · scheduled','0.6s','0']};
    this.setState({wfRun:{status:{},active:null,start:Date.now()},runLog:[{t:'00.0',m:'▶ Test run started · run_8f2a',c:'#e7e7ea'}]});
    let t=0; order.forEach((id,i)=>{ setTimeout(()=>this.setState(s=>({wfRun:{...s.wfRun,active:id,status:{...s.wfRun.status,[id]:'running'}}})),t); t+=700;
      setTimeout(()=>{ const m=meta[id]||['completed','0.3s','0']; const n=this.state.nodes.find(x=>x.id===id); this.setState(s=>({wfRun:{...s.wfRun,status:{...s.wfRun.status,[id]:'done'}},runLog:[...s.runLog,{t:(i+1).toFixed(1).padStart(4,'0'),m:'✓ '+(n?n.title:'Step')+' — '+m[0]+' · '+m[1]+' · '+m[2]+' cr',c:'#d8d9dc'}]})); if(i===order.length-1){ this.setState(s=>({wfRun:{...s.wfRun,active:null,done:true},runLog:[...s.runLog,{t:'done',m:'■ Completed · 59.5s · 30 credits',c:'oklch(0.78 0.14 150)'}]})); this.toast('Test run completed'); } },t); });
  };
  renderVals(){
    const s=this.state, W=s.w, isMobile=W<860, narrow=W<1180, pro=s.mode==='Pro';
    const N=(k,label,icon,badge,bc)=>({label,icon:'icon-'+icon,go:()=>k==='video'?(window.location.href='/studio'):this.go(k),bg:s.screen===k?'#1f2126':'transparent',fg:s.screen===k?'#fff':'#b7b8bd',hasBadge:!!badge,badge,badgeBg:bc?bc:'#24262b',badgeFg:bc?'#fff':'#b7b8bd'});
    const groups=[
      {title:'Create',items:[N('home','Home','house'),N('workspace','Workspace','layout-grid'),N('video','Create Video','circle-plus')],pro:false},
      {title:'Produce',items:[N('cinematic','Cinematic Studio','clapperboard'),N('characters','Characters','users-round'),N('styles','Visual Styles','swatch-book'),N('repurpose','Repurpose','split')],pro:true},
      {title:'Automate',items:[N('workflows','Workflows','workflow'),N('agents','AI Agents','bot'),N('runs','Runs','activity','2','oklch(0.55 0.13 250)')],pro:true},
      {title:'Deploy',items:[N('api','API & Webhooks','webhook')],pro:true}
    ].filter(g=>pro||!g.pro);
    const titles={home:['Create','Home'],workspace:['Create','Workspace'],cinematic:['Produce','Cinematic Studio'],characters:['Produce','Characters'],styles:['Produce','Visual Styles'],repurpose:['Produce','Repurpose'],workflows:['Automate','Workflows'],builder:['Automate','Workflow Builder'],agents:['Automate','AI Agents'],runs:['Automate','Runs'],api:['Deploy','API & Webhooks']};
    const scr=titles[s.screen]?s.screen:'home';
    const sf={}; Object.keys(titles).forEach(k=>sf[k]=scr===k);
    sf.cinematic=['cinematic','characters','styles'].includes(scr);
    const g={}; Object.keys(titles).forEach(k=>g[k]=()=>this.go(k));
    const openBrief=(i,item)=>this.setState({brief:i,briefItem:item});

    // studios
    const studioDefs=[
      ['Video Production','video','Social, YouTube, product and explainer videos from a single idea.',['Social videos','YouTube videos','Product videos','Explainers','Commercials','Documentaries','Short films'],'Create Video',()=>{window.location.href='/studio';},'Most used'],
      ['Cinematic Studio','clapperboard','Multi-shot productions with characters, scenes and camera control.',['Film scenes','Trailers','Story sequences','Multi-shot','Dialogue scenes','Cinematic ads'],'Start Production',g.cinematic,'Pro'],
      ['Animation Studio','shapes','2D, 3D and motion graphics for ads, explainers and characters.',['2D animation','3D animation','Motion graphics','Animated ads','Character animation','Explainer animation'],'Open studio',null,''],
      ['Advertising Studio','megaphone','Product-to-video ads with multiple variations for testing.',['Product ads','UGC-style ads','Social ads','Commercials','Ad variations','Product-to-video'],'Create ad',null,''],
      ['Content Studio','pen-line','Scripts, articles, posts and campaign copy on-brand.',['Scripts','Articles','Social posts','Marketing copy','Storytelling','Campaigns'],'Write content',null,''],
      ['Audio Studio','audio-lines','Voiceovers, narration, podcasts, music and sound effects.',['Voiceovers','Narration','Podcasts','Sound effects','Background music','Audio production'],'Create audio',null,''],
      ['Automation Studio','workflow','Multi-step AI pipelines with triggers, webhooks and schedules.',['AI workflows','API workflows','Webhooks','Scheduled','Trigger / action','Multi-step pipelines'],'Build workflow',g.workflows,'Pro']
    ];
    const studios=studioDefs.map((d,i)=>{const dark=i===0; return {t:d[0],icon:'icon-'+d[1],d:d[2],cta:d[4],hasTag:!!d[6],tag:d[6],tagBg:dark?'oklch(0.58 0.19 35)':'#f1f0ed',tagFg:dark?'#fff':'#55575c',
      bg:dark?'#111214':'#fff',fg:dark?'#fff':'#17181a',bd:dark?'#111214':'#e8e7e3',ibg:dark?'#24262b':'#f3f2ef',chipBd:dark?'#3a3c42':'#e1e0dc',btnBg:dark?'oklch(0.58 0.19 35)':'#17181a',btnFg:'#fff',
      open:d[5]||(()=>openBrief(i,d[3][0])), items:d[3].map(it=>({label:it,on:d[5]&&i!==0?d[5]:()=>openBrief(i,it)}))};});
    const B=s.brief!==null?studioDefs[s.brief]:null;

    // dashboard
    const quick=[['Create Video','video',()=>{window.location.href='/studio';},1],['Create Image','image',()=>openBrief(3,'Social ads')],['Create Audio','audio-lines',()=>openBrief(5,'Voiceovers')],['Create Workflow','workflow',g.builder],['Create AI Agent','bot',g.agents],['Start Production','clapperboard',g.cinematic]].map(q=>({t:q[0],icon:'icon-'+q[1],go:q[2],bg:q[3]?'oklch(0.58 0.19 35)':'#fff',fg:q[3]?'#fff':'#17181a',bd:q[3]?'oklch(0.58 0.19 35)':'#e8e7e3'}));
    const pipeline=[['CREATE','sparkles','oklch(0.55 0.17 35)',[['Videos','248'],['Images','3,902'],['Audio clips','611']]],['AUTOMATE','workflow','oklch(0.5 0.12 300)',[['Active automations','6'],['Workflows run','1,284'],['Agent tasks','9,410']]],['PRODUCE','clapperboard','oklch(0.5 0.13 250)',[['Productions','4'],['Shots generated','186'],['Renders','302']]],['DEPLOY','rocket','oklch(0.45 0.12 150)',[['API calls','48.2k'],['Webhooks sent','3,118'],['Storage','18.4 GB']]]].map(p=>({t:p[0],icon:'icon-'+p[1],c:p[2],stats:p[3].map(x=>({k:x[0],v:x[1]}))}));
    const outputs=[['Spring Collection Launch','Video','film','Video Production'],['Hydra ad · variation B','Image','image','Advertising Studio'],['The Last Lighthouse · Sc 2','Shot','clapperboard','Cinematic Studio'],['Q3 webinar → LinkedIn post','Article','file-text','Repurpose'],['Onboarding narration','Audio','audio-lines','Audio Studio'],['Product launch → client','Workflow','workflow','Run #1284']].map((o,i)=>({t:o[0],kind:o[1],icon:'icon-'+o[2],src:o[3],c:this.F[(i*3+2)%10]}));
    const activeAuto=[['Product launch → video → client','Shopify · product.created','142 runs','oklch(0.62 0.14 150)',1],['Weekly blog → 5 shorts','Every Monday 08:00','18 runs','oklch(0.62 0.14 150)',0],['Webinar repurposing','Upload to /webinars','running','oklch(0.6 0.15 250)',1],['Lead magnet video','Webhook · form.submitted','failed 2×','oklch(0.6 0.2 25)',0]].map(a=>({t:a[0],trig:a[1],runs:a[2],c:a[3],anim:a[4]?'rs-pulse 1.6s ease-in-out infinite':'none'}));

    // builder
    const run=s.wfRun;
    const nodes=s.nodes.map(n=>{const m=this.NT[n.t]; const st=run?run.status[n.id]:null; const sel=s.selNode===n.id;
      return {...n,kind:m[0],icon:'icon-'+m[1],c:this.CAT[m[2]],left:n.x+'px',top:n.y+'px',bd:st==='running'?'oklch(0.6 0.15 250)':sel?'#17181a':'#e1e0dc',sh:sel?'0 8px 24px rgba(20,20,25,.12)':'0 1px 2px rgba(20,20,25,.05)',
        sIcon:st==='running'?'icon-loader-circle':st==='done'?'icon-circle-check':'icon-circle',sColor:st==='running'?'oklch(0.55 0.13 250)':st==='done'?'oklch(0.5 0.14 150)':'#dcdad4',sAnim:st==='running'?'rs-spin 1s linear infinite':'none',
        down:e=>{ this.drag={id:n.id,sx:e.clientX,sy:e.clientY,ox:n.x,oy:n.y,moved:false}; },
        pick:e=>{ e.stopPropagation(); if(!this.drag||!this.drag.moved) this.setState({selNode:n.id}); this.drag=null; }};});
    const byId={}; s.nodes.forEach(n=>byId[n.id]=n);
    const edges=s.edges.filter(e=>byId[e[0]]&&byId[e[1]]).map(e=>{const a=byId[e[0]],b=byId[e[1]]; const W2=210,H=76; let d;
      const dx=b.x-a.x, dy=b.y-a.y;
      if(Math.abs(dy)>Math.abs(dx)){ const x1=a.x+W2/2,y1=a.y+H,x2=b.x+W2/2,y2=b.y; d=`M${x1} ${y1} C${x1} ${y1+50}, ${x2} ${y2-50}, ${x2} ${y2}`; }
      else if(dx>=0){ const x1=a.x+W2,y1=a.y+36,x2=b.x,y2=b.y+36; d=`M${x1} ${y1} C${x1+50} ${y1}, ${x2-50} ${y2}, ${x2} ${y2}`; }
      else { const x1=a.x,y1=a.y+36,x2=b.x+W2,y2=b.y+36; d=`M${x1} ${y1} C${x1-50} ${y1}, ${x2+50} ${y2}, ${x2} ${y2}`; }
      const act=run&&(run.status[e[0]]==='done')&&(run.status[e[1]]==='running'); const done=run&&run.status[e[1]]==='done';
      return {d,c:act?'oklch(0.6 0.15 250)':done?'oklch(0.6 0.12 150)':'#b3b4b8',dash:act?'6 6':'0',anim:act?'rs-dash .6s linear infinite':'none'};});
    const libGroups=[['Triggers',['trigger','webhook','schedule']],['AI',['aitext','aiimage','aivideo','aiaudio','voice','script','scene','character']],['Logic',['condition','delay','loop','transform']],['Data',['render','file','storage']],['Actions',['http','email','social','output']]];
    const nodeLib=libGroups.map(gp=>({t:gp[0],items:gp[1].filter(k=>this.NT[k][0].toLowerCase().includes(s.nq.toLowerCase())).map(k=>({label:this.NT[k][0],icon:'icon-'+this.NT[k][1],c:this.CAT[this.NT[k][2]],
      add:()=>this.setState(st=>{const last=st.nodes[st.nodes.length-1]; const nn={id:st.nextId,t:k,title:this.NT[k][0],sub:'configure →',x:Math.min(880,(last?last.x:0)+40),y:Math.min(540,(last?last.y:0)+40)}; return {nodes:[...st.nodes,nn],edges:last?[...st.edges,[last.id,st.nextId]]:st.edges,nextId:st.nextId+1,selNode:st.nextId};})}))})).filter(gp=>gp.items.length);
    const sn=byId[s.selNode]; const sm=sn?this.NT[sn.t]:null; const isAI=sm&&sm[2]==='ai';
    const fieldsFor={trigger:[['Event','shopify · product.created'],['Filter','product.status == "active"']],aitext:[['Prompt','Write a 30-second video script and 3 ad headlines for {product.title}. Audience: {brand.audience}. Tone: friendly.','area'],['Output variable','copy']],aiimage:[['Prompt','{product.title} on a clean studio background, soft light, {style}','area'],['Count','4']],aivideo:[['Prompt','Short product video using {images} and {copy.script}','area'],['Duration','30s']],voice:[['Text','{copy.script}'],['Voice','Theo · British']],render:[['Format','MP4 · 1080×1920'],['Captions','Karaoke · brand font']],email:[['To','{client.email}'],['Subject','Your new video for {product.title}']],condition:[['If','{reply.body} contains "approve"'],['Else','Notify account manager']],social:[['Account','@hydra · Instagram'],['Publish','Next weekday 09:00']]};
    const selFields=(sn?(fieldsFor[sn.t]||[['Configuration','—']]):[]).map(f=>({label:f[0],v:f[1],isArea:f[2]==='area',isInput:f[2]!=='area'}));
    const routes=['Free first','Cheapest','Fastest','Best quality','Specific model','Manual'];
    const chainBy={'Free first':['OpenRouter · free','Gemini · free','Groq'],'Cheapest':['Groq','OpenRouter · free','Gemini'],'Fastest':['Groq','Gemini','OpenRouter'],'Best quality':['OpenRouter · gpt-4o','Gemini 2.0 Pro','OpenRouter · free'],'Specific model':['OpenRouter · llama-3.3-70b'],'Manual':['Ask each run']};
    const chainImg={'Free first':['Cloudflare · free','Hugging Face','Replicate'],'Cheapest':['Cloudflare','Hugging Face'],'Fastest':['Cloudflare','Replicate'],'Best quality':['Replicate · flux-pro','Cloudflare'],'Specific model':['Cloudflare · flux-1-schnell'],'Manual':['Ask each run']};
    const chainVid={'Free first':['Fal.ai · ltx-video','Replicate'],'Cheapest':['Fal.ai · ltx-video','Replicate'],'Fastest':['Fal.ai','Replicate'],'Best quality':['Fal.ai · wan-2.1','Runway','Luma'],'Specific model':['Fal.ai · ltx-video'],'Manual':['Ask each run']};
    const chList=(sn&&sn.t==='aiimage'?chainImg:sn&&sn.t==='aivideo'?chainVid:chainBy)[s.route];
    const chain=chList.map((c,i)=>({n:c,arrow:i<chList.length-1,bd:i===0?'#17181a':'#e1e0dc',dot:c.includes('Groq')?'oklch(0.75 0.15 70)':'oklch(0.62 0.14 150)'}));
    const hints={'Free first':'Uses free models until quota or rate limits are hit, then falls back.','Cheapest':'Picks the lowest cost per request among healthy providers.','Fastest':'Picks the lowest median latency over the last hour.','Best quality':'Uses the highest-rated model you allow. Costs more.','Specific model':'Always uses one model. Fails if it\'s unavailable.','Manual':'Asks the user to choose when the workflow runs.'};

    // runs
    const runDefs=[['run_8f2a','Product launch → video → client','Running','2 min ago','0:48','Shopify webhook'],['run_8f19','Weekly blog → 5 shorts','Completed','1 hour ago','3:12','Schedule'],['run_8f11','Lead magnet video','Failed','2 hours ago','0:22','form.submitted'],['run_8f0c','Webinar repurposing','Completed','3 hours ago','11:40','Upload'],['run_8f02','Product launch → video → client','Completed','5 hours ago','1:02','Shopify webhook'],['run_8efa','Lead magnet video','Failed','6 hours ago','0:19','form.submitted'],['run_8ef1','Weekly blog → 5 shorts','Scheduled','Mon 08:00','—','Schedule'],['run_8ee9','Product launch → video → client','Queued','in queue · #3','—','Shopify webhook']];
    const RS={Running:['icon-loader-circle','oklch(0.55 0.13 250)','rs-spin 1s linear infinite','oklch(0.45 0.13 250)','oklch(0.95 0.03 250)'],Completed:['icon-circle-check','oklch(0.5 0.14 150)','none','oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Failed:['icon-circle-x','oklch(0.55 0.18 25)','none','oklch(0.5 0.18 25)','oklch(0.95 0.035 25)'],Scheduled:['icon-calendar-clock','#6b6d72','none','#55575c','#efefec'],Queued:['icon-clock','oklch(0.6 0.13 70)','none','oklch(0.5 0.13 70)','oklch(0.95 0.05 85)']};
    const runsF=runDefs.map((r,i)=>({i,r})).filter(x=>s.runFilter==='All'||x.r[2]===s.runFilter);
    const runs=runsF.map(x=>({id:x.r[0],wf:x.r[1],when:x.r[3],dur:x.r[4],icon:RS[x.r[2]][0],c:RS[x.r[2]][1],anim:RS[x.r[2]][2],bg:s.runSel===x.i?'#f6f5f2':'#fff',on:()=>this.setState({runSel:x.i})}));
    const R=runDefs[s.runSel]; const failed=R[2]==='Failed';
    const stepDefs=failed?[['trigger','Form submitted','webhook · lead@clearview.ae','0.1s','done'],['aitext','Write personalised script','OpenRouter → Groq (429) → Gemini · fallback used','2.4s','done'],['aiimage','Generate 3 images','Cloudflare flux-1-schnell','5.1s','done'],['voice','Voiceover','ElevenLabs · 401 Unauthorized','0.3s','fail'],['render','Render','skipped','—','skip'],['email','Email lead','skipped','—','skip']]:[['trigger','Trigger','Shopify webhook · product.created','0.1s','done'],['aitext','Generate marketing copy','OpenRouter llama-3.3-70b:free · 0 cr','1.8s','done'],['aiimage','Generate product images','Cloudflare flux-1-schnell · 4 cr','6.2s','done'],['aivideo','Generate video','Fal.ai ltx-video · 24 cr',R[2]==='Running'?'38s…':'38.4s',R[2]==='Running'?'run':'done'],['voice','Voiceover','Edge TTS · Theo','2.1s',R[2]==='Running'?'wait':'done'],['render','Render','FFmpeg · 1080×1920','9.7s',R[2]==='Running'?'wait':'done'],['email','Send to client','maya@northwind.io','0.4s',R[2]==='Running'?'wait':'done']];
    const stSty={done:['icon-check','oklch(0.45 0.12 150)','none'],fail:['icon-x','oklch(0.5 0.18 25)','none'],run:['icon-loader-circle','oklch(0.45 0.13 250)','rs-spin 1s linear infinite'],wait:['icon-clock','#8a8c91','none'],skip:['icon-minus','#b3b4b8','none']};
    const runD={id:R[0],wf:R[1],trig:R[5],st:R[2],sfg:RS[R[2]][3],sbg:RS[R[2]][4],failed,err:'ElevenLabs returned 401 Unauthorized. Update the API key in Admin → AI Providers, then retry. Earlier steps are cached and won\'t be charged again.',
      kpis:[['Duration',R[4]],['AI calls',failed?'4':'7'],['Credits',failed?'5':'30'],['Fallbacks',failed?'1':'0']].map(k=>({k:k[0],v:k[1]})),
      steps:stepDefs.map(x=>{const m=this.NT[x[0]]; const ss=stSty[x[4]]; return {t:x[1],meta:x[2],dur:x[3],icon:'icon-'+m[1],c:this.CAT[m[2]],sIcon:ss[0],sc:ss[1],sAnim:ss[2],mc:x[4]==='fail'?'oklch(0.5 0.18 25)':'#6b6d72'};})};

    // cinematic
    const cScenesDef=[[1,'The storm arrives','Rocky shore','Dusk'],[2,'The letter','Lantern room','Night'],[3,'A light on the water','Gallery deck','Night'],[4,'Dawn','Village pier','Dawn']];
    const shotsAll=s.shots||[];
    const cScenes=cScenesDef.map(c=>({n:String(c[0]).padStart(2,'0'),t:c[1],shotCount:shotsAll.filter(x=>x.sc===c[0]).length,bd:s.sceneSel===c[0]?'#17181a':'transparent',bg:s.sceneSel===c[0]?'#f6f5f2':'transparent',on:()=>{const f=shotsAll.find(x=>x.sc===c[0]); this.setState({sceneSel:c[0],shotSel:f?f.id:0});}}));
    const SC=cScenesDef.find(c=>c[0]===s.sceneSel);
    const sceneShots=shotsAll.filter(x=>x.sc===s.sceneSel);
    const shots=sceneShots.map((x,i)=>({...x,n:String(i+1).padStart(2,'0'),done:x.st==='done',loading:x.st==='loading',empty:x.st==='none',pct:Math.round(x.p)+'%',bg:x.st==='done'?x.c:x.st==='loading'?'#1d1f23':'#f3f2ef',lc:x.st==='none'?'#8a8c91':'rgba(255,255,255,.8)',bd:s.shotSel===x.id?'#17181a':'#e8e7e3',on:()=>this.setState({shotSel:x.id})}));
    const SH=shotsAll.find(x=>x.id===s.shotSel)||sceneShots[0]||{cam:'',move:'',lens:'',light:'',tod:'',wx:'',dur:'',st:'none'};
    const setShot=(k,v)=>this.setState(st=>({shots:st.shots.map(x=>x.id===SH.id?{...x,[k]:v}:x)}));
    const camGroups=[['Camera','cam',['Wide shot','Medium shot','Close-up','Extreme close-up','Over-the-shoulder','POV','Aerial','Establishing shot']],['Movement','move',['Static','Pan','Tilt','Dolly','Tracking','Crane','Zoom','Handheld']],['Lens','lens',['16mm','24mm','35mm','50mm','85mm','Anamorphic']],['Lighting','light',['Storm light','Lantern glow','Moonlight','Golden hour','High key','Low key']],['Time of day','tod',['Dawn','Day','Dusk','Night']],['Weather','wx',['Clear','Heavy rain','Storm','Fog','Clearing']]].map(cg=>({t:cg[0],opts:cg[2].map(o=>{const a=SH[cg[1]]===o; return {label:o,on:()=>setShot(cg[1],o),bg:a?'#17181a':'#fff',fg:a?'#fff':'#3a3c40',bd:a?'#17181a':'#e1e0dc'};})}));
    const chars=[['Mara','MA','#4a3b31'],['Eli','EL','#30384a'],['The Stranger','ST','#3d3447']];
    const sceneChars={1:[0],2:[0,1],3:[0,2],4:[0,1]}[s.sceneSel].map(i=>({n:chars[i][0],i:chars[i][1],c:chars[i][2]}));
    const shotPrompt=`${(SH.cam||'').toLowerCase()}, ${(SH.move||'').toLowerCase()} camera, ${SH.lens} lens, ${(SH.light||'').toLowerCase()}, ${(SH.tod||'').toLowerCase()}, ${(SH.wx||'').toLowerCase()}; ${SC[2].toLowerCase()}; ${sceneChars.map(c=>'[char:'+c.n.toLowerCase().replace(' ','_')+']').join(' ')}; style: ${s.style.toLowerCase()}, shallow depth of field, teal-amber grade`;
    const styleDefs=[['Cinematic','Anamorphic, film grain','#27403f'],['Photorealistic','Natural light, true color','#33413a'],['Anime','Cel-shaded, bold line','#3d3447'],['Cartoon','Flat color, rounded forms','#5a4a30'],['3D','Soft global illumination','#30384a'],['Stylised 3D family film','Warm, expressive, original characters','#4a3b31'],['Watercolor','Paper texture, bleeds','#2d3b2d'],['Comic','Halftone, ink outlines','#4a2f33'],['Documentary','Handheld, observational','#41392c'],['Luxury commercial','Glossy, high contrast','#23343f'],['Cyberpunk','Neon, rain, night city','#3d3447'],['Vintage','Faded stock, 1970s','#5a4a30'],['Minimalist','Negative space, clean','#33413a']];
    const pats=['repeating-linear-gradient(135deg,rgba(255,255,255,.07) 0 1px,transparent 1px 10px)','repeating-linear-gradient(90deg,rgba(255,255,255,.06) 0 1px,transparent 1px 12px)','radial-gradient(rgba(255,255,255,.12) 1px,transparent 1px)'];
    const styles=styleDefs.map((v,i)=>{const sel=s.style===v[0]; return {n:v[0],d:v[1],c:v[2],pat:pats[i%3],bs:i%3===2?'10px 10px':'auto',sel,bd:sel?'#17181a':'#e8e7e3',sh:sel?'0 6px 20px rgba(20,20,25,.1)':'none',on:()=>{this.setState({style:v[0]}); this.toast('Style set to '+v[0]);}};});
    const characters=[['Mara','Lighthouse keeper, late 60s. Weathered, stubborn, kind.','Silver braid, deep-set grey eyes, wind-burned skin','Oilskin coat, wool sweater','Guarded, dry humour','Lena · Calm','in 4 scenes','#4a3b31','#5a4a30','#41392c'],['Eli','Village courier, 19. Nervous but brave.','Curly dark hair, freckles, slight build','Oversized rain jacket, satchel','Earnest, talks fast','Noah · Warm','in 2 scenes','#30384a','#23343f','#27403f'],['The Stranger','Unknown figure seen only in silhouette.','Tall, face always in shadow','Long dark coat, brimmed hat','Quiet, deliberate','Theo · Low','in 1 scene','#3d3447','#4a2f33','#30384a']].map(c=>({name:c[0],desc:c[1],look:c[2],costume:c[3],pers:c[4],voice:c[5],used:c[6],c:c[7],c2:c[8],c3:c[9]}));

    // agents
    const agentDefs=[['Script Writer','pen-line','Writes hooks, scripts and CTAs in your brand voice.','llama-3.3-70b:free',['Brand kit','Web search'],'You are a senior short-form scriptwriter. Write punchy hooks in the first 3 seconds. Keep sentences under 12 words. Always end with the brand CTA.','Script JSON'],['Director','clapperboard','Turns scripts into scenes and shot lists with camera direction.','gemini-2.0-flash',['Scenes','Characters'],'You are a film director. Break the script into scenes and shots. For each shot specify camera, movement, lens and lighting.','Shot list JSON'],['Storyboard Artist','frame','Generates consistent storyboard frames per shot.','flux-1-schnell',['Images','Styles'],'Generate storyboard frames that keep characters consistent with their reference images.','Images'],['Video Producer','film','Assembles shots, voice and music into a final cut.','auto',['Render','Music'],'Assemble the final cut. Match cuts to narration beats.','MP4'],['Marketing Agent','target','Plans campaigns and writes platform-specific copy.','gpt-4o-mini',['Web search','Brand kit'],'Plan a 2-week campaign with daily posts.','Markdown'],['Ad Creator','megaphone','Produces 5 ad variations per product for A/B tests.','llama-3.3-70b:free',['Images','Video'],'Create 5 distinct ad angles: problem, social proof, offer, comparison, story.','Ad set JSON'],['Social Media Agent','share-2','Schedules and adapts content for each platform.','gemini-2.0-flash',['Social','Schedule'],'Adapt content for each platform\'s format and best posting time.','Posts JSON'],['Research Agent','search','Researches topics and summarises sources.','llama-3.3-70b:free',['Web search','Files'],'Research the topic. Cite 3–5 sources.','Markdown'],['Voice Agent','mic','Chooses voices and directs narration pacing.','edge-tts',['Voices'],'Pick the best voice for the audience and mark emphasis.','SSML'],['Repurposing Agent','split','Finds key moments and turns long content into clips.','gemini-2.0-flash',['Transcripts','Video'],'Find the 10 most shareable moments under 60 seconds.','Clips JSON']];
    const agents=agentDefs.map((a,i)=>({n:a[0],icon:'icon-'+a[1],d:a[2],model:a[3],tools:a[4],c:[this.CAT.ai,this.CAT.logic,this.CAT.action,this.CAT.data][i%4],open:()=>this.setState({agentSel:i})}));
    const AG=s.agentSel!==null?agentDefs[s.agentSel]:null;

    // repurpose
    const rpDefs=[['yt','YouTube video','square-play','16:9 · 12 min edit'],['shorts','10 Shorts','smartphone','9:16 · 30–58s'],['reels','Instagram Reels','camera','6 clips'],['tiktok','TikTok clips','music-2','8 clips'],['li','LinkedIn post','briefcase','with carousel'],['x','X thread','at-sign','9 posts'],['blog','Blog article','file-text','1,400 words'],['cap','Captions','captions','SRT + VTT'],['thumb','Thumbnail concepts','image','4 options']];
    const rpOutputs=rpDefs.map(d=>{const on=s.rpSel[d[0]]; const p=s.rpProg?(s.rpProg[d[0]]||0):0; const running=!!s.rpProg&&on;
      return {t:d[1],icon:'icon-'+d[2],d:d[3],bd:on?'#17181a':'#e8e7e3',op:on?1:0.55,cbBd:on?'#17181a':'#d6d4ce',cbBg:on?'#17181a':'#fff',pct:(running?p:0)+'%',barC:p>=100?'oklch(0.62 0.14 150)':'oklch(0.58 0.19 35)',st:!on?'skipped':!s.rpProg?'ready to generate':p>=100?'done · open':'generating '+Math.round(p)+'%',sc:running&&p>=100?'oklch(0.45 0.12 150)':'#6b6d72',
        toggle:()=>this.setState(x=>({rpSel:{...x.rpSel,[d[0]]:!on}}))};});

    // api
    const eps=[['POST','/api/video/generate','Generate a video from a topic, script or template.',`curl -X POST https://ai.acme.co/api/video/generate \\
  -H "Authorization: Bearer rsk_live_..." \\
  -d '{ "topic": "5 ways Hydra keeps you hydrated",
        "platform": "tiktok", "duration": 30,
        "routing": "free_first" }'

# 202 Accepted
{ "job_id": "job_9f2k", "status": "queued" }`],['POST','/api/workflows/run','Run a workflow with input variables.',`curl -X POST https://ai.acme.co/api/workflows/run \\
  -H "Authorization: Bearer rsk_live_..." \\
  -d '{ "workflow_id": "wf_launch_video",
        "input": { "product_id": "8841" } }'

{ "run_id": "run_8f2a", "status": "running" }`],['GET','/api/jobs/{id}','Check any job: video, image, audio or workflow run.',`curl https://ai.acme.co/api/jobs/job_9f2k \\
  -H "Authorization: Bearer rsk_live_..."

{ "id": "job_9f2k", "type": "video", "status": "completed",
  "output": { "url": "https://cdn.acme.co/v/job_9f2k.mp4" },
  "credits": 38 }`],['POST','/api/images/generate','Generate images with a style and routing rule.',`{ "prompt": "Hydra bottle on marble", "count": 4, "style": "luxury_commercial" }`],['POST','/api/agents/{id}/run','Run an AI agent with a message.',`{ "message": "Write 5 ad angles for Hydra", "format": "json" }`]];
    const MC={GET:'oklch(0.5 0.13 250)',POST:'oklch(0.48 0.13 150)'};
    const E=eps[s.ep];
    const hooks=[['https://hooks.zapier.com/…/8841','Outgoing','video.completed, run.failed','99.8%','Active'],['https://api.northwind.io/reels','Outgoing','video.completed','97.1%','Active'],['/in/shopify/product-created','Incoming','product.created','100%','Active'],['/in/forms/lead','Incoming','form.submitted','88.0%','Failing']].map(h=>({url:h[0],dir:h[1],dIcon:h[1]==='Incoming'?'icon-arrow-down-left':'icon-arrow-up-right',ev:h[2],rate:h[3],rc:parseFloat(h[3])<95?'oklch(0.5 0.18 25)':'#17181a',st:h[4],sfg:h[4]==='Active'?'oklch(0.42 0.12 150)':'oklch(0.5 0.18 25)',sbg:h[4]==='Active'?'oklch(0.95 0.04 150)':'oklch(0.95 0.035 25)'}));
    const events=[['09:41:12','OUT','video.completed','200','hooks.zapier.com'],['09:40:58','IN','product.created','202','shopify · Hydra Bottle'],['09:40:31','OUT','run.failed','200','hooks.zapier.com'],['09:39:44','IN','form.submitted','422','invalid payload: missing email'],['09:38:02','OUT','video.completed','200','api.northwind.io'],['09:37:10','OUT','video.completed','500','api.northwind.io · retry 1/3'],['09:36:44','IN','product.created','202','shopify · Glass Tumbler'],['09:35:01','OUT','image.completed','200','hooks.zapier.com']].map(e=>({t:e[0],dir:e[1],dc:e[1]==='IN'?'oklch(0.75 0.1 250)':'oklch(0.78 0.13 45)',ev:e[2],code:e[3],sc:e[3][0]==='2'?'oklch(0.78 0.14 150)':'oklch(0.72 0.17 25)',target:e[4]}));
    const reqLogs=[['09:41:10','POST','/api/workflows/run','rsk_live_…8f2a','202','84 ms'],['09:41:02','GET','/api/jobs/job_9f2k','rsk_live_…8f2a','200','12 ms'],['09:40:47','POST','/api/video/generate','rsk_live_…11cd','202','96 ms'],['09:40:12','GET','/api/jobs/job_9f1a','rsk_live_…11cd','200','11 ms'],['09:39:30','POST','/api/images/generate','rsk_live_…8f2a','429','4 ms'],['09:38:58','POST','/api/agents/ad-creator/run','rsk_live_…8f2a','200','2.1 s'],['09:38:11','GET','/api/jobs/job_nope','rsk_test_…a09e','404','6 ms']].map(r=>({t:r[0],m:r[1],p:r[2],k:r[3],s:r[4],l:r[5],mc:MC[r[1]],sc:r[4][0]==='2'?'oklch(0.45 0.12 150)':'oklch(0.5 0.18 25)'}));

    return {
      isMobile, notMobile:!isMobile, drawerOverlay:isMobile&&s.drawer, openDrawer:()=>this.setState({drawer:true}), closeDrawer:()=>this.setState({drawer:false}),
      sb:{pos:isMobile?'fixed':'sticky',tf:isMobile&&!s.drawer?'translateX(-100%)':'none'},
      modeChips:['Simple','Pro'].map(m=>({label:m,on:()=>{this.setState({mode:m}); if(m==='Simple'&&!['home','workspace'].includes(s.screen)) this.go('home');},bg:s.mode===m?'#3a3c42':'transparent',fg:s.mode===m?'#fff':'#8a8c91'})),
      navGroups:groups, crumbGroup:titles[scr][0], pageTitle:titles[scr][1], hdrPad:isMobile?'16px':'32px', pad:isMobile?'20px 16px 60px':'28px 32px 60px', maxW:scr==='builder'?'1680px':'1400px',
      s:sf, go:g, isPro:pro,
      quick, pipeline, pipeCols:W<700?'1fr 1fr':'repeat(4,minmax(0,1fr))', outputs, activeAuto, dashCols:narrow?'minmax(0,1fr)':'minmax(0,1fr) 340px',
      studios, advanced:[['Workflow Builder','workflow','Drag-and-drop AI pipelines',g.builder],['AI Agents','bot','Specialists with tools & memory',g.agents],['API & Webhooks','webhook','Trigger anything from your apps',g.api],['Repurpose','split','One source, many outputs',g.repurpose]].map(a=>({t:a[0],icon:'icon-'+a[1],d:a[2],go:a[3]})),
      wfList:[['Product launch → video → client','Shopify webhook','zap','1,142','2 min ago','Active',['trigger','ai','ai','ai','ai','data','action']],['Weekly blog → 5 shorts','Every Monday 08:00','calendar-clock','18','Mon 08:00','Active',['trigger','action','ai','ai','data']],['Webinar repurposing','Upload to /webinars','upload','6','3 hours ago','Active',['trigger','ai','logic','ai','action']],['Lead magnet video','Form submitted','webhook','214','2 hours ago','Failing',['trigger','ai','ai','ai','data','action']],['Daily product ad variations','Every day 06:00','calendar-clock','0','Never','Draft',['trigger','ai','ai','action']]].map(w=>({name:w[0],trig:w[1],ticon:'icon-'+w[2],runs:w[3],last:w[4],st:w[5],steps:w[6].map(c=>this.CAT[c]),sfg:w[5]==='Active'?'oklch(0.42 0.12 150)':w[5]==='Failing'?'oklch(0.5 0.18 25)':'#55575c',sbg:w[5]==='Active'?'oklch(0.95 0.04 150)':w[5]==='Failing'?'oklch(0.95 0.035 25)':'#efefec'})),
      wfTemplates:[['Product → video ad','New product in your store becomes a video ad and social post.',['trigger','aitext','aivideo','social']],['Repurpose long video','Turn a webinar into shorts, posts and a blog.',['file','aitext','aivideo','output']],['Blog → narrated video','Publish a blog, get a voiced video with captions.',['webhook','script','voice','render']],['Lead → personalised video','Form submission triggers a personal video email.',['webhook','aitext','aivideo','email']]].map(t=>({t:t[0],d:t[1],go:g.builder,chain:t[2].map(k=>({icon:'icon-'+this.NT[k][1],c:this.CAT[this.NT[k][2]]}))})),
      nodes, edges, nodeLib, nq:s.nq, setNq:e=>this.setState({nq:e.target.value}), wfNodeCount:s.nodes.length,
      canvasMove:e=>{ const d=this.drag; if(!d) return; const dx=e.clientX-d.sx, dy=e.clientY-d.sy; if(Math.abs(dx)+Math.abs(dy)>3) d.moved=true; if(!d.moved) return; this.setState(st=>({nodes:st.nodes.map(n=>n.id===d.id?{...n,x:Math.max(0,Math.min(890,d.ox+dx)),y:Math.max(0,Math.min(540,d.oy+dy))}:n)})); },
      canvasUp:()=>{ if(this.drag&&!this.drag.moved) return; setTimeout(()=>{this.drag=null;},0); }, canvasClick:()=>{ if(!this.drag) this.setState({selNode:null}); },
      hasSel:!!sn, noSel:!sn, sel:sn?{title:sn.title,kind:sm[0],icon:'icon-'+sm[1],c:this.CAT[sm[2]],isAI}:{}, selFields,
      setSelTitle:e=>{const v=e.target.value; this.setState(st=>({nodes:st.nodes.map(n=>n.id===st.selNode?{...n,title:v}:n)}));},
      delNode:()=>this.setState(st=>({nodes:st.nodes.filter(n=>n.id!==st.selNode),edges:st.edges.filter(e=>e[0]!==st.selNode&&e[1]!==st.selNode),selNode:null})),
      routeChips:routes.map(r=>({label:r,on:()=>this.setState({route:r}),bg:s.route===r?'#17181a':'#fff',fg:s.route===r?'#fff':'#3a3c40',bd:s.route===r?'#17181a':'#e1e0dc'})), routeHint:hints[s.route], chain,
      testWf:()=>{ if(run&&!run.done) return; this.runWorkflow(); }, testIcon:run&&!run.done?'icon-loader-circle':'icon-flask-conical', testAnim:run&&!run.done?'rs-spin 1s linear infinite':'none', testLabel:run&&!run.done?'Running…':'Test workflow',
      wfLive:s.live?{c:'oklch(0.62 0.14 150)',t:'Live · 142 runs'}:{c:'#b3b4b8',t:'Paused'}, liveIcon:s.live?'icon-pause':'icon-play', liveLabel:s.live?'Pause':'Activate', toggleLive:()=>{this.setState({live:!s.live}); this.toast(s.live?'Workflow paused':'Workflow is live');},
      runLog:s.runLog, logEmpty:s.runLog.length===0, runMeta:run?(run.done?'run_8f2a · completed':'run_8f2a · running'):'no test runs yet',
      bCols:narrow?'minmax(0,1fr)':'220px minmax(0,1fr) 300px', bOrd:narrow?{lib:3,canvas:1,props:2}:{lib:1,canvas:2,props:3}, canvasH:isMobile?'420px':'600px', libH:narrow?'none':'600px',
      runTabs:this.chips(['All','Running','Completed','Failed','Scheduled','Queued'],s.runFilter,v=>this.setState({runFilter:v})).map(c=>({...c,count:runDefs.filter(r=>c.label==='All'||r[2]===c.label).length})),
      runs, run:runD, runCols:narrow?'minmax(0,1fr)':'380px minmax(0,1fr)', retryRun:()=>this.toast('Retrying run_8f11 from step 4','info'),
      prod:{title:s.prodTitle}, setProdTitle:e=>this.setState({prodTitle:e.target.value}),
      cTabs:this.tabs(['Setup','Story','Characters','Scenes & Shots','Visual Style'],scr==='characters'?'Characters':scr==='styles'?'Visual Style':s.cTab,v=>{this.setState({cTab:v}); if(s.screen!=='cinematic') this.go('cinematic');}),
      ct:{setup:scr==='cinematic'&&s.cTab==='Setup',story:scr==='cinematic'&&s.cTab==='Story',scenes:scr==='cinematic'&&s.cTab==='Scenes & Shots'},
      showChars:scr==='characters'||(scr==='cinematic'&&s.cTab==='Characters'), showStyles:scr==='styles'||(scr==='cinematic'&&s.cTab==='Visual Style'),
      sideCols:narrow?'minmax(0,1fr)':'minmax(0,1fr) 340px',
      setupSelects:[['Genre','Drama · Mystery',['Drama · Mystery','Thriller','Comedy','Sci-fi','Documentary','Commercial']],['Visual style',s.style,styleDefs.map(x=>x[0])],['Duration','2:30',['0:30','1:00','2:30','5:00','10:00']],['Aspect ratio','2.39:1',['2.39:1','16:9','9:16','1:1']],['Language','English',['English','Spanish','French','German','Japanese']],['Target platform','YouTube',['YouTube','Festival / screening','Instagram','TikTok']]].map(f=>({label:f[0],v:f[1],opts:f[2]})),
      prodToggles:[['consistency','Character consistency','Lock faces, costumes and voices to the Character Library across all shots.'],['style','Lock visual style','Apply '+s.style+' to every generated frame.'],['autoShots','Auto shot list','Director agent suggests shots for new scenes.'],['sound','Generate ambient sound','Adds rain, wind and room tone per scene.']].map(t=>({t:t[1],d:t[2],...this.tog(s.prodT[t[0]]),toggle:()=>this.setState(x=>({prodT:{...x.prodT,[t[0]]:!x.prodT[t[0]]}}))})),
      prodStats:[['Scenes','4'],['Shots',String(shotsAll.length)],['Generated',shotsAll.filter(x=>x.st==='done').length+' / '+shotsAll.length],['Characters','3'],['Est. credits','≈ 420']].map(p=>({k:p[0],v:p[1]})),
      scriptPad:isMobile?'18px':'40px',
      screenplay:[['INT. LIGHTHOUSE LANTERN ROOM — NIGHT','left','0','0',700,'uppercase','#17181a'],['Rain hammers the glass. The great lens turns. MARA (60s) winds the clockwork, counting under her breath.','left','0','0',400,'none','#3a3c40'],['ELI','center','0','0',700,'uppercase','#17181a'],['(breathless)','center','0','0',400,'none','#6b6d72'],['There\'s a letter. It came with no stamp. It has your name on it.','left','22%','22%',400,'none','#3a3c40'],['MARA','center','0','0',700,'uppercase','#17181a'],['Nobody\'s written to me in thirty years.','left','22%','22%',400,'none','#3a3c40'],['She takes it. Her hands stop shaking.','left','0','0',400,'none','#3a3c40']].map(x=>({t:x[0],align:x[1],indent:x[2],indentR:x[3],fw:x[4],tt:x[5],c:x[6]})),
      storyCards:[['lightbulb','Story idea','A keeper must choose between duty and the one person she lost.'],['map-pin','Locations','Rocky shore · Lantern room · Gallery deck · Village pier'],['globe','World','A North Atlantic island, 1962. No phones; the mail boat comes weekly.'],['list-ordered','Structure','4 scenes · 14 shots · 2:30 runtime']].map(x=>({icon:'icon-'+x[0],t:x[1],d:x[2]})),
      cScenes, scene:{n:String(SC[0]).padStart(2,'0'),t:SC[1],loc:SC[2],time:SC[3],desc:'Mara climbs to the lantern room as the storm peaks. Eli arrives soaked, carrying a letter with her name on it.',
        facts:[['Location',SC[2]],['Lighting','Lantern glow, storm flashes'],['Sound','Rain, wind, gears'],['Duration','0:38']].map(f=>({k:f[0],v:f[1]})),chars:sceneChars,dialogue:'ELI: There\'s a letter. It came with no stamp.  MARA: Nobody\'s written to me in thirty years.'},
      shots, shot:{n:String(Math.max(1,sceneShots.findIndex(x=>x.id===SH.id)+1)).padStart(2,'0'),dur:SH.dur}, camGroups, shotPrompt, shotBtn:SH.st==='done'?'Regenerate shot':SH.st==='loading'?'Generating…':'Generate shot',
      genShot:()=>{ if(SH.st!=='loading') setShot('st','loading'); this.setState(st=>({shots:st.shots.map(x=>x.id===SH.id?{...x,st:'loading',p:0}:x)})); },
      genAllShots:()=>this.setState(st=>({shots:st.shots.map(x=>x.st==='none'?{...x,st:'loading',p:0}:x)})),
      addShot:()=>this.setState(st=>{const id=st.shots.length; return {shots:[...st.shots,{id,sc:st.sceneSel,cam:'Medium shot',move:'Static',lens:'50mm',light:'Lantern glow',tod:'Night',wx:'Heavy rain',st:'none',p:0,dur:'4s',c:this.F[id%10]}],shotSel:id};}),
      shotCols:narrow?'minmax(0,1fr)':'200px minmax(0,1fr) 320px', shOrd:narrow?{a:1,b:2,c:3}:{a:1,b:2,c:3},
      characters, styles,
      agents, agentOpen:!!AG, ag:AG?{n:AG[0],icon:'icon-'+AG[1],model:AG[3],sys:AG[5],fmt:AG[6],c:agents[s.agentSel].c}:{}, closeAgent:()=>this.setState({agentSel:null}), saveAgent:()=>{this.setState({agentSel:null}); this.toast('Agent saved');}, newAgent:()=>this.setState({agentSel:0}),
      agTools:[['web','Web search','search'],['brand','Brand kit','palette'],['files','Files','file'],['images','Image generation','image'],['workflows','Run workflows','workflow'],['api','HTTP requests','globe']].map(t=>{const on=s.agTools[t[0]]; return {label:t[1],icon:'icon-'+t[2],bd:on?'#17181a':'#e1e0dc',bg:on?'#f6f5f2':'#fff',toggle:()=>this.setState(x=>({agTools:{...x.agTools,[t[0]]:!on}}))};}),
      agPerms:[['run','Can be used as a workflow step'],['publish','Can publish to social accounts'],['spend','Can spend up to 50 credits per task']].map(p=>({t:p[1],...this.tog(s.agPerms[p[0]]),toggle:()=>this.setState(x=>({agPerms:{...x.agPerms,[p[0]]:!x.agPerms[p[0]]}}))})),
      rpCols:narrow?'minmax(0,1fr)':'minmax(0,1fr) minmax(0,1.3fr)', rpAnalysis:[['Transcribed','48:12 · 7,904 words'],['Speakers identified','3'],['Key moments','14'],['Topics','6'],['Quotable lines','22']].map(a=>({t:a[0],v:a[1]})), rpTopics:['Auto-captions','Pricing update','Customer story','Roadmap','Q&amp;A highlights'.replace('&amp;','&'),'Integrations'],
      rpOutputs, rpSelCount:Object.values(s.rpSel).filter(Boolean).length, runRepurpose:()=>{ const p={}; Object.keys(s.rpSel).forEach(k=>{ if(s.rpSel[k]) p[k]=0; }); this.setState({rpProg:p}); },
      apiKpis:[['Requests · 24h','12,480'],['Webhooks delivered','3,118'],['Error rate','0.8%'],['p95 latency','96 ms']].map(k=>({k:k[0],v:k[1]})),
      apiTabs:this.tabs(['Webhooks','Events','Request logs','Keys','Docs'],s.apiTab,v=>this.setState({apiTab:v})), at:{webhooks:s.apiTab==='Webhooks',events:s.apiTab==='Events',logs:s.apiTab==='Request logs',keys:s.apiTab==='Keys',docs:s.apiTab==='Docs'},
      hooks, events, reqLogs, keys:[['Production','rsk_live_••••8f2a','video, workflows, jobs','48,210','2 min ago'],['Zapier','rsk_live_••••11cd','video, jobs','9,114','1 hour ago'],['Staging','rsk_test_••••a09e','all','1,202','Sep 18']].map(k=>({n:k[0],k:k[1],s:k[2],r:k[3],l:k[4]})),
      eps:eps.map((e,i)=>({m:e[0],p:e[1],mc:MC[e[0]],bg:s.ep===i?'#f3f2ef':'transparent',on:()=>this.setState({ep:i})})), ep:{m:E[0],p:E[1],d:E[2],code:E[3],mc:MC[E[0]]}, docCols:W<1000?'minmax(0,1fr)':'280px minmax(0,1fr)', addHook:()=>this.toast('New endpoint · add a URL and choose events','info'),
      briefOpen:!!B, brief:B?{t:B[0],icon:'icon-'+B[1],item:(s.briefItem||B[3][0]).toLowerCase(),ph:'Describe what you want. e.g. "15-second ad for Hydra smart bottle, upbeat, 3 variations"',items:B[3].map(it=>({label:it,on:()=>this.setState({briefItem:it}),bg:(s.briefItem||B[3][0])===it?'#17181a':'#fff',fg:(s.briefItem||B[3][0])===it?'#fff':'#3a3c40',bd:(s.briefItem||B[3][0])===it?'#17181a':'#e1e0dc'}))}:{items:[]},
      closeBrief:()=>this.setState({brief:null}), startBrief:()=>{ this.setState({brief:null,runFilter:'All',runSel:0}); this.toast((s.briefItem||'Job')+' started · follow it in Runs'); this.go('runs'); },
      toasts:s.toasts
    };
  }
}
