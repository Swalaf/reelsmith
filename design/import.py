#!/usr/bin/env python3
"""Import the Claude Design (.dc.html) sources into the Laravel app.

Each design in design/src/ is split into:
  resources/designs/<page>.template.html  – the <x-dc> template (lightly patched)
  resources/designs/<page>.logic.js        – the design's logic class, renamed to DesignComponent
  resources/designs/<page>.props.json      – the data-props attribute (editor metadata)

The Laravel integration for each page lives in resources/designs/integration/<page>.js
and defines `class Component extends DesignComponent`, overriding only what needs a
backend. Keep patches here minimal: they give uncontrolled inputs a `name` so the
integration layer can read them, swap hard-coded demo identities for live data, and
point cross-page links at Laravel routes.

Re-run after pulling a new version of the design:  python3 design/import.py
"""
import html
import json
import pathlib
import re
import shutil
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'design' / 'src'
OUT = ROOT / 'resources' / 'designs'

PAGES = {
    'website': 'Reelsmith Website.dc.html',
    'studio': 'Reelsmith Studio.dc.html',
    'platform': 'Reelsmith Platform.dc.html',
    'admin': 'Reelsmith Admin.dc.html',
    'installer': 'Reelsmith Installer.dc.html',
}

LINKS = {
    'Reelsmith Studio.dc.html': '/studio',
    'Reelsmith Admin.dc.html': '/admin',
    'Reelsmith Platform.dc.html': '/platform',
    'Reelsmith Website.dc.html': '/',
    'Reelsmith Installer.dc.html': '/install',
}

# ---------------------------------------------------------------------------
# Template patches: (old, new, count). count=None → replace all (must match ≥1);
# an int → exactly that many occurrences must exist; ('nth', n) → replace only
# the n-th (1-based) occurrence.
# ---------------------------------------------------------------------------

USER_CARD_LOGOUT = ('<i class="icon-chevrons-up-down" style="margin-left:auto;opacity:.5"></i>',
                    '<button onClick="{{ logout }}" title="Log out" style="margin-left:auto;background:none;border:0;color:inherit;cursor:pointer;opacity:.6;font-size:15px"><i class="icon-log-out"></i></button>', 1)

T = {
    'website': [
        # Contact form
        ('<input style="height:44px;padding:0 13px', '<input name="contact_name" style="height:44px;padding:0 13px', 1),
        ('<input type="email" style="height:44px', '<input name="contact_email" type="email" style="height:44px', 1),
        ('<select style="height:44px;padding:0 12px', '<select name="contact_topic" style="height:44px;padding:0 12px', 1),
        ('<textarea rows="5" style="padding:12px 13px', '<textarea name="contact_message" rows="5" style="padding:12px 13px', 1),
        # Login / register / forgot
        ('<input type="email" defaultValue="john@acme.co" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none" style-focus',
         '<input name="login_email" type="email" placeholder="you@company.com" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none" style-focus', ('nth', 1)),
        ('<input type="email" defaultValue="john@acme.co" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none" style-focus',
         '<input name="register_email" type="email" placeholder="you@company.com" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none" style-focus', ('nth', 1)),
        ('<input type="email" defaultValue="john@acme.co" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none">',
         '<input name="forgot_email" type="email" placeholder="you@company.com" style="height:46px;padding:0 14px;border:1px solid #e1e0dc;border-radius:11px;font:inherit;font-size:15px;outline:none">', 1),
        ('<input type="password" defaultValue="password"', '<input name="login_password" type="password" placeholder="••••••••"', 1),
        ('<input type="checkbox" defaultChecked="{{ true }}"', '<input name="login_remember" type="checkbox" defaultChecked="{{ true }}"', 1),
        ('<input defaultValue="John Doe" style="height:46px', '<input name="register_name" placeholder="Your name" style="height:46px', 1),
        # Live identities instead of the demo account
        ('If an account exists for john@acme.co, a reset link is on its way. <button onClick="{{ go.reset }}" style="background:none;border:0;padding:0;font:inherit;color:inherit;text-decoration:underline;cursor:pointer">Open link</button>',
         'If an account exists for {{ forgotEmail }}, a reset link is on its way.', 1),
        ('<span style="font-size:14.5px;color:#6b6d72">For john@acme.co</span>', '<span style="font-size:14.5px;color:#6b6d72">For {{ resetEmail }}</span>', 1),
        ('We sent a 6-digit code to <b style="color:#17181a">john@acme.co</b>', 'We sent a 6-digit code to <b style="color:#17181a">{{ meEmail }}</b>', 1),
        ('A receipt is on its way to john@acme.co.', 'A receipt is on its way to {{ meEmail }}.', 1),
        # Email verification: a real code input behind the six boxes, error line, resend
        ('<div style="display:flex;gap:8px;justify-content:center"><sc-for list="{{ codeBoxes }}" as="c">',
         '<div style="position:relative;display:flex;gap:8px;justify-content:center"><input name="verify_code" value="{{ vcode }}" onChange="{{ setVcode }}" inputMode="numeric" maxLength="6" autoComplete="one-time-code" aria-label="Verification code" style="position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:text;font-size:16px;z-index:2"><sc-for list="{{ codeBoxes }}" as="c">', 1),
        ('<button onClick="{{ doVerify }}" style="height:48px;border-radius:12px;border:0;background:oklch(0.58 0.19 35);color:#fff;font:inherit;font-size:15px;font-weight:600;cursor:pointer">Verify email</button>',
         '<sc-if value="{{ vErr }}"><span style="color:oklch(0.5 0.18 25);font-size:13px;text-align:center">{{ vErr }}</span></sc-if><button onClick="{{ doVerify }}" style="height:48px;border-radius:12px;border:0;background:oklch(0.58 0.19 35);color:#fff;font:inherit;font-size:15px;font-weight:600;cursor:pointer">Verify email</button>', 1),
        ('Didn\'t get it? <button style="background:none;border:0;padding:0;font:inherit;color:#17181a;font-weight:600;cursor:pointer">Resend code</button> · 0:42',
         'Didn\'t get it? <button onClick="{{ resendCode }}" style="background:none;border:0;padding:0;font:inherit;color:#17181a;font-weight:600;cursor:pointer">Resend code</button>{{ resendNote }}', 1),
        # Checkout coupon + onboarding topic
        ('<input placeholder="Coupon code"', '<input name="coupon" placeholder="Coupon code"', 1),
        ('<input defaultValue="5 ways Hydra keeps you hydrated" style="height:50px', '<input name="ob_topic" defaultValue="5 ways Hydra keeps you hydrated" style="height:50px', 1),
    ],
    'studio': [
        ('Generated with <span style="font-family:\'Geist Mono\',monospace;color:#17181a">OpenRouter · llama-3.3-70b:free</span> · 0 credits', 'Generated with <span style="font-family:\'Geist Mono\',monospace;color:#17181a">{{ genVia }}</span>', 1),
        ('Good morning, John 👋', '{{ greeting }} 👋', 1),
        ('<span style="width:8px;height:8px;border-radius:50%;background:oklch(0.62 0.14 150)"></span>\n          </div>', '<span style="width:8px;height:8px;border-radius:50%;background:{{ k.dot }}"></span>\n          </div>', 1),
        ('letter-spacing:-0.02em">$4.82</span>', 'letter-spacing:-0.02em">{{ aiCost }}</span>', 1),
        ('letter-spacing:-0.01em">Reelsmith</span>', 'letter-spacing:-0.01em">{{ appName }}</span>', 1),
        ('<span style="font-family:\'Geist Mono\',monospace;color:{{ sb.strong }}">2,480 / 5,000</span>', '<span style="font-family:\'Geist Mono\',monospace;color:{{ sb.strong }}">{{ me.creditsLabel }}</span>', 1),
        ('<div style="width:49.6%;height:100%;border-radius:9px;background:oklch(0.58 0.19 35)"></div>', '<div style="width:{{ me.creditsPct }};height:100%;border-radius:9px;background:oklch(0.58 0.19 35)"></div>', 1),
        ('<div style="font-size:12px;opacity:.7">Renews Oct 1 · Professional plan</div>', '<div style="font-size:12px;opacity:.7">{{ me.planLabel }}</div>', 1),
        ('font-size:12px;font-weight:600">JD</div>', 'font-size:12px;font-weight:600">{{ me.initials }}</div>', 1),
        ('color:{{ sb.strong }}">John Doe</span>', 'color:{{ sb.strong }}">{{ me.name }}</span>', 1),
        ('<span style="font-size:11.5px;opacity:.6">john@acme.co</span>', '<span style="font-size:11.5px;opacity:.6">{{ me.email }}</span>', 1),
        USER_CARD_LOGOUT,
        ('<span>Acme Studio</span>', '<span>{{ appName }}</span>', 1),
        ('<span style="font-family:\'Geist Mono\',monospace">2,480</span>', '<span style="font-family:\'Geist Mono\',monospace">{{ me.credits }}</span>', 1),
        # Create-video idea form
        ('<textarea rows="3" defaultValue="Short product ad', '<textarea name="idea_description" rows="3" defaultValue="Short product ad', 1),
        ('<input defaultValue="Busy professionals, 25–40"', '<input name="idea_audience" defaultValue="Busy professionals, 25–40"', 1),
        ('<input defaultValue="Shop now at hydra.co" style="height:42px', '<input name="idea_cta" defaultValue="Shop now at hydra.co" style="height:42px', 1),
        # Provider configuration drawer
        ('<input type="password" defaultValue="{{ cfg.keyRaw }}"', '<input name="cfg_key" type="password" defaultValue="{{ cfg.keyRaw }}"', 1),
        ('<input defaultValue="{{ cfg.url }}"', '<input name="cfg_url" defaultValue="{{ cfg.url }}"', 1),
        ('<select style="height:42px;padding:0 11px;border:1px solid #e1e0dc;border-radius:10px;font-family:\'Geist Mono\',monospace;font-size:13px;background:#fff"><sc-for list="{{ cfg.models }}" as="o"><option>{{ o }}</option>',
         '<select name="cfg_model" defaultValue="{{ cfg.model }}" style="height:42px;padding:0 11px;border:1px solid #e1e0dc;border-radius:10px;font-family:\'Geist Mono\',monospace;font-size:13px;background:#fff"><sc-for list="{{ cfg.models }}" as="o"><option>{{ o }}</option>', 1),
        ('<button onClick="{{ go.create }}" style="height:36px;border-radius:9px;border:0;background:#17181a;color:#fff;font:inherit;font-size:13px;font-weight:500;cursor:pointer">Use Template</button>',
         '<button onClick="{{ t.use }}" style="height:36px;border-radius:9px;border:0;background:#17181a;color:#fff;font:inherit;font-size:13px;font-weight:500;cursor:pointer">Use Template</button>', 1),
        # Scene media: upload box, media library pickers, music tracks
        ('<sc-if value="{{ v.isUpload }}"><div style="height:64px;', '<sc-if value="{{ v.isUpload }}"><div onClick="{{ v.onUpload }}" style="cursor:pointer;height:64px;', 1),
        ('<sc-for list="{{ libPick }}" as="lp" hint-placeholder-count="5"><div style="width:64px;aspect-ratio:1;border-radius:8px;background:{{ lp }};cursor:pointer"></div></sc-for><div style="width:64px;aspect-ratio:1;border-radius:8px;border:1px solid #e1e0dc;display:grid;place-items:center;font-size:12px;color:#6b6d72">+248</div>',
         '<sc-for list="{{ v.lib }}" as="lp" hint-placeholder-count="5"><div onClick="{{ lp.on }}" title="Use this image" style="width:64px;aspect-ratio:1;border-radius:8px;background:{{ lp.bg }};cursor:pointer"></div></sc-for><div style="width:64px;aspect-ratio:1;border-radius:8px;border:1px solid #e1e0dc;display:grid;place-items:center;font-size:12px;color:#6b6d72">{{ libMore }}</div>', 1),
        ('<sc-for list="{{ libPick }}" as="lp"><div style="aspect-ratio:1;border-radius:8px;background:{{ lp }}"></div></sc-for><div style="aspect-ratio:1;border-radius:8px;border:1.5px dashed #d6d4ce;display:grid;place-items:center;color:#6b6d72"><i class="icon-upload"></i></div>',
         '<sc-for list="{{ edLib }}" as="lp"><div onClick="{{ lp.on }}" title="Use for this scene" style="aspect-ratio:1;border-radius:8px;background:{{ lp.bg }};cursor:pointer"></div></sc-for><div onClick="{{ edUpload }}" title="Upload" style="aspect-ratio:1;border-radius:8px;border:1.5px dashed #d6d4ce;display:grid;place-items:center;color:#6b6d72;cursor:pointer"><i class="icon-upload"></i></div>', 1),
        ('<sc-for list="{{ tracks }}" as="tr"><div style="display:flex;align-items:center;gap:10px;padding:8px;', '<sc-for list="{{ tracks }}" as="tr"><div onClick="{{ tr.on }}" style="cursor:pointer;display:flex;align-items:center;gap:10px;padding:8px;', 1),
        # Per-scene provider choice, and the finished video on the render card
        ('<select style="height:36px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13px;background:#fff"><sc-for list="{{ v.provs }}"',
         '<select name="vis_prov_{{ v.id }}" key="{{ v.provKey }}" onChange="{{ v.onProv }}" style="height:36px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13px;background:#fff"><sc-for list="{{ v.provs }}"', 1),
        ('<select style="height:36px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13px;background:#fff;font-family:\'Geist Mono\',monospace"><sc-for list="{{ v.models }}"',
         '<select name="vis_model_{{ v.id }}" key="{{ v.provKey }}-m" style="height:36px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13px;background:#fff;font-family:\'Geist Mono\',monospace"><sc-for list="{{ v.models }}"', 1),
        ('<div style="position:relative;width:220px;aspect-ratio:9/16;border-radius:16px;overflow:hidden;background:#2f4b4b;flex:none;margin:0 auto">',
         '<div style="position:relative;width:{{ out.w }};aspect-ratio:{{ out.ar }};border-radius:16px;overflow:hidden;background:{{ out.bg }};flex:none;margin:0 auto"><sc-if value="{{ out.url }}"><video src="{{ out.url }}" controls playsinline preload="none" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;z-index:2;background:transparent"></video></sc-if>', 1),
        # Render result + library download buttons
        ('<span style="font-family:\'Geist Mono\',monospace">1080×1920</span><span style="font-family:\'Geist Mono\',monospace">18.2 MB</span><span style="font-family:\'Geist Mono\',monospace">36</span>',
         '<span style="font-family:\'Geist Mono\',monospace">{{ out.res }}</span><span style="font-family:\'Geist Mono\',monospace">{{ out.size }}</span><span style="font-family:\'Geist Mono\',monospace">{{ out.credits }}</span>', 1),
        ('<button style="display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border-radius:10px;border:0;background:oklch(0.58 0.19 35);color:#fff;font:inherit;font-size:14px;font-weight:600;cursor:pointer"><i class="icon-download"',
         '<button onClick="{{ download }}" style="display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border-radius:10px;border:0;background:oklch(0.58 0.19 35);color:#fff;font:inherit;font-size:14px;font-weight:600;cursor:pointer"><i class="icon-download"', 1),
        ('<button style="display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:10px;border:1px solid #e1e0dc;background:#fff;font:inherit;font-size:14px;font-weight:500;cursor:pointer"><i class="icon-play"',
         '<button onClick="{{ download }}" style="display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:10px;border:1px solid #e1e0dc;background:#fff;font:inherit;font-size:14px;font-weight:500;cursor:pointer"><i class="icon-play"', 1),
        ('<sc-if value="{{ p.completed }}"><button title="Download" style=', '<sc-if value="{{ p.completed }}"><button onClick="{{ p.download }}" title="Download" style=', 1),
        ('<select style="height:42px;padding:0 11px;border:1px solid #e1e0dc;border-radius:10px;font:inherit;font-size:13px;background:#fff"><option>Primary</option>',
         '<select name="cfg_priority" style="height:42px;padding:0 11px;border:1px solid #e1e0dc;border-radius:10px;font:inherit;font-size:13px;background:#fff"><option>Primary</option>', 1),
    ],
    'platform': [
        ('Good morning, John 👋', '{{ greeting }} 👋', 1),
        ('<span style="font-weight:600;font-size:15px;color:#fff">Reelsmith</span>', '<span style="font-weight:600;font-size:15px;color:#fff">{{ appName }}</span>', 1),
        ('<span style="font-family:\'Geist Mono\',monospace;color:#fff">2,480</span>', '<span style="font-family:\'Geist Mono\',monospace;color:#fff">{{ me.credits }}</span>', 1),
        ('<div style="width:49.6%;height:100%;border-radius:9px;background:oklch(0.58 0.19 35)"></div>', '<div style="width:{{ me.creditsPct }};height:100%;border-radius:9px;background:oklch(0.58 0.19 35)"></div>', 1),
        ('font-size:12px;font-weight:600">JD</div>', 'font-size:12px;font-weight:600">{{ me.initials }}</div>', 1),
        ('<span style="font-size:13px;font-weight:500;color:#fff">John Doe</span><span style="font-size:11.5px;opacity:.6">Acme Studio</span></div>',
         '<span style="font-size:13px;font-weight:500;color:#fff">{{ me.name }}</span><span style="font-size:11.5px;opacity:.6">{{ me.email }}</span></div><button onClick="{{ logout }}" title="Log out" style="margin-left:auto;background:none;border:0;color:inherit;cursor:pointer;opacity:.6;font-size:15px"><i class="icon-log-out"></i></button>', 1),
    ],
    'admin': [
        ('<span style="font-weight:600;font-size:15px;color:#fff">Reelsmith</span>', '<span style="font-weight:600;font-size:15px;color:#fff">{{ appName }}</span>', 1),
        ('font-size:12px;font-weight:600">SA</div>', 'font-size:12px;font-weight:600" title="{{ me.name }}">{{ me.initials }}</div><button onClick="{{ logout }}" title="Log out" style="width:34px;height:34px;border-radius:9px;border:1px solid #e1e0dc;background:#fff;cursor:pointer;display:grid;place-items:center"><i class="icon-log-out" style="font-size:15px"></i></button>', 1),
        ('Acme Video Cloud · self-hosted on video.acme.co', '{{ wl.appName }} · self-hosted on {{ wl.domain }}', 1),
        ('color:oklch(0.45 0.12 150)">+18.2%</span>', 'color:oklch(0.45 0.12 150)">{{ revDelta }}</span>', 1),
        ('font-family:\'Geist Mono\',monospace">$38,420</div>', 'font-family:\'Geist Mono\',monospace">{{ revTotal }}</div>', 1),
        ('All systems normal', '{{ sysLabel }}', 1),
        ('<input placeholder="Reason (visible in user\'s credit history)"', '<input name="adj_reason" placeholder="Reason (visible in user\'s credit history)"', 1),
        ('<button style="height:32px;border-radius:8px;border:1px solid #e1e0dc;background:#fff;font:inherit;font-size:12.5px;cursor:pointer">Configure keys</button>',
         '<button onClick="{{ g.configure }}" style="height:32px;border-radius:8px;border:1px solid #e1e0dc;background:#fff;font:inherit;font-size:12.5px;cursor:pointer">Configure keys</button>', 1),
        # Provider drawer
        ('<span style="font-size:12.5px;font-weight:500;color:#3a3c40">Type</span><select style=', '<span style="font-size:12.5px;font-weight:500;color:#3a3c40">Type</span><select name="pd_type" defaultValue="{{ pd.type }}" style=', 1),
        ('<input type="password" defaultValue="{{ pd.key }}"', '<input name="pd_key" type="password" defaultValue="{{ pd.key }}"', 1),
        ('<input defaultValue="{{ pd.url }}"', '<input name="pd_url" defaultValue="{{ pd.url }}"', 1),
        ('<input type="checkbox" defaultChecked="{{ m.on }}"', '<input name="pd_model" value="{{ m.id }}" type="checkbox" defaultChecked="{{ m.on }}"', 1),
        ('<select style="height:40px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13.5px;background:#fff"><option>1 · Primary</option>',
         '<select name="pd_priority" style="height:40px;padding:0 10px;border:1px solid #e1e0dc;border-radius:9px;font:inherit;font-size:13.5px;background:#fff"><option>1 · Primary</option>', 1),
        # Plan drawer
        ('<input defaultValue="{{ pe.name }}"', '<input name="plan_name" defaultValue="{{ pe.name }}"', 1),
        ('<input defaultValue="{{ pe.price }}"', '<input name="plan_price" defaultValue="{{ pe.price }}"', 1),
        ('<input defaultValue="{{ pe.credits }}"', '<input name="plan_credits" defaultValue="{{ pe.credits }}"', 1),
        ('<input defaultValue="{{ pe.videos }}"', '<input name="plan_videos" defaultValue="{{ pe.videos }}"', 1),
        ('<input defaultValue="{{ pe.storage }}"', '<input name="plan_storage" defaultValue="{{ pe.storage }}"', 1),
        # Pages CMS
        ('<input value="{{ page.h }}"', '<input name="page_h" defaultValue="{{ page.h }}" key="{{ page.slug }}"', 1),
        ('<textarea rows="7" defaultValue="{{ page.body }}"', '<textarea name="page_body" key="{{ page.slug }}" rows="7" defaultValue="{{ page.body }}"', 1),
        ('<input defaultValue="{{ page.meta }}"', '<input name="page_meta" key="{{ page.slug }}" defaultValue="{{ page.meta }}"', 1),
        ('<textarea rows="2" defaultValue="{{ page.desc }}"', '<textarea name="page_desc" key="{{ page.slug }}" rows="2" defaultValue="{{ page.desc }}"', 1),
        # Settings form fields get stable names from their labels
        ('<input defaultValue="{{ f.v }}" type="{{ f.inputType }}"', '<input name="{{ f.name }}" defaultValue="{{ f.v }}" type="{{ f.inputType }}"', 1),
        ('<select defaultValue="{{ f.v }}"', '<select name="{{ f.name }}" defaultValue="{{ f.v }}"', 1),
        # Credit limits
        ('<input defaultValue="{{ l.v }}"', '<input name="{{ l.name }}" defaultValue="{{ l.v }}"', 1),
    ],
    'installer': [
        ('<span style="font-weight:600;font-size:15px">Reelsmith</span>', '<span style="font-weight:600;font-size:15px">{{ appName }}</span>', 1),
        ('<a href="#" style="margin-left:auto;', '<a href="/?page=docs" style="margin-left:auto;', 1),
        ('<input defaultValue="8f3a2c1e-77b0-4d2a-9e51-0c6f1a2b3d4e"', '<input name="purchase_code" placeholder="Optional"', 1),
        ('<span style="font-size:12px;color:oklch(0.45 0.12 150);display:flex;align-items:center;gap:6px"><i class="icon-circle-check" style="font-size:13px"></i>Valid regular license</span>', '', 1),
        ('<input defaultValue="{{ f.v }}" type="{{ f.type }}"', '<input key="{{ f.name }}" name="{{ f.name }}" defaultValue="{{ f.v }}" type="{{ f.type }}" placeholder="{{ f.ph }}"', 1),
        ('<p style="margin:0;color:#55575c;font-size:14.5px">Create an empty MySQL or MariaDB database, then enter its details.</p></div>',
         '<p style="margin:0;color:#55575c;font-size:14.5px">Create an empty MySQL or MariaDB database, then enter its details — or use SQLite for a zero-config install.</p></div>\n  <div style="display:flex;padding:3px;border-radius:10px;background:#f1f0ed;gap:2px;align-self:flex-start"><sc-for list="{{ dbDrivers }}" as="c"><button onClick="{{ c.on }}" style="height:32px;padding:0 14px;border:0;border-radius:8px;background:{{ c.segBg }};box-shadow:{{ c.segSh }};font:inherit;font-size:13px;cursor:pointer">{{ c.label }}</button></sc-for></div>', 1),
        ('<input defaultValue="Acme Video Cloud"', '<input name="app_name" defaultValue="{{ appName }}"', 1),
        ('<input defaultValue="https://video.acme.co"', '<input name="app_url" defaultValue="{{ appUrl }}"', 1),
        ('<span style="font-size:13px;font-weight:500;color:#3a3c40">Timezone</span><select style=', '<span style="font-size:13px;font-weight:500;color:#3a3c40">Timezone</span><select name="app_timezone" style=', 1),
        ('<span style="font-size:13px;font-weight:500;color:#3a3c40">Default currency</span><select style=', '<span style="font-size:13px;font-weight:500;color:#3a3c40">Default currency</span><select name="app_currency" style=', 1),
        ('<input defaultValue="Sam Admin"', '<input name="admin_name" defaultValue="{{ form.admin_name }}" onChange="{{ remember }}"', 1),
        ('<input defaultValue="admin@acme.co"', '<input name="admin_email" type="email" defaultValue="{{ form.admin_email }}" onChange="{{ remember }}"', 1),
        ('<input defaultValue="{{ f.v }}" style="height:42px;padding:0 13px;border:1px solid #e1e0dc;border-radius:10px;font-family:\'Geist Mono\',monospace;font-size:13px;outline:none"></label></sc-for></div></sc-if>',
         '<input name="{{ f.name }}" defaultValue="{{ f.v }}" onChange="{{ remember }}" style="height:42px;padding:0 13px;border:1px solid #e1e0dc;border-radius:10px;font-family:\'Geist Mono\',monospace;font-size:13px;outline:none"></label></sc-for></div></sc-if>', 1),
        ('Files will be stored in <code style="font-family:\'Geist Mono\',monospace;color:#17181a">/var/www/reelsmith/storage/app</code>. 212 GB free on this disk.',
         'Files will be stored in <code style="font-family:\'Geist Mono\',monospace;color:#17181a">{{ localPath }}</code>. {{ localFree }} free on this disk.', 1),
        ('<input placeholder="API key" defaultValue="{{ a.key }}"', '<input name="{{ a.inputName }}" placeholder="API key" defaultValue="{{ a.key }}" onChange="{{ remember }}"', 1),
        ('<input defaultValue="/usr/bin/ffmpeg"', '<input name="ffmpeg_path" defaultValue="{{ ffPath }}" onChange="{{ remember }}"', 1),
        ('cd /var/www/reelsmith &amp;&amp; php artisan', 'cd {{ basePath }} &amp;&amp; php artisan', 1),
        ('php artisan queue:work --queue=render,ai,default --tries=3', 'php {{ basePath }}/artisan queue:work --queue=render,ai,default --tries=3', 1),
        ('<sc-if value="{{ st.install }}" hint-placeholder-val="{{ false }}">\n  <div style="display:flex;flex-direction:column;gap:6px"><h1 style="margin:0;font-size:26px;font-weight:600;letter-spacing:-0.02em">Installing</h1><p style="margin:0;color:#55575c;font-size:14.5px">Don\'t close this window.</p></div>',
         '<sc-if value="{{ st.install }}" hint-placeholder-val="{{ false }}">\n  <div style="display:flex;flex-direction:column;gap:6px"><h1 style="margin:0;font-size:26px;font-weight:600;letter-spacing:-0.02em">Installing</h1><p style="margin:0;color:#55575c;font-size:14.5px">Don\'t close this window.</p></div>\n  <sc-if value="{{ instErr }}"><div style="display:flex;gap:12px;padding:14px 16px;border-radius:12px;background:oklch(0.97 0.025 25);border:1px solid oklch(0.88 0.06 25);font-size:13.5px;line-height:1.5"><i class="icon-circle-x" style="font-size:17px;color:oklch(0.52 0.18 25);margin-top:1px"></i><span style="flex:1">{{ instErr }}</span><button onClick="{{ retryInstall }}" style="height:32px;padding:0 12px;border-radius:9px;border:1px solid #e1e0dc;background:#fff;font:inherit;font-size:13px;cursor:pointer">Back</button></div></sc-if>', 1),
        ('Acme Video Cloud is live at <span style="font-family:\'Geist Mono\',monospace;color:#17181a">video.acme.co</span>. For security, delete the <code style="font-family:\'Geist Mono\',monospace">/install</code> folder.',
         '{{ appName }} is live at <span style="font-family:\'Geist Mono\',monospace;color:#17181a">{{ appUrl }}</span>. The installer is now locked.', 1),
        ('<a href="#" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 18px;', '<a href="/docs" style="display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 18px;', 1),
    ],
}

# Logic patches applied to the design's JS before it is renamed.
L = {
    'website': [],
    'studio': [],
    'platform': [],
    'admin': [],
    'installer': [],
}


RAW_WRAP = ['select', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'caption']


def encode_case(html: str) -> str:
    """Mirror support.js encodeCase(). The template is embedded in the page's HTML, so the
    browser parses it before the runtime sees it: that lowercases camelCase attributes
    (defaultValue → defaultvalue) and drops non-<option> children of <select>. Encoding them
    the way the runtime expects keeps both intact."""
    html = re.sub(r'<helmet(\s|>)', r'<sc-helmet\1', html, flags=re.I)
    html = re.sub(r'</helmet\s*>', '</sc-helmet>', html, flags=re.I)
    html = re.sub(r'(\s)([a-z]+[A-Z][A-Za-z0-9]*)(\s*=)',
                  lambda m: m.group(1) + 'sc-camel-' + re.sub(r'[A-Z]', lambda c: '-' + c.group(0).lower(), m.group(2)) + m.group(3), html)
    for tag in RAW_WRAP:
        html = re.sub(r'(</?)' + tag + r'(?=[\s>])', r'\1sc-raw-' + tag, html, flags=re.I)
    return html


def apply(text: str, patches, label: str) -> str:
    for old, new, count in patches:
        found = text.count(old)
        if isinstance(count, tuple):
            n = count[1]
            if found < n:
                sys.exit(f'[{label}] expected ≥{n} of: {old[:80]!r} (found {found})')
            idx = -1
            for _ in range(n):
                idx = text.index(old, idx + 1)
            text = text[:idx] + new + text[idx + len(old):]
            continue
        if found == 0 or (count is not None and found != count):
            sys.exit(f'[{label}] expected {count or "≥1"} of: {old[:80]!r} (found {found})')
        text = text.replace(old, new)
    return text


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for page, fname in PAGES.items():
        src = (SRC / fname).read_text(encoding='utf-8')
        m = re.search(r'<x-dc(?:\s[^>]*)?>(.*)</x-dc>', src, re.S)
        s = re.search(r'<script type="text/x-dc" data-dc-script(?: data-props="([^"]*)")?>(.*?)</script>', src, re.S)
        if not m or not s:
            sys.exit(f'{fname}: could not find <x-dc> template or logic script')
        template, props, logic = m.group(1), html.unescape(s.group(1) or '{}'), s.group(2)

        template = apply(template, T[page], page + ' template')
        # Self-hosted icon font (public/vendor/lucide) instead of unpkg.com
        template = template.replace('https://unpkg.com/lucide-static@0.460.0/font/lucide.css', '/vendor/lucide/lucide.css')
        for a, b in LINKS.items():
            template = template.replace(f'href="{a}"', f'href="{b}"')
            logic = logic.replace(f"'{a}'", f"'{b}'")
        logic = apply(logic, L[page], page + ' logic')
        if 'class Component extends DCLogic' not in logic:
            sys.exit(f'{fname}: logic class not found')
        logic = logic.replace('class Component extends DCLogic', 'class DesignComponent extends DCLogic', 1)

        template = encode_case(template)
        (OUT / f'{page}.template.html').write_text(template.strip('\n') + '\n', encoding='utf-8')
        (OUT / f'{page}.logic.js').write_text(logic.strip('\n') + '\n', encoding='utf-8')
        (OUT / f'{page}.props.json').write_text(json.dumps(json.loads(props), indent=2) + '\n', encoding='utf-8')
        print(f'imported {page:9s} ← {fname}')

    shutil.copyfile(SRC / 'support.js', ROOT / 'public' / 'support.js')
    print('copied support.js → public/support.js')


if __name__ == '__main__':
    main()
