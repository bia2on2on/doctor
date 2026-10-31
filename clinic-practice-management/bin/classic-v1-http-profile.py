#!/usr/bin/env python3
"""Synthetic, authenticated real-HTTP profile. CI only; no response bodies persisted."""
import http.cookiejar
import json
import os
import re
import statistics
import time
import urllib.parse
import urllib.request

BASE = os.environ.get('WP_URL', 'http://localhost:8080').rstrip('/')
LABEL = os.environ['PROFILE_LABEL']
OUT = os.environ['PROFILE_OUTPUT']
login, password, _user, _clinician, clinic, location, visit, *_ = os.environ['DOCTOR_ONE'].split('|')
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def request(url, data=None, headers=None, expected=200):
    body = None if data is None else json.dumps(data).encode()
    h = {'Accept': 'application/json', **(headers or {})}
    if body is not None:
        h['Content-Type'] = 'application/json'
    start = time.monotonic()
    try:
        with opener.open(urllib.request.Request(url, data=body, headers=h), timeout=60) as response:
            payload = response.read()
            elapsed = (time.monotonic() - start) * 1000
            if response.status != expected:
                raise RuntimeError(f'HTTP {response.status}, expected {expected}')
            return response.headers, payload, elapsed
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f'HTTP {exc.code} for {urllib.parse.urlparse(url).path}, expected {expected}') from None

request(BASE + '/wp-login.php', {})
form = urllib.parse.urlencode({'log': login, 'pwd': password, 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': '1'}).encode()
with opener.open(BASE + '/wp-login.php', data=form, timeout=60) as response:
    response.read()
portal = os.environ['STAFF_PORTAL_URL']
headers, html, _ = request(portal, headers={'Accept': 'text/html'})
match = re.search(rb'<script type="application/json" class="cpms-doctor-portal__config">(.*?)</script>', html, re.S)
if not match:
    raise RuntimeError('Doctor module config absent from canonical Staff Portal')
config = json.loads(match.group(1))
rest = config['rest_root'].rstrip('/')
auth = {'X-WP-Nonce': config['nonce'], 'X-CPMS-Clinic-Id': clinic, 'X-CPMS-Location-Id': location}
samples = {}

def sample(name, url, data=None):
    h, payload, elapsed = request(url, data, auth)
    samples.setdefault(name, []).append({'client_ms': round(elapsed, 3), 'server_ms': float(h.get('X-CI-Milliseconds', 'nan')), 'queries': int(h.get('X-CI-Queries', '-1'))})
    return json.loads(payload)

# Warm the same real page and REST routes once before the measured window.
request(portal, headers={'Accept': 'text/html'})
sample('queue', rest + '/doctor/portal/today')
for _ in range(12):
    h, _, elapsed = request(portal, headers={'Accept': 'text/html'})
    samples.setdefault('staff_portal_html', []).append({'client_ms': round(elapsed, 3), 'server_ms': float(h.get('X-CI-Milliseconds', 'nan')), 'queries': int(h.get('X-CI-Queries', '-1'))})
    sample('queue', rest + '/doctor/portal/today')
    sample('record', f'{rest}/visits/{visit}/record')
# A fresh in-consultation Visit per POST sequence is seeded by the CI fixture.
ids = json.loads(os.environ['PROFILE_VISIT_IDS'])
for visit_id in ids:
    sample('note_post', f'{rest}/visits/{visit_id}/notes', {'category': 'chief_complaint', 'visibility': 'patient_visible', 'content_text': 'Synthetic HTTP performance note'})
    sample('prescription_post', f'{rest}/visits/{visit_id}/prescriptions', {'items': [{'generic_name': 'Synthetic', 'dose': '1', 'frequency': 'daily'}]})
    sample('complete_post', f'{rest}/visits/{visit_id}/complete', {})
report = {'schema': 1, 'kind': 'authenticated_real_http', 'label': LABEL, 'samples': samples,
          'summary': {k: {'n': len(v), 'median_ms': statistics.median(x['client_ms'] for x in v), 'median_queries': statistics.median(x['queries'] for x in v)} for k, v in samples.items()}}
with open(OUT, 'w') as f:
    json.dump(report, f, indent=2)
print(json.dumps(report['summary']))
