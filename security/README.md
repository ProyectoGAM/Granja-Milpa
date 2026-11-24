# Suite SSDLC de GAM

Instalá Docker Engine con Compose v2.24.4 o posterior, Bash, Git, Python 3 y `timeout` de GNU coreutils. Las imágenes apuntan a Linux amd64. En equipos ARM, Docker necesita emulación. Inicializá los submódulos antes de iniciar la suite.

```bash
git submodule update --init --recursive
bash security/run.sh code
bash security/run.sh code backend
bash security/run.sh code frontend
bash security/run.sh all
```

El análisis toma los archivos versionados en el `HEAD` de cada submódulo presente. Para probar cambios locales, usá un commit de trabajo. En GitHub Actions se comprueban los SHA de entrada. El modo `code` ejecuta Semgrep y Trivy sobre el código y los locks. El modo `all` también construye los runtimes, analiza las imágenes, verifica el Compose de producción sin secretos reales y ejecuta el recorrido HTTP fijo y pasivo de ZAP en redes internas.

Cada corrida guarda sus resultados debajo de `.security-reports/`. El código 0 indica éxito, 10 indica un hallazgo bloqueante y 20 indica una prueba incompleta o un error operativo. Abrí `summary.md` para ubicar la etapa. Los logs y JSON crudos son locales. CI publica solo el resumen filtrado. Para un fallo de arranque, revisá en orden el build, las migraciones, FPM, gateway y web. Si falla la base de Trivy, comprobá acceso al registro y espacio disponible. El script crea un proyecto y volúmenes propios para cada corrida.

Para abrir un PR de seguridad de un submódulo, primero debe existir un SHA público de la raíz que contenga la suite y apunte a commits públicos compatibles. Configurá ese SHA completo en `GAM_SECURITY_BASE_SHA` en los dos repositorios de componentes. Cada `release.yml` espera la suite completa antes de etiquetar o desplegar. Las revisiones del proxy, TLS, backups y artefacto desplegado requieren evidencia aparte. [Registro de riesgos](../docs/seguridad-gam.md)
