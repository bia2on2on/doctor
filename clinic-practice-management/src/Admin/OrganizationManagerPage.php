<?php

declare(strict_types=1);
namespace ClinicCore\Admin;

use ClinicCore\Application\Onboarding\OrganizationClinicProvisioningService;
use ClinicCore\Bootstrap\App;

/** Classic RTL admin screen; its selectors are display only, domain authorization is repeated on write. */
final class OrganizationManagerPage {
    public const PAGE='cpms-organizations';
    public static function register(): void {
        add_action('admin_menu',static function(): void { add_submenu_page(CpmsAdminMenu::parentSlug(),'سازمان‌ها و درمانگاه‌ها','سازمان‌ها و درمانگاه‌ها','read',self::PAGE,[self::class,'render']); });
        add_action('admin_post_cpms_provision_organization_clinic',[self::class,'handle']);
    }
    public static function render(): void {
        $service=App::organization_clinic_provisioning_service();
        $orgs=$service->organizations_for_manager((int)get_current_user_id());
        echo '<div class="wrap" dir="rtl"><h1>مدیریت سازمان و درمانگاه‌ها</h1>';
        if(!$orgs){echo '<div class="notice notice-warning"><p>برای این حساب، عضویت فعال و مجازِ مدیر سازمان ثبت نشده است. دسترسی مدیر وردپرس به‌تنهایی مجوز ایجاد درمانگاه نیست.</p></div></div>';return;}
        echo '<p>این اختیار فقط برای ایجاد درمانگاه در سازمان انتخاب‌شده است و دسترسی بالینی، مالی یا پرونده‌ای ایجاد نمی‌کند.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="cpms_provision_organization_clinic">';
        wp_nonce_field('cpms_provision_organization_clinic');
        echo '<table class="form-table"><tbody><tr><th><label for="org">سازمان</label></th><td><select id="org" name="organization_id" required><option value="">انتخاب صریح سازمان</option>';
        foreach($orgs as $org) echo '<option value="'.esc_attr((string)$org['id']).'">'.esc_html($org['name']).'</option>';
        echo '</select></td></tr>';
        foreach(['name'=>'نام درمانگاه','slug'=>'شناسهٔ یکتا (لاتین)','timezone'=>'منطقهٔ زمانی IANA','location_name'=>'نام مکان اصلی','address'=>'نشانی','phone'=>'تلفن'] as $key=>$label){$value=$key==='timezone'?'':($key==='location_name'?'':'');echo '<tr><th><label for="'.$key.'">'.esc_html($label).'</label></th><td><input class="regular-text" id="'.$key.'" name="'.$key.'" value="'.esc_attr($value).'" '.(in_array($key,['name','slug','timezone'],true)?'required':'').' maxlength="'.($key==='address'?'255':($key==='phone'?'32':'190')).'"></td></tr>';}
        echo '</tbody></table><p><button class="button button-primary">ایجاد درمانگاه و مکان اصلی</button></p></form><h2>درمانگاه‌های سازمان‌های مجاز</h2><ul>';
        foreach($service->clinics_for_manager((int)get_current_user_id()) as $c) echo '<li>'.esc_html($c['name']).' ('.esc_html($c['slug']).')</li>';
        echo '</ul></div>';
    }
    public static function handle(): void {
        if(!is_user_logged_in()) wp_die(esc_html__('دسترسی ندارید','cpms'),403);
        if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'cpms_provision_organization_clinic')) wp_die(esc_html__('درخواست نامعتبر است','cpms'),403);
        try {
            App::organization_clinic_provisioning_service()->provision((int)get_current_user_id(),isset($_POST['organization_id'])?absint($_POST['organization_id']):0,isset($_POST['name'])?sanitize_text_field(wp_unslash($_POST['name'])):'',isset($_POST['slug'])?sanitize_title(wp_unslash($_POST['slug'])):'',isset($_POST['timezone'])?sanitize_text_field(wp_unslash($_POST['timezone'])):'',isset($_POST['location_name'])?sanitize_text_field(wp_unslash($_POST['location_name'])):null,isset($_POST['address'])?sanitize_text_field(wp_unslash($_POST['address'])):null,isset($_POST['phone'])?sanitize_text_field(wp_unslash($_POST['phone'])):null);
        } catch(\Throwable $e) {
            wp_die(esc_html__('ایجاد درمانگاه انجام نشد؛ دسترسی یا داده‌ها معتبر نیستند و تغییری ثبت نشد.','cpms'),403);
        }
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&created=1')); exit;
    }
}
