// Laravel integration for the AI Platform: workflows (builder + real runs), runs history, studio
// briefs, cinematic productions, characters, agents, repurposing and API & webhooks.
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    const R = window.RS, P = R.platform || {};
    this.P = P;
    const wf = (P.workflows || [])[0];
    this.state = { ...this.state, screen: R.startScreen || this.state.screen,
      workflows: P.workflows || [], runsData: P.runs || [], chars: P.characters || [], prod: P.production, agentsData: P.agents || [],
      hooksData: P.webhooks || [], projects: P.projects || [], rpSourceId: ((P.projects || [])[0] || {}).id || null,
      wfId: null, wfRunId: null, runSel: 0, tryReply: '', trying: false, saved: 'saved', busy: {} };
    if (wf) Object.assign(this.state, this.loadWf(wf));
    if (this.state.prod) Object.assign(this.state, { shots: this.prodShots(this.state.prod), prodTitle: this.state.prod.title, style: this.state.prod.style, prodT: this.state.prod.toggles || this.state.prodT, sceneSel: ((this.state.prod.scenes || [])[0] || [1])[0] });
  }

  componentDidMount() {
    super.componentDidMount();
    if (this.state.prod) this.setState({ shots: this.prodShots(this.state.prod) }); // base mount resets shots to its demo list
    this.pollIv = setInterval(() => this.poll(), 3000);
  }
  componentWillUnmount() { super.componentWillUnmount(); clearInterval(this.pollIv); clearTimeout(this.saveT); clearTimeout(this.prodT2); }

  // ---------------------------------------------------------------- helpers
  api(method, url, body) { return rs.api(method, '/platform/api' + url, body); }
  bg(url, c) { return url ? "url('" + url + "') center/cover no-repeat, " + (c || '#23343f') : (c || '#23343f'); }
  defaults(t) { return { ...((this.P.nodeDefaults || {})[t] || {}) }; }
  loadWf(wf) {
    const nodes = (wf.nodes || []).map(n => ({ ...n, cfg: { ...this.defaults(n.t), ...(n.cfg || {}) } }));
    return { wfId: wf.id, nodes, edges: wf.edges || [], nextId: Math.max(0, ...nodes.map(n => n.id)) + 1, route: wf.route || 'Free first', live: !!wf.live, selNode: null, wfRun: null, runLog: [], wfSettings: wf.settings || {} };
  }
  curWf() { return this.state.workflows.find(w => w.id === this.state.wfId); }
  prodShots(prod) { return (prod.shots || []).map(s => ({ ...s, st: s.img ? 'done' : (s.st === 'loading' ? 'loading' : 'none'), p: s.img ? 100 : 0, c: s.imgUrl ? this.bg(s.imgUrl, s.c) : s.c })); }
  toastErr(e) { this.toast(e.message || String(e), 'err'); }

  async refresh() {
    try {
      const P = await this.api('GET', '/state');
      this.P = P;
      const up = { workflows: P.workflows, runsData: P.runs, chars: P.characters, agentsData: P.agents, hooksData: P.webhooks, projects: P.projects };
      if (P.production && !this.state.busy.shots) up.prod = P.production;
      this.setState(up);
    } catch (e) {}
  }

  poll() {
    const s = this.state;
    const active = s.runsData.some(r => ['Queued', 'Running'].includes(r.status)) || s.wfRunId || (s.rpStarted && !s.rpRunId) || (s.prod && s.prod.project && s.prod.project.status === 'Processing');
    if (active) this.refresh().then(() => this.syncTestRun());
  }

  /** Mirror a workflow test run into the builder's node badges and execution log. */
  syncTestRun() {
    const s = this.state, r = s.runsData.find(x => x.id === s.wfRunId);
    if (!r) return;
    const status = {}; let active = null;
    (r.steps || []).forEach(st => { if (st.status === 'done' || st.status === 'skip') status[st.id] = 'done'; if (st.status === 'run') { status[st.id] = 'running'; active = st.id; } if (st.status === 'fail') status[st.id] = 'running'; });
    const done = !['Queued', 'Running'].includes(r.status);
    this.setState({ wfRun: { status, active, done }, runLog: r.log || [] , wfRunId: done ? null : s.wfRunId });
    if (done) this.toast(r.status === 'Completed' ? 'Test run completed' : 'Run failed: ' + (r.error || ''), r.status === 'Completed' ? 'ok' : 'err');
  }

  saveWfSoon() {
    clearTimeout(this.saveT);
    this.setState({ saved: 'saving…' });
    this.saveT = setTimeout(async () => {
      const s = this.state;
      if (!s.wfId) return;
      try {
        const r = await this.api('PUT', '/workflows/' + s.wfId, { nodes: s.nodes, edges: s.edges, route: s.route, settings: s.wfSettings });
        this.setState(x => ({ saved: 'saved just now', workflows: x.workflows.map(w => w.id === r.workflow.id ? r.workflow : w) }));
      } catch (e) { this.setState({ saved: 'not saved' }); this.toastErr(e); }
    }, 700);
  }

  saveProdSoon(patch) {
    this.setState(x => ({ prod: { ...x.prod, ...patch } }));
    clearTimeout(this.prodT2);
    this.prodT2 = setTimeout(async () => {
      const s = this.state;
      try { await this.api('PUT', '/production', { title: s.prodTitle, logline: s.prod.logline, style: s.style, settings: s.prod.settings, toggles: s.prodT, shots: (s.shots || []).map(({ id, sc, cam, move, lens, light, tod, wx, dur }) => ({ id, sc, cam, move, lens, light, tod, wx, dur })) }); }
      catch (e) { this.toastErr(e); }
    }, 800);
  }

  async openWf(id) {
    const wf = this.state.workflows.find(w => w.id === id);
    if (wf) { this.setState(this.loadWf(wf)); this.go('builder'); }
  }

  async newWf(template) {
    try {
      const r = await this.api('POST', '/workflows', { template });
      this.setState(x => ({ workflows: [r.workflow, ...x.workflows], ...this.loadWf(r.workflow) }));
      this.go('builder');
      this.toast('Workflow created');
    } catch (e) { this.toastErr(e); }
  }

  async genShot(id) {
    this.setState(x => ({ busy: { ...x.busy, shots: true }, shots: x.shots.map(q => q.id === id ? { ...q, st: 'loading', p: 5 } : q) }));
    try {
      await this.api('PUT', '/production', { shots: this.state.shots.map(({ id, sc, cam, move, lens, light, tod, wx, dur }) => ({ id, sc, cam, move, lens, light, tod, wx, dur })) });
      const r = await this.api('POST', '/production/shots/' + id);
      this.setState(x => ({ prod: r.production, shots: this.prodShots(r.production).map(q => (x.shots.find(o => o.id === q.id && o.st === 'loading' && o.id !== id) ? { ...q, st: 'loading' } : q)) }));
    } catch (e) { this.setState(x => ({ shots: x.shots.map(q => q.id === id ? { ...q, st: 'none', p: 0 } : q) })); this.toastErr(e); }
    this.setState(x => ({ busy: { ...x.busy, shots: x.shots.some(q => q.st === 'loading') } }));
  }

  // Replaces the design's timer: shot and repurpose progress now come from the server.
  componentDidUpdate() {}

  renderVals() {
    const v = super.renderVals(), s = this.state, R = window.RS, P = this.P;
    v.appName = R.appName; v.me = R.user || {}; v.logout = () => rs.logout(); v.greeting = rs.greeting();
    const F = this.F, st = P.stats || {};
    const RSX = { Running: ['icon-loader-circle', 'oklch(0.55 0.13 250)', 'rs-spin 1s linear infinite', 'oklch(0.45 0.13 250)', 'oklch(0.95 0.03 250)'], Completed: ['icon-circle-check', 'oklch(0.5 0.14 150)', 'none', 'oklch(0.42 0.12 150)', 'oklch(0.95 0.04 150)'], Failed: ['icon-circle-x', 'oklch(0.55 0.18 25)', 'none', 'oklch(0.5 0.18 25)', 'oklch(0.95 0.035 25)'], Scheduled: ['icon-calendar-clock', '#6b6d72', 'none', '#55575c', '#efefec'], Queued: ['icon-clock', 'oklch(0.6 0.13 70)', 'none', 'oklch(0.5 0.13 70)', 'oklch(0.95 0.05 85)'] };

    // ---- navigation / home
    const running = s.runsData.filter(r => ['Queued', 'Running'].includes(r.status)).length;
    v.navGroups = v.navGroups.map(g => ({ ...g, items: g.items.map(n => n.label === 'Runs' ? { ...n, hasBadge: running > 0, badge: String(running) } : n) }));
    v.go = { ...v.go, builder: () => (s.wfId ? this.openWf(s.wfId) : this.newWf()) };
    v.quick = v.quick.map(q => q.t === 'Create Workflow' ? { ...q, go: () => this.newWf() } : q);
    const n = x => Number(x || 0).toLocaleString();
    v.pipeline = [['CREATE', 'sparkles', 'oklch(0.55 0.17 35)', [['Videos', n(st.videos)], ['Images', n(st.images)], ['Audio clips', n(st.audio)]]], ['AUTOMATE', 'workflow', 'oklch(0.5 0.12 300)', [['Active automations', n(st.automations)], ['Workflows run', n(st.runs)], ['Agent tasks', n(st.agentTasks)]]], ['PRODUCE', 'clapperboard', 'oklch(0.5 0.13 250)', [['Productions', n(st.productions)], ['Shots generated', n(st.shots)], ['Renders', n(st.renders)]]], ['DEPLOY', 'rocket', 'oklch(0.45 0.12 150)', [['API calls', n(st.apiCalls)], ['Webhooks sent', n(st.webhooks)], ['Storage', '—']]]].map(p => ({ t: p[0], icon: 'icon-' + p[1], c: p[2], stats: p[3].map(x => ({ k: x[0], v: x[1] })) }));
    const kindIcon = { workflow: 'workflow', brief: 'sparkles', repurpose: 'split', agent: 'bot', image: 'image' };
    v.outputs = s.runsData.filter(r => r.status === 'Completed').slice(0, 6).map((r, i) => ({ t: r.wf, kind: r.kind, icon: 'icon-' + (kindIcon[r.kind] || 'file'), src: r.code + ' · ' + r.when, c: F[(i * 3 + 2) % 10], on: () => { this.setState({ runSel: s.runsData.indexOf(r) }); this.go('runs'); } }));
    v.activeAuto = s.workflows.filter(w => w.live || w.status === 'Failing').map(w => ({ t: w.name, trig: w.trigger, runs: w.runs + ' runs', c: w.status === 'Failing' ? 'oklch(0.6 0.2 25)' : 'oklch(0.62 0.14 150)', anim: s.runsData.some(r => r.workflowId === w.id && r.status === 'Running') ? 'rs-pulse 1.6s ease-in-out infinite' : 'none' }));

    // ---- workflows list + templates
    const catOf = t => this.CAT[(this.NT[t] || ['', '', 'data'])[2]];
    v.wfList = s.workflows.map(w => ({ name: w.name, trig: w.trigger + (w.nextRun ? ' · next ' + w.nextRun : ''), ticon: 'icon-' + ({ webhook: 'webhook', schedule: 'calendar-clock' }[w.triggerType] || 'zap'), runs: String(w.runs), last: w.last, st: w.status,
      steps: (w.nodes || []).map(nd => catOf(nd.t)), sfg: w.status === 'Active' ? 'oklch(0.42 0.12 150)' : w.status === 'Failing' ? 'oklch(0.5 0.18 25)' : '#55575c', sbg: w.status === 'Active' ? 'oklch(0.95 0.04 150)' : w.status === 'Failing' ? 'oklch(0.95 0.035 25)' : '#efefec', open: () => this.openWf(w.id) }));
    const tplNames = ['Product → video ad', 'Repurpose long video', 'Blog → narrated video', 'Lead → personalised video'];
    v.wfTemplates = v.wfTemplates.map((t, i) => ({ ...t, go: () => this.newWf(tplNames[i]) }));

    // ---- builder
    const wf = this.curWf();
    v.wfName = wf ? wf.name : 'New workflow'; v.wfSaved = s.saved;
    const nodesJson = JSON.stringify([s.nodes, s.edges, s.route, s.wfSettings]);
    if (this.lastNodes !== undefined && this.lastNodes !== nodesJson && s.wfId) this.saveWfSoon();
    this.lastNodes = nodesJson;
    v.nodes = v.nodes.map(nd => { const node = s.nodes.find(x => x.id === nd.id) || {}; return { ...nd, sub: node.sub || Object.values(node.cfg || this.defaults(node.t)).find(x => x) || nd.sub }; });
    v.nodeLib = v.nodeLib.map(gp => ({ ...gp, items: gp.items.map(it => ({ ...it, add: () => { const t = Object.keys(this.NT).find(k => this.NT[k][0] === it.label); this.setState(st2 => { const last = st2.nodes[st2.nodes.length - 1]; const nn = { id: st2.nextId, t, title: this.NT[t][0], sub: 'configure →', x: Math.min(880, (last ? last.x : 0) + 40), y: Math.min(540, (last ? last.y : 0) + 40), cfg: this.defaults(t) }; return { nodes: [...st2.nodes, nn], edges: last ? [...st2.edges, [last.id, st2.nextId]] : st2.edges, nextId: st2.nextId + 1, selNode: st2.nextId }; }); } })) }));
    const sn = s.nodes.find(x => x.id === s.selNode);
    const areas = ['Prompt', 'Body', 'Input (JSON)', 'Text', 'Value', 'Notes', 'Caption'];
    v.selFields = sn ? Object.entries({ ...this.defaults(sn.t), ...(sn.cfg || {}) }).map(([label, val]) => ({ label, v: val, isArea: areas.includes(label), isInput: !areas.includes(label), key: s.wfId + ':' + sn.id + ':' + label,
      set: e => { const val2 = e.target.value; this.setState(x => ({ nodes: x.nodes.map(q => q.id === sn.id ? { ...q, cfg: { ...(q.cfg || {}), [label]: val2 } } : q) })); } })) : [];
    if (sn && sn.t === 'webhook' && wf) v.selFields.unshift({ label: 'Incoming URL (POST JSON here while the workflow is live)', v: wf.hookUrl, isInput: true, isArea: false, key: 'hook' + wf.id, set: () => {} });
    const provs = cat => (P.providers || []).filter(p => p.cat === cat).map(p => p.n + (p.model ? ' · ' + p.model : ''));
    const chainList = sn && sn.t === 'aiimage' ? provs('Image') : sn && sn.t === 'aivideo' ? provs('Video') : sn && ['voice', 'aiaudio'].includes(sn.t) ? provs('Voice') : provs('Text');
    v.chain = (chainList.length ? chainList : ['No provider connected']).map((c, i, a) => ({ n: c, arrow: i < a.length - 1, bd: i === 0 ? '#17181a' : '#e1e0dc', dot: chainList.length ? 'oklch(0.62 0.14 150)' : '#d6d4ce' }));
    v.wfKey = 'wf' + s.wfId; v.wfCap = String((s.wfSettings || {}).creditCap || 120);
    v.setWfCap = e => { const val = parseInt(e.target.value) || 0; this.setState(x => ({ wfSettings: { ...(x.wfSettings || {}), creditCap: val } })); };
    v.testWf = async () => {
      if (s.wfRunId || !s.wfId) return;
      clearTimeout(this.saveT);
      try {
        await this.api('PUT', '/workflows/' + s.wfId, { nodes: s.nodes, edges: s.edges, route: s.route, settings: s.wfSettings });
        const r = await this.api('POST', '/workflows/' + s.wfId + '/run', { input: {} });
        this.setState(x => ({ wfRunId: r.run.id, runsData: [r.run, ...x.runsData], wfRun: { status: {}, active: null, done: false }, runLog: [{ t: '00.0', m: '▶ Test run queued · ' + r.run.code, c: '#e7e7ea' }] }));
      } catch (e) { this.toastErr(e); }
    };
    const testing = !!s.wfRunId;
    v.testIcon = testing ? 'icon-loader-circle' : 'icon-flask-conical'; v.testAnim = testing ? 'rs-spin 1s linear infinite' : 'none'; v.testLabel = testing ? 'Running…' : 'Test workflow';
    v.wfLive = s.live ? { c: 'oklch(0.62 0.14 150)', t: 'Live · ' + (wf ? wf.runs : 0) + ' runs' } : { c: '#b3b4b8', t: 'Paused' };
    v.liveIcon = s.live ? 'icon-pause' : 'icon-play'; v.liveLabel = s.live ? 'Pause' : 'Activate';
    v.toggleLive = async () => {
      try { const r = await this.api('PUT', '/workflows/' + s.wfId, { live: !s.live }); this.setState(x => ({ live: r.workflow.live, workflows: x.workflows.map(w => w.id === r.workflow.id ? r.workflow : w) })); this.toast(r.workflow.live ? 'Workflow is live' + (r.workflow.triggerType === 'webhook' ? ' · POST to ' + r.workflow.hookUrl : '') : 'Workflow paused'); }
      catch (e) { this.toastErr(e); }
    };
    v.runMeta = s.wfRunId ? 'run_' + s.wfRunId + ' · running' : (s.runLog.length ? 'last test run' : 'no test runs yet');
    v.logEmpty = !s.runLog.length; v.runLog = s.runLog;

    // ---- runs
    const runs = s.runsData;
    const filtered = runs.map((r, i) => ({ r, i })).filter(x => s.runFilter === 'All' || x.r.status === s.runFilter);
    v.runTabs = v.runTabs.map(c => ({ ...c, count: runs.filter(r => c.label === 'All' || r.status === c.label).length }));
    v.runs = filtered.map(x => ({ id: x.r.code, wf: x.r.wf, when: x.r.when, dur: x.r.dur, icon: (RSX[x.r.status] || RSX.Queued)[0], c: (RSX[x.r.status] || RSX.Queued)[1], anim: (RSX[x.r.status] || RSX.Queued)[2], bg: s.runSel === x.i ? '#f6f5f2' : '#fff', on: () => this.setState({ runSel: x.i }) }));
    const RR = runs[Math.min(s.runSel, runs.length - 1)];
    if (RR) {
      const stSty = { done: ['icon-check', 'oklch(0.45 0.12 150)', 'none'], fail: ['icon-x', 'oklch(0.5 0.18 25)', 'none'], run: ['icon-loader-circle', 'oklch(0.45 0.13 250)', 'rs-spin 1s linear infinite'], wait: ['icon-clock', '#8a8c91', 'none'], skip: ['icon-minus', '#b3b4b8', 'none'] };
      const outLinks = [].concat(RR.output && RR.output.video ? ['video: ' + RR.output.video] : [], RR.output && RR.output.images ? RR.output.images.map(u => 'image: ' + u) : [], RR.output && RR.output.audio ? ['audio: ' + RR.output.audio] : [], RR.output && RR.output.file ? ['file: ' + RR.output.file] : []);
      v.run = { id: RR.code, wf: RR.wf, trig: RR.trigger, st: RR.status, sfg: (RSX[RR.status] || RSX.Queued)[3], sbg: (RSX[RR.status] || RSX.Queued)[4], failed: RR.status === 'Failed', err: RR.error || '',
        kpis: [['Duration', RR.dur], ['AI calls', String((RR.steps || []).filter(x => ['aitext', 'aiimage', 'aivideo', 'voice', 'aiaudio', 'script'].includes(x.t) && x.status === 'done').length)], ['Credits', String(RR.credits || 0)], ['Outputs', String(outLinks.length + (RR.output && RR.output.text ? 1 : 0))]].map(k => ({ k: k[0], v: k[1] })),
        steps: (RR.steps || []).map(x => { const m = this.NT[x.t] || this.NT.output; const ss = stSty[x.status] || stSty.wait; return { t: x.title, meta: (x.meta || '') + (x.files ? ' · ' + x.files.length + ' file(s)' : ''), dur: x.dur || '—', icon: 'icon-' + m[1], c: this.CAT[m[2]], sIcon: ss[0], sc: ss[1], sAnim: ss[2], mc: x.status === 'fail' ? 'oklch(0.5 0.18 25)' : '#6b6d72' }; })
          .concat(RR.output && RR.output.text ? [{ t: 'Output text', meta: String(RR.output.text).slice(0, 400), dur: '', icon: 'icon-file-text', c: '#17181a', sIcon: 'icon-check', sc: 'oklch(0.45 0.12 150)', sAnim: 'none', mc: '#3a3c40' }] : [])
          .concat(outLinks.map(l => ({ t: l.split(': ')[0], meta: l.split(': ')[1], dur: '', icon: 'icon-link', c: '#17181a', sIcon: 'icon-check', sc: 'oklch(0.45 0.12 150)', sAnim: 'none', mc: '#3a3c40' }))) };
      v.retryRun = async () => { try { const r = await this.api('POST', '/runs/' + RR.id + '/retry'); this.setState(x => ({ runsData: [r.run, ...x.runsData], runSel: 0 })); this.toast('Retrying as ' + r.run.code, 'info'); } catch (e) { this.toastErr(e); } };
    } else {
      v.run = { id: '—', wf: 'No runs yet', trig: '—', st: 'Queued', sfg: '#55575c', sbg: '#efefec', failed: false, err: '', kpis: [], steps: [] };
    }

    // ---- studio briefs
    v.agentNames = s.agentsData.map(a => a.name);
    const studioNames = ['Video Production', 'Cinematic Studio', 'Animation Studio', 'Advertising Studio', 'Content Studio', 'Audio Studio', 'Automation Studio'];
    v.startBrief = async () => {
      const text = rs.val('brief_text');
      if (!text || text.trim().length < 3) { this.toast('Describe what you want first', 'err'); return; }
      try {
        const r = await this.api('POST', '/briefs', { studio: studioNames[s.brief], item: s.briefItem, brief: text, style: rs.val('brief_style'), agent: rs.val('brief_agent') });
        this.setState(x => ({ brief: null, runsData: [r.run, ...x.runsData], runSel: 0, runFilter: 'All' }));
        this.toast((s.briefItem || 'Job') + ' started · follow it in Runs'); this.go('runs');
      } catch (e) { this.toastErr(e); }
    };

    // ---- cinematic production
    const prod = s.prod;
    if (prod) {
      const scenesDef = prod.scenes || [];
      const shotsAll = s.shots || [];
      v.prod = { title: s.prodTitle, logline: prod.logline, key: 'prod' + prod.id };
      v.setLogline = e => this.saveProdSoon({ logline: e.target.value });
      v.setProdTitle = e => { this.setState({ prodTitle: e.target.value }); this.saveProdSoon({ title: e.target.value }); };
      v.setupSelects = v.setupSelects.map(f => { const cur = f.label === 'Visual style' ? s.style : ((prod.settings || {})[f.label] || f.v); return { ...f, v: cur, key: 'set' + prod.id + f.label, set: e => { const val = e.target.value; if (f.label === 'Visual style') { this.setState({ style: val }); this.saveProdSoon({}); } else this.saveProdSoon({ settings: { ...(this.state.prod.settings || {}), [f.label]: val } }); } }; });
      v.prodToggles = v.prodToggles.map(t => ({ ...t, toggle: () => { t.toggle(); setTimeout(() => this.saveProdSoon({}), 0); } }));
      v.styles = v.styles.map(x => ({ ...x, on: () => { this.setState({ style: x.n }); this.toast('Style set to ' + x.n); setTimeout(() => this.saveProdSoon({}), 0); } }));
      v.cScenes = scenesDef.map(c => ({ n: String(c[0]).padStart(2, '0'), t: c[1], shotCount: shotsAll.filter(x => x.sc === c[0]).length, bd: s.sceneSel === c[0] ? '#17181a' : 'transparent', bg: s.sceneSel === c[0] ? '#f6f5f2' : 'transparent', on: () => { const f = shotsAll.find(x => x.sc === c[0]); this.setState({ sceneSel: c[0], shotSel: f ? f.id : 0 }); } }));
      const SC = scenesDef.find(c => c[0] === s.sceneSel) || scenesDef[0] || [1, 'Scene', 'Location', 'Day'];
      const charsV = s.chars.map((c, i) => ({ n: c.name, i: c.name.slice(0, 2).toUpperCase(), c: c.image ? this.bg(c.image, F[i % 10]) : F[(i * 3 + 1) % 10] }));
      v.scene = { ...v.scene, n: String(SC[0]).padStart(2, '0'), t: SC[1], loc: SC[2], time: SC[3], desc: prod.logline || '', chars: charsV,
        facts: [['Location', SC[2]], ['Time of day', SC[3]], ['Shots', String(shotsAll.filter(x => x.sc === SC[0]).length)], ['Style', s.style]].map(f => ({ k: f[0], v: f[1] })),
        dialogue: (prod.screenplay || []).filter(l => l.kind === 'dialogue').slice(0, 2).map(l => l.t).join('  ') || 'Press Rewrite on the Story tab to draft dialogue.' };
      v.shots = v.shots.map(x => ({ ...x, bg: x.st === 'done' ? (shotsAll.find(q => q.id === x.id) || {}).c : x.bg }));
      const SH = shotsAll.find(x => x.id === s.shotSel) || shotsAll.find(x => x.sc === s.sceneSel) || {};
      v.genShot = () => { if (SH.id !== undefined && SH.st !== 'loading') this.genShot(SH.id); };
      v.genAllShots = async () => { for (const x of this.state.shots.filter(q => q.st === 'none')) await this.genShot(x.id); };
      v.shotBtn = SH.st === 'done' ? 'Regenerate shot' : SH.st === 'loading' ? 'Generating…' : 'Generate shot · 1 credit';
      const lines = prod.screenplay || [];
      const fmt = { heading: ['left', '0', '0', 700, 'uppercase', '#17181a'], action: ['left', '0', '0', 400, 'none', '#3a3c40'], character: ['center', '0', '0', 700, 'uppercase', '#17181a'], paren: ['center', '0', '0', 400, 'none', '#6b6d72'], dialogue: ['left', '22%', '22%', 400, 'none', '#3a3c40'] };
      v.screenplay = lines.length ? lines.map(l => { const f = fmt[l.kind] || fmt.action; return { t: l.t, align: f[0], indent: f[1], indentR: f[2], fw: f[3], tt: f[4], c: f[5] }; }) : [{ t: 'No script yet. Write your logline on the Setup tab, then press Rewrite to draft it with AI.', align: 'left', indent: '0', indentR: '0', fw: 400, tt: 'none', c: '#8a8c91' }];
      v.storyCards = [['lightbulb', 'Story idea', prod.logline || '—'], ['map-pin', 'Locations', [...new Set(scenesDef.map(c => c[2]))].join(' · ')], ['globe', 'World', ((prod.settings || {}).Genre || '') + ' · ' + ((prod.settings || {}).Language || '')], ['list-ordered', 'Structure', scenesDef.length + ' scenes · ' + shotsAll.length + ' shots · ' + ((prod.settings || {}).Duration || '')]].map(x => ({ icon: 'icon-' + x[0], t: x[1], d: x[2] }));
      v.prodStats = [['Scenes', String(scenesDef.length)], ['Shots', String(shotsAll.length)], ['Generated', shotsAll.filter(x => x.st === 'done').length + ' / ' + shotsAll.length], ['Characters', String(s.chars.length)], ['Est. credits', '≈ ' + (shotsAll.length + 8)]].map(p => ({ k: p[0], v: p[1] }));
      v.rewriteLabel = s.rewriting ? 'Writing…' : 'Rewrite';
      v.rewrite = async () => { if (s.rewriting) return; this.setState({ rewriting: true }); try { const r = await this.api('POST', '/production/rewrite'); this.setState({ prod: r.production, rewriting: false }); this.toast('Script drafted'); } catch (e) { this.setState({ rewriting: false }); this.toastErr(e); } };
      const cut = prod.project;
      v.assembleLabel = cut && cut.status === 'Processing' ? 'Rendering ' + (cut.progress || 0) + '%' : cut && cut.status === 'Completed' ? 'Open cut' : 'Assemble cut';
      v.assemble = async () => {
        if (cut && cut.status === 'Completed' && !confirm('Open the finished cut? (Cancel to re-assemble.)') === false) { window.open(cut.url, '_blank'); return; }
        if (cut && cut.status === 'Processing') return;
        try { const r = await this.api('POST', '/production/assemble'); this.setState(x => ({ prod: { ...x.prod, project: r.project } })); this.toast('Assembling your cut · this takes a minute', 'info'); } catch (e) { this.toastErr(e); }
      };
    }

    // ---- characters
    v.characters = s.chars.map((c, i) => ({ name: c.name, desc: c.description || '', look: c.look || '—', costume: c.costume || '—', pers: c.personality || '—', voice: c.voice || '—', used: c.image ? 'reference ready' : 'no reference yet',
      c: c.image ? this.bg(c.image, F[i % 10]) : F[(i * 3) % 10], c2: F[(i * 3 + 1) % 10], c3: F[(i * 3 + 2) % 10],
      edit: async () => {
        const choice = prompt('Edit ' + c.name + ':\n• type a new description to update it\n• type IMAGE to generate a reference image (1 credit)\n• type DELETE to remove', c.description || '');
        if (choice === null) return;
        try {
          if (choice.trim().toUpperCase() === 'IMAGE') { this.toast('Generating reference for ' + c.name, 'info'); await this.api('POST', '/characters/' + c.id + '/image'); }
          else if (choice.trim().toUpperCase() === 'DELETE') { if (confirm('Delete ' + c.name + '?')) await this.api('DELETE', '/characters/' + c.id); }
          else await this.api('PUT', '/characters/' + c.id, { name: c.name, description: choice, look: c.look, costume: c.costume, personality: c.personality, voice: c.voice });
          await this.refresh();
        } catch (e) { this.toastErr(e); }
      } }));
    v.newCharacter = async () => {
      const name = prompt('Character name'); if (!name) return;
      const description = prompt('Who are they? (one or two sentences)') || '';
      const look = prompt('Appearance (hair, face, build)') || '';
      const costume = prompt('Costume / clothing') || '';
      try { await this.api('POST', '/characters', { name, description, look, costume, personality: '', voice: 'Theo' }); await this.refresh(); this.toast(name + ' added · click the card to generate a reference image'); } catch (e) { this.toastErr(e); }
    };
    v.sceneChars = v.scene ? v.scene.chars : [];

    // ---- agents
    const A = s.agentsData;
    v.agents = A.map((a, i) => ({ n: a.name, icon: 'icon-' + (a.icon || 'bot'), d: a.description || '', model: a.model || 'auto', tools: a.tools || [], c: [this.CAT.ai, this.CAT.logic, this.CAT.action, this.CAT.data][i % 4], open: () => this.setState({ agentSel: i, tryReply: '', agTools: this.toolsState(a.tools), agPerms: { run: true, publish: false, spend: true, ...(a.perms || {}) } }) }));
    const AG = s.agentSel !== null ? A[s.agentSel] : null;
    v.agentOpen = !!AG;
    if (AG) {
      const models = [...new Set([AG.model, ...(P.providers || []).filter(p => p.cat === 'Text').map(p => p.model)].filter(Boolean))];
      v.ag = { n: AG.name, icon: 'icon-' + (AG.icon || 'bot'), model: AG.model, sys: AG.system || '', fmt: AG.format || 'Markdown', c: v.agents[s.agentSel].c, key: 'ag' + AG.id, models: models.length ? models : ['auto'] };
      v.saveAgent = async () => {
        const toolNames = { web: 'Web search', brand: 'Brand kit', files: 'Files', images: 'Images', workflows: 'Workflows', api: 'HTTP requests' };
        try {
          const r = await this.api('PUT', '/agents/' + AG.id, { name: AG.name, description: AG.description, icon: AG.icon, system: rs.val('ag_sys'), model: rs.val('ag_model'), format: rs.val('ag_fmt'), tools: Object.keys(s.agTools).filter(k => s.agTools[k]).map(k => toolNames[k]), perms: s.agPerms });
          this.setState(x => ({ agentSel: null, agentsData: x.agentsData.map(q => q.id === r.agent.id ? r.agent : q) })); this.toast('Agent saved');
        } catch (e) { this.toastErr(e); }
      };
      v.tryLabel = s.trying ? 'Thinking…' : 'Run · 1 credit'; v.tryReply = s.tryReply;
      v.tryAgent = async () => {
        const msg = rs.val('ag_try'); if (!msg || s.trying) return;
        this.setState({ trying: true, tryReply: '' });
        try { const r = await this.api('POST', '/agents/' + AG.id + '/run', { message: msg }); this.setState(x => ({ trying: false, tryReply: r.reply + '\n\n— ' + r.provider, runsData: [r.run, ...x.runsData] })); }
        catch (e) { this.setState({ trying: false, tryReply: 'Error: ' + e.message }); }
      };
    }
    v.newAgent = async () => {
      const name = prompt('Name your agent'); if (!name) return;
      try { const r = await this.api('POST', '/agents', { name, description: 'Custom agent', system: 'You are a helpful assistant for ' + R.appName + '.', format: 'Markdown', icon: 'bot', tools: [], perms: { run: true } }); this.setState(x => ({ agentsData: [...x.agentsData, r.agent], agentSel: x.agentsData.length, tryReply: '' })); }
      catch (e) { this.toastErr(e); }
    };

    // ---- repurpose
    const projects = s.projects;
    const src = projects.find(p => p.id === s.rpSourceId) || projects[0];
    v.rpSources = projects.length ? projects.map(p => ({ id: p.id, name: p.name + ' · ' + p.dur })) : [{ id: '', name: 'Render a video in the Studio first' }];
    v.rpSourceId = src ? src.id : '';
    v.setRpSource = e => this.setState({ rpSourceId: parseInt(e.target.value) || null });
    v.rp = src ? { name: src.name, dur: src.dur, meta: src.ratio + ' · MP4', bg: src.thumb ? "url('" + src.thumb + "') center/contain no-repeat, #111" : '#30384a' } : { name: 'No rendered videos yet', dur: '—', meta: 'Render a video in the Studio, then repurpose it here', bg: '#30384a' };
    if (src && src.analysis) { v.rpAnalysis = src.analysis.rows.map(a => ({ t: a[0], v: a[1] })); v.rpTopics = src.analysis.topics; }
    // While the start request is still open (PHP's dev server holds it until the job ends) follow the newest run for this source.
    const rpRun = s.rpRunId ? runs.find(r => r.id === s.rpRunId) : s.rpStarted && src ? runs.find(r => r.kind === 'repurpose' && r.wf === 'Repurpose · ' + src.name && r.id > (s.rpStarted.after || 0)) : null;
    const keys = ['yt', 'shorts', 'reels', 'tiktok', 'li', 'x', 'blog', 'cap', 'thumb'];
    v.rpOutputs = v.rpOutputs.map((o, i) => {
      const step = rpRun ? (rpRun.steps || []).find(x => x.key === keys[i]) : null;
      if (!step) return o;
      const pct = step.status === 'done' ? 100 : step.status === 'run' ? 50 : step.status === 'fail' ? 100 : 5;
      const files = step.files || [];
      return { ...o, pct: pct + '%', barC: step.status === 'fail' ? 'oklch(0.6 0.2 25)' : pct >= 100 ? 'oklch(0.62 0.14 150)' : 'oklch(0.58 0.19 35)',
        st: step.status === 'done' ? 'done · ' + step.meta + ' · open' : step.status === 'fail' ? 'failed · ' + step.meta : step.status === 'run' ? 'generating…' : 'queued',
        sc: step.status === 'done' ? 'oklch(0.45 0.12 150)' : step.status === 'fail' ? 'oklch(0.5 0.18 25)' : '#6b6d72', toggle: files.length ? () => files.forEach(f => window.open(f, '_blank')) : o.toggle };
    });
    v.runRepurpose = async () => {
      if (!src) { this.toast('Render a video in the Studio first', 'err'); return; }
      const outs = keys.filter(k => s.rpSel[k]);
      const after = Math.max(0, ...runs.map(r => r.id)); this.setState({ rpStarted: { after }, rpRunId: null }); this.toast('Repurposing started');
      try { const r = await this.api('POST', '/repurpose', { project_id: src.id, outputs: outs }); this.setState(x => ({ rpRunId: r.run.id, runsData: x.runsData.some(y => y.id === r.run.id) ? x.runsData : [r.run, ...x.runsData], rpProg: null })); this.refresh(); }
      catch (e) { this.setState({ rpStarted: null }); this.toastErr(e); }
    };

    // ---- API & webhooks
    const as = P.apiStats || {};
    v.apiKpis = [['Requests · 24h', n(as.requests)], ['Webhooks delivered', n(as.delivered)], ['Error rate', as.errorRate || '0%'], ['p95 latency', as.p95 || '—']].map(k => ({ k: k[0], v: k[1] }));
    v.hooks = s.hooksData.map(h => ({ url: h.url, dir: 'Outgoing', dIcon: 'icon-arrow-up-right', ev: (h.events || []).join(', '), rate: h.rate, rc: parseFloat(h.rate) < 95 ? 'oklch(0.5 0.18 25)' : '#17181a', st: h.active ? 'Active' : 'Paused', sfg: 'oklch(0.42 0.12 150)', sbg: 'oklch(0.95 0.04 150)',
      on: async () => { const a = prompt('Endpoint ' + h.url + '\nSigning secret: ' + h.secret + '\n\nType TEST to send a test event, or DELETE to remove it.'); if (!a) return;
        try { if (a.trim().toUpperCase() === 'TEST') { const r = await this.api('POST', '/webhooks/' + h.id + '/test'); this.toast('Test delivered · HTTP ' + r.code, r.code >= 200 && r.code < 300 ? 'ok' : 'err'); } else if (a.trim().toUpperCase() === 'DELETE') { await this.api('DELETE', '/webhooks/' + h.id); this.toast('Endpoint removed'); } await this.refresh(); } catch (e) { this.toastErr(e); } } }))
      .concat((P.incoming || []).map(h => ({ url: h.url, dir: 'Incoming', dIcon: 'icon-arrow-down-left', ev: 'runs “' + h.name + '”', rate: '—', rc: '#17181a', st: h.live ? 'Active' : 'Paused', sfg: h.live ? 'oklch(0.42 0.12 150)' : '#55575c', sbg: h.live ? 'oklch(0.95 0.04 150)' : '#efefec', on: () => { try { navigator.clipboard.writeText(h.url); } catch (e) {} this.toast('Incoming URL copied'); } })));
    v.addHook = async () => {
      const url = prompt('Endpoint URL (receives signed JSON POSTs)'); if (!url) return;
      const ev = prompt('Events (comma-separated): video.completed, video.failed, run.completed, run.failed, image.completed — or * for all', 'video.completed, run.completed, run.failed'); if (ev === null) return;
      try { const r = await this.api('POST', '/webhooks', { url, events: ev.split(',').map(x => x.trim()).filter(Boolean) }); await this.refresh(); alert('Endpoint added.\n\nSigning secret (verify X-Reelsmith-Signature = sha256 HMAC of the body):\n' + r.webhook.secret); }
      catch (e) { this.toastErr(e); }
    };
    v.events = (P.events || []).map(e => ({ ...e, dc: e.dir === 'IN' ? 'oklch(0.75 0.1 250)' : 'oklch(0.78 0.13 45)', sc: e.code[0] === '2' ? 'oklch(0.78 0.14 150)' : 'oklch(0.72 0.17 25)' }));
    const MC = { GET: 'oklch(0.5 0.13 250)', POST: 'oklch(0.48 0.13 150)', PUT: 'oklch(0.5 0.13 70)', DELETE: 'oklch(0.5 0.18 25)' };
    v.reqLogs = (P.reqLogs || []).map(r => ({ ...r, mc: MC[r.m] || '#17181a', sc: r.s[0] === '2' ? 'oklch(0.45 0.12 150)' : 'oklch(0.5 0.18 25)' }));
    v.keys = P.keys || [];
    if (v.ep && v.ep.code) v.ep = { ...v.ep, code: v.ep.code.split('https://ai.acme.co').join(location.origin) };
    return v;
  }

  toolsState(tools) {
    const t = tools || [];
    return { web: t.includes('Web search'), brand: t.includes('Brand kit'), files: t.includes('Files'), images: t.includes('Images') || t.includes('Image generation'), workflows: t.includes('Workflows'), api: t.includes('HTTP requests') };
  }
}
