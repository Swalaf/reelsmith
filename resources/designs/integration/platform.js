// Laravel integration for the AI Platform (workflows, cinematic, agents, repurposing).
// The platform canvas is client-side; it shares the signed-in user, credits and branding
// with the Studio, and remembers the last screen per browser.
class Component extends DesignComponent {
  constructor(p) {
    super(p);
    if (window.RS.startScreen) this.state = { ...this.state, screen: window.RS.startScreen };
  }
  renderVals() {
    const v = super.renderVals(), R = window.RS;
    v.appName = R.appName; v.me = R.user || {}; v.logout = () => rs.logout(); v.greeting = rs.greeting();
    return v;
  }
}
