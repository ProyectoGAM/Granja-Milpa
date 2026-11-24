import json
import sys


def require(condition, message):
    if not condition:
        raise ValueError(message)


def check_restrictions(services):
    for name, service in services.items():
        require(not service.get("privileged"), f"{name}: privileged")
        require(service.get("network_mode") != "host", f"{name}: host networking")
        for volume in service.get("volumes", []):
            require(volume.get("source") not in {"/var/run/docker.sock", "/run/docker.sock"},
                    f"{name}: Docker socket")


def restricted(service):
    return ("ALL" in service.get("cap_drop", []) and
            any(value.replace("=", ":") == "no-new-privileges:true"
                for value in service.get("security_opt", [])))


def verify(config, mode):
    services = config["services"]
    check_restrictions(services)
    if mode == "fixture":
        targets = {"web", "gateway", "api", "app-init", "postgres", "redis", "swagger-ui", "zap"}
        for name in targets:
            service = services[name]
            require(not service.get("ports") and not service.get("external_links"), f"{name}: external exposure")
            for network in service["networks"]:
                require(network in {"dast-front", "dast-back"}, f"{name}: foreign network")
                definition = config["networks"][network]
                require(definition.get("internal") and not definition.get("external"), f"{network}: not isolated")
        require(set(services["zap"]["networks"]) == {"dast-front"}, "ZAP must use only the web network")
        return

    if mode == "backend":
        for name in ("postgres", "redis", "api", "gateway"):
            require(not services[name].get("ports"), f"{name}: published port")
        values = services["api"]["environment"]
        require(values.get("APP_DEBUG") == "false", "debug enabled")
        require(values.get("SESSION_SECURE_COOKIE") == "true", "session cookie is insecure")
        require(values.get("DEMO_DB_RESET_ENABLED") == "false", "demo reset enabled")
        require(restricted(services["api"]), "API lacks privilege restrictions")
        edge = services["gateway"]
    elif mode == "frontend":
        edge = services["web"]
    else:
        raise ValueError("unknown Compose model")
    require(edge.get("read_only") and restricted(edge), "HTTP runtime lacks privilege restrictions")


if __name__ == "__main__":
    try:
        verify(json.load(sys.stdin), sys.argv[1])
    except (ValueError, KeyError) as exc:
        sys.exit(str(exc))
    print(f"Compose {sys.argv[1]} verificado")
