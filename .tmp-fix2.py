import io

def load(p):
    return io.open(p, encoding='utf-8').read()

def save(p, s):
    io.open(p, 'w', encoding='utf-8').write(s)

def rep(s, old, new, label, count=1):
    assert s.count(old) == count, 'ANCHOR FAIL: %s (count=%d)' % (label, s.count(old))
    return s.replace(old, new, count)

# =====================================================================
# 1) PRODUCT — MedicalFileService: indistinguishable denial envelope
# =====================================================================
p = 'clinic-practice-management/src/Application/Clinical/MedicalFileService.php'
s = load(p)

s = rep(s, """    private function auditAndThrow(int $wpUserId, string $resourceType, int $resourceId, string $message): void""",
"""    private function auditAndThrow(
        int $wpUserId,
        string $resourceType,
        int $resourceId,
        string $message,
        ?string $publicMessage = null
    ): void""", 'auditAndThrow sig')

s = rep(s, """        throw ClinicalException::of('CLINIC_NOT_FOUND', $message, 404);""",
"""        // Envelope بیرونی باید **عیناً** مثل «یافت نشد» باشد تا وجودِ ردیفِ
        // Clinic/Organization دیگر قابل تمایز نباشد؛ دلیلِ رد فقط در Audit می‌ماند.
        throw ClinicalException::of('CLINIC_NOT_FOUND', $publicMessage ?? $message, 404);""", 'auditAndThrow throw')

# file-path denials share the canonical "file not found" envelope
s = rep(s, """                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست');""",
"""                $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');""",
    'file deny 1', count=2)
s = rep(s, """            $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست');""",
"""            $this->auditAndThrow($actorUserId, 'file', $fileId, 'دسترسی به این فایل مجاز نیست', 'فایل یافت نشد');""",
    'file deny 2')
s = rep(s, """            $this->auditAndThrow($actorUserId, $resourceType, $resourceId, 'دسترسی به این فایل مجاز نیست');""",
"""            $this->auditAndThrow(
                $actorUserId,
                $resourceType,
                $resourceId,
                'دسترسی به این فایل مجاز نیست',
                $resourceType === 'file' ? 'فایل یافت نشد' : 'بیمار یافت نشد'
            );""", 'assertStaffClinic deny')

save(p, s)
print('product: denial envelope normalized')

# =====================================================================
# 2) TESTS
# =====================================================================
t = 'clinic-practice-management/tests/Integration/ClinicTenantIsolationTest.php'
s = load(t)

# 2a) fourth reserved clinic
s = rep(s, """    private const CLINIC_C = 61003;""",
"""    private const CLINIC_C = 61003;

    private const CLINIC_D = 61004;""", 'const D')
s = rep(s, """        $this->insertClinic(self::CLINIC_C, $this->orgB, 'iso-clinic-c');
        $this->locA = $this->insertLocation(self::CLINIC_A, 'iso-loc-a');""",
"""        $this->insertClinic(self::CLINIC_C, $this->orgB, 'iso-clinic-c');
        $this->locC = $this->insertLocation(self::CLINIC_C, 'iso-loc-c');
        $this->insertClinic(self::CLINIC_D, $this->orgA, 'iso-clinic-d');
        $this->locD = $this->insertLocation(self::CLINIC_D, 'iso-loc-d');
        $this->locA = $this->insertLocation(self::CLINIC_A, 'iso-loc-a');""", 'setUp clinics')
s = rep(s, """    private int $locB = 0;""",
"""    private int $locB = 0;

    private int $locC = 0;

    private int $locD = 0;""", 'loc props')
s = rep(s, """        $this->writeStorageSettings([self::CLINIC_A, self::CLINIC_B, self::CLINIC_C]);""",
"""        $this->writeStorageSettings([self::CLINIC_A, self::CLINIC_B, self::CLINIC_C, self::CLINIC_D]);""", 'settings')

# 2b) no clinic-1 decoy (it contaminated other suites that legitimately own id 1);
#     the non-1 assertion keeps its witness via CLINIC_C + the new exact-scope probe.
s = rep(s, """        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA, 1307)['visitId'];
        // ردیفِ نمایه‌ساز در Clinic 1: اگر حدسِ clinic_id=1 در VisitService باقی
        // باشد، Today دقیقاً دامنهٔ A نیست (ردیفِ Clinic 1 هم می‌آید).
        $this->seedQueueRow(1, $this->primaryLocationOf(1), 1308);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);""",
"""        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA, 1307)['visitId'];
        // همهٔ Clinicهای این fixture id ≠ 1 دارند؛ ردیفِ Clinik دیگر (C) هم‌زمان
        // درج می‌شود تا «دامنهٔ دقیق» سنجیده شود. (نمونه‌سازی روی clinic_id=1 در
        // این suite ممنوع است: آن شناسه متعلق به فیکسچر کلاس‌های دیگر است و
        // آلوده‌کردنش آن‌ها را می‌شکند — شاهدِ «نه Clinic 1» در
        // testQueueIsExactDomainForMultiMembershipSecretary انجام می‌شود.)
        $this->seedQueueRow(self::CLINIC_C, $this->locC, 1308);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);""", 'decoy')

# 2c) new exact-domain probe (non-vacuous witness for "not clinic 1")
s = rep(s, """    /** ۱۵) صف نباید Clinicها را از Organization دیگر ببیند. */""",
"""    /**
     * ۱۵) منشیِ عضوِ A و C: Today با context A باید **دقیقاً** دامنهٔ A باشد —
     * این همان شاهدِ «clinic_id=1 نیست» است: اگر حدسِ Clinic دیگری در سرویس
     * بماند، دامنه یا خالی می‌شود یا با ردیفِ C مخلوط.
     */
    public function testQueueIsExactDomainForMultiMembershipSecretary(): void
    {
        $visitA = $this->seedQueueRow(self::CLINIC_A, $this->locA, 1309)['visitId'];
        $rowC = $this->seedQueueRow(self::CLINIC_C, $this->locC, 1310);
        $sec = $this->seedStaff('cpms_secretary', [self::CLINIC_A, self::CLINIC_C]);
        wp_set_current_user($sec);

        $inA = $this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_A]);
        $this->assertSame(200, $inA->get_status(), $this->body($inA));
        $this->assertSame([$visitA], $this->queueIds($inA), 'context A ⇒ فقط دامنهٔ A');

        $inC = $this->call('GET', self::NS . '/secretary/today', [], ['X-CPMS-Clinic-Id' => (string) self::CLINIC_C]);
        $this->assertSame(200, $inC->get_status(), $this->body($inC));
        $this->assertSame([$rowC['visitId']], $this->queueIds($inC), 'context C ⇒ فقط دامنهٔ C (نه A، نه Clinic 1)');
    }

    /** ۱۶) صف نباید Clinicها را از Organization دیگر ببیند. */""", 'new probe')

# 2d) renumber the remaining section markers only where they collide (kept stable
#     where unchanged) — skip: comments are documentation only.

# 2e) org-boundary probe: patient self-upload (no staff membership in C needed)
s = rep(s, """        [$patientC] = $this->seedPatientWithUser(self::CLINIC_C, 'PatC8');
        $fileC = $this->seedFile(self::CLINIC_C, $patientC, 'patient_visible', 'org-b-file.pdf');
        $manager = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);""",
"""        [$patientC, $userC] = $this->seedPatientWithUser(self::CLINIC_C, 'PatC8');
        // آپلود توسط خودِ بیمارِ C (مسیر patientUpload — بدون مرز staff)، تا
        // شاهدِ «خواندنِ کارکنانِ Clinic A از Organization دیگر» واقعی بماند و
        // fixture به عضویتِ ساختگیِ C نیاز پیدا نکند.
        $fileC = $this->seedFile(self::CLINIC_C, $patientC, 'patient_visible', 'org-b-file.pdf', null, $userC);
        $manager = $this->seedStaff('cpms_secretary', [self::CLINIC_A]);""", 't8 upload')

# 2f) residue witness in tearDown
s = rep(s, """        $this->purgeReserveRows();""",
"""        $this->purgeReserveRows();

        /*
         * شاهدِ باقی‌مانده: پس از پاک‌سازی، هیچ ردیفِ fixture نباید در DB بماند.
         * اگر روزی جدولِ جدیدی به دامنهٔ این کلاس اضافه شود و در purge جا بماند،
         * همین‌جا گزارش می‌شود (نه به‌شکل یک assertion بی‌ربط در کلاسِ بعدی).
         */
        global $wpdb;
        $residue = [];
        foreach (
            [
                'cpms_patients' => 'clinic_id',
                'cpms_visits' => 'clinic_id',
                'cpms_medical_attachments' => 'clinic_id',
                'cpms_clinicians' => 'clinic_id',
                'cpms_locations' => 'clinic_id',
                'cpms_clinic_memberships' => 'clinic_id',
                'cpms_settings' => 'clinic_id',
                'cpms_notifications' => 'clinic_id',
            ] as $table => $col
        ) {
            $n = (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . $table . ' WHERE ' . $col . ' >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
            if ($n > 0) {
                $residue[] = $table . '=' . $n;
            }
        }
        self::assertSame([], $residue, 'CPMS_FIXTURE_RESIDUE (نشتیِ fixture به کلاس‌های بعدی): ' . implode(', ', $residue));""", 'residue')

# 2g) drop the now-unused clinic-1 location helper
s = rep(s, """    private function primaryLocationOf(int $clinicId): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId
            )
        );
        if ($id > 0) {
            return $id;
        }

        return $this->insertLocation($clinicId, 'iso-loc-decoy-' . $clinicId);
    }

""", "", 'drop primaryLocationOf')

save(t, s)
print('tests patched')
