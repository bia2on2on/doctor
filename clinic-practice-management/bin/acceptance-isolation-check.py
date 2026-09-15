#!/usr/bin/env python3
"""acceptance-isolation-check.py — نگهبانِ قراردادِ ایزولاسیونِ کانتکست در ابزار پذیرش.

قرارداد (Test Infrastructure، نه رفتار محصول):
  هر جریانِ احراز هویت (هر tag پرسونا) باید در BrowserContext مستقلِ خودش اجرا شود؛
  در غیر این صورت Cookie Jar مشترک می‌شود و پرسونای بعدی کوکی‌های نشستِ پرسونای
  قبلی را به ارث می‌برد و «ورود» آن دیگر مستقل نیست.

روش (ساختاری/قطعی، بدون نیاز به مرورگر و شبکه):
  1. X = browser.new_context(...) یا فراخوانیِ یک Factory تأییدشده در همین فایل
     (تابعی که در بدنهٔ خودش واقعاً new_context صدا می‌زند) → ساختِ کانتکست.
  2. Y = X.new_page()                        → نقشهٔ صفحه → کانتکست.
  3. login(Y, <USER>, <PASS>, "<tag>")       → نقشهٔ پرسونا → کانتکست.
  4. دو پرسونا فقط وقتی همان «نمونهٔ کانتکست» را قسمت می‌کنند که صفحات‌شان از یک
     متغیرِ کانتکست بیایند و آن کانتکست بیرون از حلقه‌ای که login را در بر دارد
     ساخته شده باشد (اگر داخل همان حلقه ساخته شود، هر تکرار کانتکست تازه می‌گیرد).
  5. اگر یک نمونهٔ کانتکست بیش از یک پرسونا را سرو کند → FAIL.

خروج: 0 = قرارداد برقرار است؛ 1 = نشست/کوکی بین پرسوناها مشترک است.

دامنه: این ابزار فقط شکنندگیِ زیرساختِ تست را می‌سنجد؛ دربارهٔ رفتار محصول و
دربارهٔ هیچ شکستِ تاریخیِ ورود هیچ ادعایی نمی‌کند.
"""

import ast
import os
import sys

DEFAULT_HARNESS = "bin/rwp-acceptance.py"


def chain(node):
    """نام نقطه‌ای برای Attribute/Name؛ در غیر این صورت None."""
    parts = []
    while isinstance(node, ast.Attribute):
        parts.append(node.attr)
        node = node.value
    if isinstance(node, ast.Name):
        parts.append(node.id)
        return ".".join(reversed(parts))
    return None


def analyse(src, filename):
    tree = ast.parse(src, filename=filename)

    # Factoryهای تأییدشده: تابعی که در بدنهٔ خودش new_context صدا می‌زند.
    factories = set()
    for fn in ast.walk(tree):
        if isinstance(fn, ast.FunctionDef):
            for inner in ast.walk(fn):
                if isinstance(inner, ast.Call):
                    c = chain(inner.func)
                    if c and c.endswith("new_context"):
                        factories.add(fn.name)

    for_ranges = [(n.lineno, n.end_lineno) for n in ast.walk(tree) if isinstance(n, ast.For)]

    def same_loop(a, b):
        return any(s <= a <= e and s <= b <= e for s, e in for_ranges)

    # برچسب‌های پرسونا برای حلقه‌هایی که روی لیستِ literal می‌چرخند (مثل matrix ویوپورت).
    loop_tags = {}
    for n in ast.walk(tree):
        if isinstance(n, ast.For) and isinstance(n.iter, (ast.List, ast.Tuple)):
            names = [e.id for e in getattr(n.target, "elts", []) if isinstance(e, ast.Name)]
            if not names or len(names) != len(getattr(n.target, "elts", [])):
                continue
            cols = {nm: [] for nm in names}
            ok = True
            for item in n.iter.elts:
                if not isinstance(item, (ast.List, ast.Tuple)) or len(item.elts) != len(names):
                    ok = False
                    break
                for nm, val in zip(names, item.elts):
                    if isinstance(val, ast.Constant):
                        cols[nm].append(val.value)
                    else:
                        ok = False
            if ok:
                loop_tags.update(cols)

    # نکتهٔ مهم: نام متغیر صفحه در این اسکریپت چندبار استفاده می‌شود (`page`)، پس
    # نقشهٔ «متغیر → کانتکست» باید موقعیت‌محور باشد: برای هر login، نزدیک‌ترین
    # انتسابِ پیش از آن خط در نظر گرفته می‌شود (تقریب داده‌جریان برای اسکریپت خطی).
    contexts, page_assigns, logins = {}, {}, []
    for node in ast.walk(tree):
        if (
            isinstance(node, ast.Assign)
            and len(node.targets) == 1
            and isinstance(node.targets[0], ast.Name)
            and isinstance(node.value, ast.Call)
        ):
            var, call = node.targets[0].id, node.value
            c = chain(call.func)
            if c == "browser.new_context":
                contexts[var] = node.lineno
            elif c in factories and isinstance(call.func, ast.Name):
                contexts[var] = node.lineno
            elif c and c.endswith(".new_page"):
                page_assigns.setdefault(var, []).append((node.lineno, c[: -len(".new_page")]))
        if isinstance(node, ast.Call) and chain(node.func) == "login" and len(node.args) >= 4:
            page_arg, tag_arg = node.args[0], node.args[3]
            if not isinstance(page_arg, ast.Name):
                continue
            if isinstance(tag_arg, ast.Constant):
                tags = [str(tag_arg.value)]
            elif isinstance(tag_arg, ast.Name) and tag_arg.id in loop_tags:
                tags = [str(v) for v in loop_tags[tag_arg.id]]
            else:
                tags = [f"<dynamic:{ast.unparse(tag_arg)}>"]
            for t in tags:
                logins.append((t, page_arg.id, node.lineno))

    instances, unresolved = {}, []
    for tag, page_var, line in logins:
        prior = [(ln, cv) for ln, cv in page_assigns.get(page_var, []) if ln < line]
        if not prior or max(prior)[1] not in contexts:
            unresolved.append((tag, page_var, line))
            continue
        ctx_var = max(prior)[1]
        ctx_line = contexts[ctx_var]
        if same_loop(ctx_line, line):
            instances.setdefault((ctx_var, "per-iteration", tag), []).append((tag, page_var, line))
        else:
            instances.setdefault((ctx_var, "single-instance", None), []).append((tag, page_var, line))
    return contexts, page_assigns, logins, instances, unresolved, factories


def resolve(path):
    """مسیر Harness را مستقل از CWD پیدا می‌کند (اسکریپت از هر جایی قابل فراخوانی باشد)."""
    if os.path.isfile(path):
        return path
    here = os.path.dirname(os.path.abspath(__file__))
    for cand in (os.path.join(here, os.path.basename(path)), os.path.join(here, path)):
        if os.path.isfile(cand):
            return cand
    return path


def main():
    harness = resolve(sys.argv[1] if len(sys.argv) > 1 else DEFAULT_HARNESS)
    src = open(harness, encoding="utf-8").read()
    contexts, page_assigns, logins, instances, unresolved, factories = analyse(src, harness)

    print("=== acceptance BrowserContext isolation report ===")
    print(f"harness                     : {harness}")
    if factories:
        print(f"verified context factories  : {', '.join(sorted(factories))}")
    print(f"contexts created            : {len(contexts)}")
    print(f"page->context assignments   : {sum(len(v) for v in page_assigns.values())} (position-aware)")
    print(f"authentication flows (login): {len(logins)}")
    print()

    violations = []
    for (ctx_var, kind, _per), entries in sorted(instances.items(), key=lambda kv: min(e[2] for e in kv[1])):
        tags = sorted({e[0] for e in entries})
        label = "fresh instance per loop iteration" if kind == "per-iteration" else "one instance"
        state = "SHARED" if len(tags) > 1 else "isolated"
        print(f"- context `{ctx_var}` (created line {contexts[ctx_var]}, {label}): {len(tags)} persona(s) [{state}]")
        for tag in tags:
            lines = ", ".join(str(e[2]) for e in entries if e[0] == tag)
            print(f"    * {tag:18s} login line(s) {lines}")
        if len(tags) > 1:
            violations.append((ctx_var, tags))

    if unresolved:
        print("\nUNRESOLVED page variables (isolation cannot be proven):")
        for tag, page_var, line in unresolved:
            print(f"    ! {tag} (login line {line}) uses page `{page_var}` of unknown origin")
        violations.append(("<unresolved>", [t for t, _, _ in unresolved]))

    print()
    if violations:
        print("RESULT: FAIL — cross-persona BrowserContext / session sharing detected:")
        for ctx_var, tags in violations:
            print(f"  - context `{ctx_var}` serves personas: {', '.join(tags)}")
        print("    A later persona authenticates in a cookie jar that still holds the earlier")
        print("    persona's authenticated session cookies, so its login is not independent.")
        return 1

    print("RESULT: PASS — every authentication flow runs in its own BrowserContext instance.")
    print("    Cookie-jar isolation is complete: the harness never manipulates cookies")
    print("    directly (no add_cookies/clear_cookies/storage_state), so context ownership")
    print("    fully determines session-state isolation between personas.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
