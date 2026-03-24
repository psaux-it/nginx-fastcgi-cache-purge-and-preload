| <img width="90" height="90" alt="Image" src="https://github.com/user-attachments/assets/fc121fa8-813c-4eb3-bf7a-6628f4d8353a" />  | safexec (secure, privilege-dropping wrapper) |
|---|---|


[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](./LICENSE) [![safexec CI](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml/badge.svg)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml)

`safexec` is a secure, privilege-dropping wrapper for executing a restricted set of tools (e.g., `wget`, `curl`) from higher-level contexts such as **PHP’s `shell_exec()`**.  
It is written as the backend for **NPP (Nginx Cache Purge Preload for Wordpress)** and pairs with an optional LD_PRELOAD library, **`libnpp_norm.so`**, that normalizes percent-encoded HTTP request-lines during cache preloading to ensure consistent Nginx cache keys.

---

## 📦 Installation

You can install **safexec** using the `.deb`, `.rpm` or `.apk` packages from the [Releases](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) page,

**-> or directly with one liner**

```sh
curl -fsSL https://psaux-it.github.io/install-safexec.sh | sudo sh
```

---

### 🔹Debian / Ubuntu (DEB)

```bash
# Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec_1.9.5-1_amd64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install ./safexec_1.9.5-1_amd64.deb

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec_1.9.5-1_arm64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install ./safexec_1.9.5-1_arm64.deb
```

### 🔹RHEL / CentOS / Fedora (RPM)

```bash
# Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec-1.9.5-1.el10.x86_64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-1.9.5-1.el10.x86_64.rpm

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec-1.9.5-1.el10.aarch64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-1.9.5-1.el10.aarch64.rpm
```

### 🔹 Alpine Linux (APK)

```bash
# Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec-1.9.5-r1.x86_64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted ./safexec-1.9.5-r1.x86_64.apk

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.5/safexec-1.9.5-r1.aarch64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted ./safexec-1.9.5-r1.aarch64.apk
```

> **Note:** `--allow-untrusted` is required because the package is not signed with an Alpine trusted key. The SHA256 checksum above provides integrity verification.

---

## Workflow

<img width="1200" height="1738" alt="Image" src="https://github.com/user-attachments/assets/048d59e8-f21f-49eb-bdd7-6f5f85c9089f" />

## Features

### safexec
- ✅ **Strict allowlist** of safe binaries (e.g., `wget`, `curl`, `tar`, `ffmpeg`, `pandoc`, etc.)
- ✅ **Absolute path pinning** — refuses symlinks or untrusted paths
- ✅ **Privilege dropping**
  - Runs as `nobody` (or caller if fallback)
  - Aborts if still `root`
- ✅ **Environment sanitization**
  - Clears environment, resets `PATH`, enforces `umask 077`
  - Disables core dumps (`PR_SET_DUMPABLE=0`)
  - Enables `PR_SET_NO_NEW_PRIVS`
- ✅ **Isolation**
  - Cgroup v2 support under `/sys/fs/cgroup/nppp`
  - Fallback to rlimits + nice/ionice if unavailable
- ✅ **Safe temp handling**
  - Ensures `/tmp/nppp-cache` root-owned sticky dir
  - Rewrites unsafe `-P /tmp` destinations for `wget`
- ✅ **Kill support**
  - `--kill=<pid>` terminates only `nppp.*` jobs owned by `nobody`
  - Uses race-safe `pidfd` if available
- 🔒 **Pass-through mode** if not installed setuid-root (still enforces allowlist, no isolation)

### libnpp_norm.so
- 🔄 Intercepts `send`, `write`, `SSL_write`, `gnutls_record_send` etc.
- 🔄 Normalizes percent-encoded triplets (`%xx`) in HTTP request-lines
  - Configurable via `PCTNORM_CASE=upper|lower|off`
  - Default: uppercase hex
- 🔄 Prevents cache-key inconsistencies due to mixed-case encodings.
- 🔄 Optional “repaint” mode preserves original triplet case from CLI URL
- ⚡ Compatible with `wget`, `curl`, TLS (OpenSSL & GnuTLS)
- ⚡ Linux-first (glibc/musl), may work on BSD/macOS

---

## Security Model

- 🚫 **Never executes as root** (drops to `nobody` or caller UID).  
- ✅ **Only allowlisted tools** (wget, curl, tar, ffmpeg, etc. — extendable at build time).  
- 🔒 **Absolute path verification** (no symlinks, trusted system dirs only).  
- 🧹 **Clean environment** (removes unsafe variables, minimal safe defaults).  
- 🛡 **Isolation** via:
  - cgroup v2 (`/sys/fs/cgroup/nppp`) when available.  
  - Fallback to rlimits if cgroup is not supported.  
- 📂 **Safe /tmp handling**:
  - Uses `/tmp/nppp-cache` with strict root-owned sticky permissions.  
  - Prevents unsafe writes to `/tmp`.

---

## Environment Variables

| Variable              | Description                                                                 | Default |
|------------------------|-----------------------------------------------------------------------------|---------|
| **SAFEXEC_PCTNORM**   | Enable/disable preload of `libnpp_norm.so` for wget/curl                    | `1`     |
| **SAFEXEC_PCTNORM_SO** | Path to normalization `.so` (must be `root:root`, non-writable, trusted)    | —       |
| **SAFEXEC_PCTNORM_CASE** | Percent triplet case: `upper`, `lower`, or `off`                         | `upper` |
| **SAFEXEC_DETACH**    | Isolation mode: `auto`, `cgv2`, `rlimits`, `off`                           | `auto`  |
| **SAFEXEC_QUIET**     | Suppress informational messages                                             | `0`     |
| **SAFEXEC_SAFE_CWD**  | Handle inaccessible CWDs: `1` always, `-1` interactive only, `0` never      | `-1`    |

---

## ⚒️ Build-time Flags

`safexec` can be compiled with extra buckets of tools enabled at build time.  
Pass `-D<FLAG>` to `make` or `gcc` to include additional allowlisted binaries:

| Flag                        | Enables                                                                                  |
|-----------------------------|------------------------------------------------------------------------------------------|
| **SAFEXEC_WITH_GS**         | Ghostscript (`gs`) for PS/PDF rasterization (⚠️ riskier, disabled by default).           |
| **SAFEXEC_WITH_POPPLER**    | Poppler utils: `pdfinfo`, `pdftoppm`, `pdftocairo`.                                      |
| **SAFEXEC_WITH_DB**         | Database clients/dumpers: `mysqldump`, `mysql`, `mariadb-dump`, `mariadb`, `pg_dump`, `pg_restore`, `psql`, `redis-cli`. |
| **SAFEXEC_WITH_RSYNC_GIT**  | File sync / VCS tools: `rsync`, `git` (⚠️ may indirectly use SSH, use carefully).        |

Default build includes only the **core safe set** (wget, curl, tar, ffmpeg, pandoc, etc.).  

---

## 🔗 Intended Use

- Backend for **NPP (Nginx Cache Purge Preload for WordPress)**
- Safe execution of fetchers (`wget`, `curl`), converters (`ffmpeg`, `pandoc`), and archive tools.
- Works seamlessly with **libnpp_norm.so** to normalize HTTP request-lines for cache consistency.

---

## 📜 License

GPL-2.0-only — see [LICENSE](./LICENSE).

---

**Author:** Hasan Calisir  
**Version:** 1.9.5 (2025)
