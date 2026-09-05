# GAM

Repositorio principal de GAM.

Este repositorio actúa como **orquestador del proyecto** y utiliza Git Submodules para mantener separados:

* `GAM-Backend`
* `GAM-Frontend`

Cada submódulo es un repositorio Git independiente, con su propio historial, ramas y releases.

Documentación Swagger UI en desarrollo: http://localhost:8080/docs/

## Estructura

```text
GAM/
├── GAM-Backend/
├── GAM-Frontend/
├── compose.dev.yaml
├── .gitmodules
└── README.md
```

El repositorio raíz guarda solamente la referencia al commit exacto utilizado de cada submódulo.

---

## Clonar el proyecto

La forma recomendada es:

```bash
git clone --recurse-submodules https://github.com/ProyectoGAM/GAM.git
cd GAM
```

Esto descarga automáticamente:

```text
GAM
├── GAM-Backend
└── GAM-Frontend
```

Si el repositorio ya fue clonado sin `--recurse-submodules`:

```bash
git submodule update --init --recursive
```

---

## Ver estado de los submódulos

```bash
git submodule status
```

También puede utilizarse:

```bash
git status
```

El repositorio principal detectará cuando alguno de los submódulos apunta a un commit diferente.

---

## Trabajar en Backend

Entrar al submódulo:

```bash
cd GAM-Backend
```

Actualizar la rama:

```bash
git switch main
git pull
```

Trabajar normalmente:

```bash
git add .
git commit -m "feat: add feature"
git push
```

### Datos de prueba locales

Con `APP_ENV=local`, el backend carga datos ficticios coherentes mediante sus seeders para facilitar las pruebas. Para reconstruir la base local:

```bash
docker compose -f compose.dev.yaml exec api php artisan migrate:fresh --seed --force
```

Cada módulo nuevo debe incluir su seeder de datos demo y registrarlo en el seeder local correspondiente, para que sus datos estén disponibles automáticamente en el ambiente local.

Luego volver al repositorio principal:

```bash
cd ..
```

Git mostrará algo similar a:

```text
modified: GAM-Backend (new commits)
```

Actualizar la referencia:

```bash
git add GAM-Backend
git commit -m "chore: update backend submodule"
git push
```

---

## Trabajar en Frontend

Entrar al submódulo:

```bash
cd GAM-Frontend
```

Actualizar:

```bash
git switch main
git pull
```

Trabajar normalmente:

```bash
git add .
git commit -m "feat: add feature"
git push
```

Volver al repositorio principal:

```bash
cd ..
```

Actualizar la referencia del submódulo:

```bash
git add GAM-Frontend
git commit -m "chore: update frontend submodule"
git push
```

---

## Actualizar todos los submódulos

Para traer los últimos commits configurados por los repositorios:

```bash
git submodule update --init --recursive
```

Para actualizar los submódulos siguiendo sus ramas remotas:

```bash
git submodule update --remote --recursive
```

Después revisar:

```bash
git status
```

Si cambiaron las referencias:

```bash
git add GAM-Backend GAM-Frontend
git commit -m "chore: update submodules"
git push
```

---

## Traer cambios del repositorio principal

Cuando otra persona actualice las referencias de los submódulos:

```bash
git pull
git submodule update --init --recursive
```

Es importante ejecutar ambos comandos.

`git pull` actualiza el repositorio principal.

`git submodule update` coloca cada submódulo exactamente en el commit registrado por GAM.

---

## Detached HEAD

Al ejecutar:

```bash
git submodule update
```

Git puede dejar un submódulo en estado:

```text
HEAD detached at ...
```

Esto es normal.

Antes de desarrollar dentro del submódulo, cambiar explícitamente a la rama correspondiente:

```bash
cd GAM-Backend
git switch main
```

o:

```bash
cd GAM-Frontend
git switch main
```

Nunca desarrollar directamente sobre un `detached HEAD`.

---

## Flujo recomendado

### 1. Actualizar GAM

```bash
git pull
git submodule update --init --recursive
```

### 2. Entrar al proyecto correspondiente

```bash
cd GAM-Backend
```

o:

```bash
cd GAM-Frontend
```

### 3. Cambiar a la rama de trabajo

```bash
git switch main
git pull
```

o crear una feature branch:

```bash
git switch -c feature/nueva-funcionalidad
```

### 4. Desarrollar y publicar

```bash
git add .
git commit -m "feat: nueva funcionalidad"
git push
```

### 5. Actualizar GAM

```bash
cd ..

git add GAM-Backend GAM-Frontend
git commit -m "chore: update submodules"
git push
```

---

## Desarrollo con Docker

Desde el repositorio raíz:

```bash
docker compose -f compose.dev.yaml up --build
```

El compose raíz permite levantar conjuntamente Backend y Frontend.

Para detenerlo:

```bash
docker compose -f compose.dev.yaml down
```

Para eliminar también los volúmenes:

```bash
docker compose -f compose.dev.yaml down -v
```

---

## Comandos útiles

Ver submódulos:

```bash
git submodule status
```

Inicializar submódulos:

```bash
git submodule update --init --recursive
```

Actualizar desde los remotos:

```bash
git submodule update --remote --recursive
```

Ejecutar un comando en todos los submódulos:

```bash
git submodule foreach git status
```

Actualizar todas las ramas `main`:

```bash
git submodule foreach "git switch main && git pull"
```

Ver URLs configuradas:

```bash
git config --file .gitmodules --get-regexp path
git config --file .gitmodules --get-regexp url
```

---

## Regla importante

Los cambios deben commitearse primero dentro del submódulo.

Incorrecto:

```text
Modificar GAM-Frontend
↓
Commit únicamente en GAM
```

Correcto:

```text
Modificar GAM-Frontend
↓
Commit + push en GAM-Frontend
↓
Volver a GAM
↓
Commit de la nueva referencia del submódulo
↓
Push de GAM
```

El repositorio principal nunca contiene directamente el código fuente de Backend o Frontend.

Solamente registra el commit exacto que debe utilizar de cada uno.

---

## Resumen

Para empezar a trabajar:

```bash
git pull
git submodule update --init --recursive
```

Para desarrollar:

```bash
cd GAM-Backend
# o GAM-Frontend

git switch main
git pull
```

Después del trabajo:

```bash
git add .
git commit -m "feat: ..."
git push

cd ..

git add GAM-Backend GAM-Frontend
git commit -m "chore: update submodules"
git push
```

Esta separación permite mantener Backend y Frontend como proyectos independientes mientras GAM funciona como repositorio principal de integración y desarrollo.
