// Laravel integration for the Admin console. Every mutation goes to /admin/* and the
// response replaces the local copy, so what you see is what is stored.
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    const A = window.RS.admin || {};
    this.PAGES = (A.pages || []).map(x => [x.name, x.slug, x.status, x.title, x.desc, x.body]);
    if (!this.PAGES.length) this.PAGES = [['Home', '/', 'Published', '', '', '']];
    const saved = A.settings || {};
    this.state = { ...this.state,
      users: (A.users || []).map((u, i) => this.toUser(u, i)),
      prov: A.providers || [],
      keys: A.apiKeys || [],
      wl: { ...this.state.wl, ...(A.whitelabel || {}) },
      setToggles: { ...this.state.setToggles, twofa: false, ...(saved.toggles || {}) },
      gw: { ...this.state.gw, stripe: true, paypal: true, razorpay: true, paystack: true, bank: true, ...(saved.gateways || {}) },
      rules: saved.creditRules || this.state.rules,
      rtRule: saved.routing ? saved.routing.rule : undefined,
      rtOrder: saved.routing ? saved.routing.order : undefined,
      models: this.state.models.map(m => ({ ...m, on: saved.disabledModels ? !saved.disabledModels.includes(m.id) : m.on })),
      plansData: A.plans || [], projects: A.projects || [], payments: A.payments || [], logRows: A.logs || [], templatesData: A.templates || [],
      screen: window.RS.startScreen || this.state.screen
    };
  }

  componentDidMount() { super.componentDidMount(); this.setState({ tpls: this.tplData() }); }

  toUser(u, i) { return { ...u, c: this.F[(i * 3 + 1) % 10] }; }
  tplData() { return (this.state.templatesData || []).map((t, i) => ({ ...t, c: this.F[(i * 3) % 10] })); }

  async call(fn, okMsg) {
    try { const r = await fn(); if (okMsg) this.toast(okMsg); return r; } catch (e) { this.toast(e.message, 'err'); return null; }
  }
  putUser(u) { this.setState(x => ({ users: x.users.map((y, i) => y.id === u.id ? this.toUser(u, i) : y) })); }
  putProv(p) { this.setState(x => ({ prov: x.prov.some(q => q.id === p.id) ? x.prov.map(q => q.id === p.id ? p : q) : [...x.prov, p] })); }
  saveSetting(key, value, msg) { return this.call(() => rs.put('/admin/settings', { [key]: value }), msg); }

  renderVals() {
    const v = super.renderVals(), s = this.state, A = window.RS.admin || {}, R = window.RS;
    v.appName = R.appName; v.me = R.user || {}; v.logout = () => rs.logout();

    // Overview
    if (A.kpis) v.kpis = A.kpis.map(k => ({ label: k[0], value: k[1], icon: 'icon-' + k[2], delta: k[3], dc: k[4] ? 'oklch(0.45 0.12 150)' : '#8a8c91' }));
    if (A.system) v.sys = A.system.map(x => ({ label: x[0], val: x[1], c: x[2] === 'ok' ? 'oklch(0.62 0.14 150)' : x[2] === 'warn' ? 'oklch(0.75 0.15 70)' : 'oklch(0.6 0.2 25)', tc: x[2] === 'ok' ? '#6b6d72' : x[2] === 'warn' ? 'oklch(0.5 0.13 70)' : 'oklch(0.5 0.18 25)', anim: x[2] === 'ok' ? 'none' : 'rs-pulse 1.4s ease-in-out infinite' }));
    if (A.chart) {
      const days = s.range === '7d' ? 7 : s.range === '90d' ? 90 : 30, series = A.chart.slice(-Math.min(days, 45)), max = Math.max(1, ...series.map(d => d[1]));
      v.chart = series.map(d => ({ h: Math.max(4, d[1] / max * 100) + '%', f: (d[2] / Math.max(1, d[1]) * 100) + '%', tip: d[1] + ' videos · ' + d[0] }));
      v.chartStart = series.length ? series[0][0] : '';
    }
    v.revTotal = A.revenueTotal || '$0'; v.revDelta = A.revenueDelta || '';
    const issues = (A.system || []).filter(x => x[2] !== 'ok').length;
    v.sysLabel = issues ? issues + (issues === 1 ? ' item needs' : ' items need') + ' attention' : 'All systems normal';
    if (A.usage) v.usage = A.usage.map(u => ({ name: u[0], req: u[1], cost: u[2], pct: u[3] + '%', c: u[4] }));
    if (A.revenue) v.rev = A.revenue.map((r, i) => ({ m: r[0], h: r[1] + '%', c: i === A.revenue.length - 1 ? 'oklch(0.58 0.19 35)' : '#d9d7d1' }));
    const icons = { INFO: ['info', '#f3f2ef', '#17181a'], WARNING: ['gauge', 'oklch(0.95 0.05 85)', 'oklch(0.5 0.13 70)'], ERROR: ['circle-alert', 'oklch(0.95 0.035 25)', 'oklch(0.5 0.18 25)'], DEBUG: ['bug', '#f3f2ef', '#17181a'] };
    v.activity = (s.logRows || []).slice(0, 6).map(l => { const ic = icons[l.lvl] || icons.INFO; return { icon: 'icon-' + ic[0], text: l.msg, time: l.ago, bg: ic[1], fg: ic[2] }; });

    // Users
    const wrapUser = u => ({ ...u,
      impersonate: () => this.call(async () => { await rs.post('/admin/users/' + u.id + '/impersonate'); window.location.href = '/studio'; }),
      suspend: u.status === 'Suspended'
        ? () => this.call(async () => { const r = await rs.put('/admin/users/' + u.id, { status: 'Active' }); this.putUser(r.user); }, u.name + ' reactivated')
        : () => this.ask({ title: 'Suspend ' + u.name + '?', body: 'They will be signed out and blocked from creating videos. Their projects and credits are kept.', okLabel: 'Suspend user', danger: false, icon: 'icon-ban', ok: () => this.call(async () => { const r = await rs.put('/admin/users/' + u.id, { status: 'Suspended' }); this.putUser(r.user); this.setState({ confirm: null }); }, u.name + ' suspended') }),
      del: () => this.ask({ title: 'Delete ' + u.name + '?', body: 'This permanently deletes the account, ' + u.videos + ' videos and all media. This cannot be undone.', okLabel: 'Delete permanently', danger: true, icon: 'icon-trash-2', ok: () => this.call(async () => { await rs.del('/admin/users/' + u.id); this.setState(x => ({ users: x.users.filter(y => y.id !== u.id), confirm: null, userId: null })); }, u.name + ' deleted') }) });
    v.users = v.users.map(wrapUser);
    if (v.userOpen) v.cu = wrapUser(v.cu);
    v.applyAdj = () => this.call(async () => {
      const r = await rs.post('/admin/users/' + s.userId + '/credits', { mode: s.adjMode, amount: parseInt(s.adjAmt) || 0, reason: rs.val('adj_reason') });
      this.putUser(r.user);
    }, 'Credits updated');

    // Projects / videos
    const vStat = { All: null, Completed: 'Completed', Processing: 'Processing', Failed: 'Failed', Drafts: 'Draft' };
    v.vids = (s.projects || []).filter(p => !vStat[s.vTab] || p.status === vStat[s.vTab]).map((p, i) => ({ name: p.name, owner: p.owner, status: p.status, provs: p.provs, credits: p.credits, date: p.date, dur: p.dur, c: this.F[i % 10], sfg: this.ST[p.status][0], sbg: this.ST[p.status][1],
      sub: p.status === 'Failed' ? (p.error || 'Render failed') : s.screen === 'projects' ? ((p.scenes || []).length + ' scenes · ' + p.dur) : p.ratio + ' · ' + p.dur, subc: p.status === 'Failed' ? 'oklch(0.5 0.18 25)' : '#6b6d72',
      del: () => this.ask({ title: 'Delete “' + p.name + '”?', body: 'The video file and its project will be removed from storage.', okLabel: 'Delete video', danger: true, icon: 'icon-trash-2', ok: () => this.call(async () => { await rs.del('/admin/projects/' + p.id); this.setState(x => ({ projects: x.projects.filter(q => q.id !== p.id), confirm: null })); }, 'Video deleted') }) }));
    v.vidSub = s.screen === 'projects' ? 'All user projects, including drafts' : (s.projects || []).length.toLocaleString() + ' videos across all users';

    // Providers
    v.provRows = v.provRows.map(p => ({ ...p,
      toggle: () => { if (p.status === 'off') { this.setState({ provEdit: p.id, provAdd: false, provResult: null }); return; } this.call(async () => { const r = await rs.post('/admin/providers/' + p.id + '/toggle'); this.putProv(r.provider); }, p.name + (p.status === 'disabled' ? ' enabled' : ' disabled')); },
      test: async () => { this.setState({ provTest: p.id }); try { const r = await rs.post('/studio/providers/' + p.id + '/test'); this.putProv(r.provider); this.toast(p.name + ': ' + r.result.message, r.result.ok ? 'ok' : 'err'); } catch (e) { this.toast(e.message, 'err'); } this.setState({ provTest: null }); } }));
    const pm = { connected: 'Healthy', rate: 'Rate limited', error: 'Check the API key', disabled: 'Not receiving jobs', off: 'Add an API key to enable' };
    v.provRows = v.provRows.map(p => ({ ...p, statusSub: p.test && p.test !== '—' ? p.test : pm[p.status] }));
    const spend = (s.prov || []).reduce((a, p) => a + (parseFloat(String(p.cost).replace(/[$,]/g, '')) || 0), 0);
    v.pSummary = v.pSummary.map(x => x.label.startsWith('AI spend') ? { ...x, value: '$' + spend.toFixed(2) } : x);
    const pe = s.prov.find(p => p.id === s.provEdit);
    const TYPES = { 'Text AI': 'Text', 'Image AI': 'Image', 'Video AI': 'Video', 'Voice AI': 'Voice' };
    v.pd = { ...v.pd, type: pe ? pe.cat + ' AI' : 'Text AI', key: '',
      models: pe ? (pe.models && pe.models.length ? pe.models : [pe.model]).filter(Boolean).map(m => ({ id: m, tier: m === pe.model ? 'Default' : '', on: true })) : v.pd.models,
      rmsg: s.provResultMsg || v.pd.rmsg, keyErr: pe && pe.status === 'error' && s.provResult !== 'ok',
      test: async () => {
        if (!pe) { this.toast('Save the provider first, then test it', 'info'); return; }
        this.setState({ provResult: 'testing' });
        try {
          const key = rs.val('pd_key');
          if (key) { const u = await rs.put('/admin/providers/' + pe.id, { api_key: key, base_url: rs.val('pd_url') }); this.putProv(u.provider); }
          const r = await rs.post('/studio/providers/' + pe.id + '/test'); this.putProv(r.provider);
          this.setState({ provResult: r.result.ok ? 'ok' : 'err', provResultMsg: (r.result.ok ? 'Connection successful · ' : 'Connection failed · ') + r.result.message });
        } catch (e) { this.setState({ provResult: 'err', provResultMsg: e.message }); }
      },
      save: () => this.call(async () => {
        const body = { api_key: rs.val('pd_key') || undefined, base_url: rs.val('pd_url'), category: TYPES[rs.val('pd_type')] || 'Text', models: rs.checked('pd_model'), priority: { '1 · Primary': 10, '2 · Fallback': 50, '3 · Last resort': 90 }[rs.val('pd_priority')] || 50 };
        const r = pe ? await rs.put('/admin/providers/' + pe.id, { ...body, status: 'connected' }) : await rs.post('/admin/providers', { ...body, preset: s.preset });
        this.putProv(r.provider); this.setState({ provEdit: null, provAdd: false, provResult: null, provResultMsg: null });
      }, 'Provider saved') };

    // Models (catalog on/off is stored as a setting)
    v.models = v.models.map(m => ({ ...m, toggle: () => { const models = s.models.map(q => q.idx === m.idx ? { ...q, on: !q.on } : q); this.setState({ models }); this.saveSetting('disabledModels', models.filter(q => !q.on).map(q => q.id)); } }));

    // Templates
    v.tpls = (s.tpls || []).map(t => ({ ...t, sfg: this.ST[t.status][0], sbg: this.ST[t.status][1],
      dup: () => this.call(async () => { const r = await rs.post('/admin/templates/' + t.id + '/duplicate'); this.setState(x => ({ templatesData: [...x.templatesData, r.template] }), () => this.setState({ tpls: this.tplData() })); }, 'Template duplicated'),
      del: () => this.ask({ title: 'Delete “' + t.name + '”?', body: 'Users will no longer see this template. Videos already created from it are not affected.', okLabel: 'Delete template', danger: true, icon: 'icon-trash-2', ok: () => this.call(async () => { await rs.del('/admin/templates/' + t.id); this.setState(x => ({ templatesData: x.templatesData.filter(q => q.id !== t.id), confirm: null }), () => this.setState({ tpls: this.tplData() })); }, 'Template deleted') }) }));

    // Credits
    if (A.creditKpis) v.creditKpis = A.creditKpis.map(k => ({ label: k[0], value: k[1], sub: k[2], sc: k[3] || '#6b6d72' }));
    const lim = (A.settings && A.settings.limits) || {};
    v.limits = v.limits.map((l, i) => ({ ...l, name: 'limit_' + i, v: lim['limit_' + i] !== undefined ? lim['limit_' + i] : l.v }));
    if (A.adjustments) v.adjustments = A.adjustments.map(a => ({ user: a[0], reason: a[1], amt: a[2], c: a[2].startsWith('+') ? 'oklch(0.45 0.12 150)' : 'oklch(0.5 0.18 25)' }));

    // Plans
    const PD = s.plansData || [];
    v.plans = PD.map((p, i) => { const pop = !!p.popular; return { name: p.name, price: '$' + p.price, credits: p.credits.toLocaleString(), videos: p.videos, storage: p.storage, api: p.api ? 'Yes' : '—', features: p.features, subs: String(p.subs), popular: pop,
      bg: pop ? '#111214' : '#fff', fg: pop ? '#fff' : '#17181a', bd: pop ? '#111214' : '#e8e7e3', tile: pop ? '#1d1f23' : '#f6f5f2', btnBd: pop ? '#3a3c42' : '#e1e0dc',
      edit: () => this.setState({ planEdit: i }),
      del: () => this.ask({ title: 'Delete the ' + p.name + ' plan?', body: p.subs + ' subscribers will move to the Free plan.', okLabel: 'Delete plan', danger: true, icon: 'icon-trash-2', ok: () => this.call(async () => { await rs.del('/admin/plans/' + p.id); this.setState(x => ({ plansData: x.plansData.filter(q => q.id !== p.id), confirm: null })); }, p.name + ' plan deleted') }) }; });
    const cur = s.planEdit === 'new' ? null : PD[s.planEdit];
    const featList = ['Paid AI models', 'Remove watermark', 'Custom voices', 'API access', 'Priority rendering', 'Team seats', 'White-label exports'];
    if (v.planOpen) {
      v.pe = { title: cur ? 'Edit ' + cur.name : 'Create plan', name: cur ? cur.name : '', price: cur ? String(cur.price) : '', credits: cur ? String(cur.credits) : '', videos: cur ? cur.videos : '', storage: cur ? cur.storage : '',
        feats: featList.map((f, j) => { const k = s.planEdit + ':' + j; const on = s.planFeats[k] !== undefined ? s.planFeats[k] : !!(cur && cur.features.includes(f)); return { label: f, ...this.tog(on), toggle: () => this.setState(x => ({ planFeats: { ...x.planFeats, [k]: !on } })) }; }) };
      v.savePlan = () => this.call(async () => {
        const feats = v.pe.feats.filter(f => f.trk === '#17181a').map(f => f.label).concat(cur ? cur.features.filter(f => !featList.includes(f)) : []);
        const body = { name: rs.val('plan_name'), price: parseFloat(rs.val('plan_price')) || 0, credits: parseInt(String(rs.val('plan_credits')).replace(/,/g, '')) || 0, videos: rs.val('plan_videos') || 'Unlimited', storage_gb: parseInt(rs.val('plan_storage')) || 1, features: feats, api_access: feats.includes('API access') };
        const r = cur ? await rs.put('/admin/plans/' + cur.id, body) : await rs.post('/admin/plans', body);
        this.setState(x => ({ plansData: cur ? x.plansData.map(q => q.id === cur.id ? r.plan : q) : [...x.plansData, r.plan], planEdit: null, planFeats: {} }));
      }, 'Plan saved');
    }

    // Payments
    const GW = ['stripe', 'paypal', 'razorpay', 'paystack', 'bank'];
    v.gateways = v.gateways.map((g, i) => ({ ...g, toggle: () => { const gw = { ...s.gw, [GW[i]]: !s.gw[GW[i]] }; this.setState({ gw }); this.saveSetting('gateways', gw, g.name + (gw[GW[i]] ? ' enabled' : ' disabled')); } }));
    const PS = A.gatewayStatus || {};
    v.gateways = v.gateways.map((g, i) => GW[i] === 'stripe' ? { ...g, mode: PS.stripe ? 'Connected · Stripe Checkout' : 'Add your secret key in Settings → Payments' }
      : GW[i] === 'paypal' ? { ...g, mode: PS.paypal ? 'Connected · PayPal Orders' : 'Add client ID + secret in Settings → Payments' }
      : GW[i] === 'razorpay' ? { ...g, mode: PS.razorpay ? 'Connected · Payment Links' : 'Add key ID + secret in Settings → Payments' }
      : GW[i] === 'paystack' ? { ...g, mode: PS.paystack ? 'Connected · Paystack Checkout' : 'Add your secret key in Settings → Payments' }
      : { ...g, mode: PS.bank ? 'Manual approval below' : 'Add bank details in Settings → Payments' }).map(g => ({ ...g, configure: () => { this.setState({ setTab: 'Payments' }); this.go('settings'); } }));
    const settle = (t, received) => this.ask({ title: (received ? 'Approve ' : 'Reject ') + t.id + '?', body: received ? 'Confirm the transfer of ' + t.amt + ' arrived. The plan and credits are applied to ' + t.user + ' right away.' : 'Mark this transfer as not received. ' + t.user + ' is emailed.', okLabel: received ? 'Approve payment' : 'Reject', danger: !received, icon: 'icon-landmark',
      ok: () => this.call(async () => { const r = await rs.post('/admin/payments/' + t.id + '/settle', { received }); this.setState(x => ({ payments: x.payments.map(q => q.id === t.id ? r.payment : q), confirm: null })); }, received ? 'Payment approved' : 'Payment rejected') });
    v.txns = (s.payments || []).map(t => ({ ...t, sfg: (this.ST[t.st] || this.ST.Pending)[0], sbg: (this.ST[t.st] || this.ST.Pending)[1], approve: () => settle(t, true), reject: () => settle(t, false) }));

    // API keys
    v.keys = s.keys.map(k => ({ ...k, active: !k.revoked, op: k.revoked ? 0.5 : 1,
      revoke: () => this.ask({ title: 'Revoke “' + k.name + '”?', body: 'Any integration using this key will immediately stop working.', okLabel: 'Revoke key', danger: true, icon: 'icon-key-round', ok: () => this.call(async () => { const r = await rs.del('/admin/api-keys/' + k.id); this.setState(x => ({ keys: x.keys.map(q => q.id === k.id ? r.key : q), confirm: null })); }, 'Key revoked') }) }));
    v.createKey = () => this.call(async () => { const r = await rs.post('/admin/api-keys', { name: 'Key ' + (s.keys.length + 1) }); this.setState(x => ({ newKey: r.plain, keys: [r.key, ...x.keys] })); });

    // White label
    v.saveWl = () => this.call(async () => { await rs.put('/admin/whitelabel', { whitelabel: s.wl }); this.setState({ wlSaved: JSON.stringify(s.wl) }); }, 'White-label settings published');
    v.previewWl = () => window.open('/', '_blank');

    // Pages CMS
    v.pages = this.PAGES.map((p, i) => ({ name: p[0], slug: p[1], st: p[2], sc: p[2] === 'Draft' ? '#8a8c91' : 'oklch(0.45 0.12 150)', bg: s.page === i ? '#f3f2ef' : 'transparent', on: () => this.setState({ page: i }) }));
    const P = this.PAGES[s.page] || this.PAGES[0];
    v.page = { h: P[3], body: P[5] || P[4], meta: P[3], desc: P[4], slug: P[1] };

    // Settings: give every text/select field a stable name and load saved values
    const vals = (A.settings && A.settings.values) || {};
    const slug = t => 'set_' + String(t).toLowerCase().replace(/[^a-z0-9]+/g, '_');
    const txt = (label, hint, secret) => ({ label, hint, isText: true, v: '', ff: "'Geist Mono',monospace", inputType: secret ? 'password' : 'text' });
    const extra = {
      'Email (SMTP)': [txt('SMTP username', ''), txt('SMTP password', 'Stored encrypted', true)],
      'Payments': [txt('Stripe secret key', 'sk_live_… · stored encrypted', true), txt('Stripe webhook secret', 'whsec_… · endpoint: ' + location.origin + '/webhooks/stripe', true),
        txt('PayPal client ID', ''), txt('PayPal secret', 'Stored encrypted', true), { label: 'PayPal mode', hint: '', isSelect: true, v: 'Live', options: ['Live', 'Sandbox'] },
        txt('Razorpay key ID', 'rzp_live_…'), txt('Razorpay key secret', 'Stored encrypted', true), txt('Razorpay webhook secret', 'Event payment_link.paid · endpoint: ' + location.origin + '/webhooks/razorpay', true),
        txt('Paystack secret key', 'sk_live_… · webhook: ' + location.origin + '/webhooks/paystack', true),
        { ...txt('Bank transfer details', 'Shown to buyers who pick bank transfer. Separate lines with |, e.g. Bank: Acme Bank | Account: 0123456789 | Sort code: 00-11-22'), ff: 'inherit' }]
    };
    v.setGroups = v.setGroups.map(g => extra[g.title] ? { ...g, fields: [...g.fields, ...extra[g.title]] } : g);
    v.setGroups = v.setGroups.map(g => ({ ...g, fields: g.fields.map(f => { const n = slug(g.title + ' ' + f.label); return f.isToggle ? f : { ...f, name: n, v: vals[n] !== undefined ? vals[n] : f.v }; }) }));
    if (A.health) v.setGroups = v.setGroups.map(g => ({ ...g, fields: g.fields.map(f => f.isStatus && A.health[slug(g.title + ' ' + f.label)] ? { ...f, v: A.health[slug(g.title + ' ' + f.label)][0], sc: A.health[slug(g.title + ' ' + f.label)][1] ? 'oklch(0.45 0.12 150)' : 'oklch(0.5 0.18 25)' } : f) }));
    v.setGroups = v.setGroups.map(g => ({ ...g, fields: g.fields.map(f => f.isToggle ? { ...f, toggle: () => { f.toggle(); this.saveToggles(); } } : f) }));

    // Generic "Save changes" buttons (routing, credits, pages, settings)
    v.toastSaved = () => {
      const scr = s.screen;
      if (scr === 'routing') return this.saveSetting('routing', { rule: s.rtRule || {}, order: s.rtOrder || {} }, 'Routing saved');
      if (scr === 'credits') { const limits = {}; v.limits.forEach(l => limits[l.name] = rs.val(l.name)); return this.saveSetting('creditRules', s.rules).then(() => this.saveSetting('limits', limits, 'Credit settings saved')); }
      if (scr === 'pages') return this.call(async () => { const r = await rs.put('/admin/pages', { slug: P[1], title: rs.val('page_h'), body: rs.val('page_body'), meta: rs.val('page_meta'), desc: rs.val('page_desc') }); this.PAGES = r.pages.map(x => [x.name, x.slug, x.status, x.title, x.desc, x.body]); this.forceUpdate(); }, 'Page saved');
      if (scr === 'settings') { const values = { ...vals }; document.querySelectorAll('[name^="set_"]').forEach(el => values[el.name] = el.value); A.settings = { ...(A.settings || {}), values }; return this.saveSetting('values', values, 'Settings saved'); }
      this.toast('Changes saved');
    };

    // Logs
    const lc = { INFO: 'oklch(0.75 0.1 250)', WARNING: 'oklch(0.8 0.13 75)', ERROR: 'oklch(0.72 0.17 25)', DEBUG: '#8a8c91' };
    v.logs = (s.logRows || []).filter(l => s.logLvl === 'All' || l.lvl === s.logLvl.toUpperCase()).map(l => ({ ...l, c: lc[l.lvl] || lc.INFO }));
    return v;
  }

  saveToggles() { setTimeout(() => this.saveSetting('toggles', this.state.setToggles), 0); }
}
