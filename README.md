<div align="center">

# 📜 eScriptorium Proxy

### From historical pages to structured text.

A Laravel API for orchestrating **OCR and handwritten text recognition** with [eScriptorium](https://gitlab.com/scripta/escriptorium): submit images, follow the workflow, retrieve your results.

[![Release v1.0.0](https://img.shields.io/badge/Release-v1.0.0-2563eb?style=flat-square)](https://github.com/net7/escriptorium-proxy/releases/tag/v1.0.0) [![eScriptorium v26.07](https://img.shields.io/badge/eScriptorium-v26.07-0d9488?style=flat-square)](https://gitlab.com/scripta/escriptorium/-/tree/v26.07) [![Laravel 12](https://img.shields.io/badge/Laravel-12-ef4444?style=flat-square&logo=laravel&logoColor=white)](proxy/composer.json) [![PHP 8.4 container](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](docker/Dockerfile.proxy) [![Docker Compose](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat-square&logo=docker&logoColor=white)](docker-compose.development.yml) [![GPL-3.0 license](https://img.shields.io/badge/License-GPL--3.0-16a34a?style=flat-square)](LICENSE)

[🚀 Quick start](#quick-start) · [🔌 API guide](#api-guide) · [🏗️ Architecture](#architecture) · [🌍 Deployment](#deployment) · [🤝 Contributing](#contributing) · [⚖️ License](#license)

</div>

---

## ✨ What you can do

Turn a sequence of eScriptorium operations into a small, asynchronous API workflow.

| | Capability |
| --- | --- |
| 🖼️ **Bring your documents** | Submit a IIIF manifest or upload images directly. |
| 🧠 **Choose your models** | List available writing systems and OCR/HTR models; select recognition and optional segmentation models. |
| ⚙️ **Let the workers handle it** | Queue import, segmentation, transcription and export, then poll for progress. |
| 📦 **Export for your workflow** | Retrieve TEI XML, plain text, PAGE XML, ALTO XML or OpenITI mARkdown. |
| 🔑 **Pick an authentication mode** | Use a proxy API key for service processing, or an eScriptorium token to work in your own account. |
| 🧭 **Explore the API** | Browse the Scalar reference, generated OpenAPI schema and development debug console. |

> [!TIP]
> **One workflow, three steps:** submit a document → poll its transcription ID → download the export.

<a id="quick-start"></a>

## 🚀 Quick start

### 1. Get the code

You need **Git**, **Docker with Compose v2 or later**, **Bash** and **OpenSSL**. Make is optional. PHP, Composer and Bun are installed in the application containers.

```bash
git clone --recurse-submodules https://github.com/net7/escriptorium-proxy.git
cd escriptorium-proxy
```

Already cloned? Initialize the pinned eScriptorium checkout **before** running setup:

```bash
git submodule update --init --recursive
```

For the published release, add `--branch v1.0.0` to the clone command. Source archives from GitHub do not include the submodule contents.

### 2. Prepare your environment

```bash
./scripts/setup.sh
```

The script detects ARM64 or AMD64, updates the development Compose platform entries, creates missing environment files and fills missing development `APP_KEY` values. It also prepares the pgAdmin configuration and enables TEI export in a newly created eScriptorium environment.

Review `.env.development` and `escriptorium/variables.env` before starting. Existing environment files are preserved; setup does not automatically migrate their settings.

### 3. Start the development stack

```bash
docker compose --env-file .env.development -f docker-compose.development.yml up -d --build
```

The first build includes eScriptorium and its dependencies, so allow time for image builds and database initialization. The Laravel entrypoint installs missing dependencies and runs migrations and seeders.

> [!IMPORTANT]
> Keep `--env-file` in your Compose commands. A service's `env_file:` loads variables into that container; `--env-file` also supplies values for `${...}` substitutions in the Compose file, including database credentials.

### 4. Open the tools

These addresses apply to the **development** configuration:

| Tool | Address |
| --- | --- |
| 📚 Scalar API reference | [localhost:8083](http://localhost:8083) |
| 🧪 Interactive debug console | [localhost:8083/debug](http://localhost:8083/debug) |
| 📖 Scramble API reference | [localhost:8083/docs/api](http://localhost:8083/docs/api) |
| 🧾 OpenAPI JSON | [localhost:8083/docs/api.json](http://localhost:8083/docs/api.json) |
| 📜 eScriptorium interface | [localhost:8082](http://localhost:8082) |
| 🗄️ phpMyAdmin | [localhost:8081](http://localhost:8081) |
| 🐘 pgAdmin | [localhost:5050](http://localhost:5050) |
| 🌸 Flower task monitor | [localhost:5555](http://localhost:5555) |
| ⚡ Vite+ development server | [localhost:5173](http://localhost:5173) |

The initial eScriptorium account is created from `DJANGO_SU_NAME` and `DJANGO_SU_PASSWORD` in `escriptorium/variables.env` during the first database initialization. Changing those variables later does not update an existing account. Development templates contain example credentials and are intended for a trusted local environment.

<a id="api-guide"></a>

## 🔌 API guide

The base URL in development is **`http://localhost:8083/api/v1`**. Every API endpoint requires the `X-API-Key` header.

### 🔑 Two ways to authenticate

| Mode | Credential | Project lifecycle |
| --- | --- | --- |
| **Service mode** | A proxy key generated with `apikey:generate` | Uses the configured service account. Temporary eScriptorium projects are cleaned up; exported files are retained in proxy storage. |
| **Direct mode** | Your personal eScriptorium API token | Runs as your eScriptorium user and preserves projects. An existing `document_id` can be reused when accessible to your account. |

Generate a proxy key:

```bash
docker compose --env-file .env.development -f docker-compose.development.yml exec proxy-php \
  php artisan apikey:generate "My integration" --permissions=up,models,scripts,process
```

Save the generated key: its full value is shown only once. The command also supports `--rate-limit` and `--expires`; `apikey:list` and `apikey:revoke` manage existing keys. See [API key management](proxy/docs/api-keys.md) for details.

For direct mode, obtain a token from your eScriptorium profile. The proxy needs access to that instance's PostgreSQL database, and `ESCRIPTORIUM_DJANGO_SECRET_KEY` must match its Django `SECRET_KEY`.

### 🛤️ Available endpoints

| Method | Path, relative to `/api/v1` | Purpose |
| --- | --- | --- |
| `GET` | `/up` | Check the eScriptorium connection |
| `GET` | `/models` | List available models |
| `GET` | `/scripts` | List writing systems |
| `POST` | `/process/manifest` | Start processing from a IIIF manifest |
| `POST` | `/process/images` | Start processing uploaded images |
| `GET` | `/process/{id}` | Read progress and results |
| `GET` | `/process/{id}/download` | Download the exported file |

Try a first request, replacing the placeholder with your key:

```bash
curl --fail --silent --show-error \
  -H "Accept: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  http://localhost:8083/api/v1/models
```

A processing request returns a `transcription_id`. Poll `/process/{id}` with the same credential until `status` is `COMPLETED` or `FAILED`; completed results expose a `download_url` when the export is available. Downloads require authentication too.

Use the running [API reference](http://localhost:8083) for request bodies and model IDs from your instance. For manifest requests, set `pages` explicitly: omitting it currently selects pages **1–10**. The proxy's model-upload route is currently disabled; manage model uploads in eScriptorium.

### 📦 Export formats

Set `export_format` when submitting a processing request:

| Value | Download | Content in the status response's `text` field |
| --- | --- | --- |
| `teixml` — default | ZIP of TEI XML files | Merged TEI XML |
| `text` | TXT | Plain text |
| `pagexml` | ZIP of PAGE XML files | Empty |
| `alto` | ZIP of ALTO XML files | Empty |
| `openitimarkdown` | ZIP of OpenITI mARkdown files | Empty |

Enable the corresponding eScriptorium export features in `escriptorium/variables.env`: `EXPORT_TEI_XML=true` for TEI and `EXPORT_OPENITI_MARKDOWN=true` for OpenITI. Fresh setup enables TEI; review existing files and enable OpenITI explicitly when needed.

<a id="architecture"></a>

## 🏗️ How it fits together

The project currently pins **eScriptorium v26.07**. The proxy uses **Laravel 12** in a **PHP 8.4** container; the debug interface uses **React 19**, **Inertia 2**, **Tailwind CSS 4** and **Vite+**, with Bun for frontend dependencies.

```mermaid
flowchart LR
    Client["🖼️ Images / IIIF"] --> Proxy["🔌 Laravel API"]
    Proxy --> Queue["⚙️ Background jobs"]
    Queue --> ES["📜 eScriptorium + Celery"]
    ES --> Exports["📦 Exported results"]

    classDef entry fill:#dbeafe,stroke:#2563eb,color:#172554
    classDef proxy fill:#ede9fe,stroke:#7c3aed,color:#2e1065
    classDef engine fill:#ccfbf1,stroke:#0d9488,color:#134e4a
    classDef data fill:#fef3c7,stroke:#d97706,color:#78350f
    class Client entry
    class Proxy,Queue proxy
    class ES engine
    class Exports data
```

This is the processing flow: NGINX fronts the API, and Laravel workers retrieve and store the exports that clients download.

- **Laravel** stores API keys and transcription records in MariaDB, orchestrates processing, and serves exports from proxy storage.
- **eScriptorium** performs OCR/HTR, with PostgreSQL for application data and a separate media volume for files.
- **Redis** supports both applications' background processing. Celery workers consume `default`, `low-priority`, `live` and `gpu,intensive-inference` queues.
- **PostgreSQL access includes writes:** the proxy reads tokens and account data, creates Django sessions for direct-mode WebSockets, and provides an account-creation command.

Despite its name, `celery-gpu` is configured with `KRAKEN_TRAINING_DEVICE=cpu` in the supplied environments. GPU acceleration requires additional device and runtime configuration.

<details>
<summary>🗂️ Repository map</summary>

```text
escriptorium-proxy/
├── proxy/                         Laravel API and React debug interface
│   ├── app/                       Controllers, jobs, services and commands
│   ├── resources/                 Frontend components and views
│   ├── routes/                    Public API and web routes
│   ├── docs/                      API key documentation
│   └── tests/                     Proxy tests
├── escriptorium/                  Pinned upstream Git submodule
├── docker/                        Proxy image, NGINX and container setup
├── scripts/                       Environment setup and its tests
├── docker-compose.development.yml
├── docker-compose.staging.yml
├── docker-compose.production.yml
├── .env.*.example                 Environment templates
└── Makefile                       Optional command shortcuts
```

The repository also retains upstream application files and an older `docker-compose.yml`. For the proxy stack, use the explicitly named environment-specific Compose files shown in this README.

</details>

<a id="deployment"></a>

## 🌍 Configuration & deployment

### Environment files

| File | Role |
| --- | --- |
| `.env.development`, `.env.staging`, `.env.production` | Proxy settings and Compose interpolation values for the selected environment |
| `proxy/.env` | Local Laravel settings; initially copied from the development environment |
| `escriptorium/variables.env` | Django, PostgreSQL, worker and export settings for eScriptorium |
| `.env.*.example` | Versioned templates; copy and customize for your deployment |

Keep database credentials consistent between the selected root environment and `escriptorium/variables.env`. `SQL_HOST` must be `postgres` for this stack. Proxy service-account settings are `ESCRIPTORIUM_USERNAME` and `ESCRIPTORIUM_PASSWORD`; set them to valid eScriptorium credentials.

Set `ESCRIPTORIUM_DJANGO_SECRET_KEY` to the same value as Django's `SECRET_KEY`. The proxy's PostgreSQL connection must permit the session writes used by direct mode.

### Environment differences

| | Development | Staging | Production |
| --- | --- | --- | --- |
| Proxy HTTP binding | `8083` | `80` | `127.0.0.1:8082` |
| eScriptorium HTTP binding | `8082` | Internal only | `127.0.0.1:8083` |
| Frontend | Vite+ live reload | Built assets | Built assets |
| Admin tools | phpMyAdmin, pgAdmin, Flower | phpMyAdmin, Flower | Not included |
| `/debug` | Available with `APP_ENV=local` | Available with `APP_ENV=staging` | Disabled |
| Container platform | Set by setup | Host architecture | Host architecture |

Production HTTP listeners bind to loopback and are intended to sit behind a host reverse proxy. Configure HTTPS there and forward the appropriate host/protocol headers. Staging and production generate HTTPS URLs, so configure HTTPS for both deployed environments.

### Start a deployment

Run the clone, submodule and setup steps first. For a new production environment:

```bash
cp .env.production.example .env.production
# Edit .env.production and escriptorium/variables.env before continuing.
docker compose --env-file .env.production -f docker-compose.production.yml config --quiet
docker compose --env-file .env.production -f docker-compose.production.yml up -d --build
```

For staging, use `.env.staging.example`, `.env.staging` and `docker-compose.staging.yml` instead. Use a separate checkout/environment for each deployment.

> [!IMPORTANT]
> Set a non-empty, persistent Laravel `APP_KEY` in the selected root environment file. Setup generates it for **development only**. For a new staging or production installation, generate a key with `openssl rand -base64 32` and store it as `APP_KEY=base64:<generated-value>`. Keep existing keys when updating an installation.

Before exposing a deployment, replace template passwords and secrets, set public `APP_URL`, `DOMAIN` and `CSRF_TRUSTED_ORIGINS`, and configure backups for both databases and stored files. The API documentation is public by default; restrict it at the application or reverse proxy if required.

<details>
<summary>🛠️ Everyday commands & troubleshooting</summary>

Use the same environment file and Compose file that started your stack. For development:

```bash
# Check services and follow logs.
docker compose --env-file .env.development -f docker-compose.development.yml ps
docker compose --env-file .env.development -f docker-compose.development.yml logs -f proxy-php proxy-queue

# Open a shell or restart workers.
docker compose --env-file .env.development -f docker-compose.development.yml exec proxy-php sh
docker compose --env-file .env.development -f docker-compose.development.yml restart proxy-queue

# Stop containers while retaining named data volumes.
docker compose --env-file .env.development -f docker-compose.development.yml down
```

| Symptom | What to check |
| --- | --- |
| Database connection failures | Matching credentials, `SQL_HOST=postgres`, and MariaDB/PostgreSQL health in `compose ps`. |
| Authentication errors | The `X-API-Key` header, proxy-key permissions/expiry and valid service-account credentials. |
| Direct-mode export failures | Matching Django secret keys, PostgreSQL session-write access and the internal WebSocket URL. |
| Processing remains pending | `proxy-queue` and the Celery workers, including `celery-gpu` for intensive inference. |
| Missing API docs | Use `/`, `/docs/api` or `/docs/api.json`; `/docs` is not the documentation route. |
| Missing frontend assets | Check the `proxy-vite` service in development and the production asset build logs. |

`make help` lists the optional shortcuts. They do not pass `--env-file`, so use the explicit Compose commands above when loading environment-specific interpolation values.

**Data deletion:** `make clean` removes volumes and local images across all three configurations; `make clean-volumes` deletes the selected environment's volumes, and `make db-fresh` recreates the Laravel database. These are reset operations, not routine troubleshooting steps.

</details>

## 📚 Further reading

| Resource | What it covers |
| --- | --- |
| [API key management](proxy/docs/api-keys.md) | Key generation, permissions, expiry and revocation |
| [Proxy architecture](ARCHITETTURA_PROXY_ESCRIPTORIUM.md) 🇮🇹 | Components, authentication modes and asynchronous processing |
| [How the proxy works](FUNZIONAMENTO_PROXY_ESCRIPTORIUM.md) 🇮🇹 | Workflow and integration concepts |
| [Releases](https://github.com/net7/escriptorium-proxy/releases) | Versioned snapshots and release notes |
| [Upstream eScriptorium](https://gitlab.com/scripta/escriptorium) | The underlying transcription platform |

For exact request fields and enabled routes, use the API reference generated by your running installation.

<a id="contributing"></a>

## 🤝 Contributing

Bug reports, documentation improvements and focused pull requests are welcome.

1. **Discuss the change.** Check [existing issues](https://github.com/net7/escriptorium-proxy/issues), or open one with the expected behavior and a reproducible example.
2. **Fork and branch from `main`.** Include the eScriptorium submodule when cloning. Keep upstream changes separate from proxy changes.
3. **Make a focused contribution.** Update documentation and add relevant tests when changing behavior.
4. **Check your work.** Run the applicable checks below and describe their results in your pull request.
5. **Open a PR against `main`.** Explain the problem, the resulting behavior and any configuration or migration changes.

### 🧪 Development checks

With PHP 8.4 (including `pdo_sqlite`), Composer and the proxy dependencies available locally, run from `proxy/`:

```bash
composer test
composer exec pint -- --test
```

The test configuration uses an in-memory SQLite database. For the setup-script tests, run from the repository root with Python 3:

```bash
python3 -m unittest discover -s scripts/tests
```

Frontend changes live in `proxy/resources/`. The `proxy-vite` development service reloads them; validate a frontend build with:

```bash
docker compose --env-file .env.development -f docker-compose.development.yml exec proxy-vite bun run build
```

Include your version, environment and sanitized logs when reporting a problem. Keep credentials, API keys, local environment files and document data out of commits and public reports.

## 💙 Acknowledgements

Developed by [Net7](https://github.com/net7), building on the work of the [eScriptorium project](https://gitlab.com/scripta/escriptorium) and its community. Thank you to everyone who contributes code, documentation, testing and feedback.

Meet the [contributors](https://github.com/net7/escriptorium-proxy/graphs/contributors).

<a id="license"></a>

## ⚖️ License

The root license for this repository is the **GNU General Public License v3.0**. See [LICENSE](LICENSE) for the full terms.

The eScriptorium submodule and third-party components retain their own licenses and copyright notices.

---

<div align="center">

**📜 Historical documents. 🔌 A practical API. 📦 Reusable results.**

[Explore the latest release](https://github.com/net7/escriptorium-proxy/releases/latest) · [Report an issue](https://github.com/net7/escriptorium-proxy/issues)

</div>
