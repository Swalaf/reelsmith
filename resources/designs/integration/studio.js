// Laravel integration for the Studio: projects, script generation, rendering, providers and brand kit.
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    const R = window.RS;
    this.VIDEOS = (R.projects || []).map(x => this.toVideo(x));
    if (R.templates && R.templates.length) this.TPL = R.templates.map(t => [t.name, t.cat, t.dur, t.ratio]);
    this.state = { ...this.state,
      screen: R.setup2fa ? 'settings' : (R.startScreen || this.state.screen), acct: R.account || {},
      prov: (R.providers || []).map(x => ({ ...x })),
      brand: { ...this.state.brand, ...(R.brand || {}) },
      projectId: null, busy: false, output: null
    };
    if (R.project) Object.assign(this.state, this.projectState(R.project), { screen: 'editor' });
  }

  toVideo(x) { return { id: x.id, name: x.name, dur: x.dur, status: x.status, date: x.date, platform: x.platform, ratio: x.ratio, c: x.thumb ? this.bgUrl(x.thumb, x.c) : x.c, ts: x.ts, url: x.url, credits: x.credits }; }

  bgUrl(url, fallback) { return "url('" + url + "') center/cover no-repeat, " + (fallback || '#23343f'); }
  sceneBg(sc) { return sc && sc.imgUrl ? this.bgUrl(sc.imgUrl, sc.c) : (sc ? sc.c : '#23343f'); }
  TRACK_NAMES = ['Uplifting Corporate', 'Soft Focus', 'Night Drive', 'Morning Run'];

  projectState(x) {
    const st = { projectId: x.id, output: x };
    if (x.idea) st.idea = { ...this.state.idea, ...x.idea };
    if (x.script) st.script = { ...this.state.script, ...x.script };
    if (x.scenes && x.scenes.length) { st.scenes = x.scenes.map((sc, i) => this.fromServer(sc, i)); st.nextId = Math.max(...st.scenes.map(s => s.id)) + 1; st.edSel = 0; st.edT = 0; }
    st.track = x.music === null || x.music === undefined ? 0 : Math.max(0, this.TRACK_NAMES.indexOf(x.music) === -1 ? 4 : this.TRACK_NAMES.indexOf(x.music));
    if (x.captions) st.cap = { ...this.state.cap, ...x.captions };
    if (x.voice) st.voice = x.voice;
    return st;
  }

  fromServer(sc, i) { return { rg: false, vp: 0, src: 'AI Image', tr: 'Fade', ...sc, v: sc.imgUrl || sc.clipUrl ? 'done' : 'none', id: sc.id || i + 1 }; }

  /** Replace one scene with the server's copy, keeping unsaved local edits to the others. */
  applyServerScene(project, id) {
    const fresh = (project.scenes || []).find(x => x.id === id);
    if (!fresh) return;
    this.setState(st => ({ scenes: st.scenes.map((x, i) => x.id === id ? this.fromServer(fresh, i) : x) }));
    this.upsert(project);
  }

  async genVisual(sceneId, src) {
    const s = this.state;
    if (!s.projectId) return;
    await this.save();
    this.updScene(sceneId, { v: 'loading', vp: 0, rg: true });
    try {
      const sc = this.state.scenes.find(x => x.id === sceneId) || {};
      const want = src || (sc.src === 'AI Video' ? 'AI Video' : 'AI Image');
      const pick = this.providerFor(sceneId, want === 'AI Video' ? 'Video' : 'Image');
      const r = await rs.post('/studio/projects/' + s.projectId + '/scenes/' + sceneId + '/visual', { src: want, provider: pick ? pick.id : null, model: rs.val('vis_model_' + sceneId) || null });
      if (r.user) window.RS.user = r.user;
      this.applyServerScene(r.project, sceneId);
      this.loadMedia();
    } catch (e) {
      this.updScene(sceneId, { v: 'none', vp: 0, rg: false });
      alert('Could not generate this visual: ' + e.message);
    }
  }

  pickFile(sceneId) {
    const input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm';
    input.onchange = async () => {
      if (!input.files.length) return;
      await this.save();
      const fd = new FormData(); fd.append('file', input.files[0]);
      this.updScene(sceneId, { v: 'loading', vp: 0 });
      try {
        const res = await fetch('/studio/projects/' + this.state.projectId + '/scenes/' + sceneId + '/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': window.RS.csrf, 'Accept': 'application/json' } });
        const r = await res.json();
        if (!res.ok) throw new Error(r.message || 'Upload failed');
        this.applyServerScene(r.project, sceneId); this.loadMedia();
      } catch (e) { this.updScene(sceneId, { v: 'none' }); alert(e.message); }
    };
    input.click();
  }

  async useMedia(sceneId, path) {
    if (!this.state.projectId) return;
    await this.save();
    try { const r = await rs.post('/studio/projects/' + this.state.projectId + '/scenes/' + sceneId + '/upload', { media: path }); this.applyServerScene(r.project, sceneId); } catch (e) { alert(e.message); }
  }

  /** The provider chosen in a scene's dropdown (defaults to the highest-priority connected one). */
  providerFor(sceneId, cat) {
    const list = this.state.prov.filter(p => p.cat === cat && p.status === 'connected');
    const chosen = (this.state.provChoice || {})[sceneId + ':' + cat];
    return list.find(p => p.name === chosen) || list[0] || null;
  }

  async loadMedia() { try { const r = await rs.get('/studio/media'); this.setState({ media: r.media || [] }); } catch (e) {} }

  async previewVoice(name) {
    if (this.audio) { this.audio.pause(); this.audio = null; }
    if (this.state.playing === name) { this.setState({ playing: null }); return; }
    this.setState({ playing: name });
    try {
      const r = await rs.post('/studio/voices/preview', { voice: name });
      this.audio = new Audio(r.url);
      this.audio.onended = () => this.setState({ playing: null });
      await this.audio.play();
    } catch (e) { this.setState({ playing: null }); alert('Voice preview unavailable: ' + e.message); }
  }

  // Replaces the design's timer: generation and render progress come from the server.
  tick = () => {
    const s = this.state, up = {}; this.tk = (this.tk || 0) + 1;
    if (s.screen === 'create' && s.step === 5 && this.tk % 4 === 0) up.wi = (s.wi + 1) % this.WORDS.length;
    if (s.screen === 'editor' && s.edPlay) { let t = s.edT + 0.1; if (t >= this.total()) { t = 0; up.edPlay = false; } up.edT = t; }
    if (s.scenes.some(x => x.v === 'loading' && x.vp < 92)) up.scenes = s.scenes.map(x => x.v === 'loading' ? { ...x, vp: Math.min(92, x.vp + 1.5) } : x);
    if (Object.keys(up).length) this.setState(up);
  };

  componentDidMount() { super.componentDidMount(); this.loadMedia(); }

  upsert(x) {
    const v = this.toVideo(x), i = this.VIDEOS.findIndex(y => y.id === x.id);
    if (i < 0) this.VIDEOS = [v, ...this.VIDEOS]; else this.VIDEOS = this.VIDEOS.map(y => y.id === x.id ? v : y);
  }

  payload() {
    const s = this.state;
    return { idea: s.idea, script: s.script, scenes: s.scenes.map(({ id, prompt, narration, caption, dur, src, c, tr }) => ({ id, prompt, narration, caption, dur, src, c, tr })), captions: s.cap, voice: s.voice, music: s.track === 4 ? 'None' : this.TRACK_NAMES[s.track || 0] };
  }

  async save() {
    const s = this.state;
    if (!s.projectId) return null;
    try { const r = await rs.put('/studio/projects/' + s.projectId, this.payload()); this.upsert(r.project); return r.project; } catch (e) { console.warn(e); return null; }
  }

  async openProject(id) {
    try {
      const r = await rs.get('/studio/projects/' + id);
      this.setState({ ...this.projectState(r.project), step: 0 });
      this.go('editor');
    } catch (e) { alert(e.message); }
  }

  async createFromIdea() {
    const s = this.state;
    this.setState({ busy: true, regen: true, step: 1 });
    try {
      const r = await rs.post('/studio/projects', { idea: s.idea, description: rs.val('idea_description'), audience: rs.val('idea_audience'), cta: rs.val('idea_cta'), template_id: s.fromTemplate || null });
      this.upsert(r.project);
      this.setState({ ...this.projectState(r.project), busy: false, regen: false });
    } catch (e) { this.setState({ busy: false, regen: false, step: 0 }); alert(e.message); }
  }

  startRender() {
    const s = this.state;
    if (!s.projectId || s.rendering) return;
    const back = { screen: s.screen, step: s.step };
    this.setState({ renderP: 0, rendering: true, output: { ...(s.output || {}), status: 'Processing', stage: 'Starting…', url: null } });
    this.go('render');
    (async () => {
      await this.save();
      try {
        const r = await rs.post('/studio/projects/' + s.projectId + '/render');
        this.upsert(r.project);
        if (r.user) window.RS.user = r.user;
        this.setState({ output: r.project });
        this.poll(s.projectId);
      } catch (e) { this.setState({ rendering: false, step: back.step }); this.go(back.screen); alert(e.message); }
    })();
  }

  poll(id) {
    clearTimeout(this.pt2);
    this.pt2 = setTimeout(async () => {
      try {
        const r = await rs.get('/studio/projects/' + id);
        this.upsert(r.project);
        const done = r.project.status !== 'Processing';
        this.setState({ output: r.project, renderP: r.project.status === 'Completed' ? 100 : (r.project.progress || 0), rendering: !done });
        if (done && r.project.scenes) this.setState({ scenes: r.project.scenes.map((sc, i) => this.fromServer(sc, i)) });
        if (!done) this.poll(id);
        else if (r.project.status === 'Failed') alert('Render failed: ' + (r.project.error || 'unknown error') + '. Credits were refunded.');
      } catch (e) { this.poll(id); }
    }, 2000);
  }

  setBrand = (k, v) => {
    this.setState(s => ({ brand: { ...s.brand, [k]: v } }));
    clearTimeout(this.bt);
    this.bt = setTimeout(() => rs.put('/studio/brand', { brand: this.state.brand }).catch(e => console.warn(e)), 600);
  };

  componentWillUnmount() { super.componentWillUnmount(); clearTimeout(this.pt2); clearTimeout(this.bt); }

  renderVals() {
    const v = super.renderVals(), s = this.state, R = window.RS, me = R.user || {};
    const isAdmin = me.role === 'admin';
    v.appName = R.appName; v.me = me; v.logout = () => rs.logout(); v.greeting = rs.greeting(); v.genVia = (s.output && s.output.provs) || 'Built-in writer';
    const processing = this.VIDEOS.filter(x => x.status === 'Processing').length;
    v.navGroups = v.navGroups.map(g => ({ ...g, items: g.items.map(n => n.label === 'My Videos' ? { ...n, hasBadge: processing > 0, badge: String(processing) } : n) }));
    v.aiCost = '$' + s.prov.reduce((a, p) => a + (parseFloat(String(p.cost || '0').replace(/[$,]/g, '')) || 0), 0).toFixed(2);

    // Real numbers on the dashboard
    const st = R.stats || {};
    v.stats = [
      { label: 'Videos Created', value: String(this.VIDEOS.length), icon: 'icon-film', sub: st.since ? 'Since ' + st.since : 'All time', subColor: '#8a8c91' },
      { label: 'Videos This Month', value: String(this.VIDEOS.filter(x => x.ts * 1000 >= new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime()).length), icon: 'icon-calendar', sub: new Date().toLocaleDateString('en-US', { month: 'long' }), subColor: '#8a8c91' },
      { label: 'Credits Remaining', value: me.credits || '0', icon: 'icon-coins', bar: true, pct: me.creditsPct || '0%', barColor: 'oklch(0.58 0.19 35)', sub: me.planLabel || '', subColor: '#8a8c91' },
      { label: 'Processing', value: String(this.VIDEOS.filter(x => x.status === 'Processing').length), icon: 'icon-loader', live: this.VIDEOS.some(x => x.status === 'Processing'), sub: 'Render queue', subColor: '#8a8c91' },
      { label: 'Storage Used', value: st.storageMb >= 1024 ? (st.storageMb / 1024).toFixed(1) : String(st.storageMb || 0), unit: st.storageMb >= 1024 ? 'GB' : 'MB', icon: 'icon-hard-drive', bar: true, pct: Math.min(100, (st.storageMb || 0) / ((st.storageGb || 1) * 1024) * 100).toFixed(1) + '%', barColor: '#17181a', sub: 'of ' + (st.storageGb || 1) + ' GB', subColor: '#8a8c91' }
    ];

    // Projects: open, download
    const withActions = x => ({ ...x, open: () => this.openProject(x.id), download: () => { const f = this.VIDEOS.find(y => y.id === x.id); if (f && f.url) window.open(f.url, '_blank'); else alert('No video file for this project yet. Make sure FFmpeg is installed on the server.'); } });
    v.recent = v.recent.map(withActions);
    v.libItems = v.libItems.map(withActions);
    v.libEmpty = v.libItems.length === 0;

    // Create wizard: step 0 → server writes the script and scene plan
    const next = v.nextStep;
    v.nextStep = () => {
      if (s.busy) return;
      if (s.step === 0) { this.createFromIdea(); return; }
      this.save();
      next();
    };
    v.steps = v.steps.map((x, i) => ({ ...x, go: () => { if (!s.projectId && i > 0) return; i === 6 ? this.startRender() : this.setState({ step: i }); } }));
    v.regenScript = async () => {
      if (!s.projectId) return;
      this.setState({ regen: true, editScript: false });
      try { const r = await rs.post('/studio/projects/' + s.projectId + '/script', { idea: s.idea }); this.setState({ ...this.projectState(r.project), regen: false }); } catch (e) { this.setState({ regen: false }); alert(e.message); }
    };
    v.go = { ...v.go, create: () => { this.setState({ step: 0, projectId: null, fromTemplate: null }); this.go('create'); } };
    v.quick = v.quick.map(q => ({ ...q, go: q.title === 'Create from Template' ? v.go.templates : v.go.create }));
    v.tplItems = v.tplItems.map(t => ({ ...t, use: () => { const T = (R.templates || []).find(q => q.name === t.name); this.setState({ step: 0, projectId: null, fromTemplate: T ? T.id : null, idea: { ...s.idea, platform: ['TikTok', 'Instagram Reels', 'YouTube Shorts', 'YouTube', 'LinkedIn'].includes(t.cat) ? t.cat : s.idea.platform, ratio: t.ratio } }); this.go('create'); } }));

    // Render result
    const o = s.output || {};
    const ratio = o.ratio || s.idea.ratio;
    v.out = { res: { '16:9': '1280×720', '1:1': '1080×1080', '4:5': '1080×1350' }[ratio] || '720×1280', size: o.status === 'Completed' ? (o.url ? 'MP4' : 'no file') : '—', credits: String(o.credits || 0) };
    v.download = () => { if (o.url) window.open(o.url, '_blank'); else alert(o.status === 'Completed' ? 'This render produced no file — FFmpeg is not installed on the server.' : 'The video is still rendering.'); };
    v.eta = o.status === 'Processing' ? (o.stage || 'Queued…') : v.eta;
    const conn = cat => { const p = s.prov.find(q => q.cat === cat && q.status === 'connected'); return p ? p.name : null; };
    const metas = [conn('Text') || 'Built-in writer', s.scenes.length + ' scenes', conn('Image') || 'Uploads / colour cards', conn('Voice') || 'No voice provider',
      (s.cap.anim || 'Karaoke') + ' · ' + (s.cap.font || '').split(' ')[0], 'FFmpeg · H.264'];
    v.stages = v.stages.map((st, i) => ({ ...st, meta: metas[i] }));
    const dims = /(\d+)×(\d+)/.exec(o.stage || '');
    if (dims) v.out.res = dims[1] + '×' + dims[2];
    const ar = (o.ratio || s.idea.ratio || '9:16').replace(':', '/');
    v.out = { ...v.out, url: o.status === 'Completed' ? o.url : null, ar, w: ar === '16/9' ? '360px' : ar === '1/1' ? '280px' : '220px', bg: o.thumb ? this.bgUrl(o.thumb) : '#2f4b4b' };
    v.out.size = o.status === 'Completed' && o.url ? 'MP4 · ' + (o.dur || '') : v.out.size;

    // Scene visuals: real generation, uploads, media library, thumbnails
    const media = s.media || [];
    v.libMore = media.length > 5 ? '+' + (media.length - 5) : String(media.length || 0);
    v.sceneRows = v.sceneRows.map(x => ({ ...x, c: this.sceneBg(x), onRegen: () => this.genVisual(x.id) }));
    v.visRows = v.visRows.map(x => ({ ...x,
      thumbBg: x.v === 'done' ? this.sceneBg(x) : x.thumbBg,
      onGen: () => this.genVisual(x.id, x.src === 'AI Video' ? 'AI Video' : 'AI Image'),
      onUpload: () => this.pickFile(x.id),
      lib: media.slice(0, 5).map(m => ({ bg: this.bgUrl(m.url), on: () => this.useMedia(x.id, m.path) })),
      cost: x.src === 'AI Video' ? '≈ 8 credits · AI video clip' : '≈ 1 credit · AI image',
      ...(() => {
        const cat = x.src === 'AI Video' ? 'Video' : 'Image';
        const list = s.prov.filter(p => p.cat === cat && p.status === 'connected');
        const cur = this.providerFor(x.id, cat);
        return { provs: list.length ? list.map(p => p.name) : ['No ' + cat.toLowerCase() + ' provider connected'], models: cur && cur.models && cur.models.length ? cur.models : [cur ? cur.model : '—'],
          provKey: cat + ':' + (cur ? cur.id : 'none'), onProv: e => { const val = e.target.value; this.setState(st => ({ provChoice: { ...(st.provChoice || {}), [x.id + ':' + cat]: val } })); } };
      })() }));
    v.genAll = async () => { for (const x of this.state.scenes.filter(y => y.v === 'none' && ['AI Image', 'AI Video'].includes(y.src))) await this.genVisual(x.id); };
    v.libPick = media.slice(0, 5).map(m => this.bgUrl(m.url));
    const es = s.scenes[Math.min(s.edSel, s.scenes.length - 1)] || {};
    v.edScenes = v.edScenes.map((e, i) => ({ ...e, c: this.sceneBg(s.scenes[i]) }));
    v.edCur = { ...v.edCur, c: this.sceneBg(s.scenes.find(x => x.id === v.edCur.id) || es) };
    v.edRegen = () => this.genVisual(es.id);
    v.edLib = media.slice(0, 8).map(m => ({ bg: this.bgUrl(m.url), on: () => this.useMedia(es.id, m.path) }));
    v.edUpload = () => this.pickFile(es.id);

    // Voice previews + music
    v.voiceList = v.voiceList.map(vc => ({ ...vc, onPlay: () => this.previewVoice(vc.name) }));
    const trackList = [...v.tracks, { name: 'No music', mood: 'Voice only', len: '—', bd: s.track === 4 ? '#17181a' : '#e8e7e3', bg: s.track === 4 ? '#f6f5f2' : '#fff' }];
    v.tracks = trackList.map((t, i) => ({ ...t, on: () => { this.setState({ track: i }); setTimeout(() => this.save(), 0); } }));
    v.trackName = trackList[s.track || 0].name + ' · ' + trackList[s.track || 0].mood;

    // Providers (installation-wide, managed by admins)
    const catMeta = { Text: 'type', Image: 'image', Video: 'clapperboard', Voice: 'mic' };
    v.stack = Object.keys(catMeta).map(c => { const p = s.prov.find(x => x.cat === c && x.status === 'connected'); return { cat: c, name: p ? p.name + (p.model ? ' · ' + p.model : '') : (c === 'Text' ? 'Built-in writer (no key)' : 'Not connected'), icon: 'icon-' + catMeta[c], dot: p || c === 'Text' ? 'oklch(0.62 0.14 150)' : '#d6d4ce' }; });
    v.plan = [['Script', 'Text', 'type'], ['Visuals', 'Image', 'image'], ['Voice', 'Voice', 'mic']].map(x => { const p = s.prov.find(q => q.cat === x[1] && q.status === 'connected'); return { step: x[0], via: p ? p.name + ' · ' + (p.model || 'default') : (x[1] === 'Text' ? 'Built-in writer' : 'Not connected'), icon: 'icon-' + x[2] }; }).concat([{ step: 'Render', via: 'FFmpeg on your server', icon: 'icon-server' }]);
    const adminOnly = () => { alert('Only administrators can change AI providers. Ask your admin, or manage them in Admin → AI Providers.'); };
    v.provSections = v.provSections.map(sec => ({ ...sec, items: sec.items.map(pv => ({ ...pv,
      onCfg: isAdmin ? pv.onCfg : adminOnly,
      onToggle: isAdmin ? async () => { try { const r = await rs.post('/admin/providers/' + pv.id + '/toggle'); this.setState(x => ({ prov: x.prov.map(q => q.id === pv.id ? { ...q, ...r.provider } : q) })); } catch (e) { alert(e.message); } } : adminOnly,
      onTest: async () => {
        this.setState({ testing: pv.id });
        try { const r = await rs.post('/studio/providers/' + pv.id + '/test'); this.setState(x => ({ testing: null, prov: x.prov.map(q => q.id === pv.id ? { ...q, ...r.provider, test: r.result.message } : q) })); }
        catch (e) { this.setState(x => ({ testing: null, prov: x.prov.map(q => q.id === pv.id ? { ...q, test: e.message } : q) })); }
      } })) }));
    const cp = s.prov.find(p2 => p2.id === s.cfg);
    if (cp) v.cfg = { ...v.cfg, keyRaw: cp.hasKey ? '' : '', models: (cp.models && cp.models.length ? cp.models : (this.MODELS[cp.cat] || [])), model: cp.model };
    v.openCustom = () => (isAdmin ? this.setState({ cfg: 'custom' }) : adminOnly());
    v.saveCfg = async () => {
      if (!cp) return;
      try {
        const r = await rs.put('/admin/providers/' + cp.id, { api_key: rs.val('cfg_key') || undefined, base_url: rs.val('cfg_url'), model: rs.val('cfg_model'), priority: rs.val('cfg_priority') === 'Primary' ? 10 : 50, status: 'connected' });
        this.setState(x => ({ cfg: null, prov: x.prov.map(q => q.id === cp.id ? { ...q, ...r.provider } : q) }));
      } catch (e) { alert(e.message); }
    };
    this.accountVals(v);
    return v;
  }

  // ------------------------------------------------ account screens (Media, Credits, Usage, API, Settings, Support)
  acct() { return this.state.acct || window.RS.account || {}; }
  async reloadAccount() { try { const r = await rs.get('/studio/account'); if (r.user) window.RS.user = r.user; this.setState({ acct: r }); } catch (e) {} }
  patchAcct(part) { this.setState(st => ({ acct: { ...(st.acct || window.RS.account || {}), ...part } })); }
  seg(list, cur, set) { return list.map(x => { const label = Array.isArray(x) ? x[0] : x; return { label, count: Array.isArray(x) ? x[1] : '', on: () => set(label), segBg: cur === label ? '#fff' : 'transparent', segSh: cur === label ? '0 1px 2px rgba(0,0,0,.08)' : 'none' }; }); }
  statusColors(st) { return ({ Completed: ['oklch(0.42 0.12 150)', 'oklch(0.95 0.04 150)'], Paid: ['oklch(0.42 0.12 150)', 'oklch(0.95 0.04 150)'], Open: ['oklch(0.45 0.14 250)', 'oklch(0.95 0.03 250)'], Answered: ['oklch(0.42 0.12 150)', 'oklch(0.95 0.04 150)'], Pending: ['oklch(0.5 0.13 70)', 'oklch(0.96 0.05 85)'], Failed: ['oklch(0.5 0.18 25)', 'oklch(0.95 0.03 25)'], Refunded: ['#55575c', '#efeeea'], Closed: ['#55575c', '#efeeea'] })[st] || ['#55575c', '#efeeea']; }
  async copy(text, stateKey) { try { await navigator.clipboard.writeText(text); this.setState({ [stateKey]: 'Copied' }); setTimeout(() => this.setState({ [stateKey]: null }), 1500); } catch (e) { prompt('Copy this:', text); } }

  accountVals(v) {
    const s = this.state, A = this.acct(), R = window.RS;
    v.go = { ...v.go, media: () => this.go('media'), credits: () => this.go('credits') };

    // ---- Media library
    const media = A.media || [], kinds = { Images: 'image', Videos: 'video', Audio: 'audio' }, mTab = s.mTab || 'All';
    v.mTabs = this.seg([['All', media.length], ['Images', media.filter(m => m.kind === 'image').length], ['Videos', media.filter(m => m.kind === 'video').length], ['Audio', media.filter(m => m.kind === 'audio').length]], mTab, t => this.setState({ mTab: t }));
    v.mItems = media.filter(m => mTab === 'All' || m.kind === kinds[mTab]).map(m => ({ ...m, isVideo: m.kind === 'video', isAudio: m.kind === 'audio', canDel: m.deletable,
      bg: m.kind === 'image' ? this.bgUrl(m.url, '#23343f') : m.kind === 'video' ? '#23343f' : 'linear-gradient(135deg,#3b3f58,#5b4a6e)',
      open: () => window.open(m.url, '_blank'),
      del: async () => { if (!confirm('Delete "' + m.name + '"? Scenes that use it will fall back to a generated visual.')) return; try { const r = await rs.del('/studio/media/' + m.id); this.patchAcct({ media: r.media }); this.loadMedia(); } catch (e) { alert(e.message); } } }));
    v.mEmpty = v.mItems.length === 0;
    const U = A.usage || {};
    v.mStorage = (U.storage || '0 KB') + ' / ' + (U.storageQuota || '1 GB'); v.mStoragePct = (U.storagePct || 0) + '%';
    v.mUploadLabel = s.mUploading ? 'Uploading…' : 'Upload';
    v.mUpload = () => {
      if (s.mUploading) return;
      const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*,video/mp4,video/quicktime,video/webm,audio/*';
      input.onchange = async () => {
        if (!input.files[0]) return;
        this.setState({ mUploading: true });
        const fd = new FormData(); fd.append('file', input.files[0]);
        try {
          const res = await fetch('/studio/media', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': window.RS.csrf, 'Accept': 'application/json' } });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.message || (data.errors && Object.values(data.errors)[0][0]) || 'Upload failed');
          this.patchAcct({ media: data.media }); this.loadMedia(); this.reloadAccount();
        } catch (e) { alert(e.message); }
        this.setState({ mUploading: false });
      };
      input.click();
    };

    // ---- Credits & billing
    const C = A.credits || {};
    v.crKpis = [['Balance', C.balance, C.plan + ' plan'], ['Plan', C.plan, C.planPrice], ['Spent this month', C.spentMonth, 'credits used'], ['Added this month', C.addedMonth, 'plan, purchases, bonuses']].map(k => ({ label: k[0], v: k[1] || '0', sub: k[2] || '' }));
    v.crPlans = (C.plans || []).map(p => ({ ...p, per: p.price > 0 ? '/mo' : '', bd: p.current ? '#17181a' : '#e8e7e3',
      btn: p.current ? 'Current plan' : p.price > 0 ? 'Upgrade' : 'Free', btnBg: p.current ? '#f3f2ef' : p.price > 0 ? '#17181a' : '#fff', btnFg: p.current ? '#6b6d72' : p.price > 0 ? '#fff' : '#17181a',
      btnBd: p.current || p.price > 0 ? '0' : '1px solid #e1e0dc', cursor: p.current || p.price <= 0 ? 'default' : 'pointer',
      on: () => { if (!p.current && p.price > 0) window.location.href = '/checkout?plan=' + encodeURIComponent(p.slug); } }));
    v.crBuy = () => { window.location.href = '/pricing'; };
    v.crHist = (C.history || []).map(h => ({ ...h, fg: h.positive ? 'oklch(0.45 0.13 150)' : '#17181a' }));
    v.crHistEmpty = v.crHist.length === 0;
    v.crPays = (C.payments || []).map(p => { const c = this.statusColors(p.status); return { ...p, sfg: c[0], sbg: c[1], hasBank: !!(p.bank && p.bank.length), bank: (p.bank || []).join('\n') }; });
    v.crPaysEmpty = v.crPays.length === 0;

    // ---- Usage
    const mode = s.usMode || 'Credits', days = U.days || [];
    v.usModes = this.seg(['Credits', 'Videos'], mode, m => this.setState({ usMode: m }));
    const max = mode === 'Credits' ? (U.max || 1) : (U.maxVideos || 1);
    v.usBars = days.map(d => { const val = mode === 'Credits' ? d.v : d.videos; return { h: Math.round(val / max * 100) + '%', c: val ? (mode === 'Credits' ? 'oklch(0.58 0.19 35)' : '#17181a') : '#efeeea', title: d.label + ' · ' + val + (mode === 'Credits' ? ' credits' : ' videos') }; });
    v.usFrom = days.length ? days[0].label : ''; v.usTo = days.length ? days[days.length - 1].label : '';
    v.usKpis = [['Credits spent', U.spent30, 'last 30 days'], ['Videos created', String(U.videos30 || 0), (U.rendered30 || 0) + ' rendered'], ['API calls', String(U.apiCalls30 || 0), 'last 30 days'], ['Storage', U.storage || '0 KB', 'of ' + (U.storageQuota || '1 GB')]].map(k => ({ label: k[0], v: k[1] || '0', sub: k[2] }));
    v.usCats = (U.byCategory || []).map(c => ({ ...c, w: c.pct + '%' }));
    v.usCatsEmpty = v.usCats.length === 0;
    v.usStorage = U.storage || '0 KB'; v.usStoragePct = (U.storagePct || 0) + '%';
    v.usStorageNote = (U.storagePct || 0) + '% of your ' + (U.storageQuota || '1 GB') + ' plan storage (renders and uploads).';

    // ---- API
    const API = A.api || {};
    v.apiAllowed = !!API.allowed; v.apiDenied = !API.allowed;
    v.apiKeys = (API.keys || []).map(k => ({ ...k, active: !k.revoked, st: k.revoked ? 'Revoked' : 'Active', sfg: k.revoked ? '#55575c' : 'oklch(0.42 0.12 150)', sbg: k.revoked ? '#efeeea' : 'oklch(0.95 0.04 150)',
      revoke: async () => { if (!confirm('Revoke "' + k.name + '"? Apps using it stop working immediately.')) return; try { const r = await rs.del('/studio/api-keys/' + k.id); this.patchAcct({ api: r.api }); } catch (e) { alert(e.message); } } }));
    v.apiKeysEmpty = v.apiKeys.length === 0;
    v.hasNewKey = !!s.newKey; v.apiNewKey = s.newKey || ''; v.copyLabel = s.keyCopied || 'Copy';
    v.copyNewKey = () => this.copy(s.newKey, 'keyCopied');
    v.createKey = async () => {
      const name = (rs.val('api_key_name') || '').trim() || 'My key';
      try { const r = await rs.post('/studio/api-keys', { name }); this.patchAcct({ api: r.api }); this.setState({ newKey: r.key }); } catch (e) { alert(e.message); }
    };
    const base = API.base || (location.origin + '/api'), key = s.newKey || 'rsk_live_…';
    const snips = {
      'Create a video': 'curl -X POST ' + base + '/v1/videos \\\n  -H "Authorization: Bearer ' + key + '" \\\n  -H "Content-Type: application/json" \\\n  -d \'{"topic": "3 tips for better sleep", "ratio": "9:16", "duration": "30s"}\'',
      'Check a job': 'curl ' + base + '/jobs/vid_123 \\\n  -H "Authorization: Bearer ' + key + '"',
      'Run a workflow': 'curl -X POST ' + base + '/workflows/run \\\n  -H "Authorization: Bearer ' + key + '" \\\n  -H "Content-Type: application/json" \\\n  -d \'{"workflow_id": "wf_1", "input": {"topic": "Morning routines"}}\'',
      'Credits': 'curl ' + base + '/v1/credits \\\n  -H "Authorization: Bearer ' + key + '"'
    };
    const snip = s.apiSnip || 'Create a video';
    v.apiSnips = Object.keys(snips).map(k => ({ label: k, bg: k === snip ? '#3a3c42' : 'transparent', on: () => this.setState({ apiSnip: k }) }));
    v.apiSnippet = snips[snip];
    v.openPlatformApi = () => { window.location.href = '/platform/api'; };

    // ---- Settings
    const ST = A.settings || {};
    v.acc = { name: ST.name || '', email: ST.email || '', verifiedLabel: ST.verified ? '· verified' : '· not verified' };
    v.accKey = 'acc' + (s.accV || 0); v.pwKey = 'pw' + (s.pwV || 0);
    v.saveProfile = async () => {
      try {
        const r = await rs.put('/studio/account/profile', { name: rs.val('acc_name'), email: rs.val('acc_email') });
        window.RS.user = r.user; this.patchAcct({ settings: r.settings }); this.setState({ accV: (s.accV || 0) + 1 });
        alert(r.verify ? 'Saved. We sent a verification code to your new email address.' : 'Profile saved.');
        if (r.verify) window.location.href = '/verify';
      } catch (e) { alert(e.message); }
    };
    v.savePassword = async () => {
      try {
        await rs.put('/studio/account/password', { current_password: rs.val('pw_current'), password: rs.val('pw_new'), password_confirmation: rs.val('pw_confirm') });
        this.setState({ pwV: (s.pwV || 0) + 1 }); alert('Password updated. Other devices were signed out.');
      } catch (e) { alert(e.message); }
    };
    const setup = s.tfaSetup, on = !!ST.twoFactor;
    v.tfaRequiredNote = !!(ST.twoFactorRequired && !on);
    v.tfa = { on: on && !s.tfaCodes, offIdle: !on && !setup, setup: !!setup, hasCodes: !!s.tfaCodes, codes: s.tfaCodes || [],
      qrBg: setup ? "url('" + setup.qr + "')" : 'none', secret: setup ? setup.secret : '',
      label: on ? 'On' : 'Off', bg: on ? 'oklch(0.95 0.04 150)' : '#efeeea', fg: on ? 'oklch(0.42 0.12 150)' : '#55575c',
      left: (ST.recoveryLeft || 0) + ' recovery codes left' };
    v.startTfa = async () => { try { this.setState({ tfaSetup: await rs.post('/studio/account/2fa/setup') }); } catch (e) { alert(e.message); } };
    v.cancelTfa = () => this.setState({ tfaSetup: null });
    v.confirmTfa = async () => {
      try { const r = await rs.post('/studio/account/2fa/confirm', { code: rs.val('tfa_setup_code') }); this.patchAcct({ settings: r.settings }); this.setState({ tfaSetup: null, tfaCodes: r.codes }); } catch (e) { alert(e.message); }
    };
    v.copyCodesLabel = s.codesCopied || 'Copy codes';
    v.copyCodes = () => this.copy((s.tfaCodes || []).join('\n'), 'codesCopied');
    v.hideCodes = () => this.setState({ tfaCodes: null });
    v.regenCodes = async () => {
      const password = prompt('Enter your password to create new recovery codes (the old ones stop working):'); if (!password) return;
      try { const r = await rs.post('/studio/account/2fa/recovery', { password }); this.patchAcct({ settings: r.settings }); this.setState({ tfaCodes: r.codes }); } catch (e) { alert(e.message); }
    };
    v.disableTfa = async () => {
      const password = prompt('Enter your password to turn off two-factor authentication:'); if (!password) return;
      try { const r = await rs.api('DELETE', '/studio/account/2fa', { password }); this.patchAcct({ settings: r.settings }); } catch (e) { alert(e.message); }
    };
    const prefs = ST.prefs || {};
    v.prefRows = [['renderDone', 'Video ready', 'Email me when a render finishes.'], ['lowCredits', 'Low credits', 'Warn me when my balance runs low.'], ['product', 'Product updates', 'New features and templates, about once a month.'], ['weekly', 'Weekly summary', 'Videos, credits and runs from the past week.']].map(p => ({
      label: p[1], desc: p[2], bg: prefs[p[0]] ? 'oklch(0.58 0.19 35)' : '#d6d4ce', knob: prefs[p[0]] ? '19px' : '3px',
      toggle: async () => { const next = { ...prefs, [p[0]]: !prefs[p[0]] }; this.patchAcct({ settings: { ...ST, prefs: next } }); try { await rs.put('/studio/account/prefs', { [p[0]]: next[p[0]] }); } catch (e) { alert(e.message); } } }));
    v.deleteAccount = async () => {
      if (!confirm('Delete your account and everything in it? This cannot be undone.')) return;
      const password = prompt('Enter your password to confirm:'); if (!password) return;
      try { await rs.api('DELETE', '/studio/account', { password }); window.location.href = '/'; } catch (e) { alert(e.message); }
    };

    // ---- Support
    const tickets = A.tickets || [], admin = me().role === 'admin';
    function me() { return window.RS.user || {}; }
    v.supTitle = admin ? 'Support inbox' : 'Support'; v.supSub = admin ? 'Tickets from every user. Your replies are emailed to them.' : 'Ask us anything. We usually reply within one business day.';
    v.supCols = this.state.isMobile || window.innerWidth < 980 ? '1fr' : 'minmax(0,1fr) 320px';
    const sel = tickets.find(t => t.id === s.tkSel);
    v.tktOpen = !!sel; v.tktNew = !sel;
    v.tktListTitle = admin ? 'All tickets' : 'Your tickets';
    v.tkts = tickets.map(t => { const c = this.statusColors(t.status); return { ...t, sfg: c[0], sbg: c[1], bg: t.id === s.tkSel ? '#f7f6f3' : '#fff', meta: (admin && !t.mine ? t.user + ' · ' : '') + t.category + ' · ' + t.updated, open: () => this.setState({ tkSel: t.id }) }; });
    v.tktsEmpty = v.tkts.length === 0;
    if (sel) {
      const c = this.statusColors(sel.status);
      v.tkt = { ...sel, sfg: c[0], sbg: c[1], who: sel.mine ? 'you' : sel.user + ' <' + sel.email + '>', canReply: sel.status !== 'Closed' };
      v.tktMsgs = (sel.messages || []).map(m => { const mine = (m.from === 'staff') === (admin && !sel.mine); return { ...m, align: mine ? 'flex-end' : 'flex-start', bg: mine ? '#17181a' : '#f3f2ef', fg: mine ? '#fff' : '#17181a', name: m.from === 'staff' ? m.name + ' (support)' : m.name }; });
    } else { v.tkt = {}; v.tktMsgs = []; }
    v.replyKey = 'r' + (s.replyV || 0); v.tktKey = 't' + (s.tktV || 0);
    v.backTkt = () => this.setState({ tkSel: null });
    v.sendReply = async () => {
      const message = (rs.val('tkt_reply') || '').trim(); if (!message) return;
      try { const r = await rs.post('/studio/tickets/' + sel.id + '/reply', { message }); this.patchAcct({ tickets: r.tickets }); this.setState({ replyV: (s.replyV || 0) + 1 }); } catch (e) { alert(e.message); }
    };
    v.closeTkt = async () => { try { const r = await rs.post('/studio/tickets/' + sel.id + '/close'); this.patchAcct({ tickets: r.tickets }); } catch (e) { alert(e.message); } };
    v.submitLabel = s.tktBusy ? 'Sending…' : 'Send to support';
    v.submitTicket = async () => {
      if (s.tktBusy) return;
      this.setState({ tktBusy: true });
      try {
        const r = await rs.post('/studio/tickets', { subject: rs.val('tkt_subject'), category: rs.val('tkt_category'), message: rs.val('tkt_message') });
        this.patchAcct({ tickets: r.tickets }); this.setState({ tktBusy: false, tktV: (s.tktV || 0) + 1, tkSel: (r.tickets[0] || {}).id || null });
      } catch (e) { this.setState({ tktBusy: false }); alert(e.message); }
    };
    const faqs = [
      ['Why does my video have no AI images?', 'No image provider is connected, or all of them failed. Add a key under AI Providers (Cloudflare Workers AI and Hugging Face have free tiers). Failed scenes become colour cards, and the reason is in the render log.'],
      ['How are credits charged?', 'Rendering costs credits per video (more for AI video scenes), and generating an image or clip for a scene costs a little. Failed renders are refunded automatically. See Usage for the breakdown.'],
      ['Can I use my own footage?', 'Yes. Upload images or MP4 clips here in Media Library or straight onto a scene in the editor, then pick them for any scene.'],
      ['How do I get the API key?', 'Go to API in the sidebar and create a key. It is shown once. API access depends on your plan.'],
      ['I lost my phone with the authenticator app', 'Sign in with one of your recovery codes ("Use a recovery code instead"), then turn two-factor off and on again in Settings. If you have no codes left, contact support.']
    ];
    v.faq = faqs.map((f, i) => ({ q: f[0], a: f[1], open: s.faqOpen === i, icon: s.faqOpen === i ? 'icon-chevron-up' : 'icon-chevron-down', toggle: () => this.setState({ faqOpen: s.faqOpen === i ? null : i }) }));
    v.supportEmail = (R.whiteLabel && R.whiteLabel.support) || R.supportEmail || 'support@example.com';
  }
}
