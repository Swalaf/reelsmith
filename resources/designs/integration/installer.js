// Laravel integration for the web installer: real server checks, database test,
// provider key tests and the actual install (writes .env, migrates, seeds, creates the admin).
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    const I = window.RS.install || {};
    this.form = { admin_name: '', admin_email: '', db_driver: 'mysql', ...(I.form || {}) };
    this.state = { ...this.state, reqs: I.requirements || [], reqFixed: !(I.requirements || []).some(r => !r.ok), db: 'idle', dbMsg: '', pw: '', pw2: '', driver: 'Local disk', ai: { or: 'idle', cf: 'idle', edge: 'done', hf: 'idle' }, aiMsg: {}, cron: I.cron && I.cron.ok ? 'ok' : 'pending', cronInfo: I.cron || null, installing: false, done: false, instErr: '', dbDriver: 'MySQL' };
  }

  componentDidMount() {
    this.onR = () => this.setState({ w: window.innerWidth });
    window.addEventListener('resize', this.onR);
    this.iv = setInterval(() => {
      const s = this.state;
      if (s.step !== 9 || s.instErr) return;
      const cap = s.done ? 100 : 92, p = Math.min(cap, s.inst + (s.done ? 4 : 0.6));
      if (p !== s.inst) this.setState({ inst: p });
      if (p >= 100 && !this.finished) { this.finished = true; setTimeout(() => this.setState({ step: 10 }), 600); }
    }, 100);
  }

  chips(list, cur, fn) { return list.map(l => { const a = l === cur; return { label: l, on: () => fn(l), segBg: a ? '#fff' : 'transparent', segSh: a ? '0 1px 2px rgba(0,0,0,.08)' : 'none' }; }); }
  snapshot() { document.querySelectorAll('[name]').forEach(el => { this.form[el.name] = el.type === 'checkbox' ? el.checked : el.value; }); }
  remember = e => { if (e && e.target && e.target.name) this.form[e.target.name] = e.target.value; };

  dbPayload() {
    this.snapshot();
    const f = this.form, sqlite = this.state.dbDriver === 'SQLite';
    return sqlite ? { driver: 'sqlite', database: f.db_file || '' } : { driver: 'mysql', host: f.db_host, port: f.db_port, database: f.db_database, username: f.db_username, password: f.db_password, prefix: f.db_prefix };
  }

  async install() {
    this.snapshot();
    const s = this.state, f = this.form;
    this.finished = false;
    this.setState({ step: 9, inst: 0, done: false, instErr: '' });
    try {
      const r = await rs.post('/install/run', {
        db: this.dbPayload(),
        app: { name: f.app_name, url: f.app_url, timezone: f.app_timezone, currency: f.app_currency, mode: s.mode, purchase_code: f.purchase_code },
        admin: { name: f.admin_name, email: f.admin_email, password: s.pw, password_confirmation: s.pw2 },
        storage: { driver: s.driver, endpoint: f.s3_endpoint, bucket: f.s3_bucket, key: f.s3_key, secret: f.s3_secret, region: f.s3_region, url: f.s3_url },
        providers: { or: f.ai_or, cf: f.ai_cf, hf: f.ai_hf },
        ffmpeg_path: f.ffmpeg_path
      });
      this.result = r;
      this.setState({ done: true });
    } catch (e) {
      this.setState({ instErr: e.message });
    }
  }

  renderVals() {
    const v = super.renderVals(), s = this.state, I = window.RS.install || {}, f = this.form;
    v.appName = f.app_name || window.RS.appName; v.appUrl = f.app_url || I.appUrl;
    v.form = f; v.remember = this.remember;
    v.basePath = I.basePath; v.localPath = I.localPath; v.localFree = I.localFree;

    // Requirements from the server
    const rows = s.reqs.map(r => ({ label: r.label, val: r.value, ...(s.reqChecking ? this.spin() : r.ok ? this.ok() : this.bad()) }));
    v.reqs = rows;
    v.reqFail = !s.reqChecking && s.reqs.some(r => !r.ok && r.key === 'storage');
    v.recheck = async () => {
      this.setState({ reqChecking: true });
      try { const r = await rs.post('/install/requirements'); this.setState({ reqs: r.requirements, reqChecking: false, reqFixed: !r.requirements.some(x => !x.ok) }); }
      catch (e) { this.setState({ reqChecking: false }); }
    };

    // Database
    const sqlite = s.dbDriver === 'SQLite';
    v.dbDrivers = this.chips(['MySQL', 'SQLite'], s.dbDriver, x => { this.snapshot(); this.setState({ dbDriver: x, db: 'idle' }); });
    const dbBad = s.db === 'err';
    const defs = sqlite ? [['db_file', 'Database file', f.db_file || I.sqlitePath, 'text', I.sqlitePath]]
      : [['db_host', 'Host', f.db_host || '127.0.0.1'], ['db_port', 'Port', f.db_port || '3306'], ['db_database', 'Database name', f.db_database || ''], ['db_username', 'Username', f.db_username || ''], ['db_password', 'Password', f.db_password || '', 'password'], ['db_prefix', 'Table prefix', f.db_prefix || '', 'text', 'optional']];
    v.dbFields = defs.map(d => ({ name: d[0], label: d[1], v: d[2], type: d[3] || 'text', ph: d[4] || '', bd: dbBad && (d[0] === 'db_password' || d[0] === 'db_username' || sqlite) ? 'oklch(0.7 0.15 25)' : '#e1e0dc' }));
    v.db = { ...v.db, msg: s.dbMsg };
    v.testDb = async () => {
      this.setState({ db: 'testing' });
      try { const r = await rs.post('/install/database', this.dbPayload()); this.setState({ db: 'ok', dbMsg: r.message }); }
      catch (e) { this.setState({ db: 'err', dbMsg: e.message }); }
    };

    // Storage
    const s3Keys = ['s3_endpoint', 's3_bucket', 's3_key', 's3_secret', 's3_region', 's3_url'];
    v.s3Fields = v.s3Fields.map((x, i) => ({ ...x, name: s3Keys[i], v: f[s3Keys[i]] !== undefined ? f[s3Keys[i]] : '' }));

    // AI providers: test the key for real
    const ids = ['or', 'cf', 'edge', 'hf'];
    v.aiRows = v.aiRows.map((a, i) => { const id = ids[i]; return { ...a, inputName: 'ai_' + id, key: f['ai_' + id] || '', tier: s.aiMsg[id] || a.tier,
      connect: async () => {
        if (s.ai[id] === 'done' || !a.needsKey) return;
        this.snapshot();
        const key = f['ai_' + id];
        if (!key) { this.setState(x => ({ aiMsg: { ...x.aiMsg, [id]: 'Paste an API key first' } })); return; }
        this.setState(x => ({ ai: { ...x.ai, [id]: 'testing' } }));
        try { const r = await rs.post('/install/provider', { slug: id, key }); this.setState(x => ({ ai: { ...x.ai, [id]: r.ok ? 'done' : 'idle' }, aiMsg: { ...x.aiMsg, [id]: r.message } })); }
        catch (e) { this.setState(x => ({ ai: { ...x.ai, [id]: 'idle' }, aiMsg: { ...x.aiMsg, [id]: e.message } })); }
      } }; });

    // FFmpeg
    const ff = I.ffmpeg || {};
    v.ffPath = f.ffmpeg_path || ff.path || '/usr/bin/ffmpeg';
    v.ffRows = [['Version', ff.version || 'not found', ff.version ? '#17181a' : 'oklch(0.5 0.18 25)'], ['Binary', ff.path || '—', '#17181a'], ['Hardware accel', 'CPU (libx264)', 'oklch(0.5 0.13 70)'], ['Without FFmpeg', 'renders complete without a file', '#6b6d72']].map(x => ({ k: x[0], v: x[1], c: x[2] }));

    // Cron & queue: live check; not blocking (you can finish setting it up after install)
    const ci = s.cronInfo || {};
    v.cronRows = [['Scheduler heartbeat', ci.cron || 'waiting for first run…', !!ci.cronOk], ['Queue worker', ci.queue || 'no worker detected', !!ci.queueOk]].map(r => ({ label: r[0], val: r[1], ...(s.cron === 'checking' ? this.spin() : r[2] ? this.ok() : { bg: 'oklch(0.95 0.05 85)', fg: 'oklch(0.5 0.13 70)', icon: 'icon-clock', anim: 'none', vc: 'oklch(0.5 0.13 70)' }) }));
    v.detectCron = async () => { this.setState({ cron: 'checking' }); try { const r = await rs.post('/install/cron'); this.setState({ cron: r.ok ? 'ok' : 'pending', cronInfo: r }); } catch (e) { this.setState({ cron: 'pending' }); } };
    if (this.KEYS[s.step] === 'cron') v.blockedMsg = '';
    if (this.KEYS[s.step] === 'admin') { this.snapshot(); if (!f.admin_email || !f.admin_name) v.blockedMsg = v.blockedMsg || 'Enter a name and email'; }
    if (v.blockedMsg) { v.nextBg = '#b9b8b3'; v.nextCursor = 'not-allowed'; } else { v.nextBg = 'oklch(0.58 0.19 35)'; v.nextCursor = 'pointer'; }

    // Install
    v.instLog = v.instLog.map(l => ({ ...l, msg: l.msg.replace('admin@acme.co', f.admin_email || 'admin').replace('(64 tables)', '') }));
    v.instErr = s.instErr;
    v.retryInstall = () => this.setState({ step: 8, instErr: '', inst: 0 });
    const res = this.result || {};
    v.summary = [{ k: 'Admin', v: f.admin_email || '—' }, { k: 'Storage', v: s.driver }, { k: 'AI providers', v: (res.providers != null ? res.providers : Object.values(s.ai).filter(x => x === 'done').length) + ' connected' }, { k: 'Queue', v: res.queue || 'database' }];
    v.next = () => {
      if (v.blockedMsg) return;
      this.snapshot();
      if (s.step === 8) this.install(); else this.setState({ step: s.step + 1 });
    };
    v.back = () => { this.snapshot(); if (s.step > 0) this.setState({ step: s.step - 1 }); };
    return v;
  }
}
