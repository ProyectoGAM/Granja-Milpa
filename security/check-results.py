import json
import sys
from datetime import date, datetime, timezone
from pathlib import Path


def evaluate(directory, component, operational, mode="code"):
    findings = []
    errors = []
    components = ("backend", "frontend") if component == "both" else (component,)

    def read(name, parser):
        try:
            findings.extend(parser(json.loads((directory / f"{name}.json").read_text())))
        except (OSError, ValueError, TypeError, AttributeError, KeyError) as exc:
            errors.append(f"{name}: {type(exc).__name__}")

    for target in components:
        read(f"sast-{target}", lambda report: sast(report))
        locks = ("composer", "npm-backend") if target == "backend" else ("npm-frontend",)
        for lock in locks:
            production = set()
            read(f"sca-{lock}-prod", lambda report: sca(report, lock, production, False))
            read(f"sca-{lock}-dev", lambda report: sca(report, lock, production, True))

    if mode == "all":
        for target in ("backend", "frontend", "postgres", "redis", "swagger", "gateway"):
            read(f"image-{target}", lambda report: image(report, target))
        read("http", http)
        if not any(item["tool"] == "http" and item["blocking"] for item in findings):
            read("zap", zap)

    if (directory / "errors.log").is_file():
        errors.extend((directory / "errors.log").read_text().splitlines())
    try:
        apply_exceptions(findings)
    except (OSError, ValueError, KeyError, TypeError) as exc:
        errors.append(f"exceptions.json: {type(exc).__name__}")
    result = 20 if errors or operational else 10 if any(item["blocking"] for item in findings) else 0
    summary = {
        "result": result,
        "revisions": (directory / "revisions.txt").read_text().splitlines(),
        "errors": errors,
        "findings": findings,
    }
    (directory / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n")
    lines = [f"# Seguridad GAM\n\nResultado: {result}\n", *summary["revisions"]]
    lines.extend(f"- Error: {item}" for item in errors)
    lines.extend(f"- {item['tool']} {item['severity']} {item['id']} · {item['target']}" for item in findings)
    (directory / "summary.md").write_text("\n".join(lines) + "\n")
    return result


def sast(report):
    if report["errors"] or not report["paths"]["scanned"]:
        raise ValueError("incomplete scan")
    for item in report["results"]:
        severity = item["extra"]["severity"]
        yield dict(tool="semgrep", id=item["check_id"], severity=severity,
                   target=item["path"].removeprefix("/input/"), line=item["start"]["line"], blocking=severity == "ERROR")


def sca(report, lock, production, dev):
    expected = "composer.lock" if lock == "composer" else "package-lock.json"
    tables = [entry for entry in report["Results"] if Path(entry["Target"]).name == expected]
    if not any(entry.get("Packages") for entry in tables):
        raise ValueError("missing inventory")
    for table in tables:
        for item in table.get("Vulnerabilities") or []:
            key = (item["VulnerabilityID"], item["PkgName"], item["InstalledVersion"])
            if dev and key in production:
                continue
            if not dev:
                production.add(key)
            severity = item["Severity"]
            yield dict(tool="trivy", id=key[0], package=key[1], version=key[2], severity=severity,
                       target=lock, scope="dev" if dev else "prod",
                       blocking=severity == "CRITICAL" or (not dev and severity == "HIGH"))


def image(report, target):
    if not any(entry.get("Class") == "os-pkgs" and entry.get("Packages") for entry in report["Results"]):
        raise ValueError("missing OS inventory")
    for table in report["Results"]:
        for item in table.get("Vulnerabilities") or []:
            severity = item["Severity"]
            yield dict(tool="trivy-image", id=item["VulnerabilityID"], target=target,
                       package=item["PkgName"], version=item["InstalledVersion"], severity=severity,
                       blocking=severity in {"HIGH", "CRITICAL"})


def http(results):
    expected = {"/", "/auth", "/api/v1/health", "/api/v1/me", "/sanctum/csrf-cookie"}
    if {entry["path"] for entry in results} != expected:
        raise ValueError("missing HTTP coverage")
    for entry in results:
        if not entry["passed"]:
            yield dict(tool="http", id="response-contract", target=entry["path"], severity="HIGH", blocking=True)


def zap(report):
    for site in report["site"]:
        for alert in site["alerts"]:
            risk = int(alert["riskcode"])
            yield dict(tool="zap", id=alert["pluginid"], target="gam-local", severity=str(risk), blocking=risk >= 3)


def apply_exceptions(findings):
    path = Path(__file__).with_name("exceptions.json")
    entries = json.loads(path.read_text())
    today = datetime.now(timezone.utc).date()
    keys_by_tool = {
        "semgrep": ("tool", "id", "target", "line"),
        "trivy": ("tool", "id", "target", "package", "version", "scope"),
        "trivy-image": ("tool", "id", "target", "package", "version"),
        "zap": ("tool", "id", "target"),
    }
    for entry in entries:
        fields = keys_by_tool[entry["tool"]]
        until = date.fromisoformat(entry["expires"])
        opened = date.fromisoformat(entry["approved_on"])
        if not 0 < (until - opened).days <= 30 or opened > today:
            raise ValueError("invalid exception period")
        for key in (*fields, "issue", "approver", "reason", "compensating_control"):
            if not entry[key] or "*" in str(entry[key]):
                raise ValueError("invalid exception field")
        if until <= today:
            continue
        for finding in findings:
            if all(finding.get(key) == entry[key] for key in fields):
                finding["exception"] = {"issue": entry["issue"], "expires": entry["expires"]}
                finding["blocking"] = False


if __name__ == "__main__":
    sys.exit(evaluate(Path(sys.argv[1]), sys.argv[2], int(sys.argv[3]), *sys.argv[4:]))
