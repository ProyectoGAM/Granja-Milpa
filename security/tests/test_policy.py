import json
from importlib.machinery import SourceFileLoader
from importlib.util import module_from_spec, spec_from_loader
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest


script = Path(__file__).resolve().parents[1] / "check-results.py"
spec = spec_from_loader("security_policy", SourceFileLoader("security_policy", str(script)))
policy = module_from_spec(spec)
spec.loader.exec_module(policy)


class SecurityPolicyTest(unittest.TestCase):
    def test_scan_statuses(self):
        with TemporaryDirectory() as workdir:
            work = Path(workdir)
            (work / "revisions.txt").write_text("fixture revision\n")
            (work / "sast-frontend.json").write_text(json.dumps({
                "errors": [], "paths": {"scanned": ["fixture.ts"]}, "results": [],
            }))
            production = work / "sca-npm-frontend-prod.json"
            development = work / "sca-npm-frontend-dev.json"
            inventory = {"Target": "package-lock.json", "Packages": [{"Name": "fixture"}]}
            production.write_text(json.dumps({"Results": [inventory]}))
            development.write_text(json.dumps({"Results": [inventory]}))
            self.assertEqual(policy.evaluate(work, "frontend", 0), 0)

            inventory["Vulnerabilities"] = [
                {"VulnerabilityID": "FIXTURE", "PkgName": "fixture", "InstalledVersion": "1.0.0", "Severity": "HIGH"},
            ]
            production.write_text(json.dumps({"Results": [inventory]}))
            self.assertEqual(policy.evaluate(work, "frontend", 0), 10)

            development.unlink()
            self.assertEqual(policy.evaluate(work, "frontend", 0), 20)


if __name__ == "__main__":
    unittest.main()
