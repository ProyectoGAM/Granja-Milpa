#!/usr/bin/env bash
set -Eeuo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
mode=${1:-code}
component=${2:-both}
case "$mode:$component" in
    code:both|code:backend|code:frontend|all:both) ;;
    *) printf 'Uso: bash security/run.sh {code [backend|frontend]|all}\n' >&2; exit 20 ;;
esac

for command in git docker python3 tar timeout; do
    command -v "$command" >/dev/null || { printf 'Falta %s\n' "$command" >&2; exit 20; }
done
docker compose version >/dev/null || exit 20
docker info >/dev/null || exit 20

umask 077
mkdir -p .security-reports
reports=$(mktemp -d "$root/.security-reports/scan.XXXXXXXX")
scratch=$(mktemp -d)
# Scanner UIDs need to traverse the bind mount. Only archived source and fixture inputs go here.
chmod 755 "$scratch"
umask 022
project="gam-sec-${reports##*.}"
project=${project,,}
export GAM_SECURITY_INPUT="$scratch"
compose=(docker compose --env-file /dev/null -p "$project" -f "$root/compose.security.yaml")
built_images=()
if [[ "$mode" == all ]]; then
    compose+=(-f "$root/security/compose.target.yaml")
    export GAM_SECURITY_API="gam-security-api:$project" GAM_SECURITY_WEB="gam-security-web:$project"
    GAM_SECURITY_APP_KEY=$(python3 -c 'import base64, secrets; print("base64:" + base64.b64encode(secrets.token_bytes(32)).decode())')
    export GAM_SECURITY_APP_KEY
    source security/scan-runtime.sh
fi
cleanup() {
    local result=$?
    trap - EXIT
    if ! "${compose[@]}" down -v --remove-orphans >> "$reports/cleanup.log" 2>&1; then
        result=20
    fi
    if ((${#built_images[@]})); then
        docker image rm "${built_images[@]}" >> "$reports/cleanup.log" 2>&1 || result=20
    fi
    python3 -c 'import shutil, sys; shutil.rmtree(sys.argv[1])' "$scratch" || result=20
    printf 'Reportes: %s\n' "$reports"
    exit "$result"
}
trap cleanup EXIT
trap 'exit 20' INT TERM

run_tool() {
    timeout 360 "${compose[@]}" run --no-deps --rm -T "$@"
}

status=0
record_failure() {
    status=20
    printf '%s\n' "$1" >> "$reports/errors.log"
}

scan_component() {
    local name=$1 repo=$2
    shift 2
    local sha
    sha=$(git -C "$repo" rev-parse HEAD) || { record_failure "$repo: falta checkout"; return; }
    printf '%s %s\n' "$repo" "$sha" >> "$reports/revisions.txt"
    mkdir -p "$scratch/$name"
    local paths=("$@")
    if [[ "$mode" == all ]]; then
        paths=()
    fi
    if ! git -C "$repo" archive "$sha" "${paths[@]}" | tar -xf - -C "$scratch/$name"; then
        record_failure "$repo: no se pudo preparar el snapshot"
        return
    fi
    if find "$scratch/$name" -type f \( -name '.env' -o -name '.env.*' -o -name '*.p12' -o -name '*.pfx' -o -name '*.key' \) \
        ! -name '*.example' ! -name '*.sample' -print -quit | grep -q .; then
        record_failure "$repo: el snapshot contiene configuración privada o material de claves"
        return
    fi
    touch "$scratch/$name.approved"

    (umask 077; run_tool semgrep scan --config /config/semgrep --metrics=off --disable-version-check --strict --json \
        --exclude '*.spec.ts' --exclude '*.test.ts' --exclude tests --exclude android --exclude ios "/input/$name" \
        > "$reports/sast-$name.json" 2> "$reports/sast-$name.log") ||
        record_failure "$repo: error de Semgrep"
}

scan_lock() {
    local name=$1 source=$2 manifest=$3 lock=$4
    mkdir -p "$scratch/$name"
    if ! cp "$scratch/$source/$manifest" "$scratch/$source/$lock" "$scratch/$name/"; then
        record_failure "$name: faltan manifests versionados"
        return
    fi
    local scope
    for scope in prod dev; do
        local flags=()
        [[ "$scope" == prod ]] || flags+=(--include-dev-deps)
        run_tool trivy fs --skip-db-update --scanners vuln --list-all-pkgs --format json \
            "${flags[@]}" "/input/$name" > "$reports/sca-$name-$scope.json" \
            2> "$reports/sca-$name-$scope.log" || record_failure "$name/$scope: error de Trivy"
    done
}

printf 'Granja-Milpa %s\n' "$(git rev-parse HEAD)" > "$reports/revisions.txt"
if [[ "$component" != frontend ]]; then
    scan_component backend GAM-Backend app routes bootstrap config database resources/js composer.json composer.lock package.json
    git -C GAM-Backend show HEAD:package-lock.json > "$scratch/backend/package-lock.json" ||
        { unlink "$scratch/backend/package-lock.json"; record_failure 'Backend: falta package-lock.json versionado'; }
fi
if [[ "$component" != backend ]]; then
    scan_component frontend GAM-Frontend src package.json package-lock.json
fi

db_ready=false
if run_tool trivy image --download-db-only --no-progress > "$reports/trivy-db.log" 2>&1 ||
   { sleep 3; run_tool trivy image --download-db-only --no-progress >> "$reports/trivy-db.log" 2>&1; }; then
    db_ready=true
    if [[ "$component" != frontend ]]; then
        scan_lock composer backend composer.json composer.lock
        scan_lock npm-backend backend package.json package-lock.json
    fi
    if [[ "$component" != backend ]]; then
        scan_lock npm-frontend frontend package.json package-lock.json
    fi
else
    record_failure 'Trivy: no se pudo descargar la base de vulnerabilidades'
fi

if [[ "$mode" == all && "$db_ready" == true && -f "$scratch/backend.approved" && -f "$scratch/frontend.approved" ]]; then
    scan_runtime
fi

if python3 security/check-results.py "$reports" "$component" "$status" "$mode"; then
    exit 0
else
    exit "$?"
fi
