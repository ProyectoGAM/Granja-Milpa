# Sourced by run.sh. The caller owns the scratch directory and Compose project.
scan_runtime() {
    local model
    for model in backend frontend; do
        if ! (
            cd "$scratch/$model"
            env RELEASE_SHA=security-fixture EDGE_NETWORK=fixture-edge APP_URL=https://web.test \
                APP_KEY="$GAM_SECURITY_APP_KEY" DB_DATABASE=fixture DB_USERNAME=fixture DB_PASSWORD=fixture \
                GAM_REDIS_PASSWORD=fixture SESSION_DOMAIN=web.test IDENTITY_PIN_PEPPER=fixture \
                SANCTUM_STATEFUL_DOMAINS=web.test DEMO_DB_RESET_ENABLED=false \
                docker compose --env-file /dev/null -f compose.prod.yaml config --format json
        ) | python3 security/check-compose.py "$model" > "$reports/hardening-$model.log" 2>&1; then
            record_failure "Hardening de Compose falló para $model"
        fi
    done
    if ! "${compose[@]}" config --format json | python3 security/check-compose.py fixture \
        > "$reports/hardening-fixture.log" 2>&1; then
        record_failure 'La configuración de DAST no está aislada'
        return
    fi

    local images=(
        "$GAM_SECURITY_API" "$GAM_SECURITY_WEB"
        postgres:18-alpine redis:8-alpine
        docker.swagger.io/swaggerapi/swagger-ui:v5.32.14
        nginxinc/nginx-unprivileged:1.28-alpine
    )
    local name ref id
    for name in backend frontend; do
        ref=$GAM_SECURITY_API
        [[ "$name" == backend ]] || ref=$GAM_SECURITY_WEB
        if ! docker build --platform linux/amd64 --target runtime -t "$ref" "$scratch/$name" \
            > "$reports/build-$name.log" 2>&1; then
            record_failure "No se pudo construir runtime $name"
            return
        fi
        built_images+=("$ref")
    done

    local names=(backend frontend postgres redis swagger gateway)
    for index in "${!images[@]}"; do
        ref=${images[index]}
        name=${names[index]}
        if (( index >= 2 )) && ! docker pull --platform linux/amd64 "$ref" > "$reports/pull-$name.log" 2>&1; then
            record_failure "No se pudo descargar $name"
            return
        fi
        id=$(docker image inspect --format '{{.Id}}' "$ref") || { record_failure "Imagen ausente: $name"; return; }
        printf '%s %s %s\n' "$name" "$ref" "$id" >> "$reports/images.txt"
        if docker image save -o "$scratch/image.tar" "$id"; then
            chmod 644 "$scratch/image.tar"
            run_tool trivy image --skip-db-update --input /input/image.tar --scanners vuln \
                --list-all-pkgs --format json > "$reports/image-$name.json" 2> "$reports/image-$name.log" ||
                record_failure "No se pudo escanear $name"
            unlink "$scratch/image.tar"
        else
            record_failure "No se pudo exportar $name"
        fi
    done

    if ! "${compose[@]}" up -d --wait --wait-timeout 180 web > "$reports/fixture.log" 2>&1; then
        "${compose[@]}" logs --no-color >> "$reports/fixture.log" 2>&1
        record_failure 'El entorno HTTP no inició'
        return
    fi

    local probe_status=0
    "${compose[@]}" exec -T api php < security/check-http.php > "$reports/http.json" || probe_status=$?
    if (( probe_status != 0 )); then
        (( probe_status == 10 )) || record_failure 'Falló el transporte de la prueba HTTP'
        return
    fi

    if ! timeout 180 "${compose[@]}" up --no-deps --exit-code-from zap zap > "$reports/zap.log" 2>&1; then
        record_failure 'ZAP no completó el análisis pasivo'
        return
    fi
    "${compose[@]}" cp zap:/zap/wrk/zap.json "$reports/zap.json" ||
        record_failure 'ZAP no generó el reporte esperado'
}
