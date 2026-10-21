<?php
/**
 * Production standalone MANAGEMENT PORTAL template (dedicated frontend surface).
 *
 * Owns the full HTML document: deliberately does NOT call get_header(),
 * get_footer(), wp_head(), wp_footer() or wp_body_open(), so the active Theme
 * never controls the shell. Nothing here links to the WordPress admin back office.
 *
 * Rendering model: server-rendered forms + Post/Redirect/Get handled by
 * ManagementPortalController on the portal's own URL. No client-side routing and
 * no privileged AJAX endpoint is introduced.
 *
 * Authority: every Organization/Clinic block below is rendered from a durable
 * authorization result (ManagementPortalService). The template never decides
 * access; it only decides what is worth showing to an already-authorized actor.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Application\Management\ManagementPortalService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Admin\AddressSelectorField;

if ( ! function_exists( 'wp_get_current_user' ) ) {
	return;
}

$cpms_mp_user      = wp_get_current_user();
$cpms_mp_logged_in = $cpms_mp_user instanceof WP_User && (int) $cpms_mp_user->ID > 0;
$cpms_mp_actor     = $cpms_mp_logged_in ? (int) $cpms_mp_user->ID : 0;
$cpms_mp_service   = App::management_portal_service();
$cpms_mp_url       = ManagementPortalShell::portal_url();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only selectors and an allowlisted notice key; no state change.
$cpms_mp_notice_key = isset( $_GET['notice'] ) && is_string( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
$cpms_mp_org_id     = isset( $_GET[ ManagementPortalShell::ORG_PARAM ] ) ? absint( wp_unslash( (string) $_GET[ ManagementPortalShell::ORG_PARAM ] ) ) : 0;
$cpms_mp_clinic_id  = isset( $_GET[ ManagementPortalShell::CLINIC_PARAM ] ) ? absint( wp_unslash( (string) $_GET[ ManagementPortalShell::CLINIC_PARAM ] ) ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$cpms_mp_notices = array(
	'done'          => array( 'class' => 'is-success', 'text' => 'عملیات با موفقیت ثبت شد.' ),
	'denied'        => array( 'class' => 'is-error', 'text' => 'عملیات انجام نشد؛ دسترسی یا داده‌های ارسالی معتبر نیستند و هیچ تغییری ثبت نشد.' ),
	'invalid'       => array( 'class' => 'is-error', 'text' => 'درخواست نامعتبر است. دوباره تلاش کنید.' ),
	'auth_required' => array( 'class' => 'is-error', 'text' => 'برای انجام این عملیات ابتدا وارد شوید.' ),
	'login_failed'  => array( 'class' => 'is-error', 'text' => 'ورود ناموفق بود یا دسترسی مدیریتی CPMS برای این حساب وجود ندارد.' ),
);
$cpms_mp_notice  = $cpms_mp_notices[ $cpms_mp_notice_key ] ?? null;

$cpms_mp_organizations = $cpms_mp_logged_in ? $cpms_mp_service->organizations( $cpms_mp_actor ) : array();
$cpms_mp_clinics       = $cpms_mp_logged_in ? $cpms_mp_service->clinics( $cpms_mp_actor ) : array();

$cpms_mp_org_view    = $cpms_mp_org_id > 0 ? $cpms_mp_service->organization_overview( $cpms_mp_actor, $cpms_mp_org_id ) : null;
$cpms_mp_clinic_view = $cpms_mp_clinic_id > 0 ? $cpms_mp_service->clinic_workspace( $cpms_mp_actor, $cpms_mp_clinic_id ) : null;

$cpms_mp_charset = (string) get_bloginfo( 'charset' );
if ( '' === $cpms_mp_charset ) {
	$cpms_mp_charset = 'UTF-8';
}
$cpms_mp_site_name  = (string) get_bloginfo( 'name' );
$cpms_mp_title_full = 'پورتال مدیریت' . ( '' !== $cpms_mp_site_name ? ' — ' . $cpms_mp_site_name : '' );

/**
 * Render one portal form control (label + input) with escaped values.
 *
 * @param string $name  Field name.
 * @param string $label Visible label.
 * @param string $value Current value.
 * @param bool   $required Whether the field is required.
 * @param string $type  Input type.
 */
$cpms_mp_field = static function ( string $name, string $label, string $value = '', bool $required = false, string $type = 'text' ): void {
	echo '<p class="cpms-mgmt-field"><label for="cpms-mgmt-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
	echo '<input id="cpms-mgmt-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"';
	echo $required ? ' required' : '';
	echo '></p>';
};

/**
 * Open a portal form with its action, nonce and explicit selectors.
 *
 * @param string $action Portal action key.
 * @param array<string,int> $ids  Explicit selector ids (never trusted as authority).
 */
$cpms_mp_form_open = static function ( string $action, array $ids = array() ): void {
	echo '<form class="cpms-mgmt-form" method="post" action="' . esc_url( ManagementPortalShell::portal_url() ) . '">';
	echo '<input type="hidden" name="cpms_action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( ManagementPortalController::NONCE_ACTION, '_cpms_nonce', true, true );
	foreach ( $ids as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) (int) $value ) . '">';
	}
};

?><!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-management-portal="v1">
<head>
<meta charset="<?php echo esc_attr( $cpms_mp_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $cpms_mp_title_full ); ?></title>
<style>
:root { --cpms-primary: #0f5c56; --cpms-bg: #f3f6f8; --cpms-text: #1b2830; --cpms-muted: #5c6b76; --cpms-border: #e1e8ee; --cpms-danger: #a3282f; --cpms-ok: #1d6f42; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Tahoma, "Segoe UI", sans-serif; background: var(--cpms-bg); color: var(--cpms-text); }
.cpms-mgmt-shell { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
.cpms-mgmt-header { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.cpms-mgmt-header h1 { font-size: 1.35rem; margin: 0; }
.cpms-mgmt-sub { color: var(--cpms-muted); font-size: .9rem; margin: .25rem 0 0; }
.cpms-mgmt-card { background: #fff; border: 1px solid var(--cpms-border); border-radius: 10px; padding: 1rem; margin-bottom: 1rem; }
.cpms-mgmt-card h2 { font-size: 1.05rem; margin: 0 0 .75rem; }
.cpms-mgmt-card h3 { font-size: .95rem; margin: 1rem 0 .5rem; }
.cpms-mgmt-notice { border-radius: 8px; padding: .7rem .9rem; margin-bottom: 1rem; font-size: .92rem; }
.cpms-mgmt-notice.is-success { background: #e7f4ec; color: var(--cpms-ok); border: 1px solid #bfe0cd; }
.cpms-mgmt-notice.is-error { background: #fbeaea; color: var(--cpms-danger); border: 1px solid #f0c6c8; }
.cpms-mgmt-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
.cpms-mgmt-tab { display: inline-block; padding: .45rem .8rem; border-radius: 999px; border: 1px solid var(--cpms-border); background: #fff; color: var(--cpms-text); text-decoration: none; font-size: .88rem; }
.cpms-mgmt-tab.is-active { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-mgmt-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.cpms-mgmt-table th, .cpms-mgmt-table td { border-bottom: 1px solid var(--cpms-border); padding: .5rem .4rem; text-align: right; vertical-align: top; }
.cpms-mgmt-form { margin: .5rem 0 1rem; }
.cpms-mgmt-field { display: flex; flex-direction: column; gap: .25rem; margin: .4rem 0; font-size: .88rem; }
.cpms-mgmt-field input, .cpms-mgmt-form select { padding: .45rem; border: 1px solid var(--cpms-border); border-radius: 6px; font: inherit; }
.cpms-mgmt-btn { display: inline-block; margin-top: .4rem; padding: .5rem .9rem; border: 0; border-radius: 6px; background: var(--cpms-primary); color: #fff; font: inherit; cursor: pointer; }
.cpms-mgmt-btn--muted { background: #5c6b76; }
.cpms-mgmt-btn--small { padding: .3rem .6rem; font-size: .82rem; }
.cpms-mgmt-inline { display: inline-block; margin: 0 .25rem .25rem 0; }
.cpms-mgmt-login { max-width: 420px; margin: 2rem auto; }
.cpms-mgmt-empty { color: var(--cpms-muted); font-size: .9rem; }
.cpms-mgmt-badge { font-size: .75rem; padding: .1rem .4rem; border-radius: 999px; background: var(--cpms-border); }
.cpms-mgmt-badge.is-off { background: #f6d7d9; color: var(--cpms-danger); }
.cpms-mgmt-meta { color: var(--cpms-muted); font-size: .85rem; }
</style>
</head>
<body data-cpms-portal="management">
<div class="cpms-mgmt-shell">

	<header class="cpms-mgmt-header">
		<div>
			<h1>پورتال مدیریت CPMS</h1>
			<p class="cpms-mgmt-sub">مدیریت سازمان و درمانگاه — بدون نیاز به پیشخوان وردپرس</p>
		</div>
		<div class="cpms-mgmt-meta">
			<?php if ( $cpms_mp_logged_in ) : ?>
				<?php echo esc_html( (string) $cpms_mp_user->display_name ); ?> —
				<a href="<?php echo esc_url( wp_logout_url( $cpms_mp_url ) ); ?>">خروج</a>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( $cpms_mp_notice !== null ) : ?>
		<div class="cpms-mgmt-notice <?php echo esc_attr( $cpms_mp_notice['class'] ); ?>" role="status"><?php echo esc_html( $cpms_mp_notice['text'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! $cpms_mp_logged_in ) : ?>
		<section class="cpms-mgmt-card cpms-mgmt-login" aria-labelledby="cpms-mgmt-login-title">
			<h2 id="cpms-mgmt-login-title">ورود به پورتال مدیریت</h2>
			<p class="cpms-mgmt-meta">با حساب وردپرس خود وارد شوید. دسترسی هر بخش فقط از عضویت سازمانی/درمانگاهی فعال و مجوزهای CPMS همان سازمان/درمانگاه می‌آید.</p>
			<form class="cpms-mgmt-form" method="post" action="<?php echo esc_url( $cpms_mp_url ); ?>">
				<input type="hidden" name="cpms_action" value="login">
				<?php wp_nonce_field( ManagementPortalController::LOGIN_NONCE_ACTION, '_cpms_nonce', true, true ); ?>
				<?php
				$cpms_mp_field( 'log', 'نام کاربری یا ایمیل' );
				$cpms_mp_field( 'pwd', 'گذرواژه', '', true, 'password' );
				?>
				<p class="cpms-mgmt-field"><label><input type="checkbox" name="rememberme" value="1"> مرا به خاطر بسپار</label></p>
				<button class="cpms-mgmt-btn" type="submit">ورود</button>
			</form>
		</section>
	<?php else : ?>

		<?php if ( array() === $cpms_mp_organizations && array() === $cpms_mp_clinics ) : ?>
			<section class="cpms-mgmt-card">
				<h2>دسترسی مدیریتی فعال نیست</h2>
				<p class="cpms-mgmt-empty">برای این حساب، عضویت سازمانی یا درمانگاهی فعال همراه با مجوز مدیریتی ثبت نشده است. نقش مدیر وردپرس به‌تنهایی مجوز مدیریت درمانگاه یا دسترسی به داده‌های بالینی/مالی نیست.</p>
			</section>
		<?php endif; ?>

		<nav class="cpms-mgmt-tabs" aria-label="انتخاب سطح مدیریت">
			<a class="cpms-mgmt-tab <?php echo ( 0 === $cpms_mp_org_id && array() !== $cpms_mp_organizations ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $cpms_mp_url ); ?>">سازمان‌ها</a>
			<?php foreach ( $cpms_mp_organizations as $cpms_mp_org ) : ?>
				<a class="cpms-mgmt-tab <?php echo ( $cpms_mp_org['id'] === $cpms_mp_org_id ) ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( ManagementPortalShell::ORG_PARAM, (string) $cpms_mp_org['id'], $cpms_mp_url ) ); ?>">
					<?php echo esc_html( $cpms_mp_org['name'] ); ?>
				</a>
			<?php endforeach; ?>
			<?php foreach ( $cpms_mp_clinics as $cpms_mp_clinic ) : ?>
				<a class="cpms-mgmt-tab <?php echo ( $cpms_mp_clinic['id'] === $cpms_mp_clinic_id ) ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( ManagementPortalShell::CLINIC_PARAM, (string) $cpms_mp_clinic['id'], $cpms_mp_url ) ); ?>">
					درمانگاه: <?php echo esc_html( $cpms_mp_clinic['name'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( array() !== $cpms_mp_organizations && 0 === $cpms_mp_org_id && 0 === $cpms_mp_clinic_id ) : ?>
			<section class="cpms-mgmt-card">
				<h2>سازمان‌های تحت مدیریت</h2>
				<ul>
					<?php foreach ( $cpms_mp_organizations as $cpms_mp_org ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( ManagementPortalShell::ORG_PARAM, (string) $cpms_mp_org['id'], $cpms_mp_url ) ); ?>"><?php echo esc_html( $cpms_mp_org['name'] ); ?></a>
							<span class="cpms-mgmt-meta">
								<?php
								$cpms_mp_flags = array();
								if ( $cpms_mp_org['can_create'] ) {
									$cpms_mp_flags[] = 'ایجاد درمانگاه';
								}
								if ( $cpms_mp_org['can_update'] ) {
									$cpms_mp_flags[] = 'ویرایش پروفایل درمانگاه';
								}
								if ( $cpms_mp_org['can_lifecycle'] ) {
									$cpms_mp_flags[] = 'چرخهٔ عمر درمانگاه';
								}
								if ( $cpms_mp_org['can_designate'] ) {
									$cpms_mp_flags[] = 'تعیین مدیر درمانگاه';
								}
								if ( $cpms_mp_org['can_manage_members'] ) {
									$cpms_mp_flags[] = 'مدیران سازمان';
								}
								echo esc_html( implode( ' • ', $cpms_mp_flags ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="cpms-mgmt-empty">سطح موردنظر را صریحاً انتخاب کنید؛ هیچ سازمان یا درمانگاهی به‌صورت خودکار انتخاب نمی‌شود.</p>
			</section>
		<?php endif; ?>

		<?php if ( $cpms_mp_org_view !== null ) : ?>
			<?php $cpms_mp_org_can = $cpms_mp_org_view['can']; ?>
			<section class="cpms-mgmt-card" data-cpms-org="<?php echo esc_attr( (string) $cpms_mp_org_view['organization']['id'] ); ?>">
				<h2>نمای کلی سازمان: <?php echo esc_html( $cpms_mp_org_view['organization']['name'] ); ?></h2>
				<table class="cpms-mgmt-table">
					<thead>
						<tr><th>درمانگاه</th><th>شناسه</th><th>وضعیت</th><th>شعبهٔ فعال</th><th>مدیران درمانگاه</th></tr>
					</thead>
					<tbody>
					<?php if ( array() === $cpms_mp_org_view['clinics'] ) : ?>
						<tr><td colspan="5" class="cpms-mgmt-empty">درمانگاهی ثبت نشده است.</td></tr>
					<?php endif; ?>
					<?php foreach ( $cpms_mp_org_view['clinics'] as $cpms_mp_row ) : ?>
						<tr>
							<td><?php echo esc_html( $cpms_mp_row['name'] ); ?></td>
							<td><?php echo esc_html( $cpms_mp_row['slug'] ); ?></td>
							<td>
								<span class="cpms-mgmt-badge <?php echo 'active' === $cpms_mp_row['status'] ? '' : 'is-off'; ?>">
									<?php echo 'active' === $cpms_mp_row['status'] ? 'فعال' : 'غیرفعال'; ?>
								</span>
							</td>
							<td><?php echo esc_html( (string) $cpms_mp_row['location_count'] ); ?></td>
							<td>
								<?php
								$cpms_mp_names = array();
								foreach ( $cpms_mp_row['manager_user_ids'] as $cpms_mp_mid ) {
									$cpms_mp_muser   = get_userdata( (int) $cpms_mp_mid );
									$cpms_mp_names[] = $cpms_mp_muser === false ? '#' . (int) $cpms_mp_mid : (string) $cpms_mp_muser->display_name;
								}
								echo esc_html( array() === $cpms_mp_names ? '—' : implode( '، ', $cpms_mp_names ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<?php if ( $cpms_mp_org_can['create'] ) : ?>
				<section class="cpms-mgmt-card">
					<h2>ایجاد درمانگاه جدید</h2>
					<?php $cpms_mp_form_open( 'org_create_clinic', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
						<?php
						$cpms_mp_field( 'cpms_entity_name', 'نام درمانگاه', '' );
						$cpms_mp_field( 'slug', 'شناسهٔ یکتا (لاتین)', '' );
						$cpms_mp_field( 'timezone', 'منطقهٔ زمانی IANA (مثال: Asia/Tehran)' );
						$cpms_mp_field( 'location_name', 'نام شعبهٔ اصلی' );
						AddressSelectorField::render( '', 'cpms-mgmt-address', 'address_province', 'address_city', 'cpms-mgmt-address' );
						$cpms_mp_field( 'address', 'نشانی' );
						$cpms_mp_field( 'phone', 'تلفن' );
						?>
						<button class="cpms-mgmt-btn" type="submit">ایجاد درمانگاه و شعبهٔ اصلی</button>
					</form>
				</section>
			<?php endif; ?>

			<?php if ( $cpms_mp_org_can['create'] ) : ?>
				<?php $cpms_mp_facility = $cpms_mp_service->facility_plan( $cpms_mp_actor, $cpms_mp_org_view['organization']['id'] ); ?>
				<section class="cpms-mgmt-card">
					<h2>راه‌اندازی مرکز (بیمارستان / ساختمان پزشکان)</h2>
					<?php if ( null === $cpms_mp_facility ) : ?>
						<?php $cpms_mp_form_open( 'org_facility_start', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
							<p class="cpms-mgmt-field">
								<label for="cpms-mgmt-facility-type">نوع مرکز</label>
								<select id="cpms-mgmt-facility-type" name="facility_type">
									<?php foreach ( \ClinicCore\Application\Onboarding\FacilityTypeCatalog::all() as $cpms_mp_type ) : ?>
										<option value="<?php echo esc_attr( (string) $cpms_mp_type['id'] ); ?>"><?php echo esc_html( (string) $cpms_mp_type['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="cpms-mgmt-field">
								<label for="cpms-mgmt-facility-structure">ساختار</label>
								<select id="cpms-mgmt-facility-structure" name="structure">
									<option value="hospital_network">شبکهٔ بیمارستانی (چند درمانگاه، فقط سرپایی)</option>
									<option value="medical_building">ساختمان پزشکان (درمانگاه‌های مستقل)</option>
									<option value="multi_doctor">درمانگاه چندپزشکی</option>
									<option value="single">مطب/واحد منفرد</option>
								</select>
							</p>
							<?php $cpms_mp_field( 'display_name', 'نام نمایشی مرکز', '', true ); ?>
							<button class="cpms-mgmt-btn" type="submit">آغاز راه‌اندازی</button>
						</form>
					<?php else : ?>
						<p class="cpms-mgmt-meta">
							نوع: <strong><?php echo esc_html( (string) $cpms_mp_facility['facility_type'] ); ?></strong>
							— جریان: <strong><?php echo esc_html( (string) $cpms_mp_facility['flow'] ); ?></strong>
							— ساختمان‌ها: <?php echo esc_html( (string) count( (array) $cpms_mp_facility['sites'] ) ); ?>
							— درمانگاه‌ها: <?php echo esc_html( (string) count( (array) $cpms_mp_facility['clinics'] ) ); ?>
							<?php echo '' !== (string) $cpms_mp_facility['activated_at'] ? ' — <strong>فعال‌شده</strong>' : ''; ?>
						</p>

						<?php if ( 'hospital_network' === (string) $cpms_mp_facility['flow'] && (int) $cpms_mp_facility['outpatient_ack_by'] <= 0 ) : ?>
							<?php $cpms_mp_form_open( 'org_facility_ack', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<p class="cpms-mgmt-meta">این سامانه فقط درمانگاه و فرایندهای سرپایی را پوشش می‌دهد؛ بستری، HIS و پروندهٔ بیمارستانی پشتیبانی نمی‌شود.</p>
								<button class="cpms-mgmt-btn" type="submit">تأیید محدودهٔ فقط سرپایی</button>
							</form>
						<?php endif; ?>

						<h3>افزودن ساختمان/سایت</h3>
						<?php $cpms_mp_form_open( 'org_facility_site', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
							<?php
							$cpms_mp_field( 'label', 'نام ساختمان', '', true );
							AddressSelectorField::render( '', 'cpms-mgmt-address', 'address_province', 'address_city', 'cpms-mgmt-address' );
							$cpms_mp_field( 'address', 'نشانی' );
							?>
							<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت ساختمان</button>
						</form>

						<h3>افزودن درمانگاه به برنامه</h3>
						<?php if ( array() !== (array) $cpms_mp_facility['sites'] ) : ?>
							<?php $cpms_mp_form_open( 'org_facility_clinic', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<?php $cpms_mp_field( 'cpms_entity_name', 'نام درمانگاه', '', true ); ?>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-site-key">ساختمان</label>
									<select id="cpms-mgmt-site-key" name="site_key">
										<?php foreach ( (array) $cpms_mp_facility['sites'] as $cpms_mp_site ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_site['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_site['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<?php $cpms_mp_field( 'timezone', 'منطقهٔ زمانی IANA', 'Asia/Tehran' ); ?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">افزودن درمانگاه (معلق)</button>
							</form>
						<?php else : ?>
							<p class="cpms-mgmt-empty">نخست یک ساختمان/سایت ثبت کنید؛ انتخاب ساختمان از فهرست مجاز انجام می‌شود.</p>
						<?php endif; ?>

						<?php $cpms_mp_selectors = $cpms_mp_service->facility_selectors( $cpms_mp_actor, $cpms_mp_org_view['organization']['id'] ); ?>
						<?php
						// Organization authority never implies Clinic authority: the Clinic-level
						// steps are only rendered for a Clinic the ACTOR may really configure.
						$cpms_mp_configurable = array_values(
							array_filter(
								$cpms_mp_selectors,
								static fn( array $cpms_mp_row ): bool => ! empty( $cpms_mp_row['can_configure'] )
							)
						);
						?>
						<?php if ( array() === $cpms_mp_configurable ) : ?>
							<p class="cpms-mgmt-meta" data-cpms-clinic-steps-delegated="1">گام‌های درمانگاهی (اتاق، پزشک، برنامه و سیاست رزرو) با مدیر تعیین‌شدهٔ همان درمانگاه در پورتال خودش انجام می‌شود؛ اختیار سازمانی به‌تنهایی اجازهٔ این گام‌ها را نمی‌دهد.</p>
						<?php endif; ?>
						<?php if ( array() !== $cpms_mp_configurable ) : ?>
							<h3>افزودن اتاق/مکان به درمانگاه <span class="cpms-mgmt-meta">(نیازمند اختیار درمانگاه)</span></h3>
							<?php $cpms_mp_form_open( 'org_facility_room', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-room-clinic">درمانگاه</label>
									<select id="cpms-mgmt-room-clinic" name="clinic_key">
										<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_sel['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-room-site">ساختمان (اختیاری)</label>
									<select id="cpms-mgmt-room-site" name="site_key">
										<?php foreach ( (array) $cpms_mp_facility['sites'] as $cpms_mp_primary_site ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_primary_site['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_primary_site['label'] ); ?></option>
										<?php endforeach; ?>
										<?php foreach ( (array) $cpms_mp_facility['sites'] as $cpms_mp_site ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_site['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_site['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<?php $cpms_mp_field( 'cpms_entity_name', 'نام اتاق/مکان', '', true ); ?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت اتاق</button>
							</form>
							<p class="cpms-mgmt-meta">ایجاد اتاق یک عملیات درمانگاهی است: اختیار سازمانی به‌تنهایی کافی نیست و مدیر تعیین‌شدهٔ همان درمانگاه (عضویت فعال + مجوز پیکربندی) لازم است.</p>
						<?php endif; ?>

						<?php if ( array() !== $cpms_mp_configurable ) : ?>
							<h3>اختصاص پزشک</h3>
							<?php $cpms_mp_form_open( 'org_facility_clinician', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-facl-clinic">درمانگاه</label>
									<select id="cpms-mgmt-facl-clinic" name="clinic_key">
										<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_sel['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-facl-room">اتاق/مکان</label>
									<select id="cpms-mgmt-facl-room" name="room_key[]">
										<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
											<?php foreach ( $cpms_mp_sel['rooms'] as $cpms_mp_room ) : ?>
												<option value="<?php echo esc_attr( (string) $cpms_mp_room['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] . ' — ' . (string) $cpms_mp_room['name'] ); ?></option>
											<?php endforeach; ?>
										<?php endforeach; ?>
									</select>
								</p>
								<?php
								$cpms_mp_field( 'target_user_id', 'شناسهٔ کاربر پزشک', '', true, 'number' );
								$cpms_mp_field( 'full_name', 'نام کامل (اگر پروفایل تازه است)' );
								?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">اختصاص پزشک</button>
							</form>

							<h3>برنامهٔ هفتگی پزشک</h3>
							<?php if ( array() !== array_merge( ...array_map( static fn( array $row ): array => $row['clinicians'], $cpms_mp_configurable ) ) ) : ?>
								<?php $cpms_mp_form_open( 'org_facility_schedule', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
									<p class="cpms-mgmt-field">
										<label for="cpms-mgmt-fsch-clinician">پزشک</label>
										<select id="cpms-mgmt-fsch-clinician" name="clinician_id">
											<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
												<?php foreach ( $cpms_mp_sel['clinicians'] as $cpms_mp_clinician_option ) : ?>
													<option value="<?php echo esc_attr( (string) $cpms_mp_clinician_option['id'] ); ?>"><?php echo esc_html( (string) $cpms_mp_clinician_option['full_name'] . ' — ' . (string) $cpms_mp_sel['name'] ); ?></option>
												<?php endforeach; ?>
											<?php endforeach; ?>
										</select>
									</p>
									<p class="cpms-mgmt-field">
										<label for="cpms-mgmt-fsch-clinic">درمانگاه</label>
										<select id="cpms-mgmt-fsch-clinic" name="clinic_key">
											<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
												<option value="<?php echo esc_attr( (string) $cpms_mp_sel['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</p>
									<p class="cpms-mgmt-field">
										<label for="cpms-mgmt-fsch-room">اتاق/مکان</label>
										<select id="cpms-mgmt-fsch-room" name="room_key">
											<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
												<?php foreach ( $cpms_mp_sel['rooms'] as $cpms_mp_room ) : ?>
													<option value="<?php echo esc_attr( (string) $cpms_mp_room['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] . ' — ' . (string) $cpms_mp_room['name'] ); ?></option>
												<?php endforeach; ?>
											<?php endforeach; ?>
										</select>
									</p>
									<p class="cpms-mgmt-field">
										<label for="cpms-mgmt-fsch-day">روز هفته</label>
										<select id="cpms-mgmt-fsch-day" name="day_of_week">
											<option value="0">شنبه</option><option value="1">یکشنبه</option><option value="2">دوشنبه</option>
											<option value="3">سه‌شنبه</option><option value="4">چهارشنبه</option><option value="5">پنجشنبه</option>
											<option value="6">جمعه</option>
										</select>
									</p>
									<?php
									$cpms_mp_field( 'start_time', 'ساعت شروع (HH:MM)', '09:00', true );
									$cpms_mp_field( 'end_time', 'ساعت پایان (HH:MM)', '12:00', true );
									?>
									<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت برنامه</button>
								</form>
							<?php else : ?>
								<p class="cpms-mgmt-empty">برای ثبت برنامه ابتدا یک پزشک را به درمانگاه اختصاص دهید.</p>
							<?php endif; ?>

							<h3>سیاست رزرو و ظرفیت</h3>
							<?php $cpms_mp_form_open( 'org_facility_booking', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-book-clinic">درمانگاه</label>
									<select id="cpms-mgmt-book-clinic" name="clinic_key">
										<?php foreach ( $cpms_mp_configurable as $cpms_mp_sel ) : ?>
											<option value="<?php echo esc_attr( (string) $cpms_mp_sel['key'] ); ?>"><?php echo esc_html( (string) $cpms_mp_sel['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<?php
								$cpms_mp_field( 'duration', 'مدت نوبت (دقیقه)', '20', true, 'number' );
								$cpms_mp_field( 'capacity', 'ظرفیت هر اسلات', '1', true, 'number' );
								$cpms_mp_field( 'lead', 'حداقل فاصلهٔ رزرو (ساعت)', '2', true, 'number' );
								$cpms_mp_field( 'future', 'حداکثر روز آینده', '30', true, 'number' );
								$cpms_mp_field( 'cancel', 'مهلت لغو (ساعت)', '6', true, 'number' );
								?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ذخیرهٔ سیاست رزرو</button>
							</form>
						<?php endif; ?>

						<h3>تعیین مدیر درمانگاه (صریح)</h3>
						<?php $cpms_mp_form_open( 'org_facility_designate', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
							<p class="cpms-mgmt-field">
								<label for="cpms-mgmt-desig-clinic">درمانگاه</label>
								<select id="cpms-mgmt-desig-clinic" name="clinic_key">
									<?php foreach ( (array) $cpms_mp_facility['clinics'] as $cpms_mp_plan_clinic ) : ?>
										<option value="<?php echo esc_attr( (string) $cpms_mp_plan_clinic['key'] ); ?>"><?php echo esc_html( (string) ( $cpms_mp_plan_clinic['name'] ?? $cpms_mp_plan_clinic['key'] ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<?php $cpms_mp_field( 'identifier', 'نام کاربری یا ایمیل مدیر (Subscriber موجود)', '', true ); ?>
							<p class="cpms-mgmt-meta">کاربر با همین شناسه در سرور پیدا می‌شود؛ این گام هیچ حساب تازه‌ای نمی‌سازد و هیچ نقش وردپرسی را تغییر نمی‌دهد.</p>
							<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">تعیین مدیر</button>
						</form>

						<?php $cpms_mp_facility_review = $cpms_mp_service->review_facility_setup( $cpms_mp_actor, $cpms_mp_org_view['organization']['id'] ); ?>
						<h3>بازبینی و فعال‌سازی اتمیک</h3>
						<?php if ( is_array( $cpms_mp_facility_review ) ) : ?>
							<?php if ( $cpms_mp_facility_review['ready'] ) : ?>
								<p class="cpms-mgmt-meta">همهٔ پیش‌شرط‌ها فراهم است؛ فعال‌سازی همهٔ درمانگاه‌ها را در یک تراکنش فعال می‌کند.</p>
							<?php else : ?>
								<ul class="cpms-mgmt-meta">
									<?php foreach ( $cpms_mp_facility_review['blocking'] as $cpms_mp_blocker ) : ?>
										<li><?php echo esc_html( (string) $cpms_mp_blocker ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php $cpms_mp_form_open( 'org_facility_activate', array( 'organization_id' => $cpms_mp_org_view['organization']['id'] ) ); ?>
								<button class="cpms-mgmt-btn" type="submit">فعال‌سازی اتمیک مرکز</button>
							</form>
						<?php endif; ?>
						<p class="cpms-mgmt-meta">این جریان همان سرویس راه‌اندازی موجود است که در پورتال فرانت‌اند اجرا می‌شود؛ اختیار سازمانی لازم است و هیچ اختیار درمانگاهی به مدیر سازمان داده نمی‌شود.</p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $cpms_mp_org_can['update'] || $cpms_mp_org_can['lifecycle'] || $cpms_mp_org_can['designate'] ) : ?>
				<section class="cpms-mgmt-card">
					<h2>مدیریت درمانگاه‌های سازمان</h2>
					<?php foreach ( $cpms_mp_org_view['clinics'] as $cpms_mp_row ) : ?>
						<h3><?php echo esc_html( $cpms_mp_row['name'] ); ?></h3>
						<?php if ( $cpms_mp_org_can['update'] ) : ?>
							<?php $cpms_mp_form_open( 'org_update_clinic', array( 'organization_id' => $cpms_mp_org_view['organization']['id'], 'clinic_id' => $cpms_mp_row['id'] ) ); ?>
								<?php
								$cpms_mp_field( 'cpms_entity_name', 'نام درمانگاه', $cpms_mp_row['name'] );
								AddressSelectorField::render( '', 'cpms-mgmt-address', 'address_province', 'address_city', 'cpms-mgmt-address' );
								$cpms_mp_field( 'address', 'نشانی' );
								$cpms_mp_field( 'phone', 'تلفن' );
								?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ذخیرهٔ پروفایل</button>
							</form>
						<?php endif; ?>

						<?php if ( $cpms_mp_org_can['lifecycle'] ) : ?>
							<?php $cpms_mp_form_open( 'org_clinic_status', array( 'organization_id' => $cpms_mp_org_view['organization']['id'], 'clinic_id' => $cpms_mp_row['id'], 'active' => 'active' === $cpms_mp_row['status'] ? 0 : 1 ) ); ?>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">
									<?php echo 'active' === $cpms_mp_row['status'] ? 'غیرفعال‌سازی درمانگاه' : 'فعال‌سازی درمانگاه'; ?>
								</button>
							</form>
						<?php endif; ?>

						<?php if ( $cpms_mp_org_can['designate'] ) : ?>
							<?php $cpms_mp_form_open( 'org_designate_clinic_manager', array( 'organization_id' => $cpms_mp_org_view['organization']['id'], 'clinic_id' => $cpms_mp_row['id'] ) ); ?>
								<p class="cpms-mgmt-field">
									<label for="cpms-mgmt-target-<?php echo esc_attr( (string) $cpms_mp_row['id'] ); ?>">شناسهٔ کاربر برای تعیین مدیر درمانگاه</label>
									<input id="cpms-mgmt-target-<?php echo esc_attr( (string) $cpms_mp_row['id'] ); ?>" name="target_user_id" type="number" min="1" required>
								</p>
								<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">تعیین مدیر درمانگاه</button>
							</form>
						<?php endif; ?>
					<?php endforeach; ?>
					<p class="cpms-mgmt-meta">تعیین مدیر درمانگاه فقط عضویت مدیریتی همان درمانگاه را ایجاد می‌کند و دسترسی بالینی یا مالی نمی‌دهد.</p>
				</section>
			<?php endif; ?>

			<?php if ( $cpms_mp_org_can['members'] ) : ?>
				<section class="cpms-mgmt-card">
					<h2>مدیران سازمان</h2>
					<ul>
						<?php foreach ( $cpms_mp_service->organization_managers( $cpms_mp_actor, $cpms_mp_org_view['organization']['id'] ) as $cpms_mp_manager ) : ?>
							<li>
								<?php echo esc_html( '' !== $cpms_mp_manager['display_name'] ? $cpms_mp_manager['display_name'] : '#' . (string) $cpms_mp_manager['wp_user_id'] ); ?>
								<span class="cpms-mgmt-badge <?php echo 'active' === $cpms_mp_manager['status'] ? '' : 'is-off'; ?>"><?php echo 'active' === $cpms_mp_manager['status'] ? 'فعال' : 'معلق'; ?></span>
								<?php if ( 'active' === $cpms_mp_manager['status'] && $cpms_mp_manager['wp_user_id'] !== $cpms_mp_actor ) : ?>
									<?php $cpms_mp_form_open( 'org_set_manager', array( 'organization_id' => $cpms_mp_org_view['organization']['id'], 'target_user_id' => $cpms_mp_manager['wp_user_id'], 'active' => 0 ) ); ?>
										<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">لغو عضویت مدیر</button>
									</form>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php $cpms_mp_form_open( 'org_set_manager', array( 'organization_id' => $cpms_mp_org_view['organization']['id'], 'active' => 1 ) ); ?>
						<p class="cpms-mgmt-field">
							<label for="cpms-mgmt-org-manager-target">شناسهٔ کاربر برای اعطای نقش مدیر سازمان</label>
							<input id="cpms-mgmt-org-manager-target" name="target_user_id" type="number" min="1" required>
						</p>
						<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">اعطای نقش مدیر سازمان</button>
					</form>
				</section>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( 0 < $cpms_mp_clinic_id && $cpms_mp_clinic_view === null ) : ?>
			<section class="cpms-mgmt-card">
				<h2>دسترسی به این درمانگاه مجاز نیست</h2>
				<p class="cpms-mgmt-empty">برای این درمانگاه عضویت فعال همراه با مجوز مدیریتی ندارید. شناسهٔ ارسالی به‌تنهایی مجوز ایجاد نمی‌کند.</p>
			</section>
		<?php endif; ?>

		<?php if ( $cpms_mp_clinic_view !== null ) : ?>
			<?php $cpms_mp_c = $cpms_mp_clinic_view['clinic']; ?>
			<section class="cpms-mgmt-card" data-cpms-clinic="<?php echo esc_attr( (string) $cpms_mp_c['id'] ); ?>">
				<h2>درمانگاه: <?php echo esc_html( $cpms_mp_c['name'] ); ?></h2>
				<p class="cpms-mgmt-meta">
					شناسه: <?php echo esc_html( (string) $cpms_mp_c['slug'] ); ?> •
					منطقهٔ زمانی: <?php echo esc_html( $cpms_mp_c['timezone'] ); ?> •
					وضعیت: <?php echo esc_html( 'active' === $cpms_mp_c['status'] ? 'فعال' : 'غیرفعال' ); ?>
				</p>

				<?php $cpms_mp_readiness = $cpms_mp_service->clinic_facility_readiness( $cpms_mp_actor, (int) $cpms_mp_c['id'] ); ?>
				<?php if ( is_array( $cpms_mp_readiness ) && ! empty( $cpms_mp_readiness['hospital'] ) ) : ?>
					<section class="cpms-mgmt-card" data-cpms-clinic-readiness="<?php echo esc_attr( $cpms_mp_readiness['ready'] ? 'ready' : 'blocked' ); ?>">
						<h3>تکمیل راه‌اندازی بیمارستان — همین درمانگاه</h3>
						<p class="cpms-mgmt-meta">
							این بخش فقط برای درمانگاهی است که مدیر آن هستید؛ اختیار سازمانی به‌تنهایی دسترسی این درمانگاه را نمی‌دهد و هیچ درمانگاه دیگری در این گزارش دیده نمی‌شود.
						</p>
						<?php if ( $cpms_mp_readiness['ready'] ) : ?>
							<p data-cpms-clinic-ready="1">✅ این درمانگاه همهٔ موارد لازم را دارد؛ فعال‌سازی نهایی را مدیر سازمان انجام می‌دهد.</p>
						<?php else : ?>
							<p data-cpms-clinic-ready="0">موارد باقی‌ماندهٔ این درمانگاه:</p>
							<ul>
								<?php foreach ( (array) $cpms_mp_readiness['blocking'] as $cpms_mp_blocker ) : ?>
									<li><?php echo esc_html( (string) $cpms_mp_blocker ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p class="cpms-mgmt-meta">مکان/اتاق، پزشکان و برنامهٔ هفتگی همین درمانگاه را در بخش‌های پایین همین صفحه ثبت کنید.</p>

						<h4>سیاست رزرو و ظرفیت</h4>
						<?php $cpms_mp_form_open( 'clinic_facility_booking', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
							<label>مدت هر نوبت (دقیقه) <input type="number" name="duration" min="5" max="240" value="15" required></label>
							<label>ظرفیت هر نوبت <input type="number" name="capacity" min="1" max="20" value="1" required></label>
							<label>حداقل فاصلهٔ رزرو (ساعت) <input type="number" name="lead" min="1" max="72" value="2" required></label>
							<label>حداکثر روز آینده <input type="number" name="future" min="1" max="365" value="30" required></label>
							<label>مهلت لغو (ساعت) <input type="number" name="cancel" min="0" max="168" value="12" required></label>
							<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ذخیرهٔ سیاست رزرو</button>
						</form>
					</section>
				<?php endif; ?>

				<h3>پروفایل درمانگاه</h3>
				<?php $cpms_mp_form_open( 'clinic_update_profile', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
					<?php
					$cpms_mp_field( 'cpms_entity_name', 'نام درمانگاه', $cpms_mp_c['name'] );
					AddressSelectorField::render( '', 'cpms-mgmt-address', 'address_province', 'address_city', 'cpms-mgmt-address' );
					$cpms_mp_field( 'address', 'نشانی', $cpms_mp_c['address'] );
					$cpms_mp_field( 'phone', 'تلفن', $cpms_mp_c['phone'] );
					?>
					<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ذخیرهٔ پروفایل</button>
				</form>

				<h3 id="cpms-mgmt-locations">شعبه‌ها (Location)</h3>
				<table class="cpms-mgmt-table">
					<thead><tr><th>نام</th><th>شناسه</th><th>وضعیت</th><th>عملیات</th></tr></thead>
					<tbody>
					<?php foreach ( $cpms_mp_clinic_view['locations'] as $cpms_mp_location ) : ?>
						<tr>
							<td><?php echo esc_html( $cpms_mp_location['name'] ); ?><?php echo $cpms_mp_location['is_primary'] ? ' <span class="cpms-mgmt-badge">اصلی</span>' : ''; ?></td>
							<td><?php echo esc_html( $cpms_mp_location['slug'] ); ?></td>
							<td><?php echo $cpms_mp_location['is_active'] ? 'فعال' : 'غیرفعال'; ?></td>
							<td>
								<?php $cpms_mp_form_open( 'clinic_location_active', array( 'clinic_id' => $cpms_mp_c['id'], 'location_id' => $cpms_mp_location['id'], 'active' => $cpms_mp_location['is_active'] ? 0 : 1 ) ); ?>
									<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">
										<?php echo $cpms_mp_location['is_active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی'; ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( array() === $cpms_mp_clinic_view['locations'] ) : ?>
						<tr><td colspan="4" class="cpms-mgmt-empty">شعبه‌ای ثبت نشده است.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3>افزودن شعبه</h3>
				<?php $cpms_mp_form_open( 'clinic_location_create', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
					<?php
					$cpms_mp_field( 'cpms_entity_name', 'نام شعبه', '' );
					$cpms_mp_field( 'slug', 'شناسهٔ یکتا (لاتین)', '' );
					$cpms_mp_field( 'timezone', 'منطقهٔ زمانی IANA', $cpms_mp_c['timezone'] );
					AddressSelectorField::render( '', 'cpms-mgmt-address', 'address_province', 'address_city', 'cpms-mgmt-address' );
					$cpms_mp_field( 'address', 'نشانی' );
					$cpms_mp_field( 'phone', 'تلفن' );
					?>
					<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ایجاد شعبه</button>
				</form>
			</section>

			<section class="cpms-mgmt-card">
				<h2>کارکنان و نقش‌های درمانگاه</h2>
				<table class="cpms-mgmt-table">
					<thead><tr><th>کاربر</th><th>نقش عضویت</th><th>وضعیت</th><th>انتساب نقش</th><th>دامنه و مجوزها</th></tr></thead>
					<tbody>
					<?php foreach ( $cpms_mp_clinic_view['staff'] as $cpms_mp_member ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== $cpms_mp_member['display_name'] ? $cpms_mp_member['display_name'] : '#' . (string) $cpms_mp_member['wp_user_id'] ); ?></td>
							<td><?php echo esc_html( $cpms_mp_member['role_key'] ); ?></td>
							<td><?php echo 'active' === $cpms_mp_member['status'] ? 'فعال' : 'معلق'; ?></td>
							<td>
								<?php if ( 'active' === $cpms_mp_member['status'] ) : ?>
									<?php $cpms_mp_form_open( 'clinic_staff_role', array( 'clinic_id' => $cpms_mp_c['id'], 'target_user_id' => $cpms_mp_member['wp_user_id'] ) ); ?>
										<select name="role_key" aria-label="نقش داخلی CPMS">
											<?php foreach ( $cpms_mp_clinic_view['assignable_roles'] as $cpms_mp_role_key ) : ?>
												<option value="<?php echo esc_attr( $cpms_mp_role_key ); ?>" <?php selected( $cpms_mp_role_key, $cpms_mp_member['role_key'] ); ?>><?php echo esc_html( $cpms_mp_role_key ); ?></option>
											<?php endforeach; ?>
										</select>
										<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت نقش</button>
									</form>
									<?php if ( array() !== $cpms_mp_clinic_view['custom_roles'] ) : ?>
										<?php $cpms_mp_form_open( 'clinic_staff_custom_role', array( 'clinic_id' => $cpms_mp_c['id'], 'target_user_id' => $cpms_mp_member['wp_user_id'] ) ); ?>
											<select name="role_key" aria-label="نقش سفارشی درمانگاه">
												<?php foreach ( $cpms_mp_clinic_view['custom_roles'] as $cpms_mp_custom ) : ?>
													<option value="<?php echo esc_attr( $cpms_mp_custom['role_key'] ); ?>"><?php echo esc_html( '' !== $cpms_mp_custom['display_name'] ? $cpms_mp_custom['display_name'] : $cpms_mp_custom['role_key'] ); ?></option>
												<?php endforeach; ?>
											</select>
											<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">نقش سفارشی</button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php $cpms_mp_form_open( 'clinic_staff_status', array( 'clinic_id' => $cpms_mp_c['id'], 'membership_id' => $cpms_mp_member['membership_id'], 'active' => 'active' === $cpms_mp_member['status'] ? 0 : 1 ) ); ?>
									<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">
										<?php echo 'active' === $cpms_mp_member['status'] ? 'تعلیق عضویت' : 'فعال‌سازی عضویت'; ?>
									</button>
								</form>
								<?php if ( 'active' === $cpms_mp_member['status'] ) : ?>
									<?php $cpms_mp_form_open( 'clinic_staff_locations', array( 'clinic_id' => $cpms_mp_c['id'], 'membership_id' => $cpms_mp_member['membership_id'] ) ); ?>
										<p class="cpms-mgmt-field">
											<label for="cpms-mgmt-mloc-<?php echo esc_attr( (string) $cpms_mp_member['membership_id'] ); ?>">شناسهٔ شعبه‌ها (خالی = کل درمانگاه)</label>
											<input id="cpms-mgmt-mloc-<?php echo esc_attr( (string) $cpms_mp_member['membership_id'] ); ?>" name="location_ids" type="text" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $cpms_mp_member['locations'] ) ) ); ?>">
										</p>
										<button class="cpms-mgmt-btn cpms-mgmt-btn--muted cpms-mgmt-btn--small" type="submit">ثبت دامنهٔ مکانی</button>
									</form>
									<?php if ( array() !== $cpms_mp_clinic_view['delegable_caps'] ) : ?>
										<?php $cpms_mp_form_open( 'clinic_staff_capability', array( 'clinic_id' => $cpms_mp_c['id'], 'membership_id' => $cpms_mp_member['membership_id'] ) ); ?>
											<select name="capability" aria-label="مجوز مؤثر">
												<?php foreach ( $cpms_mp_clinic_view['delegable_caps'] as $cpms_mp_cap ) : ?>
													<option value="<?php echo esc_attr( $cpms_mp_cap ); ?>"><?php echo esc_html( $cpms_mp_cap ); ?></option>
												<?php endforeach; ?>
											</select>
											<select name="effect" aria-label="نوع قاعده">
												<option value="grant">GRANT</option>
												<option value="deny">DENY</option>
												<option value="clear">حذف قاعده</option>
											</select>
											<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت مجوز</button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( array() !== $cpms_mp_member['capabilities'] ) : ?>
									<ul class="cpms-mgmt-meta">
										<?php foreach ( $cpms_mp_member['capabilities'] as $cpms_mp_rule ) : ?>
											<li><?php echo esc_html( $cpms_mp_rule['capability'] . ' — ' . strtoupper( $cpms_mp_rule['effect'] ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( array() === $cpms_mp_clinic_view['staff'] ) : ?>
						<tr><td colspan="5" class="cpms-mgmt-empty">عضوی ثبت نشده است.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3>افزودن کارمند (حساب موجود یا حساب تازهٔ Subscriber)</h3>
				<?php $cpms_mp_form_open( 'clinic_staff_enroll', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
					<?php
					$cpms_mp_field( 'identifier', 'ایمیل یا نام کاربری حساب موجود', '', true );
					?>
					<select name="role_key" aria-label="نقش عضویت درمانگاه">
						<?php foreach ( $cpms_mp_clinic_view['assignable_roles'] as $cpms_mp_role_key ) : ?>
							<option value="<?php echo esc_attr( $cpms_mp_role_key ); ?>"><?php echo esc_html( $cpms_mp_role_key ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">افزودن به درمانگاه</button>
				</form>
				<p class="cpms-mgmt-meta">حساب تازه فقط با نقش سراسری Subscriber ساخته می‌شود؛ هیچ نقش مدیریتی وردپرس از این پورتال اعطا نمی‌شود و رمز عبور هرگز نمایش داده نمی‌شود.</p>
				<p class="cpms-mgmt-meta">انتساب نقش، عضویت درمانگاه (role_key) را تغییر می‌دهد؛ نقش سراسری وردپرس و مجوزهای سراسری هرگز از این‌جا تغییر نمی‌کنند. نقش سفارشی فقط تا سقف مجوزهای مؤثر خودتان قابل واگذاری است.</p>
			</section>

			<section class="cpms-mgmt-card">
				<h2>پزشکان و برنامه‌ها</h2>
				<table class="cpms-mgmt-table">
					<thead><tr><th>پزشک</th><th>شناسهٔ بالینی</th><th>وضعیت</th></tr></thead>
					<tbody>
					<?php foreach ( $cpms_mp_clinic_view['clinicians'] as $cpms_mp_clinician ) : ?>
						<tr>
							<td><?php echo esc_html( $cpms_mp_clinician['full_name'] ); ?></td>
							<td><?php echo esc_html( (string) $cpms_mp_clinician['id'] ); ?></td>
							<td><?php echo $cpms_mp_clinician['is_active'] ? 'فعال' : 'غیرفعال'; ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( array() === $cpms_mp_clinic_view['clinicians'] ) : ?>
						<tr><td colspan="3" class="cpms-mgmt-empty">پزشکی ثبت نشده است.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<h3>ثبت پزشک</h3>
				<?php $cpms_mp_form_open( 'clinic_clinician_register', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
					<?php
					$cpms_mp_field( 'full_name', 'نام کامل پزشک', '', true );
					$cpms_mp_field( 'specialty', 'تخصص' );
					$cpms_mp_field( 'target_user_id', 'شناسهٔ کاربر وابسته (اختیاری)', '', false, 'number' );
					?>
					<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت پزشک</button>
				</form>
				<p class="cpms-mgmt-meta">اتصال حساب کاربری فقط برای عضویت فعال همین درمانگاه ممکن است و هر حساب تنها به یک رکورد پزشک متصل می‌شود؛ مشارکت چند‌کلینیکی با یک رکورد پزشک در هر درمانگاه بیان می‌شود.</p>

				<h3>برنامهٔ هفتگی</h3>
				<?php if ( array() !== $cpms_mp_clinic_view['clinicians'] && array() !== $cpms_mp_clinic_view['locations'] ) : ?>
					<?php $cpms_mp_form_open( 'clinic_schedule_create', array( 'clinic_id' => $cpms_mp_c['id'] ) ); ?>
						<p class="cpms-mgmt-field">
							<label for="cpms-mgmt-sched-clinician">پزشک</label>
							<select id="cpms-mgmt-sched-clinician" name="clinician_id">
								<?php foreach ( $cpms_mp_clinic_view['clinicians'] as $cpms_mp_clinician_option ) : ?>
									<option value="<?php echo esc_attr( (string) $cpms_mp_clinician_option['id'] ); ?>"><?php echo esc_html( $cpms_mp_clinician_option['full_name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="cpms-mgmt-field">
							<label for="cpms-mgmt-sched-location">شعبه</label>
							<select id="cpms-mgmt-sched-location" name="location_id">
								<?php foreach ( $cpms_mp_clinic_view['locations'] as $cpms_mp_location_option ) : ?>
									<?php if ( ! $cpms_mp_location_option['is_active'] ) : ?>
										<?php continue; ?>
									<?php endif; ?>
									<option value="<?php echo esc_attr( (string) $cpms_mp_location_option['id'] ); ?>"><?php echo esc_html( $cpms_mp_location_option['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="cpms-mgmt-field">
							<label for="cpms-mgmt-sched-day">روز هفته</label>
							<select id="cpms-mgmt-sched-day" name="day_of_week">
								<option value="0">شنبه</option><option value="1">یکشنبه</option><option value="2">دوشنبه</option>
								<option value="3">سه‌شنبه</option><option value="4">چهارشنبه</option><option value="5">پنجشنبه</option>
								<option value="6">جمعه</option>
							</select>
						</p>
						<?php
						$cpms_mp_field( 'start_time', 'ساعت شروع (HH:MM)', '09:00', true );
						$cpms_mp_field( 'end_time', 'ساعت پایان (HH:MM)', '12:00', true );
						?>
						<button class="cpms-mgmt-btn cpms-mgmt-btn--small" type="submit">ثبت برنامه</button>
					</form>
				<?php else : ?>
					<p class="cpms-mgmt-empty">برای ثبت برنامه به یک پزشک و یک شعبهٔ فعال نیاز است.</p>
				<?php endif; ?>
				<p class="cpms-mgmt-meta">تنظیم برنامهٔ نوبت‌دهی از قرارداد موجود نوبت‌دهی (کلینیک‌محور) استفاده می‌کند و به مجوزهای همان درمانگاه وابسته است؛ تعارض زمانی و قواعد شعبه دقیقاً همان قواعد REST است.</p>
			</section>

			<section class="cpms-mgmt-card">
				<h2>گزارش‌ها و مالی</h2>
				<?php if ( $cpms_mp_clinic_view['can_view_reports'] ) : ?>
					<p><a class="cpms-mgmt-tab" href="<?php echo esc_url( add_query_arg( 'cpms-module', 'reports', StaffPortalShell::portal_url() ) ); ?>">گزارش‌های عملیاتی (پورتال کارکنان)</a></p>
				<?php endif; ?>
				<?php if ( $cpms_mp_clinic_view['can_view_finance'] ) : ?>
					<p><a class="cpms-mgmt-tab" href="<?php echo esc_url( add_query_arg( 'cpms-module', 'finance', StaffPortalShell::portal_url() ) ); ?>">تسویه‌های در انتظار پرداخت (پورتال کارکنان)</a></p>
				<?php endif; ?>
				<?php if ( ! $cpms_mp_clinic_view['can_view_reports'] && ! $cpms_mp_clinic_view['can_view_finance'] ) : ?>
					<p class="cpms-mgmt-empty">دسترسی گزارش/مالی برای این درمانگاه مجاز نیست. دادهٔ بالینی یا مالی در این پورتال نمایش داده نمی‌شود.</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

	<?php endif; ?>

</div>
</body>
</html>
