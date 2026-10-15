"""Full personal-practice UI journey, called by the existing Playwright harness."""
import json
from urllib.parse import parse_qs, urlparse


def run_personal(page, check, goto_admin, wp_eval, shot):
    def visit(path, name):
        return goto_admin(page, 'personal', path, 'personal-' + name)

    def submit(form):
        with page.expect_navigation(wait_until='domcontentloaded', timeout=20000):
            form.locator('button[type="submit"]').first.click()

    def step(name):
        visit('admin.php?page=cpms-wizard&step=' + name, name)
        form = page.locator('form:has(input[name="action"][value="cpms_wizard_save"]):has(input[name="step"][value="' + name + '"])')
        check('personal.' + name + '.real_form', form.count() == 1)
        return form

    def snap():
        output = wp_eval(r'''
$db = \ClinicCore\Bootstrap\App::db();
$data = [];
foreach (['cpms_locations', 'cpms_clinicians', 'cpms_schedule', 'cpms_settings'] as $t) {
    $data[$t] = $db->fetchAll('SELECT * FROM ' . $db->table($t) . ' ORDER BY id');
}
echo 'PERSONAL_DB=' . wp_json_encode($data) . PHP_EOL;
''')
        return json.loads(next(line.split('=', 1)[1] for line in output.splitlines() if line.startswith('PERSONAL_DB=')))

    # The existing harness has selected doctor_practice and established the
    # same administrator's manager membership using the real bootstrap form.
    # No clinician, Location or schedule fixture is used for this journey.
    loc_name = 'مکان پذیرش شخصی'
    visit('admin.php?page=cpms-locations', 'location-create')
    form = page.locator('form:has(input[name="action"][value="cpms_location_save"]):has(input[name="mode"][value="create"])')
    check('personal.location.real_create_form', form.count() == 1)
    form.locator('input[name="name"]').fill(loc_name)
    form.locator('input[name="slug"]').fill('personal-acceptance')
    form.locator('input[name="timezone"]').fill('Asia/Tehran')
    submit(form)
    data = snap()
    locations = [x for x in data['cpms_locations'] if x['name'] == loc_name]
    check('personal.location.persisted', len(locations) == 1 and locations[0]['timezone'] == 'Asia/Tehran' and int(locations[0]['is_active']) == 1)
    loc_id = int(locations[0]['id'])
    submit(step('locations'))

    visit('admin.php?page=cpms-clinicians', 'clinician-create')
    form = page.locator('form:has(input[name="full_name"])').first
    form.locator('input[name="full_name"]').fill('پزشک پذیرش شخصی')
    form.locator('input[name="specialty"]').fill('پزشک عمومی')
    submit(form)
    data = snap()
    clinicians = [x for x in data['cpms_clinicians'] if x['full_name'] == 'پزشک پذیرش شخصی']
    check('personal.clinician.persisted', len(clinicians) == 1 and int(clinicians[0]['is_active']) == 1)
    cid = int(clinicians[0]['id'])
    form = step('doctors')
    form.locator('input[name="intended_clinicians"]').fill('1')
    submit(form)
    check('personal.doctors.progressed', 'هنوز کامل نیست' not in page.inner_text('body'))

    # Submit five distinct weekly shifts through the real schedule form.
    for day in range(5):
        visit('admin.php?page=cpms-clinicians&clinician_id=' + str(cid), 'schedule-' + str(day))
        form = page.locator('form:has(input[name="action"][value="cpms_schedule_save"])')
        button = form.locator('button[name="sched_submit"][value="' + f'{day}:{loc_id}:new-{day}-{loc_id}' + '"]')
        check('personal.schedule.day_' + str(day) + '.real_submit', button.count() == 1)
        row = button.locator('xpath=..').locator('xpath=..')
        row.locator('input[name$="[start_time]"]').fill('09:00')
        row.locator('input[name$="[end_time]"]').fill('13:00')
        with page.expect_navigation(wait_until='domcontentloaded', timeout=20000):
            button.click()
    data = snap()
    schedules = [x for x in data['cpms_schedule'] if int(x['clinician_id']) == cid and int(x['location_id']) == loc_id]
    check('personal.schedule.five_days_persisted', len(schedules) == 5 and all(x['start_time'].startswith('09:00') and x['end_time'].startswith('13:00') and int(x['is_active']) == 1 for x in schedules))
    submit(step('schedules'))
    form = step('booking')
    for field, value in {'duration': '25', 'capacity': '1', 'lead': '3', 'future': '45', 'cancel': '12'}.items():
        form.locator('input[name="' + field + '"]').fill(value)
    submit(form)
    data = snap()
    settings = {x['key']: json.loads(x['value_json']) for x in data['cpms_settings']}
    check('personal.booking.persisted', all(int(settings.get(k, -1)) == v for k, v in {
        'booking.duration_default_min': 25, 'booking.slot_capacity_default': 1,
        'booking.min_lead_hours': 3, 'booking.max_future_days': 45, 'booking.cancel_deadline_hours': 12}.items()))
    form = step('review')
    check('personal.review.persian_guidance', 'بازبینی' in page.inner_text('body'))
    submit(form)
    check('personal.review.completed', 'هنوز کامل نیست' not in page.inner_text('body'))
    form = step('finish')
    submit(form)
    check('personal.finish.dashboard_no_loop', parse_qs(urlparse(page.url).query).get('page') == ['cpms-dashboard'])
    data = snap()
    settings = {x['key']: json.loads(x['value_json']) for x in data['cpms_settings']}
    check('personal.finish.completed_persisted', settings.get('setup.completed') is True)
    check('personal.facility.personal_persisted', settings.get('setup.facility_type') == 'doctor_practice')
    # Authorized operational management, not merely a success notice.
    visit('admin.php?page=cpms-clinicians', 'operational-access')
    check('personal.operational.clinician_visible', 'پزشک پذیرش شخصی' in page.inner_text('body'))
    check('personal.operational.authorized_form', page.locator('input[name="full_name"]').count() == 1)
    # Evidence is a DB read, never the mechanism that completes readiness.
    output_path = __import__('os').environ.get('OUT', '/tmp/fp') + '/personal-db.json'
    with open(output_path, 'w') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    page.set_viewport_size({'width': 390, 'height': 844})
    visit('admin.php?page=cpms-wizard&step=review', 'mobile-review')
    check('personal.mobile.rtl', page.locator('[dir="rtl"]').count() > 0)
    # WP chrome can overflow independently; measure the actual wizard wrapper.
    check('personal.mobile.wizard_fits', page.locator('.wrap[dir="rtl"]').first.evaluate('(e) => e.scrollWidth <= e.clientWidth + 2'))
    shot(page, 'personal-mobile-review')
    page.screenshot(path=__import__('os').environ.get('OUT', '/tmp/fp') + '/personal-mobile-viewport.jpg', type='jpeg', quality=65)
    page.set_viewport_size({'width': 1440, 'height': 900})
