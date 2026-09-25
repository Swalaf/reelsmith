class DesignComponent extends DCLogic {
  STEPS = ['Welcome','Requirements','Database','Application','Admin','Storage','AI','FFmpeg','Cron/Queue','Installation','Complete'];
  KEYS = ['welcome','requirements','database','app','admin','storage','ai','ffmpeg','cron','install','complete'];
  state = { w: typeof window!=='undefined'?window.innerWidth:1200, step:this.props.startStep??0, reqFixed:false, reqChecking:false, db:'idle', mode:'Sell subscriptions', pw:'Rs!2026-secure', pw2:'Rs!2026-secure', driver:'S3-compatible', ai:{or:'done',cf:'idle',edge:'done',hf:'idle'}, cron:'pending', inst:0 };
  componentDidMount(){ this.onR=()=>this.setState({w:window.innerWidth}); window.addEventListener('resize',this.onR); this.iv=setInterval(()=>{ if(this.state.step===9){ const p=Math.min(100,this.state.inst+1.2); this.setState({inst:p}); if(p>=100) setTimeout(()=>this.setState({step:10}),600);} },100); }
  componentWillUnmount(){ window.removeEventListener('resize',this.onR); clearInterval(this.iv); }
  ok(){return {bg:'oklch(0.95 0.04 150)',fg:'oklch(0.45 0.12 150)',icon:'icon-check',anim:'none',vc:'#6b6d72'};}
  bad(){return {bg:'oklch(0.95 0.035 25)',fg:'oklch(0.52 0.18 25)',icon:'icon-x',anim:'none',vc:'oklch(0.5 0.18 25)'};}
  spin(){return {bg:'oklch(0.95 0.03 250)',fg:'oklch(0.45 0.13 250)',icon:'icon-loader-circle',anim:'rs-spin 1s linear infinite',vc:'#6b6d72'};}
  renderVals(){
    const s=this.state, narrow=s.w<820, k=this.KEYS[s.step];
    const st={}; this.KEYS.forEach((x,i)=>st[x]=i===s.step);
    const rail=this.STEPS.map((l,i)=>{const a=i===s.step,d=i<s.step; return {label:l,n:i+1,done:d,notDone:!d,bg:a?'#1f2126':'transparent',fg:a?'#fff':d?'#d8d9dc':'#6b6d72',fw:a?600:500,dotBg:a?'oklch(0.58 0.19 35)':d?'#fff':'transparent',dotFg:a?'#fff':d?'#111214':'#6b6d72',dotBd:a?'oklch(0.58 0.19 35)':d?'#fff':'#3a3c42'};});
    const reqFail=!s.reqFixed&&!s.reqChecking;
    const reqs=[['PHP ≥ 8.2','8.3.11',1],['MySQL / MariaDB','MariaDB 10.11',1],['PHP extensions','pdo, mbstring, curl, gd, zip, bcmath',1],['OpenSSL','3.0.13',1],['Storage permissions',s.reqFixed?'writable':'storage/ not writable',s.reqFixed],['bootstrap/cache writable','writable',1],['FFmpeg','6.1.1',1],['Cron available','crontab found',1],['Memory limit ≥ 512M','1024M',1],['max_execution_time','300s',1]].map(r=>({label:r[0],val:r[1],...(s.reqChecking?this.spin():r[2]?this.ok():this.bad())}));
    const dbBad=s.db==='err';
    const dbFields=[['Host','127.0.0.1'],['Port','3306'],['Database name','reelsmith'],['Username','reelsmith_user'],['Password','••••••••••','password'],['Table prefix','rs_']].map(f=>({label:f[0],v:f[1],type:f[2]||'text',bd:dbBad&&(f[0]==='Password'||f[0]==='Username')?'oklch(0.7 0.15 25)':'#e1e0dc'}));
    const dbS={idle:{label:'Test connection',icon:'icon-plug',anim:'none',show:false},testing:{label:'Testing…',icon:'icon-loader-circle',anim:'rs-spin 1s linear infinite',show:false},err:{label:'Test again',icon:'icon-plug',anim:'none',show:true,c:'oklch(0.5 0.18 25)',ricon:'icon-circle-x',msg:"Access denied for user 'reelsmith_user'@'localhost'"},ok:{label:'Test connection',icon:'icon-plug',anim:'none',show:true,c:'oklch(0.45 0.12 150)',ricon:'icon-circle-check',msg:'Connected · MariaDB 10.11 · empty database'}}[s.db];
    const pwScore=Math.min(4,(s.pw.length>=8)+(/[A-Z]/.test(s.pw))+(/[0-9]/.test(s.pw))+(/[^A-Za-z0-9]/.test(s.pw)));
    const pwCol=['#e1e0dc','oklch(0.6 0.2 25)','oklch(0.75 0.15 70)','oklch(0.7 0.13 120)','oklch(0.62 0.14 150)'][pwScore];
    const mismatch=s.pw2.length>0&&s.pw!==s.pw2;
    const aiDefs=[['or','OpenRouter','OR','Text','Free models available',true,'sk-or-v1-8a2f…3f9a'],['cf','Cloudflare Workers AI','CF','Image','Free daily quota',true,''],['edge','Edge TTS','ET','Voice','Free',false,''],['hf','Hugging Face','HF','Image & video','Free inference',true,'']];
    const aiRows=aiDefs.map(a=>{const v=s.ai[a[0]]; const done=v==='done',t=v==='testing';
      return {name:a[1],mono:a[2],cat:a[3],tier:a[4],needsKey:a[5],noKey:!a[5],key:a[6],bd:done?'oklch(0.85 0.07 150)':'#e8e7e3',mbg:done?'#17181a':'#8a8c91',label:done?'Connected':t?'Testing…':'Connect',icon:done?'icon-check':t?'icon-loader-circle':'icon-plug',anim:t?'rs-spin 1s linear infinite':'none',btnBg:done?'oklch(0.95 0.04 150)':'#17181a',btnFg:done?'oklch(0.42 0.12 150)':'#fff',btnBd:done?'1px solid oklch(0.85 0.07 150)':'0',
        connect:()=>{ if(done) return; this.setState(x=>({ai:{...x.ai,[a[0]]:'testing'}})); setTimeout(()=>this.setState(x=>({ai:{...x.ai,[a[0]]:'done'}})),1100);} };});
    const cronOk=s.cron==='ok';
    const cronRows=[['Scheduler heartbeat',cronOk?'ran 4s ago':'waiting for first run…',cronOk],['Queue worker',cronOk?'1 worker listening':'no worker detected',cronOk]].map(r=>({label:r[0],val:r[1],...(r[2]?this.ok():s.cron==='checking'?this.spin():{bg:'oklch(0.95 0.05 85)',fg:'oklch(0.5 0.13 70)',icon:'icon-clock',anim:'none',vc:'oklch(0.5 0.13 70)'})}));
    const logDefs=[[0,'Writing .env configuration'],[8,'Running migrations (64 tables)'],[30,'Seeding plans, templates and voices'],[52,'Creating admin account admin@acme.co'],[64,'Registering AI providers: OpenRouter, Cloudflare, Edge TTS, Hugging Face'],[78,'Linking storage and warming cache'],[90,'Scheduling cron and queue'],[99,'Done']];
    const instLog=logDefs.filter(l=>s.inst>=l[0]).map((l,i)=>({t:'00:0'+Math.floor(i*0.8)+'.'+(i*13%100),msg:(s.inst>=(logDefs[i+1]?logDefs[i+1][0]:100)?'✓ ':'→ ')+l[1],c:s.inst>=(logDefs[i+1]?logDefs[i+1][0]:100)?'#d8d9dc':'oklch(0.78 0.13 45)'}));
    let blocked='';
    if(k==='requirements'&&!s.reqFixed) blocked='Fix failed requirements to continue';
    if(k==='database'&&s.db!=='ok') blocked='Test the connection first';
    if(k==='admin'&&(mismatch||pwScore<3)) blocked='Choose a stronger password';
    if(k==='cron'&&!cronOk) blocked='Waiting for cron';
    const go=i=>this.setState({step:i});
    return {
      st, rail, showRail:!narrow, showBar:narrow&&s.step<10, stepN:s.step+1, stepLabel:this.STEPS[s.step], barW:((s.step+1)/11*100)+'%',
      cols:narrow?'minmax(0,1fr)':'240px minmax(0,1fr)', outerPad:narrow?'16px':'40px 24px', bodyPad:narrow?'22px 20px':'36px 40px', footPad:narrow?'20px':'40px',
      welcomeNeeds:[{icon:'icon-database',t:'A MySQL database',d:'An empty database and user with full privileges.'},{icon:'icon-terminal',t:'SSH or cPanel access',d:'To set folder permissions and add a cron job.'},{icon:'icon-key-round',t:'AI provider keys',d:'Optional now. Free providers work to get started.'}],
      reqs, reqFail, reqAnim:s.reqChecking?'rs-spin 1s linear infinite':'none', recheck:()=>{this.setState({reqChecking:true}); setTimeout(()=>this.setState({reqChecking:false,reqFixed:true}),1200);},
      dbFields, db:dbS, testDb:()=>{this.setState({db:'testing'}); setTimeout(()=>this.setState(x=>({db:this.dbTried?'ok':'err'})),1100); this.dbTried=!!this.dbTriedOnce; this.dbTriedOnce=true;},
      modes:[['Sell subscriptions','Run it as your own SaaS with plans and credits.'],['Internal team tool','Private use for your company. Registration off.'],['Agency for clients','Client workspaces and white-label exports.']].map(m=>({t:m[0],d:m[1],bd:s.mode===m[0]?'#17181a':'#e8e7e3',on:()=>this.setState({mode:m[0]})})),
      pw:s.pw, pw2:s.pw2, setPw:e=>this.setState({pw:e.target.value}), setPw2:e=>this.setState({pw2:e.target.value}), pwBars:[1,2,3,4].map(i=>i<=pwScore?pwCol:'#efeeea'), pwC:pwScore<3?'oklch(0.5 0.18 25)':'oklch(0.45 0.12 150)', pwLabel:['Too short','Weak','Fair','Good','Strong'][pwScore], pwMismatch:mismatch, pw2Bd:mismatch?'oklch(0.7 0.15 25)':'#e1e0dc',
      drivers:[['Local disk','hard-drive','Simplest. Uses this server.'],['S3-compatible','cloud','AWS, R2, Wasabi, MinIO'],['Backblaze B2','archive','Low-cost object storage'],['DigitalOcean Spaces','droplet','S3-compatible CDN']].map(d=>({t:d[0],icon:'icon-'+d[1],d:d[2],bd:s.driver===d[0]?'#17181a':'#e8e7e3',on:()=>this.setState({driver:d[0]})})),
      isS3:s.driver!=='Local disk', isLocal:s.driver==='Local disk', s3Fields:[['Endpoint','https://acc.r2.cloudflarestorage.com'],['Bucket','acme-video-prod'],['Access key','AKIA7Q2…R2'],['Secret key','••••••••••••'],['Region','auto'],['Public CDN URL','https://cdn.acme.co']].map(f=>({label:f[0],v:f[1]})),
      aiRows,
      ffRows:[['Version','6.1.1','#17181a'],['Codecs','libx264 · aac · libmp3lame','#17181a'],['Hardware accel','not available (CPU)','oklch(0.5 0.13 70)'],['Test encode','1080×1920 · 3s · 1.9s ✓','oklch(0.45 0.12 150)']].map(f=>({k:f[0],v:f[1],c:f[2]})),
      cronRows, detectCron:()=>{this.setState({cron:'checking'}); setTimeout(()=>this.setState({cron:'ok'}),1200);},
      instW:s.inst+'%', instPct:Math.round(s.inst)+'%', instLog,
      summary:[{k:'Admin',v:'admin@acme.co'},{k:'Storage',v:s.driver},{k:'AI providers',v:Object.values(s.ai).filter(x=>x==='done').length+' connected'},{k:'Queue',v:'Redis · 1 worker'}],
      showFooter:s.step<9, backOp:s.step===0?0.4:1, back:()=>s.step>0&&go(s.step-1),
      blockedMsg:blocked, nextBg:blocked?'#b9b8b3':'oklch(0.58 0.19 35)', nextCursor:blocked?'not-allowed':'pointer',
      nextLabel:s.step===0?'Get started':s.step===8?'Install now':'Continue',
      next:()=>{ if(blocked) return; if(s.step===8) this.setState({step:9,inst:0}); else go(s.step+1); }
    };
  }
}
