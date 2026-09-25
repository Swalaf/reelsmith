// Laravel integration for the Studio: projects, script generation, rendering, providers and brand kit.
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    const R = window.RS;
    this.VIDEOS = (R.projects || []).map(x => this.toVideo(x));
    if (R.templates && R.templates.length) this.TPL = R.templates.map(t => [t.name, t.cat, t.dur, t.ratio]);
    this.state = { ...this.state,
      screen: R.startScreen || this.state.screen,
      prov: (R.providers || []).map(x => ({ ...x })),
      brand: { ...this.state.brand, ...(R.brand || {}) },
      projectId: null, busy: false, output: null
    };
    if (R.project) Object.assign(this.state, this.projectState(R.project), { screen: 'editor' });
  }

  toVideo(x) { return { id: x.id, name: x.name, dur: x.dur, status: x.status, date: x.date, platform: x.platform, ratio: x.ratio, c: x.c, ts: x.ts, url: x.url, credits: x.credits }; }

  projectState(x) {
    const st = { projectId: x.id, output: x };
    if (x.idea) st.idea = { ...this.state.idea, ...x.idea };
    if (x.script) st.script = { ...this.state.script, ...x.script };
    if (x.scenes && x.scenes.length) { st.scenes = x.scenes.map((sc, i) => ({ rg: false, vp: 0, v: 'none', src: 'AI Image', tr: 'Fade', ...sc, id: sc.id || i + 1 })); st.nextId = Math.max(...st.scenes.map(s => s.id)) + 1; st.edSel = 0; st.edT = 0; }
    if (x.captions) st.cap = { ...this.state.cap, ...x.captions };
    if (x.voice) st.voice = x.voice;
    return st;
  }

  upsert(x) {
    const v = this.toVideo(x), i = this.VIDEOS.findIndex(y => y.id === x.id);
    if (i < 0) this.VIDEOS = [v, ...this.VIDEOS]; else this.VIDEOS = this.VIDEOS.map(y => y.id === x.id ? v : y);
  }

  payload() {
    const s = this.state;
    return { idea: s.idea, script: s.script, scenes: s.scenes.map(({ id, prompt, narration, caption, dur, src, c, tr, v }) => ({ id, prompt, narration, caption, dur, src, c, tr, v: v === 'loading' ? 'none' : v })), captions: s.cap, voice: s.voice };
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
    (async () => {
      await this.save();
      try {
        const r = await rs.post('/studio/projects/' + s.projectId + '/render');
        this.upsert(r.project);
        if (r.user) window.RS.user = r.user;
        this.setState({ output: r.project });
        super.startRender();
        this.poll(s.projectId);
      } catch (e) { alert(e.message); }
    })();
  }

  poll(id) {
    clearTimeout(this.pt2);
    this.pt2 = setTimeout(async () => {
      try {
        const r = await rs.get('/studio/projects/' + id);
        this.upsert(r.project);
        this.setState({ output: r.project });
        if (r.project.status === 'Processing') this.poll(id);
        else if (r.project.status === 'Failed') { this.setState({ rendering: false }); alert('Render failed: ' + (r.project.error || 'unknown error') + '. Credits were refunded.'); }
      } catch (e) { this.poll(id); }
    }, 2500);
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
    if (s.renderP >= 100 && o.status === 'Processing') { v.renderActive = true; v.renderDone = false; v.renderPct = '99%'; v.eta = 'Finishing up…'; }

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
    return v;
  }
}
