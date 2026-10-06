| <img width="90" height="90" alt="Image" src="https://github.com/user-attachments/assets/fc121fa8-813c-4eb3-bf7a-6628f4d8353a" />  | safexec (secure, privilege-dropping wrapper) |
|---|---|

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](./LICENSE) [![safexec CI](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml/badge.svg)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml)

> [!IMPORTANT]
> **Hardened NPP build.** Every binary and package published from this repository
> (`.deb`, `.rpm`, `.apk`, and the one-liner installer) is compiled with `-DSAFEXEC_NPP`,
> a hardened build made specifically for the **NPP WordPress plugin**:
>
> - **Allowlist is fixed to exactly `wget` and `rg`.** Nothing else can be executed.
> - **Prelude wrapper is limited to `nohup`.** `nice`, `timeout`, `stdbuf`, `ionice`,
>   `taskset`, `setsid`, `chrt` and `time` are rejected.
> - **Seccomp-BPF syscall denylist** is applied for extra hardening when installed setuid-root.
>
> **Need the full allowlisted toolset** (`curl`, `tar`, `ffmpeg`, `pandoc`, optional
> tool buckets, custom builds)? Use the main **safexec** repository instead:
> **https://github.com/psaux-it/safexec**

`safexec` is a secure, privilege-dropping wrapper for executing a restricted set of tools from higher-level contexts such as **PHP’s `shell_exec()`**. It is a general-purpose sysadmin tool, maintained in the [main safexec repository](https://github.com/psaux-it/safexec).

This repository publishes the **NPP-hardened builds** for **NPP (Nginx Cache Purge Preload for WordPress)**. They pair with an optional LD_PRELOAD library, **`libnpp_norm.so`**, that normalizes percent-encoded HTTP request-lines during cache preloading to ensure consistent Nginx cache HITs.

---

## 📦 Installation

You can install **safexec** using the `.deb`, `.rpm` or `.apk` packages from the [Releases](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) page,

**-> or directly with one liner**

```sh
curl -fsSL https://psaux-it.github.io/install-safexec.sh | sudo sh
```

---

Manual install: first download the checksums (used by all packages below):

```bash
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/SHA256SUMS
```

### 🔹Debian / Ubuntu (DEB)

```bash
# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec_1.9.7-1_amd64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install --reinstall ./safexec_1.9.7-1_amd64.deb

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec_1.9.7-1_arm64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install --reinstall ./safexec_1.9.7-1_arm64.deb
```

### 🔹RHEL / CentOS / Fedora (RPM)

```bash
# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec-1.9.7-1.el10.x86_64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install   ./safexec-1.9.7-1.el10.x86_64.rpm   # fresh install
sudo dnf reinstall ./safexec-1.9.7-1.el10.x86_64.rpm   # if already installed

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec-1.9.7-1.el10.aarch64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install   ./safexec-1.9.7-1.el10.aarch64.rpm  # fresh install
sudo dnf reinstall ./safexec-1.9.7-1.el10.aarch64.rpm  # if already installed
```

### 🔹Alpine Linux (APK)

```bash
# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec-1.9.7-r1.x86_64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted --force-overwrite ./safexec-1.9.7-r1.x86_64.apk

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.8/safexec-1.9.7-r1.aarch64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted --force-overwrite ./safexec-1.9.7-r1.aarch64.apk
```

> **Note:** `--allow-untrusted` is required because the package is not signed with an Alpine trusted key. The SHA256 checksum above provides integrity verification.

---

## Workflow

<img width="1200" height="1738" alt="Image" src="https://github.com/user-attachments/assets/048d59e8-f21f-49eb-bdd7-6f5f85c9089f" />

## Features

### safexec (NPP-hardened build)
- ✅ **Strict allowlist**: exactly `wget` and `rg`; only `nohup` accepted as a prelude wrapper
- ✅ **Absolute path pinning**: tools resolve only under trusted system dirs; symlinks are followed only if the target stays inside them
- ✅ **Never runs as root**
  - `wget` runs as `nobody`
  - `rg` runs as the owner of the nginx cache path (root-owned or symlinked paths are refused)
  - Falls back to the calling user (e.g. PHP-FPM); aborts if still `root`
- ✅ **Clean environment**: cleared env, reset `PATH`, `umask 077`, no core dumps (`PR_SET_DUMPABLE=0`), `PR_SET_NO_NEW_PRIVS`
- ✅ **Seccomp-BPF denylist** (Linux x86_64/aarch64, setuid-root mode): blocks module loading, mount/namespace ops, ptrace, bpf, io_uring, keyring and clock-setting syscalls
- ✅ **Isolation**: cgroup v2 under `/sys/fs/cgroup/nppp`, with fallback to rlimits + nice/ionice
- ✅ **Safe temp handling**: root-owned sticky `/tmp/nppp-cache`; unsafe `-P /tmp` destinations for `wget` are rewritten
- ✅ **Kill support**: `--kill=<pid>` terminates only safexec-launched jobs owned by `nobody` (race-safe `pidfd` if available)
- 🔒 **Pass-through mode** if not installed setuid-root: the allowlist is still enforced, but there is no privilege drop, isolation or seccomp

### libnpp_norm.so
- 🔄 Intercepts `send`, `write`, `SSL_write`, `gnutls_record_send`, etc.
- 🔄 Normalizes percent-encoded triplets (`%xx`) in HTTP request-lines
  - Configurable via `PCTNORM_CASE=upper|lower|off` (default: uppercase)
  - Optional “repaint” mode preserves original triplet case from the CLI URL
- 🔄 Prevents cache-key inconsistencies caused by mixed-case encodings
- ⚡ Injected for `wget` in this build (`curl` is not allowlisted here); TLS via OpenSSL & GnuTLS
- ⚡ Linux-first (glibc/musl)

---

## Environment Variables

| Variable                 | Description                                                              | Default |
|--------------------------|--------------------------------------------------------------------------|---------|
| **SAFEXEC_PCTNORM**      | Enable/disable preload of `libnpp_norm.so` for wget                      | `1`     |
| **SAFEXEC_PCTNORM_SO**   | Path to normalization `.so` (must be `root:root`, non-writable, trusted) | —       |
| **SAFEXEC_PCTNORM_CASE** | Percent triplet case: `upper`, `lower`, or `off`                         | `upper` |
| **SAFEXEC_DETACH**       | Isolation mode: `auto`, `cgv2`, `rlimits`, `off`                         | `auto`  |
| **SAFEXEC_QUIET**        | Suppress informational messages                                          | `0`     |
| **SAFEXEC_SAFE_CWD**     | Handle inaccessible CWDs: `1` always, `-1` interactive only, `0` never   | `-1`    |

