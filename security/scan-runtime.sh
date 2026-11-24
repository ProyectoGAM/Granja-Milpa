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
        ) > "$scratch/production-$model.json" ||
           ! python3 security/check-compose.py "$model" < "$scratch/production-$model.json" > "$reports/hardening-$model.log" 2>&1; then
            record_failure "Hardening de Compose falló para $model"
            return
        fi
    done
    if ! "${compose[@]}" config --format json | python3 security/check-compose.py fixture \
        > "$reports/hardening-fixture.log" 2>&1; then
        record_failure 'La configuración de DAST no está aislada'
        return
    fi

    local refs
    refs=$(python3 -c 'import json, sys; services = json.load(open(sys.argv[1]))["services"]; print(*(services[n]["image"] for n in ("postgres", "redis", "swagger-ui", "gateway")), sep="\n")' "$scratch/production-backend.json") || {
        record_failure 'No se pudo obtener el inventario de imágenes de producción'
        return
    }
    local auxiliary=()
    mapfile -t auxiliary <<< "$refs"
    local images=("$GAM_SECURITY_API" "$GAM_SECURITY_WEB" "${auxiliary[@]}")
    local name ref id alias service container
    local -A image_ids=()
    for name in backend frontend; do
        ref=$GAM_SECURITY_API
        [[ "$name" == backend ]] || ref=$GAM_SECURITY_WEB
        if ! timeout 900 docker build --platform linux/amd64 --provenance=false --target runtime -t "$ref" "$scratch/$name" \
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
        image_ids[$name]=$id
        printf '%s %s %s\n' "$name" "$ref" "$id" >> "$reports/images.txt"
        if (( index >= 2 )); then
            alias="gam-security-$name:$project"
            if ! docker image tag "$id" "$alias"; then
                record_failure "No se pudo fijar la imagen $name"
                return
            fi
            export "GAM_SECURITY_${name^^}=$alias"
            built_images+=("$alias")
        fi
        if (( index < 2 )); then
            local user
            user=$(docker image inspect --format '{{.Config.User}}' "$id")
            if [[ -z "$user" || "$user" == root* || "$user" == 0 || "$user" == 0:* ]]; then
                record_failure "El runtime $name no declara un usuario sin privilegios"
            fi
        fi
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
    for service in api app-init web postgres redis swagger-ui gateway; do
        name=$service
        case "$service" in
            api|app-init) name=backend ;;
            web) name=frontend ;;
            swagger-ui) name=swagger ;;
        esac
        container=$("${compose[@]}" ps -aq "$service")
        if [[ -z "$container" ]] || [[ $(docker inspect --format '{{.Image}}' "$container") != "${image_ids[$name]}" ]]; then
            record_failure "El fixture $service no está ejecutando la imagen analizada"
            return
        fi
    done

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
