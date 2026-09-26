// Laravel integration for the marketing site, auth screens, checkout and onboarding.
class Component extends DesignComponent {
  PATHS = {home:'/',features:'/features',pricing:'/pricing',providers:'/providers',templates:'/templates',template:'/templates',docs:'/docs',contact:'/contact',about:'/about',legal:'/legal',changelog:'/changelog',checkout:'/checkout',login:'/login',register:'/register',forgot:'/forgot-password',reset:'/reset-password',verify:'/verify',twofa:'/two-factor',onboarding:'/onboarding',e404:'/404',e500:'/500',maintenance:'/maintenance',suspended:'/suspended'};

  constructor(p) {
    super(p);
    const R = window.RS;
    if (R.templates && R.templates.length) this.TPL = R.templates.map(t => [t.name, t.cat, t.dur, t.ratio]);
    this.state = { ...this.state, page: R.startPage || 'home', legal: R.legal || this.state.legal, tpl: Math.min(R.tpl || 0, this.TPL.length - 1),
      rpw: '', rpw2: '', loginErr: false, loginMsg: '', formErr: '', forgotEmail: '', coPlan: this.coIndex(R.checkoutPlan),
      paid: R.checkoutResult === 'paid', declined: R.checkoutResult === 'failed', vcode: '', vErr: '', resent: false };
  }

  componentDidMount() {
    super.componentDidMount();
    try { history.replaceState({ page: this.state.page }, '', location.pathname + location.search); } catch (e) {}
    this.onPop = e => { const p = (e.state && e.state.page) || window.RS.startPage || 'home'; this.setState({ page: p, menu: false }); };
    window.addEventListener('popstate', this.onPop);
  }
  componentWillUnmount() { super.componentWillUnmount(); window.removeEventListener('popstate', this.onPop); }

  paidPlans() { return (window.RS.plans || []).filter(p => p.price > 0); }
  coIndex(slug) { const i = this.paidPlans().findIndex(p => p.slug === slug); return i < 0 ? Math.min(1, Math.max(0, this.paidPlans().length - 1)) : i; }

  go = p => {
    if (p === 'checkout' && !window.RS.user) { this.pendingCheckout = true; p = 'register'; }
    this.setState({ page: p, menu: false });
    let path = this.PATHS[p] || '/';
    if (p === 'legal') path = '/legal/' + this.state.legal;
    if (p === 'reset' && window.RS.resetToken) path = '/reset-password/' + window.RS.resetToken;
    try { if (location.pathname !== path) history.pushState({ page: p }, '', path); } catch (e) {}
    window.scrollTo(0, 0);
  };

  after(user) { window.location.href = (user && user.role === 'admin') ? '/admin' : '/studio'; }

  renderVals() {
    const v = super.renderVals(), s = this.state, R = window.RS;
    const busy = (k, on) => this.setState({ [k]: on });

    // Plans come from the database (Admin → Plans).
    const plans = R.plans || [];
    const money = n => '$' + (Math.round(n * 100) / 100).toLocaleString();
    const planCard = (pl, i) => {
      const pop = !!pl.popular, price = s.yearly ? Math.round(pl.price * 0.8) : pl.price;
      return { name: pl.name, price: '$' + price, per: '/ month', desc: pl.desc || '', feats: [pl.credits.toLocaleString() + ' credits / month', pl.videos === 'Unlimited' ? 'Unlimited videos' : pl.videos + ' videos', ...pl.features.slice(0, 2)], pop,
        note: pl.price === 0 ? 'Free forever' : s.yearly ? 'billed $' + price * 12 + ' yearly' : 'billed monthly',
        bg: pop ? '#111214' : '#fff', fg: pop ? '#fff' : '#17181a', bd: pop ? '#111214' : '#e8e7e3', btnBg: pop ? 'oklch(0.58 0.19 35)' : '#fff', btnFg: pop ? '#fff' : '#17181a', btnBd: pop ? '0' : '1px solid #d6d4ce',
        cta: pl.price === 0 ? 'Start Creating' : 'Choose ' + pl.name,
        go: pl.price === 0 ? v.go.register : () => { this.setState({ coPlan: this.paidPlans().findIndex(x => x.id === pl.id), paid: false }); this.go('checkout'); } };
    };
    if (plans.length) {
      v.plans = plans.map(planCard);
      v.planNames = plans.map(p => p.name);
      v.plansTeaser = [...plans.slice(0, 3).map(planCard), v.plansTeaser[v.plansTeaser.length - 1]];
    }

    // Checkout: real order against the chosen plan (test-mode gateway, see CheckoutController).
    const paid = this.paidPlans();
    if (paid.length) {
      const cp = paid[Math.min(s.coPlan, paid.length - 1)];
      const sub = s.yearly ? cp.price * 12 : cp.price, disc = s.yearly ? sub * 0.2 : 0, total = sub - disc;
      v.coPlans = paid.map((x, i) => ({ name: x.name, d: x.credits.toLocaleString() + ' credits · ' + x.videos + ' videos', price: '$' + (s.yearly ? Math.round(x.price * 0.8) : x.price) + '/mo', bd: s.coPlan === i ? '#17181a' : '#e1e0dc', dot: s.coPlan === i ? '#17181a' : 'transparent', on: () => this.setState({ coPlan: i }) }));
      const pay = R.payments || {};
      v.co = { ...v.co, card: v.co.card && !pay.stripe, plan: cp.name, credits: cp.credits.toLocaleString(), sub: '$' + sub.toFixed(2), disc: '−$' + disc.toFixed(2), total: '$' + total.toFixed(2), payLabel: s.paying ? 'Processing…' : 'Pay $' + total.toFixed(2), declined: !!s.declined };
      v.pay = async () => {
        if (s.paying) return;
        busy('paying', true);
        try {
          const r = await rs.post('/checkout', { plan_id: cp.id, cycle: s.yearly ? 'yearly' : 'monthly', method: s.coMethod, coupon: rs.val('coupon') });
          if (r.redirect) { window.location.href = r.redirect; return; }
          window.RS.user = r.user;
          this.setState({ paying: false, paid: true, declined: false });
        } catch (e) {
          if (e.status === 401) { this.go('login'); return; }
          this.setState({ paying: false, declined: true });
          if (e.status === 422) alert(e.message);
        }
      };
    }
    v.meEmail = R.user ? R.user.email : (s.regEmail || 'your inbox');

    // Contact form → contact_messages table.
    v.sendContact = async () => {
      if (s.sending) return;
      busy('sending', true);
      try {
        await rs.post('/contact', { name: rs.val('contact_name'), email: rs.val('contact_email'), topic: rs.val('contact_topic'), message: rs.val('contact_message') });
        this.setState({ sending: false, sent: true });
      } catch (e) { this.setState({ sending: false }); alert(e.message); }
    };

    // Login
    v.doLogin = async () => {
      if (s.logging) return;
      this.setState({ logging: true, loginErr: false });
      try {
        const r = await rs.post('/login', { email: rs.val('login_email'), password: rs.val('login_password'), remember: !!rs.val('login_remember') });
        window.RS.csrf = r.csrf || window.RS.csrf;
        if (r.twoFactor) { this.setState({ logging: false }); this.go('twofa'); return; }
        this.after(r.user);
      } catch (e) {
        if (e.status === 403 && e.data && e.data.suspended) { this.setState({ logging: false }); this.go('suspended'); return; }
        this.setState({ logging: false, loginErr: true });
      }
    };

    // Register → verify → onboarding
    v.doRegister = async () => {
      if (v.rpwMismatch || s.registering) return;
      this.setState({ registering: true });
      try {
        const r = await rs.post('/register', { name: rs.val('register_name'), email: rs.val('register_email'), password: s.rpw, password_confirmation: s.rpw2 });
        window.RS.user = r.user; window.RS.csrf = r.csrf || window.RS.csrf;
        this.setState({ registering: false, regEmail: r.user.email });
        if (this.pendingCheckout) { this.pendingCheckout = false; this.go('checkout'); } else this.go(r.verify ? 'verify' : 'onboarding');
      } catch (e) { this.setState({ registering: false }); alert(e.message); }
    };
    // Email verification: 6-digit code typed into the hidden input behind the boxes
    const digits = (s.vcode || '').split('');
    v.vcode = s.vcode; v.vErr = s.vErr;
    v.setVcode = e => this.setState({ vcode: e.target.value.replace(/\D/g, '').slice(0, 6), vErr: '' });
    v.codeBoxes = [0, 1, 2, 3, 4, 5].map(i => ({ v: digits[i] || '', bd: i === Math.min(digits.length, 5) ? '#17181a' : '#e1e0dc' }));
    v.resendNote = s.resent ? ' · sent' : '';
    v.resendCode = async () => { try { await rs.post('/verify/resend'); this.setState({ resent: true }); } catch (e) { alert(e.message); } };
    v.doVerify = async () => {
      if (s.page !== 'verify') { this.after(window.RS.user); return; }
      if (window.RS.user && window.RS.user.verified) { this.go('onboarding'); return; }
      try { const r = await rs.post('/verify', { code: s.vcode }); if (r.user) window.RS.user = r.user; this.go('onboarding'); }
      catch (e) { this.setState({ vErr: e.message }); }
    };

    // Forgot / reset password (Laravel password broker; emails go through the configured mailer)
    v.forgotEmail = s.forgotEmail || 'that address';
    v.doForgot = async () => {
      const email = rs.val('forgot_email');
      try { await rs.post('/forgot-password', { email }); } catch (e) { if (e.status === 422) { alert(e.message); return; } }
      this.setState({ forgotSent: true, forgotEmail: email });
    };
    v.resetEmail = R.resetEmail || 'your account';
    v.doReset = async () => {
      if (v.rpwMismatch) return;
      try {
        await rs.post('/reset-password', { token: R.resetToken, email: R.resetEmail, password: s.rpw, password_confirmation: s.rpw2 });
        this.setState({ resetDone: true });
      } catch (e) { alert(e.message); }
    };

    // Onboarding saves the use-cases and first topic, then opens the Studio.
    const finish = async (skip) => {
      try { if (!skip) await rs.post('/onboarding', { uses: s.obUses, topic: rs.val('ob_topic') }); } catch (e) {}
      window.location.href = '/studio';
    };
    v.obNext = () => { if (s.ob < 2) this.setState({ ob: s.ob + 1 }); else finish(false); };
    v.skipOb = () => finish(true);
    if (R.providerStack) v.obStack = R.providerStack;

    // Nav: logged-in visitors get a way back into the app.
    v.sys = { ...v.sys, body: s.page === 'suspended' && R.user ? v.sys.body.replace('john@acme.co', R.user.email) : v.sys.body.replace('john@acme.co', 'this account') };
    return v;
  }
}
