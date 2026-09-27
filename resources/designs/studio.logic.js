class DesignComponent extends DCLogic {
  F = ['#23343f','#4a3b31','#33413a','#3d3447','#5a4a30','#27403f','#4a2f33','#30384a','#41392c','#2d3b2d'];
  ST = {Completed:['oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],Processing:['oklch(0.45 0.13 250)','oklch(0.95 0.03 250)'],Failed:['oklch(0.5 0.18 25)','oklch(0.95 0.035 25)'],Draft:['#55575c','#efefec']};
  WORDS = 'Stay hydrated all day without even thinking about it'.split(' ');
  VIDEOS = [
    ['Spring Collection Launch','0:45','Completed','Sep 23, 2026','Instagram Reels','9:16'],
    ['Smart Bottle — Hydration Tips','0:30','Processing','Sep 23, 2026','TikTok','9:16'],
    ['Q3 Product Walkthrough','2:10','Completed','Sep 21, 2026','YouTube','16:9'],
    ['Open House: 42 Elm Street','1:05','Completed','Sep 20, 2026','YouTube Shorts','9:16'],
    ['Onboarding Explainer v2','1:40','Failed','Sep 19, 2026','YouTube','16:9'],
    ['Black Friday Teaser','0:15','Draft','Sep 18, 2026','Instagram Reels','9:16'],
    ['Customer Story: Northwind','1:20','Completed','Sep 16, 2026','LinkedIn','1:1'],
    ['5 Habits of Productive Teams','0:58','Completed','Sep 14, 2026','TikTok','9:16'],
    ['Feature Drop: Auto-Captions','0:35','Processing','Sep 13, 2026','YouTube Shorts','9:16'],
    ['Webinar Recap — September','3:05','Draft','Sep 11, 2026','YouTube','16:9'],
    ['Summer Sale Carousel','0:20','Completed','Sep 09, 2026','Instagram Reels','4:5'],
    ['History of Coffee in 60s','1:00','Completed','Sep 06, 2026','TikTok','9:16']
  ].map((v,i)=>({id:i,name:v[0],dur:v[1],status:v[2],date:v[3],platform:v[4],ratio:v[5],c:this.F[i%10],ts:12-i}));
  TPL = [['Hook · Story · Offer','TikTok','0:30','9:16'],['Product Spotlight','Product Ads','0:20','9:16'],['Listing Walkthrough','Real Estate','1:00','16:9'],['Feature Launch','SaaS Ads','0:45','16:9'],['Top 5 Countdown','Faceless Content','0:58','9:16'],['Mini Lesson','Education','1:30','16:9'],['UGC Testimonial','Instagram Reels','0:25','9:16'],['Explainer in 3 Acts','Explainer','1:15','16:9'],['Flash Sale','Ecommerce','0:15','1:1'],['Channel Intro','YouTube','0:40','16:9'],['Quick Tip','YouTube Shorts','0:30','9:16'],['Before / After','Ecommerce','0:20','4:5'],['Market Update','Real Estate','0:50','9:16'],['Story Narration','Faceless Content','1:00','9:16'],['Pain · Solution · Proof','SaaS Ads','0:35','9:16'],['Course Trailer','Education','0:45','16:9']];
  VOICES = [['Marcus','Male','English (US)','American',['Narrator','Professional']],['Ava','Female','English (US)','American',['Energetic']],['Theo','Male','English (UK)','British',['Storytelling','Calm']],['Priya','Female','English (IN)','Indian',['Professional']],['Lena','Female','German','Standard',['Calm','Narrator']],['Diego','Male','Spanish','Mexican',['Energetic']],['Sofia','Female','English (AU)','Australian',['Storytelling']],['Noah','Male','English (US)','American',['Calm','Professional']],['Camille','Female','French','Parisian',['Narrator']]];
  MODELS = {Text:['llama-3.3-70b-instruct:free','deepseek-chat-v3:free','gemini-2.0-flash','gpt-4o-mini'],Image:['flux-1-schnell','stable-diffusion-xl','sdxl-lightning'],Video:['ltx-video','wan-2.1-t2v','hunyuan-video'],Voice:['en-US-Neural','en_US-lessac','multilingual-v2']};
  mkProv = (id,cat,name,mono,tier,status,model,key,url) => ({id,cat,name,mono,tier,status,model,key,url:url||'https://api.example.com/v1',test:status==='connected'?'OK · 380 ms':'—'});
  state = {
    w: typeof window !== 'undefined' ? window.innerWidth : 1400,
    screen: (typeof localStorage !== 'undefined' && localStorage.getItem('rs_screen')) || this.props.startScreen || 'dashboard',
    drawer:false, step:0,
    idea:{topic:'5 ways Hydra keeps you hydrated', tone:'Friendly', platform:'TikTok', dur:'30s', ratio:'9:16'},
    script:{title:'5 ways Hydra keeps you hydrated', hook:'Ever get to 3pm and realize you\'ve barely had a sip of water?', body:'Hydra glows when it\'s time to drink, so you never have to remember. Every sip syncs to your phone and tracks your daily goal. And it keeps water ice-cold for 24 hours, wherever the day takes you.', cta:'Stay hydrated without thinking about it. Get yours at hydra.co.'},
    editScript:false, regen:false,
    scenes:[
      ['Close-up of a matte teal smart bottle on a sunlit desk, soft morning light','Ever get to 3pm and realize you\'ve barely had a sip of water?','Barely had a sip?',5,'done'],
      ['Bottle cap glowing gently as a reminder, shallow depth of field','Hydra glows when it\'s time to drink, so you never have to remember.','It reminds you',6,'done'],
      ['Phone screen showing a daily hydration ring filling up','Every sip syncs to your phone and tracks your daily goal.','Tracks every sip',6,'none'],
      ['Hiker on a ridge at golden hour, bottle clipped to backpack','Ice-cold for 24 hours, wherever the day takes you.','Cold for 24 hours',6,'none'],
      ['Product hero shot on a clean background with brand logo','Stay hydrated without thinking about it. Get yours at hydra.co.','Shop at hydra.co',7,'none']
    ].map((s,i)=>({id:i+1,prompt:s[0],narration:s[1],caption:s[2],dur:s[3],v:s[4],vp:0,src:'AI Image',c:['#2f4b4b','#3d3447','#30384a','#5a4a30','#23343f'][i],tr:'Fade',rg:false})),
    nextId:6, dragId:null, overId:null, editSceneId:null,
    voiceCat:'All', voice:'Theo', playing:null,
    cap:{font:'Archivo Black', size:44, pos:'Bottom', anim:'Karaoke', color:'#ffffff', bg:'None', hl:'#ffd23f', hlStyle:'Color'}, wi:0,
    renderP:0, rendering:false,
    edSel:0, edT:0, edPlay:false, edTab:'Scenes', track:0,
    libFilter:'All', q:'', sort:'Newest', tplCat:'All',
    brand:{name:'Hydra', web:'hydra.co', cta:'Shop now at hydra.co', c0:'#0e3b43', c1:'#2fb3a5', c2:'#f4efe6', heading:'Archivo Black', body:'DM Sans', wm:true, wmPos:'Top right'},
    prov:[
      this.mkProv('or','Text','OpenRouter','OR','Free models available','connected','llama-3.3-70b-instruct:free','sk-or-v1-••••••••3f9a','https://openrouter.ai/api/v1'),
      this.mkProv('gem','Text','Google Gemini','GG','Free tier','connected','gemini-2.0-flash','AIza••••••••Qk2m'),
      this.mkProv('groq','Text','Groq','GQ','Free tier','off'),
      this.mkProv('cft','Text','Cloudflare Workers AI','CF','Free daily quota','disabled','llama-3.1-8b-instruct','••••••••a71c'),
      this.mkProv('hft','Text','Hugging Face','HF','Free inference','off'),
      this.mkProv('oai','Text','Custom OpenAI-compatible','{ }','Any compatible endpoint','off'),
      this.mkProv('cfi','Image','Cloudflare Workers AI','CF','Free daily quota','connected','flux-1-schnell','••••••••a71c'),
      this.mkProv('hfi','Image','Hugging Face','HF','Free inference','connected','stable-diffusion-xl','hf_••••••••Wd8'),
      this.mkProv('stab','Image','Stability AI','SA','Paid','off'),
      this.mkProv('repi','Image','Replicate','RP','Pay as you go','off'),
      this.mkProv('fal','Video','Fal.ai','FA','Pay as you go','connected','ltx-video','fal_••••••••9c1e'),
      this.mkProv('repv','Video','Replicate','RP','Pay as you go','off'),
      this.mkProv('run','Video','Runway','RW','Paid','off'),
      this.mkProv('luma','Video','Luma','LM','Paid','off'),
      this.mkProv('edge','Voice','Edge TTS','ET','Free · no key needed','connected','en-US-Neural','not required'),
      this.mkProv('piper','Voice','Piper','PI','Self-hosted','connected','en_US-lessac','local'),
      this.mkProv('el','Voice','ElevenLabs','EL','Paid · voice cloning','off'),
      this.mkProv('oat','Voice','OpenAI TTS','OA','Paid','off')
    ],
    cfg:null, testing:null
  };

  componentDidMount() {
    this.onResize = () => this.setState({w: window.innerWidth});
    window.addEventListener('resize', this.onResize);
    this.tk = 0;
    this.iv = setInterval(this.tick, 100);
  }
  componentWillUnmount() { window.removeEventListener('resize', this.onResize); clearInterval(this.iv); }

  total() { return this.state.scenes.reduce((a,s)=>a+s.dur,0); }
  fmt(t) { t=Math.round(t); return Math.floor(t/60)+':'+String(t%60).padStart(2,'0'); }

  tick = () => {
    const s = this.state, up = {}; this.tk++;
    if (s.screen==='create' && s.step===5 && this.tk%4===0) up.wi = (s.wi+1)%this.WORDS.length;
    if (s.screen==='editor' && s.edPlay) { let t=s.edT+0.1; if (t>=this.total()) { t=0; up.edPlay=false; } up.edT=t; }
    if (s.rendering) { const p=Math.min(100,s.renderP+0.7); up.renderP=p; if (p>=100) up.rendering=false; }
    if (s.scenes.some(x=>x.v==='loading')) up.scenes = s.scenes.map(x=>x.v!=='loading'?x:(x.vp>=100?{...x,v:'done'}:{...x,vp:Math.min(100,x.vp+3)}));
    if (Object.keys(up).length) this.setState(up);
  };

  go = (k) => { this.setState({screen:k, drawer:false}); try{localStorage.setItem('rs_screen',k)}catch(e){} if (typeof window!=='undefined') window.scrollTo(0,0); };
  setIdea = (k,v) => this.setState(s=>({idea:{...s.idea,[k]:v}}));
  setCap = (k,v) => this.setState(s=>({cap:{...s.cap,[k]:v}}));
  setBrand = (k,v) => this.setState(s=>({brand:{...s.brand,[k]:v}}));
  updScene = (id,patch) => this.setState(s=>({scenes:s.scenes.map(x=>x.id===id?{...x,...patch}:x)}));

  chips(list,cur,fn) { return list.map(l=>{const a=l===cur; return {label:l,on:()=>fn(l),bg:a?'#17181a':'#fff',fg:a?'#fff':'#3a3c40',bd:a?'#17181a':'#e1e0dc',segBg:a?'#fff':'transparent',segSh:a?'0 1px 2px rgba(0,0,0,.08)':'none',tileBg:a?'#f6f5f2':'#fff'};}); }

  renderVals() {
    const s = this.state, P = this.props;
    const isMobile = s.w < 860, isNarrow = s.w < 1180;
    const dark = (P.sidebarTheme ?? 'dark') === 'dark';
    const sb = dark ? {bg:'#0f1012',fg:'#b7b8bd',strong:'#fff',line:'#24262b',badge:'#24262b',act:'#1f2126',actFg:'#fff'} : {bg:'#fff',fg:'#55575c',strong:'#17181a',line:'#e8e7e3',badge:'#f1f0ed',act:'#f1f0ed',actFg:'#17181a'};
    sb.pos = isMobile ? 'fixed' : 'sticky';
    sb.tf = isMobile && !s.drawer ? 'translateX(-100%)' : 'none';
    const scr = s.screen==='projects' ? 'library' : s.screen;
    const nav = (k,label,icon,badge) => ({label,icon:'icon-'+icon,go:()=>this.go(k),bg:s.screen===k?sb.act:'transparent',fg:s.screen===k?sb.actFg:sb.fg,hasBadge:!!badge,badge});
    const navGroups = [
      {title:'Create',items:[nav('dashboard','Dashboard','layout-dashboard'),nav('create','Create Video','circle-plus'),nav('projects','My Projects','folder'),nav('library','My Videos','film','3'),nav('templates','Templates','layout-template')]},
      {title:'Assets',items:[nav('media','Media Library','images'),nav('brand','Brand Kit','palette')]},
      {title:'Configure',items:[nav('providers','AI Providers','plug'),nav('credits','Credits','coins'),nav('usage','Usage','chart-column'),nav('api','API','code'),nav('settings','Settings','settings'),nav('support','Support','life-buoy')]}
    ];
    const titles = {media:'Media Library',credits:'Credits',usage:'Usage',api:'API',settings:'Settings',support:'Support',dashboard:'Dashboard',create:'Create Video',editor:'Video Editor',render:'Render',library:'My Videos',projects:'My Projects',templates:'Templates',brand:'Brand Kit',providers:'AI Providers'};
    const g = {}; ['dashboard','create','library','templates','providers','editor','brand','media','credits','usage','api','settings','support'].forEach(k=>g[k]=()=>this.go(k));
    g.create = () => { this.setState({step:0}); this.go('create'); };
    const scrKnown = ['dashboard','create','render','editor','library','templates','brand','providers','media','credits','usage','api','settings','support'];
    const cur = scrKnown.includes(scr) ? scr : 'dashboard';
    const sFlags = {}; scrKnown.forEach(k=>sFlags[k]=cur===k);

    const vid = v => ({...v, sfg:this.ST[v.status][0], sbg:this.ST[v.status][1], processing:v.status==='Processing', failed:v.status==='Failed', completed:v.status==='Completed', open:()=>this.go('editor')});
    const total = this.total();

    // wizard
    const stepNames = ['Idea','Script','Scenes','Visuals','Voice','Captions','Render'];
    const steps = stepNames.map((l,i)=>{const a=i===s.step, d=i<s.step; return {label:l,n:i+1,done:d,notDone:!d,go:()=>i===6?this.startRender():this.setState({step:i}),bg:a?'#f3f2ef':'transparent',fg:a||d?'#17181a':'#8a8c91',fw:a?600:500,dotBg:a?'oklch(0.58 0.19 35)':d?'#17181a':'#efeeea',dotFg:a||d?'#fff':'#8a8c91'};});
    const stFlags = {idea:s.step===0,script:s.step===1,scenes:s.step===2,visuals:s.step===3,voice:s.step===4,captions:s.step===5};
    const ratioDims = {'9:16':['16px','28px'],'1:1':['24px','24px'],'4:5':['22px','27px'],'16:9':['32px','18px']};
    const ratioChips = this.chips(Object.keys(ratioDims),s.idea.ratio,v=>this.setIdea('ratio',v)).map(c=>({...c,w:ratioDims[c.label][0],h:ratioDims[c.label][1]}));
    const words = (s.script.hook+' '+s.script.body+' '+s.script.cta).trim().split(/\s+/).length;
    const scriptParts = [['Hook','hook','0:00 – 0:04','oklch(0.58 0.19 35)'],['Body','body','0:04 – 0:26','#17181a'],['Call to action','cta','0:26 – 0:30','oklch(0.6 0.12 250)']].map(p=>({label:p[0],text:s.script[p[1]],time:p[2],color:p[3],set:e=>{const v=e.target.value; this.setState(st=>({script:{...st.script,[p[1]]:v}}));}}));
    const scriptSet = {title:e=>{const v=e.target.value; this.setState(st=>({script:{...st.script,title:v}}));}};

    const sceneRows = s.scenes.map((x,i)=>({...x, num:String(i+1).padStart(2,'0'), durLabel:x.dur+'s', editing:s.editSceneId===x.id, view:s.editSceneId!==x.id, editBg:s.editSceneId===x.id?'#f1f0ed':'#fff',
      op:s.dragId===x.id?0.45:1, bd:s.overId===x.id&&s.dragId!==x.id?'#17181a':'#e8e7e3',
      onDragStart:e=>{e.dataTransfer.effectAllowed='move'; this.setState({dragId:x.id});},
      onDragOver:e=>{e.preventDefault(); if (s.overId!==x.id) this.setState({overId:x.id});},
      onDrop:e=>{e.preventDefault(); this.setState(st=>{const arr=[...st.scenes]; const from=arr.findIndex(a=>a.id===st.dragId); const to=arr.findIndex(a=>a.id===x.id); if(from<0||to<0) return {dragId:null,overId:null}; const [m]=arr.splice(from,1); arr.splice(to,0,m); return {scenes:arr,dragId:null,overId:null};});},
      onDragEnd:()=>this.setState({dragId:null,overId:null}),
      onRegen:()=>{this.updScene(x.id,{rg:true}); setTimeout(()=>this.updScene(x.id,{rg:false}),1100);},
      onEdit:()=>this.setState({editSceneId:s.editSceneId===x.id?null:x.id}),
      onDup:()=>this.setState(st=>{const arr=[...st.scenes]; const i2=arr.findIndex(a=>a.id===x.id); arr.splice(i2+1,0,{...x,id:st.nextId}); return {scenes:arr,nextId:st.nextId+1};}),
      onDel:()=>this.setState(st=>({scenes:st.scenes.length>1?st.scenes.filter(a=>a.id!==x.id):st.scenes})),
      setPrompt:e=>this.updScene(x.id,{prompt:e.target.value}), setNarr:e=>this.updScene(x.id,{narration:e.target.value})
    }));
    const visRows = sceneRows.map(x=>{const isVid=x.src==='AI Video'; return {...x, done:x.v==='done', empty:x.v==='none', loading:x.v==='loading', pct:x.vp+'%', pctLabel:Math.round(x.vp)+'%',
      thumbBg:x.v==='done'?x.c:x.v==='loading'?'#1d1f23':'#f6f5f2', thumbBd:x.v==='none'?'#d6d4ce':'transparent', kind:isVid?'AI video':'AI image',
      tabs:[['AI Image','image'],['AI Video','clapperboard'],['Upload','upload'],['Media Library','images']].map(t=>({label:t[0],icon:'icon-'+t[1],on:()=>this.updScene(x.id,{src:t[0]}),bg:x.src===t[0]?'#fff':'transparent',sh:x.src===t[0]?'0 1px 2px rgba(0,0,0,.08)':'none'})),
      isAI:x.src==='AI Image'||isVid, isUpload:x.src==='Upload', isLib:x.src==='Media Library',
      provs:isVid?['Fal.ai']:['Cloudflare Workers AI','Hugging Face','OpenRouter'], models:isVid?this.MODELS.Video:this.MODELS.Image,
      genLabel:x.v==='done'?'Regenerate':'Generate Visual', cost:isVid?'≈ 8 credits · ~40 s on Fal.ai':'≈ 1 credit · free tier on Cloudflare',
      onGen:()=>this.updScene(x.id,{v:'loading',vp:0})};});

    const cats = ['All','Male','Female','Narrator','Professional','Energetic','Calm','Storytelling'];
    const voiceList = this.VOICES.filter(v=>s.voiceCat==='All'||v[1]===s.voiceCat||v[4].includes(s.voiceCat)).map((v,i)=>{const sel=s.voice===v[0], pl=s.playing===v[0];
      return {name:v[0],lang:v[2],accent:v[3],tags:[v[1],...v[4]],init:v[0][0],c:this.F[(v[0].charCodeAt(0))%10],sel,bd:sel?'#17181a':'#e8e7e3',sh:sel?'0 6px 20px rgba(20,20,25,.08)':'none',
        selBg:sel?'#17181a':'#f1f0ed',selFg:sel?'#fff':'#17181a',selLabel:sel?'Selected':'Select',playIcon:pl?'icon-pause':'icon-play',playLabel:pl?'Playing':'Preview',
        bars:Array.from({length:28},(_,j)=>({h:(30+Math.abs(Math.sin((j+1)*(i+2)*0.7))*70)+'%',c:pl?'oklch(0.58 0.19 35)':'#dcdad4',anim:pl?`rs-wave ${0.6+(j%5)*0.12}s ease-in-out ${j*0.03}s infinite`:'none'})),
        onPlay:()=>{clearTimeout(this.pt); if(pl){this.setState({playing:null});return;} this.setState({playing:v[0]}); this.pt=setTimeout(()=>this.setState({playing:null}),3500);},
        onSel:()=>this.setState({voice:v[0]})};});

    // captions
    const cap = s.cap;
    const chunk = Math.floor(s.wi/3)*3;
    const capWords = this.WORDS.slice(chunk,chunk+3).map((w,k)=>{const idx=chunk+k, act=idx===s.wi, past=idx<s.wi;
      let hl = cap.anim==='Karaoke' ? (act||past) : cap.anim==='None' ? false : act;
      const st = cap.hlStyle; let color=cap.color, bg='transparent', pad='0 2px', ul='0';
      if (hl && st==='Color') color=cap.hl; if (hl && st==='Box') {bg=cap.hl; color='#111'; pad='0 6px';} if (hl && st==='Underline') ul='4px solid '+cap.hl;
      if (cap.bg==='Box' && !(hl&&st==='Box')) {bg='rgba(0,0,0,.6)'; pad='0 6px';}
      return {w,color,bg,pad,ul,tf:act&&cap.anim==='Pop'?'scale(1.14)':act&&cap.anim==='Bounce'?'translateY(-6px)':'none',op:cap.anim==='Typewriter'&&idx>s.wi?0:1};});
    const fontsF = {'Archivo Black':"'Archivo Black',sans-serif",'Bebas Neue':"'Bebas Neue',sans-serif",'DM Sans':"'DM Sans',sans-serif",'Space Grotesk':"'Space Grotesk',sans-serif",'Geist':"'Geist',sans-serif"};
    const swatch = (list,cur,k) => list.map(v=>({v,on:()=>this.setCap(k,v),ring:v===cur?'0 0 0 2px #fff,0 0 0 4px #17181a':'none'}));

    // render
    const p = s.renderP, th=[6,13,22,30,38];
    const rp = Math.max(0,Math.min(100,(p-38)/62*100));
    const stages = ['Script','Scenes','Visuals','Voice','Captions','Rendering'].map((l,i)=>{
      const done = i<5 ? p>=th[i] : p>=100; const active = !done && (i===0 || (i<5 ? p>=th[i-1] : p>=38));
      return {label:i===5?`Rendering ${Math.round(rp)}%`:l, icon:done?'icon-check':active?'icon-loader-circle':'icon-circle', anim:active?'rs-spin 1s linear infinite':'none',
        bg:done?'oklch(0.95 0.04 150)':active?'oklch(0.95 0.03 45)':'#f3f2ef', fg:done?'oklch(0.45 0.12 150)':active?'oklch(0.58 0.19 35)':'#b3b4b8',
        fw:active?600:500, tc:done||active?'#17181a':'#8a8c91', meta:['OpenRouter','5 scenes','Cloudflare · Fal.ai','Edge TTS','Karaoke · Archivo','FFmpeg · H.264'][i]};});
    const remain = Math.max(0,Math.round((100-p)/0.7/10));

    // editor
    const edSel = Math.min(s.edSel, s.scenes.length-1);
    let acc=0, playIdx=0; s.scenes.forEach((x,i)=>{ if (s.edT>=acc) playIdx=i; acc+=x.dur; });
    const shownIdx = s.edPlay ? playIdx : edSel;
    const startOf = i => s.scenes.slice(0,i).reduce((a,x)=>a+x.dur,0);
    const cs = s.scenes[shownIdx], es = s.scenes[edSel];
    const edScenes = s.scenes.map((x,i)=>({...x,num:String(i+1).padStart(2,'0'),durLabel:x.dur+'s',bd:i===edSel?'#17181a':'transparent',bg:i===edSel?'#f6f5f2':'transparent',tlBd:i===shownIdx?'#17181a':'transparent',on:()=>this.setState({edSel:i,edT:startOf(i),edPlay:false})}));
    const ruler = []; for (let t=0;t<=total;t+=5) ruler.push({left:(t/total*100)+'%',label:this.fmt(t)});
    const tracks = [['Uplifting Corporate','Bright','0:45'],['Soft Focus','Calm','1:10'],['Night Drive','Moody','2:05'],['Morning Run','Energetic','1:30']].map((t,i)=>({name:t[0],mood:t[1],len:t[2],bd:i===s.track?'#17181a':'#e8e7e3',bg:i===s.track?'#f6f5f2':'#fff'}));

    // library
    const filt = s.screen==='projects'?'Drafts':s.libFilter;
    const statusOf = {All:null,Completed:'Completed',Processing:'Processing',Failed:'Failed',Drafts:'Draft'};
    let lib = this.VIDEOS.filter(v=>(!statusOf[filt]||v.status===statusOf[filt]) && v.name.toLowerCase().includes(s.q.toLowerCase()));
    const secs = d => {const [m,x]=d.split(':'); return +m*60+ +x;};
    lib = [...lib].sort((a,b)=>s.sort==='Oldest'?a.ts-b.ts:s.sort==='Name'?a.name.localeCompare(b.name):s.sort==='Duration'?secs(b.dur)-secs(a.dur):b.ts-a.ts);
    const libTabs = this.chips(['All','Completed','Processing','Failed','Drafts'],filt,v=>this.setState({libFilter:v,screen:'library'})).map(c=>({...c,count:this.VIDEOS.filter(v=>!statusOf[c.label]||v.status===statusOf[c.label]).length}));

    // templates
    const tcats = ['All','TikTok','Instagram Reels','YouTube Shorts','YouTube','Product Ads','SaaS Ads','Real Estate','Ecommerce','Explainer','Education','Faceless Content'];
    const tdim = {'9:16':[96,170],'16:9':[200,113],'1:1':[150,150],'4:5':[128,160]};
    const tplItems = this.TPL.filter(t=>s.tplCat==='All'||t[1]===s.tplCat).map((t,i)=>({name:t[0],cat:t[1],dur:t[2],ratio:t[3],c:this.F[(i*3)%10],fw:tdim[t[3]][0]+'px',fh:tdim[t[3]][1]+'px'}));

    // brand
    const b = s.brand;
    const wmMap = {'Top right':['top','right'],'Top left':['top','left'],'Bottom right':['bottom','right'],'Bottom left':['bottom','left']};
    const bset = {}; ['name','web','cta','heading','body','wmPos'].forEach(k=>bset[k]=e=>this.setBrand(k,e.target.value));

    // providers
    const catMeta = [['Text','Text AI','Scripts, hooks and scene prompts','type'],['Image','Image AI','Scene stills and thumbnails','image'],['Video','Video AI','Motion clips from prompts or stills','clapperboard'],['Voice','Voice AI','Narration and voiceover','mic']];
    const stMap = {connected:['Connected','oklch(0.42 0.12 150)','oklch(0.95 0.04 150)'],off:['Not configured','#6b6d72','#f1f0ed'],disabled:['Disabled','oklch(0.5 0.13 70)','oklch(0.95 0.05 85)']};
    const provItem = pv => {const m=stMap[pv.status], conf=pv.status!=='off', testing=s.testing===pv.id;
      return {...pv, statusLabel:m[0], sfg:m[1], sbg:m[2], configured:conf, unconfigured:!conf, bd:pv.status==='connected'?'#e1e0dc':'#e8e7e3', op:pv.status==='disabled'?0.72:1,
        monoBg:pv.status==='connected'?'#17181a':'#8a8c91', cfgLabel:conf?'Configure':'Connect', testLabel:testing?'Testing…':'Test connection', testIcon:testing?'icon-loader-circle':'icon-activity', testAnim:testing?'rs-spin 1s linear infinite':'none',
        testColor:pv.test.startsWith('OK')?'oklch(0.45 0.12 150)':'#6b6d72', toggleLabel:pv.status==='disabled'?'Enable':'Disable', toggleColor:pv.status==='disabled'?'oklch(0.45 0.12 150)':'oklch(0.5 0.18 25)',
        onCfg:()=>this.setState({cfg:pv.id}),
        onTest:()=>{this.setState({testing:pv.id}); setTimeout(()=>this.setState(st=>({testing:null,prov:st.prov.map(q=>q.id===pv.id?{...q,test:'OK · '+(280+Math.floor(Math.random()*300))+' ms · just now'}:q)})),1300);},
        onToggle:()=>this.setState(st=>({prov:st.prov.map(q=>q.id===pv.id?{...q,status:q.status==='disabled'?'connected':'disabled'}:q)}))};};
    const provSections = catMeta.map(c=>({title:c[1],desc:c[2],items:s.prov.filter(p2=>p2.cat===c[0]).map(provItem)}));
    const provSummary = catMeta.map(c=>{const n=s.prov.filter(p2=>p2.cat===c[0]&&p2.status==='connected').length; return {title:c[1],icon:'icon-'+c[3],line:n+' connected'};});
    const cp = s.prov.find(p2=>p2.id===s.cfg);

    return {
      isMobile, notMobile:!isMobile, sb, navGroups, drawerOverlay:isMobile&&s.drawer,
      openDrawer:()=>this.setState({drawer:true}), closeDrawer:()=>this.setState({drawer:false}),
      pageTitle:titles[s.screen]||'Dashboard', hdrPad:isMobile?'16px':'32px', pad:isMobile?'20px 16px 48px':'28px 32px 56px', maxW:cur==='editor'?'1600px':'1320px',
      s:sFlags, go:g,
      dashCols:isNarrow?'minmax(0,1fr)':'minmax(0,1fr) 320px', sideCols:isNarrow?'minmax(0,1fr)':'minmax(0,1fr) 340px', capCols:s.w<1000?'minmax(0,1fr)':'minmax(0,1fr) 340px',
      stats:[
        {label:'Videos Created',value:'248',icon:'icon-film',sub:'Since Jan 2026',subColor:'#8a8c91'},
        {label:'Videos This Month',value:'36',icon:'icon-calendar',sub:'↑ 12% vs August',subColor:'oklch(0.45 0.12 150)'},
        {label:'Credits Remaining',value:'2,480',icon:'icon-coins',bar:true,pct:'49.6%',barColor:'oklch(0.58 0.19 35)',sub:'of 5,000 · renews Oct 1',subColor:'#8a8c91'},
        {label:'Processing',value:'3',icon:'icon-loader',live:true,sub:'Next ready in ~2 min',subColor:'#8a8c91'},
        {label:'Storage Used',value:'18.4',unit:'GB',icon:'icon-hard-drive',bar:true,pct:'36.8%',barColor:'#17181a',sub:'of 50 GB',subColor:'#8a8c91'}
      ],
      quick:[
        {title:'Create from Idea',desc:'Type a topic. AI writes the script and plans the scenes.',icon:'icon-lightbulb',go:g.create},
        {title:'Create from Product',desc:'Turn product details and photos into an ad.',icon:'icon-shopping-bag',go:g.create},
        {title:'Create from URL',desc:'Paste a blog post or landing page to repurpose.',icon:'icon-link',go:g.create},
        {title:'Create from Template',desc:'Start from a proven structure for any platform.',icon:'icon-layout-template',go:g.templates}
      ],
      recent:this.VIDEOS.slice(0,6).map(vid),
      stack:[{cat:'Text',name:'OpenRouter · free model',icon:'icon-type'},{cat:'Image',name:'Cloudflare Workers AI',icon:'icon-image'},{cat:'Video',name:'Fal.ai',icon:'icon-clapperboard'},{cat:'Voice',name:'Edge TTS',icon:'icon-mic'}],
      idea:s.idea, setTopic:e=>this.setIdea('topic',e.target.value),
      toneChips:this.chips(['Professional','Friendly','Bold','Playful','Calm','Inspirational'],s.idea.tone,v=>this.setIdea('tone',v)),
      platChips:this.chips(['TikTok','Instagram Reels','YouTube Shorts','YouTube','LinkedIn'],s.idea.platform,v=>this.setIdea('platform',v)),
      durChips:this.chips(['15s','30s','60s','90s'],s.idea.dur,v=>this.setIdea('dur',v)), ratioChips,
      plan:[{step:'Script',via:'OpenRouter · llama-3.3-70b:free',icon:'icon-type'},{step:'Visuals',via:'Cloudflare · flux-1-schnell',icon:'icon-image'},{step:'Voice',via:'Edge TTS · en-US-Neural',icon:'icon-mic'},{step:'Render',via:'FFmpeg on your server',icon:'icon-server'}],
      outputLine:`${s.idea.platform} · ${s.idea.ratio} · ${s.idea.dur} · ${s.idea.tone}`,
      steps, st:stFlags, script:s.script, scriptParts, scriptSet, editScript:s.editScript, viewScript:!s.editScript, regen:s.regen, notRegen:!s.regen,
      editBtn:s.editScript?{label:'Done editing',bg:'#17181a',fg:'#fff',bd:'#17181a'}:{label:'Edit Script',bg:'#fff',fg:'#17181a',bd:'#e1e0dc'},
      toggleEditScript:()=>this.setState({editScript:!s.editScript}),
      regenScript:()=>{this.setState({regen:true,editScript:false}); setTimeout(()=>this.setState({regen:false}),1400);},
      wordCount:words, scriptDur:this.fmt(words/150*60),
      sceneRows, sceneCount:s.scenes.length, totalLabel:this.fmt(total),
      addScene:()=>this.setState(st=>({scenes:[...st.scenes,{id:st.nextId,prompt:'Describe what this scene should show',narration:'New narration line.',caption:'New caption',dur:5,v:'none',vp:0,src:'AI Image',c:this.F[st.nextId%10],tr:'Cut',rg:false}],nextId:st.nextId+1,editSceneId:st.nextId})),
      visRows, visDone:s.scenes.filter(x=>x.v==='done').length, genAll:()=>this.setState(st=>({scenes:st.scenes.map(x=>x.v==='none'?{...x,v:'loading',vp:0}:x)})),
      libPick:this.F.slice(0,5),
      voiceCats:this.chips(cats,s.voiceCat,v=>this.setState({voiceCat:v})), voiceList,
      cap, capWords, capFonts:this.chips(['Archivo Black','Bebas Neue','Space Grotesk','DM Sans'],cap.font,v=>this.setCap('font',v)).map(c=>({...c,ff:fontsF[c.label],bd:c.label===cap.font?'#17181a':'#e8e7e3'})),
      setCapSize:e=>this.setCap('size',+e.target.value),
      capPos:this.chips(['Top','Middle','Bottom'],cap.pos,v=>this.setCap('pos',v)), capBg:this.chips(['None','Box','Shadow'],cap.bg,v=>this.setCap('bg',v)),
      capAnim:this.chips(['Karaoke','Pop','Bounce','Typewriter','None'],cap.anim,v=>this.setCap('anim',v)),
      capHlStyle:this.chips(['Color','Box','Underline'],cap.hlStyle,v=>this.setCap('hlStyle',v)),
      capColors:swatch(['#ffffff','#f4efe6','#ffd23f','#111111'],cap.color,'color'), capHls:swatch(['#ffd23f','#3ee08f','#ff6b4a','#5ab0ff'],cap.hl,'hl'),
      capJustify:{Top:'flex-start',Middle:'center',Bottom:'flex-end'}[cap.pos], capFF:fontsF[cap.font], capPx:Math.round(cap.size*0.62)+'px',
      capTT:cap.font==='Archivo Black'||cap.font==='Bebas Neue'?'uppercase':'none', capShadow:cap.bg==='Shadow'||cap.bg==='None'?'0 2px 10px rgba(0,0,0,.55)':'none',
      capProg:((s.wi+1)/this.WORDS.length*100)+'%',
      backOp:s.step===0?0.4:1, nextLabel:s.step===5?'Render video':'Continue',
      prevStep:()=>s.step>0&&this.setState({step:s.step-1}), nextStep:()=>s.step===5?this.startRender():this.setState({step:s.step+1}),
      renderActive:p<100, renderDone:p>=100, renderPct:Math.round(p)+'%', renderW:p+'%', stages, eta:remain>0?`About ${remain}s remaining`:'Finishing up…', goRender:()=>this.startRender(),
      edCols:isNarrow?'minmax(0,1fr)':'240px minmax(0,1fr) 300px', edOrd:isNarrow?{left:3,center:1,right:2}:{left:1,center:2,right:3},
      edTabs:['Scenes','Media','Music'].map(l=>({label:l,on:()=>this.setState({edTab:l}),bg:s.edTab===l?'#f1f0ed':'transparent'})),
      edTab:{scenes:s.edTab==='Scenes',media:s.edTab==='Media',music:s.edTab==='Music'},
      edScenes, tracks, trackName:tracks[s.track].name+' · '+tracks[s.track].mood,
      edCur:{...cs, num:String(shownIdx+1).padStart(2,'0'), caption:(s.edPlay?cs:es).caption, narration:es.narration, durLabel:es.dur+'s', kind:cs.src==='AI Video'?'AI video':'AI image'},
      edPrevH:isMobile?'380px':'440px', edHead:(s.edT/total*100)+'%', edTime:this.fmt(s.edT), edPlayIcon:s.edPlay?'icon-pause':'icon-play',
      edTogglePlay:()=>this.setState({edPlay:!s.edPlay}),
      edPrev:()=>{const i=Math.max(0,edSel-1); this.setState({edSel:i,edT:startOf(i),edPlay:false});},
      edNext:()=>{const i=Math.min(s.scenes.length-1,edSel+1); this.setState({edSel:i,edT:startOf(i),edPlay:false});},
      edSetCaption:e=>this.updScene(es.id,{caption:e.target.value}), edSetNarr:e=>this.updScene(es.id,{narration:e.target.value}),
      edDurDown:()=>this.updScene(es.id,{dur:Math.max(2,es.dur-1)}), edDurUp:()=>this.updScene(es.id,{dur:Math.min(20,es.dur+1)}),
      edTrans:this.chips(['Cut','Fade','Slide','Zoom'],es.tr,v=>this.updScene(es.id,{tr:v})),
      edRegen:()=>{this.updScene(es.id,{c:this.F[Math.floor(Math.random()*10)]});},
      libTitle:s.screen==='projects'?'My Projects':'My Videos', libTabs, libItems:lib.map(vid), libEmpty:lib.length===0, q:s.q, sort:s.sort,
      setQ:e=>this.setState({q:e.target.value}), setSort:e=>this.setState({sort:e.target.value}), clearLib:()=>this.setState({q:'',libFilter:'All',screen:'library'}),
      tplCats:this.chips(tcats,s.tplCat,v=>this.setState({tplCat:v})), tplItems,
      brand:b, bset, brandInit:(b.name||'?')[0].toUpperCase(), brandHeadFF:fontsF[b.heading], brandBodyFF:fontsF[b.body],
      brandColors:[['Primary','c0'],['Accent','c1'],['Text','c2']].map(c=>({label:c[0],v:b[c[1]],set:e=>this.setBrand(c[1],e.target.value)})),
      wmCss:(()=>{const m=wmMap[b.wmPos],o={t:'auto',b:'auto',l:'auto',r:'auto'}; o[m[0][0]]='16px'; o[m[1][0]]='16px'; return o;})(), toggleWm:()=>this.setBrand('wm',!b.wm), wmTrack:b.wm?'#17181a':'#d6d4ce', wmKnob:b.wm?'21px':'3px',
      provSections, provSummary, openCustom:()=>this.setState({cfg:'oai'}),
      cfgOpen:!!cp, cfg:cp?{...cp, keyRaw:cp.status==='off'?'':'••••••••••••••••', models:this.MODELS[cp.cat]}:{models:[]},
      closeCfg:()=>this.setState({cfg:null}),
      saveCfg:()=>this.setState(st=>({cfg:null,prov:st.prov.map(q=>q.id===st.cfg?{...q,status:'connected',model:q.model||this.MODELS[q.cat][0],key:q.key||'••••••••'+Math.random().toString(36).slice(2,6),test:'OK · 342 ms · just now'}:q)}))
    };
  }

  startRender() { this.setState({renderP:0, rendering:true}); this.go('render'); }
}
