#!/usr/bin/env python3
"""Run real WP service races on a disposable site; publish aggregate facts only.

OTP delivery is replaced by a test-only, in-memory SMS adapter. Worker files,
OTP codes, tokens, raw logs, identities and audit rows are never printed or
uploaded. A file barrier synchronizes the exact OTP consume and booking hold
boundaries; worker-process and database checks must both succeed.
"""
from collections import Counter
import json
import math
import os
from pathlib import Path
import re
import statistics
import subprocess
import sys
import time

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
WP_ROOT = os.environ.get("CPMS_PROOF_WP_PATH", "/home/runner/mtx-upgrade")
DIR = Path("/tmp/cpms-production-proof")
WORKER = HERE / "wp-production-race-proof.php"
EXPORTER = ROOT / "tests/audit-chain/export.php"
VERIFIER = ROOT / "tests/audit-chain/verify.py"
BASE_ENV = {**os.environ, "CPMS_PROOF_DIR": str(DIR)}


def require(condition, code):
    if not condition:
        raise RuntimeError(code)


def wp_command():
    return ["wp", "eval-file", str(WORKER), "--path=" + WP_ROOT, "--allow-root"]


def report_proof_failure(path):
    # Only literal assertion markers present in the checked-in worker may be
    # published. The remaining private file can contain arbitrary WP output.
    allowed = set(re.findall(r"'([a-z][a-z0-9-]{0,63})'", WORKER.read_text(encoding="utf-8")))
    try:
        for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
            match = re.fullmatch(r"PROOF_FAILURE ([a-z][a-z0-9-]{0,63})", line)
            if match and match.group(1) in allowed:
                print("::error title=Production proof assertion::" + match.group(1))
    except OSError:
        pass


def db_rows(query):
    proc = subprocess.run(
        ["mysql", "--protocol=TCP", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
         "--batch", "--skip-column-names", "cpms_mtx_upgrade", "-e", query],
        capture_output=True, text=True, check=False, timeout=30,
    )
    require(proc.returncode == 0, "database-query")
    return [line.split("\t") for line in proc.stdout.splitlines() if line]


def db_scalar(query):
    rows = db_rows(query)
    require(len(rows) == 1 and len(rows[0]) == 1, "database-result")
    return int(rows[0][0])


def status():
    keys = {"Innodb_row_lock_waits", "Innodb_row_lock_time", "Innodb_deadlocks"}
    rows = db_rows("SHOW GLOBAL STATUS WHERE Variable_name IN "
                   "('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_deadlocks')")
    values = {name: int(value) for name, value in rows if name in keys and value.isdigit()}
    # MariaDB exposes Innodb_deadlocks in GLOBAL STATUS. MySQL 8 commonly
    # exposes the equivalent enabled counter only through INNODB_METRICS.
    if "Innodb_deadlocks" not in values:
        metrics = db_rows("SELECT `COUNT` FROM information_schema.INNODB_METRICS "
                          "WHERE NAME='lock_deadlocks' AND STATUS='enabled'")
        if len(metrics) == 1 and len(metrics[0]) == 1 and metrics[0][0].isdigit():
            values["Innodb_deadlocks"] = int(metrics[0][0])
    return values


def delta(before, after, key):
    return after[key] - before[key] if key in before and key in after and after[key] >= before[key] else None


def independent_chain():
    export = subprocess.Popen(
        ["wp", "eval-file", str(EXPORTER), "--path=" + WP_ROOT, "--allow-root"],
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=BASE_ENV,
    )
    verifier = subprocess.Popen(
        [sys.executable, str(VERIFIER), "--suffix", "10"], stdin=export.stdout,
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
    )
    export.stdout.close()
    output, _ = verifier.communicate(timeout=90)
    export_rc = export.wait(timeout=15)
    require(export_rc == 0 and verifier.returncode == 0, "independent-chain-process")
    result = json.loads(output)
    suffix = result.get("suffix", {})
    require(result.get("ok") is True and suffix.get("ok") is True, "independent-chain")
    require(suffix.get("checked") == 10 and suffix.get("anchor") == "predecessor", "independent-suffix")
    return int(result["checked"])


def run_group(label, count, mode):
    workers = []
    logs = []
    started = time.monotonic()
    try:
        for index in range(count):
            log = open(DIR / ("worker-" + label + "-" + str(index) + ".private"), "wb")
            logs.append(log)
            env = {**BASE_ENV, "CPMS_PROOF_MODE": mode, "CPMS_PROOF_ROUND": label, "CPMS_PROOF_INDEX": str(index)}
            workers.append(subprocess.Popen(wp_command(), env=env, stdout=log, stderr=subprocess.STDOUT))
        deadline = time.monotonic() + 170
        while len(list(DIR.glob("ready-" + label + "-*"))) < count:
            if any(worker.poll() is not None for worker in workers):
                codes = Counter()
                for result_file in DIR.glob("result-" + label + "-*.json"):
                    data = json.loads(result_file.read_text(encoding="utf-8"))
                    codes[data.get("code", "UNKNOWN")] += 1
                print("::error title=Production race::worker failed before barrier; categories=" + json.dumps(codes, sort_keys=True))
                for log_path in DIR.glob("worker-" + label + "-*.private"):
                    report_proof_failure(log_path)
                raise RuntimeError("worker-before-barrier")
            require(time.monotonic() < deadline, "barrier-timeout")
            time.sleep(0.05)
        (DIR / ("release-" + label)).touch(mode=0o600)
        for worker in workers:
            worker.wait(timeout=120)
        if not all(worker.returncode == 0 for worker in workers):
            for log_path in DIR.glob("worker-" + label + "-*.private"):
                report_proof_failure(log_path)
            raise RuntimeError("worker-exit")
        results = [json.loads((DIR / ("result-" + label + "-" + str(index) + ".json")).read_text(encoding="utf-8"))
                   for index in range(count)]
        times = sorted(float(result["elapsed_ms"]) for result in results)
        return results, {"p50_ms": round(statistics.median(times), 1),
                         "p95_ms": round(times[math.ceil(len(times) * 0.95) - 1], 1),
                         "max_ms": round(times[-1], 1), "group_s": round(time.monotonic() - started, 2)}
    finally:
        for worker in workers:
            if worker.poll() is None:
                worker.terminate()
        for worker in workers:
            if worker.poll() is None:
                try:
                    worker.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    worker.kill()
                    worker.wait()
        for log in logs:
            log.close()


def main():
    # This exact /tmp directory is owned solely by this disposable job. Refuse
    # reuse rather than deleting anything that might be a prior investigator's.
    require(not DIR.exists(), "proof-directory-exists")
    DIR.mkdir(mode=0o700)
    os.umask(0o077)
    with open(DIR / "setup.private", "wb") as log:
        setup = subprocess.run(wp_command(), env={**BASE_ENV, "CPMS_PROOF_MODE": "setup"},
                               stdout=log, stderr=subprocess.STDOUT, timeout=90, check=False)
    if setup.returncode != 0:
        report_proof_failure(DIR / "setup.private")
        raise RuntimeError("fixture-setup")
    fixture = json.loads((DIR / "fixture.json").read_text(encoding="utf-8"))
    for size in ("10", "25", "50"):
        require(int(fixture["slots"][size]["id"]) > 0, "fixture-slot")

    before = status()
    otp_before = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_VERIFY_OK'")
    otp, timing = run_group("otp", 10, "otp-verify")
    counts = Counter(result["status"] for result in otp)
    require(counts == {"success": 1, "consumed": 9}, "otp-single-use")
    require(db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_VERIFY_OK'") == otp_before + 1, "otp-audit-count")
    after = status()
    print("::notice title=OTP race::workers=10 success=1 rejected=9 " + json.dumps(timing, sort_keys=True)
          + " db_global_waits=" + str(delta(before, after, "Innodb_row_lock_waits"))
          + " db_global_deadlocks=" + str(delta(before, after, "Innodb_deadlocks")))

    before = status()
    failed_before = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_VERIFY_FAIL'")
    locked_before = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='CLINIC_OTP_LOCKED'")
    wrong, timing = run_group("wrong", 10, "otp-wrong")
    counts = Counter(result["status"] for result in wrong)
    require(counts == {"invalid": 4, "locked": 6}, "otp-lockout-race")
    require(all(result["persian"] for result in wrong), "otp-persian-errors")
    require(all(result["retry_valid"] for result in wrong if result["status"] == "locked"), "otp-lockout-retry")
    wrong_id = int(fixture["wrong_id"])
    require(wrong_id > 0, "otp-lockout-fixture")
    state = db_rows("SELECT attempts,locked_until IS NOT NULL,consumed_at IS NULL FROM wp_cpms_otp_tokens WHERE id=" + str(wrong_id))
    require(state == [["5", "1", "1"]], "otp-lockout-state")
    require(db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_VERIFY_FAIL'") == failed_before + 5, "otp-failure-audit")
    require(db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='CLINIC_OTP_LOCKED'") == locked_before + 5, "otp-locked-audit")
    after = status()
    print("::notice title=OTP wrong-code race::workers=10 invalid=4 locked=6 attempts=5 persian=ok retry_after=ok "
          + json.dumps(timing, sort_keys=True)
          + " db_global_waits=" + str(delta(before, after, "Innodb_row_lock_waits"))
          + " db_global_deadlocks=" + str(delta(before, after, "Innodb_deadlocks")))

    with open(DIR / "boundaries.private", "wb") as log:
        boundary = subprocess.run(wp_command(), env={**BASE_ENV, "CPMS_PROOF_MODE": "boundaries"},
                                  stdout=log, stderr=subprocess.STDOUT, timeout=90, check=False)
    if boundary.returncode != 0:
        report_proof_failure(DIR / "boundaries.private")
        raise RuntimeError("otp-boundaries")
    print("::notice title=OTP boundaries::cooldown=ok daily=ok ttl_expired=ok lockout=ok unlock=ok "
          "persian=ok retry_after=ok fixture_clock=adjusted")

    with open(DIR / "rollback.private", "wb") as log:
        rollback = subprocess.run(wp_command(), env={**BASE_ENV, "CPMS_PROOF_MODE": "rollback"},
                                  stdout=log, stderr=subprocess.STDOUT, timeout=90, check=False)
    if rollback.returncode != 0:
        report_proof_failure(DIR / "rollback.private")
        raise RuntimeError("booking-audit-rollback")
    print("::notice title=Booking rollback::mandatory_audit=failed capacity=restored holds=0 guard=restored")

    for size in (10, 25, 50):
        label = str(size)
        before = status()
        results, timing = run_group(label, size, "booking")
        counts = Counter(result["status"] for result in results)
        if counts != {"booked": 1, "slot_taken": size - 1}:
            codes = Counter(result["code"] for result in results)
            print("::error title=Booking race::workers=" + label + " status=" + json.dumps(counts, sort_keys=True)
                  + " codes=" + json.dumps(codes, sort_keys=True))
            raise RuntimeError("booking-outcomes")
        require(all(result["replay"] for result in results if result["status"] == "booked"), "idempotent-replay")
        slot = int(fixture["slots"][label]["id"])
        row = db_rows("SELECT capacity,booked_count,held_count FROM wp_cpms_schedule_slots WHERE id=" + str(slot))
        require(row == [["1", "1", "0"]], "slot-capacity")
        require(db_scalar("SELECT COUNT(*) FROM wp_cpms_appointments WHERE slot_id=" + str(slot)) == 1, "appointment-count")
        require(db_scalar("SELECT COUNT(*) FROM wp_cpms_slot_holds WHERE slot_id=" + str(slot) + " AND status='converted'") == 1, "converted-hold")
        require(db_scalar("SELECT COUNT(*) FROM wp_cpms_slot_holds WHERE slot_id=" + str(slot) + " AND status='active'") == 0, "orphan-hold")
        require(db_scalar("SELECT COUNT(*) FROM wp_cpms_slot_holds WHERE slot_id=" + str(slot)) == 1, "hold-count")
        rows = independent_chain()
        after = status()
        print("::notice title=Booking race::workers=" + label + " winners=1 losers=" + str(size - 1)
              + " appointments=1 active_holds=0 replay=ok full_chain=ok suffix=predecessor chain_rows=" + str(rows)
              + " " + json.dumps(timing, sort_keys=True)
              + " db_global_waits=" + str(delta(before, after, "Innodb_row_lock_waits"))
              + " db_global_wait_ms=" + str(delta(before, after, "Innodb_row_lock_time"))
              + " db_global_deadlocks=" + str(delta(before, after, "Innodb_deadlocks")))
    # Five fresh numbers, three simultaneous requests each, synchronized
    # immediately before acquiring the per-mobile issuance guard.
    issuance_ok = True
    for issue_round in range(5):
        label = "issue" + str(issue_round)
        mobile = "09129991" + str(issue_round).zfill(3)
        req_before = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_REQUEST'")
        denial_before = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_DENIED_COOLDOWN'")
        issue_results, timing = run_group(label, 3, "otp-issue")
        counts = Counter(result["status"] for result in issue_results)
        challenges = db_scalar("SELECT COUNT(*) FROM wp_cpms_otp_tokens WHERE mobile='" + mobile + "' AND purpose='login'")
        messages = db_scalar("SELECT COUNT(*) FROM wp_cpms_sms_messages WHERE recipient='" + mobile + "' AND event='otp' AND status='SENT'")
        req_audits = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_REQUEST'") - req_before
        denial_audits = db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='OTP_DENIED_COOLDOWN'") - denial_before
        denials_ok = all(result["persian"] and result["retry_valid"] for result in issue_results if result["status"] == "cooldown")
        passed = (counts == {"issued": 1, "cooldown": 2} and challenges == 1 and messages == 1
                  and req_audits == 1 and denial_audits == 2 and denials_ok)
        issuance_ok = issuance_ok and passed
        title = "notice" if passed else "error"
        print("::" + title + " title=OTP issuance race::round=" + str(issue_round + 1) + " workers=3 statuses="
              + json.dumps(counts, sort_keys=True) + " challenges=" + str(challenges) + " sent=" + str(messages)
              + " req_audits=" + str(req_audits) + " denial_audits=" + str(denial_audits)
              + " persian_retry=" + ("ok" if denials_ok else "failed") + " " + json.dumps(timing, sort_keys=True))
    # Three distinct real service writers pause immediately before locking the
    # audit guard: a clinical note, a captured partial payment and an SMS test.
    before = status()
    actions = ("NOTE_CREATED", "PAYMENT_CAPTURE", "SMS_TEST_SENT")
    audit_before = {action: db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='" + action + "'")
                    for action in actions}
    mixed, timing = run_group("mixed", 3, "mixed")
    require(Counter(result["status"] for result in mixed) == {"clinical": 1, "finance": 1, "sms": 1}, "mixed-outcomes")
    visit = int(fixture["mixed"]["clinical_visit"])
    invoice = int(fixture["mixed"]["invoice"])
    require(visit > 0 and invoice > 0, "mixed-fixture")
    require(db_scalar("SELECT COUNT(*) FROM wp_cpms_clinical_notes WHERE visit_id=" + str(visit)) == 1, "mixed-note")
    require(db_scalar("SELECT COUNT(*) FROM wp_cpms_payments WHERE invoice_id=" + str(invoice) + " AND status='captured'") == 1, "mixed-payment")
    require(db_rows("SELECT status,paid_amount,balance FROM wp_cpms_invoices WHERE id=" + str(invoice)) == [["partial", "5.00", "95.00"]], "mixed-invoice")
    for action in actions:
        require(db_scalar("SELECT COUNT(*) FROM wp_cpms_audit_logs WHERE action='" + action + "'") == audit_before[action] + 1, "mixed-audits")
    rows = independent_chain()
    after = status()
    print("::notice title=Mixed audit writers::clinical_note=1 payment_capture=1 sms_test=1 synchronized=audit_guard "
          "full_chain=ok suffix=predecessor chain_rows=" + str(rows) + " " + json.dumps(timing, sort_keys=True)
          + " db_global_waits=" + str(delta(before, after, "Innodb_row_lock_waits"))
          + " db_global_wait_ms=" + str(delta(before, after, "Innodb_row_lock_time"))
          + " db_global_deadlocks=" + str(delta(before, after, "Innodb_deadlocks")))
    require(issuance_ok, "otp-issuance-race")
    print("PRODUCTION_RACE_PROOF otp_single_use=10 otp_issuance=5x3 booking=10,25,50 mixed_writers=clinical,payment,sms adapter=in_memory chain=independent")


if __name__ == "__main__":
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        # Only fixed-stage messages and a type are published, not exception
        # payloads, SQL, credentials, raw worker logs or synthetic identifiers.
        code = str(error) if type(error) is RuntimeError and str(error) in {
            "proof-directory-exists", "database-query", "database-result", "independent-chain-process",
            "independent-chain", "independent-suffix", "worker-before-barrier", "barrier-timeout",
            "worker-exit", "fixture-setup", "fixture-slot", "otp-single-use", "otp-audit-count",
            "otp-lockout-race", "otp-issuance-race", "otp-persian-errors", "otp-lockout-retry", "otp-lockout-fixture", "otp-lockout-state",
            "otp-failure-audit", "otp-locked-audit", "otp-boundaries", "booking-audit-rollback", "booking-outcomes", "idempotent-replay", "slot-capacity", "appointment-count",
            "converted-hold", "orphan-hold", "hold-count", "mixed-outcomes", "mixed-fixture", "mixed-note",
            "mixed-payment", "mixed-invoice", "mixed-audits",
        } else "unexpected"
        print("::error title=Production race::failed=" + code)
        sys.exit(1)
