// SPDX-License-Identifier: GPL-2.0-only
/*
 * safexec.c — Secure privilege-dropping wrapper for controlled shell execution
 *
 * Purpose
 * -------
 * Safely execute a *restricted* set of external programs (wget, curl, etc.)
 * from higher-level contexts such as PHP. Designed primarily as the backend
 * for shell_exec() in NPP (Nginx Cache Purge Preload).
 *
 * Security model
 * --------------
 *  - Strict allowlist: only known-safe binaries run (see ALLOWED_BINS).
 *  - Never exec as root: drop to 'nobody' first; if that fails, drop to the
 *    original caller (e.g., PHP-FPM worker). If we still have euid==0, abort.
 *  - Environment is sanitized early (clearenv); only minimal PATH/LANG are set.
 *    For wget/curl, an optional vetted LD_PRELOAD may be injected (see below).
 *  - All inherited FDs >= 3 are closed before exec (stdin/out/err preserved).
 *  - Linux: process is moved into its own cgroup v2 child "nppp.<pid>" under
 *    /sys/fs/cgroup/nppp when available; otherwise fall back to rlimits +
 *    (optional) nice/ionice. Controllers are enabled on the parent when possible.
 *  - --kill=<pid>: only succeeds if the target is owned by 'nobody' *and*
 *    belongs to an "nppp.*" safexec cgroup; uses pidfd when available (race-safe).
 *  - PR_SET_NO_NEW_PRIVS is enabled before exec to prevent privilege regain.
 *
 * Optional normalization (pctnorm)
 * --------------------------------
 * If enabled, safexec can normalize percent-encodings for wget/curl by
 * preloading a shared object:
 *    - SAFEXEC_PCTNORM=1|0         (default 1)
 *    - SAFEXEC_PCTNORM_SO=/path/to/libnpp_norm.so
 *    - SAFEXEC_PCTNORM_CASE=upper|lower|off  (default upper)
 * The .so is injected *only* for wget/curl, and only if it is root:root,
 * a regular file, and not group/other-writable. When injected, safexec sets:
 *    - LD_PRELOAD=<SO>, PCTNORM_CASE=<value>
 * Otherwise, env remains minimal (PATH, LANG).
 *
 * Detach / isolation mode
 * -----------------------
 * SAFEXEC_DETACH=auto|cgv2|rlimits|off
 *    auto     : prefer cgroup v2; fall back to rlimits if unavailable.
 *    cgv2     : require cgroup v2; fail if not possible.
 *    rlimits  : skip cgroup; apply rlimits (+ optional nice/ionice).
 *    off      : no isolation tweaks.
 * On glibc builds, SAFEXEC_DETACH is read via secure_getenv(); on musl,
 * getenv() is used (musl does not provide secure_getenv()).
 *
 * Other controls
 * --------------
 * SAFEXEC_QUIET=0|1            : suppress informational messages (default 0)
 * SAFEXEC_SAFE_CWD=-1|0|1      : if 1, chdir to /tmp (or /) when CWD is
 *                                inaccessible; if -1 (default), enable only
 *                                for interactive sessions (any stdio is a TTY).
 *
 * Behavior notes
 * --------------
 *  - If not installed setuid-root (or euid!=0 at runtime), safexec enters
 *    *pass-through* mode: no privilege drop or isolation is applied; it simply
 *    execs the target (still enforcing the allowlist).
 *  - A per-run cgroup name "nppp.<pid>" is used to avoid stale limits. Empty
 *    stale "nppp.*" groups may be cleaned up automatically.
 *  - When /tmp is not writable by the final euid and the command is wget with
 *    "-P /tmp", safexec rewrites the destination to "/tmp/nppp-cache/<euid>"
 *    if a safe, root-owned sticky parent exists. Otherwise it leaves "-P /tmp"
 *    untouched and warns.
 *
 * Portability / features
 * ----------------------
 *  - Linux:   cgroup v2 join, pidfd-based kill (when kernel supports it),
 *             ioprio (when available), rlimits, closefrom via /proc/self/fd,
 *             PR_SET_NO_NEW_PRIVS, PR_SET_DUMPABLE(0).
 *  - BSD/macOS/other POSIX: rlimits + FD closing; no cgroup/pidfd.
 *
 * Install (recommended)
 * ---------------------
 *   chown root:root safexec && chmod 4755 safexec   (avoid nosuid mounts)
 * Without setuid root, you only get pass-through mode (still allowlisted).
 *
 * Limitations
 * -----------
 *  - Not a general-purpose sandbox: only constrains *this* child process and
 *    its descendants. It does not provide syscall-level filtering.
 *  - Only allowlisted tools may run; arbitrary commands/pipelines are rejected.
 *
 * Copyright
 * ---------
 * (C) 2025 Hasan Calisir <hasan.calisir@psauxit.com>
 * Version: 1.9.2 (2025)
 */


#define _GNU_SOURCE 1

#include <unistd.h>
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>
#include <pwd.h>
#include <grp.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <locale.h>

#ifdef __linux__
#include <sys/prctl.h>
#endif

#include <limits.h>
#include <dirent.h>
#include <stdarg.h>
#include <sys/resource.h>

#ifdef __linux__
#include <sys/syscall.h>
#endif

#ifdef __linux__
#  ifndef __NR_pidfd_open
#    define SAFEXEC_NO_PIDFD 1
#  endif
#  ifndef __NR_pidfd_send_signal
#    define SAFEXEC_NO_PIDFD 1
#  endif
#else
#  define SAFEXEC_NO_PIDFD 1
#endif

#ifdef __linux__
#include <linux/ioprio.h>
#ifndef IOPRIO_WHO_PROCESS
#define IOPRIO_WHO_PROCESS 1
#endif
#endif

#ifndef O_NOFOLLOW
#define O_NOFOLLOW 0
#endif

// Metadata
#define SAFEXEC_NAME     "safexec"
#define SAFEXEC_VERSION  "1.9.2"
#define SAFEXEC_AUTHOR   "Hasan Calisir"

// Safe DIR
#ifndef SAFEXEC_SAFE_CWD_DEFAULT
#define SAFEXEC_SAFE_CWD_DEFAULT (-1)
#endif

// Quiet
#ifndef SAFEXEC_QUIET_DEFAULT
#define SAFEXEC_QUIET_DEFAULT 0
#endif

// Allow list
static const char *const ALLOWED_BINS[] = {
    "wget","curl",
    "tar","gzip","gunzip","xz","zip","unzip",
    "ffmpeg","ffprobe",
    "magick","convert","identify","composite",
    "wkhtmltopdf","pdftk","gs","pandoc","soffice",
    "mysqldump","mysql",
    "git","rsync",
    NULL
};

static int is_allowed_bin(const char *base) {
    for (size_t i = 0; ALLOWED_BINS[i]; ++i)
        if (strcmp(base, ALLOWED_BINS[i]) == 0) return 1;
    return 0;
}

// Env flag
static int env_flag(const char *name, int dflt) {
    const char *v = getenv(name);
    if (!v || !*v) return dflt;
    if (!strcmp(v,"0") || !strcasecmp(v,"false") || !strcasecmp(v,"no")  || !strcasecmp(v,"off")) return 0;
    if (!strcmp(v,"1") || !strcasecmp(v,"true")  || !strcasecmp(v,"yes") || !strcasecmp(v,"on"))  return 1;
    return dflt;
}

// Set quiet
static int env_quiet_enabled(void) {
    return env_flag("SAFEXEC_QUIET", SAFEXEC_QUIET_DEFAULT) == 1;
}

// Quiet-aware logging layer
static int QUIET = 0;
static int s_printf(const char *fmt, ...) {
    if (QUIET) return 0;
    va_list ap; va_start(ap, fmt);
    int r = vfprintf(stdout, fmt, ap);
    va_end(ap);
    return r;
}
static int s_fprintf(FILE *stream, const char *fmt, ...) {
    if (QUIET) return 0;
    va_list ap; va_start(ap, fmt);
    int r = vfprintf(stream, fmt, ap);
    va_end(ap);
    return r;
}
static void s_perror(const char *s) {
    if (!QUIET) perror(s);
}

/* Allowed roots */
static const char *const TRUSTED_LIB_ROOTS[] = {
    "/usr/lib",
    "/lib",
    "/usr/lib64",
    "/lib64",
    NULL
};

static int has_trusted_root(const char *real) {
    for (const char *const *p = TRUSTED_LIB_ROOTS; *p; ++p) {
        size_t n = strlen(*p);
        /* must be prefix and either exactly equal or followed by '/' */
        if (strncmp(real, *p, n) == 0 && (real[n] == '\0' || real[n] == '/'))
            return 1;
    }
    return 0;
}

/* Check that the path (already absolute) contains a component exactly "npp" */
static int path_has_component_npp(const char *abs) {
    /* Walk components between '/' ... '/' boundaries */
    const char *p = abs;
    while (*p) {
        /* skip repeated '/' */
        while (*p == '/') p++;
        if (!*p) break;
        const char *start = p;
        while (*p && *p != '/') p++;
        size_t len = (size_t)(p - start);
        if (len == 3 && start[0] == 'n' && start[1] == 'p' && start[2] == 'p')
            return 1;
    }
    return 0;
}

static int is_secure_so(const char *path) {
    if (!path || !*path) return 0;

    /* Resolve to a canonical absolute path (resolves all symlinks) */
    char real[PATH_MAX];
    if (!realpath(path, real)) return 0;

    /* 1) Must be under one of the trusted roots */
    if (!has_trusted_root(real)) return 0;

    /* 2) Must include a path component exactly named "npp" somewhere below the root */
    if (!path_has_component_npp(real)) return 0;

    /* 3) Reject final symlink and TOCTOU: open final target with O_NOFOLLOW */
    int fd = open(real, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd < 0) {
        /* If ELOOP, final component is a symlink -> reject */
        return 0;
    }

    /* 4) Ownership/permissions: root:root, regular file, not group/other writable */
    struct stat st;
    int ok = (fstat(fd, &st) == 0) &&
             S_ISREG(st.st_mode) &&
             st.st_uid == 0 &&
             st.st_gid == 0 &&
             ((st.st_mode & 022) == 0);

    close(fd);
    return ok;
}
static char *dup_or_null(const char *s) { return s ? strdup(s) : NULL; }

// snprintf wrapper that errors on truncation to quiet -Wformat-truncation
static int safe_snprintf(char *dst, size_t dstsz, const char *fmt, ...) {
    va_list ap;
    va_start(ap, fmt);
    int n = vsnprintf(dst, dstsz, fmt, ap);
    va_end(ap);
    if (n < 0) return -1;
    if ((size_t)n >= dstsz) {
        errno = ENAMETOOLONG;
        return -1;
    }
    return 0;
}

static int is_bytes_or_max(const char *s) {
    if (!s || !*s) return 0;
    if (!strcasecmp(s, "max")) return 1;
    for (const char *p=s; *p; ++p) if (*p < '0' || *p > '9') return 0;
    return 1;
}

static void clearenv_portable(void) {
#if defined(__GLIBC__) || defined(__linux__)
    clearenv();
#else
    extern char **environ;
    if (!environ) return;

    size_t cnt = 0;
    for (char **p = environ; *p; ++p) ++cnt;

    // Collect names (before unsetting)
    char **names = (char**)calloc(cnt, sizeof *names);
    if (!names) {
        unsetenv("PATH"); unsetenv("IFS"); unsetenv("LD_LIBRARY_PATH");
        unsetenv("DYLD_LIBRARY_PATH"); unsetenv("PYTHONPATH");
        return;
    }

    size_t i = 0;
    for (char **p = environ; *p && i < cnt; ++p) {
        char *eq = strchr(*p, '=');
        if (!eq) continue;
        size_t n = (size_t)(eq - *p);
        names[i] = (char*)malloc(n + 1);
        if (!names[i]) break;
        memcpy(names[i], *p, n);
        names[i][n] = '\0';
        ++i;
    }

    for (size_t j = 0; j < i; ++j) { unsetenv(names[j]); free(names[j]); }
    free(names);
#endif
}

// Check whether a given PID lives under /nppp or matches nppp.* in cgroup v2 path
static int proc_in_nppp_cgroup(pid_t pid) {
#ifndef __linux__
    (void)pid; return 0;
#else
    char path[64];
    snprintf(path, sizeof path, "/proc/%d/cgroup", (int)pid);
    FILE *f = fopen(path, "r");
    if (!f) return 0;
    char line[512]; int ok = 0;
    while (fgets(line, sizeof line, f)) {
        if (strncmp(line, "0::", 3) != 0) continue;   /* v2 only */
        const char *p = line + 3;
        if (strstr(p, "/nppp/") || strstr(p, "/nppp.")) { ok = 1; break; }
    }
    fclose(f);
    return ok;
#endif
}

static long gettid_portable(void) {
#ifdef __linux__
  #ifdef __NR_gettid
    return syscall(__NR_gettid);
  #else
    return (long)getpid();
  #endif
#else
    return (long)getpid();
#endif
}

static int pidfd_open_wrap(pid_t pid) {
#ifdef SAFEXEC_NO_PIDFD
    (void)pid;
    errno = ENOSYS;
    return -1;
#else
    errno = 0;
    return (int)syscall(__NR_pidfd_open, pid, 0);
#endif
}

static int pidfd_send_signal_wrap(int pidfd, int sig) {
#ifdef SAFEXEC_NO_PIDFD
    (void)pidfd; (void)sig;
    errno = ENOSYS;
    return -1;
#else
    return (int)syscall(__NR_pidfd_send_signal, pidfd, sig, NULL, 0);
#endif
}

// Limits
typedef struct {
    const char *mem_max_v2;  // bytes or "max" (e.g., "268435456" or "max")
    int pids_max;            // -1 unlimited
    int cpu_weight_v2;       // 1..10000 (default 100)
    const char *io_max;      // e.g. "8:0 rbps=1048576 wbps=1048576" (bytes/sec, optional)
    int rlimit_cpu_secs;     // <0 => unlimited, 0 => skip, >0 => set
    unsigned long long rlimit_as_bytes; // 0 => unlimited, >0 => set
    int rlimit_nofile;       // <0 => unlimited, 0 => skip, >0 => set
    int rlimit_nproc;        // <0 => unlimited, 0 => skip, >0 => set
    int nice_adj;            // 0 => leave as-is
    int ioprio_class;        // 0 => leave as-is; 1=RT,2=BE,3=IDLE
    int ioprio_data;         // 0..7
} nppp_limits;

static nppp_limits nppp_default_limits(void) {
    nppp_limits L = {
        .mem_max_v2 = NULL,                 // v2: explicitly unlimited
        .pids_max = -1,                      // unlimited
        .cpu_weight_v2 = 0,                  // don't write -> kernel default (100)
        .io_max = NULL,
        .rlimit_cpu_secs = -1,               // unlimited CPU time
        .rlimit_as_bytes = 0,                // unlimited address space
        .rlimit_nofile = -1,                 // unlimited (clamped by nr_open)
        .rlimit_nproc = -1,                  // unlimited
        .nice_adj = 0,                       // leave priority unchanged
        .ioprio_class = 0, .ioprio_data = 0  // leave IO priority unchanged
    };
    return L;
}

static int write_all_str(const char *path, const char *s) {
    int fd = open(path, O_WRONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) return -1;
    size_t n = strlen(s);
    ssize_t w = write(fd, s, n);
    int saved = errno;
    int rc = (w == (ssize_t)n) ? 0 : -1;
    errno = saved;
    close(fd);
    return rc;
}

// Write a line (auto-append newline)
static int write_all_line(const char *path, const char *s) {
    int fd = open(path, O_WRONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) return -1;
    ssize_t w1 = write(fd, s, strlen(s));
    ssize_t w2 = (w1 >= 0) ? write(fd, "\n", 1) : -1;
    int rc = (w1 == (ssize_t)strlen(s) && w2 == 1) ? 0 : -1;
    close(fd);
    return rc;
}

static int write_all_u64(const char *path, unsigned long long v) {
    char buf[64];
    int len = snprintf(buf, sizeof buf, "%llu\n", v);
    (void)len;
    return write_all_str(path, buf);
}

static int is_dir_nosym(const char *path) {
    struct stat st;
    if (lstat(path, &st) != 0) return 0;
    return S_ISDIR(st.st_mode);
}

// Read back first token/line from a controller file (for logging)
static int read_token(const char *path, char *out, size_t outsz) {
    int fd = open(path, O_RDONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) return -1;
    ssize_t n = read(fd, out, (ssize_t)outsz - 1);
    close(fd);
    if (n < 0) return -1;
    size_t len = (n > 0) ? (size_t)n : 0;
    out[len] = '\0';
    char *nl = strchr(out, '\n'); if (nl) *nl = 0;
    return 0;
}

// cgroup v2
static const char *cgv2_root(void) {
    static const char *root = NULL;
    if (root) return root;
    if (access("/sys/fs/cgroup/cgroup.controllers", R_OK) == 0)
        root = "/sys/fs/cgroup";
    else if (access("/sys/fs/cgroup/unified/cgroup.controllers", R_OK) == 0)
        root = "/sys/fs/cgroup/unified";
    else
        root = NULL;
    return root;
}

static int cgv2_available(void) {
    return cgv2_root() != NULL;
}

static int cgv2_enable_one(const char *tok) {
    const char *root = cgv2_root(); if (!root) return -1;
    char path[PATH_MAX];
    if (safe_snprintf(path, sizeof path, "%s/cgroup.subtree_control", root) != 0) return -1;
    int fd = open(path, O_WRONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) {
        s_fprintf(stderr, "Info: cannot open %s: %s\n", path, strerror(errno));
        return -1;
    }
    char buf[32]; int len = snprintf(buf, sizeof buf, "%s\n", tok);
    ssize_t w = write(fd, buf, len);
    int e = errno; close(fd);
    if (w != (ssize_t)len) {
        if (strcmp(tok, "+cpu") == 0 && e == EINVAL) {
            s_fprintf(stderr, "Info: enabling +cpu failed (EINVAL). "
                               "Hint: v2 cpu controller needs all RT threads in root.\n");
        } else {
            s_fprintf(stderr, "Info: cannot enable %s on subtree_control: %s\n", tok, strerror(e));
        }
        return -1;
    }
    return 0;
}

static void cgv2_enable_controllers(void) {
    // Try each; failures are non-fatal
    (void)cgv2_enable_one("+memory");
    (void)cgv2_enable_one("+pids");
    (void)cgv2_enable_one("+cpu");
    (void)cgv2_enable_one("+io");
    (void)cgv2_enable_one("+cpuset");
}

// Remove empty stale groups matching prefix (e.g., "nppp.")
static void cgv2_cleanup_stale(const char *prefix) {
    const char *root = cgv2_root(); if (!root) return;
    DIR *d = opendir(root); if (!d) return;
    struct dirent *de;
    size_t plen = strlen(prefix);
    while ((de = readdir(d))) {
        if (de->d_type != DT_DIR) {
            if (de->d_type != DT_UNKNOWN) continue;
            /* fall back to lstat when d_type is unknown */
            char probe[PATH_MAX];
            if (safe_snprintf(probe, sizeof probe, "%s/%s", root, de->d_name) != 0) continue;
            struct stat st;
            if (lstat(probe, &st) != 0 || !S_ISDIR(st.st_mode)) continue;
        }
        if (de->d_name[0] == '.') continue;
        if (strncmp(de->d_name, prefix, plen) != 0) continue;
        char dir[PATH_MAX];
        if (safe_snprintf(dir, sizeof dir, "%s/%s", root, de->d_name) != 0) continue;
        // Try to remove; will only succeed if empty
        if (rmdir(dir) == 0) {
            s_fprintf(stderr, "Info: removed empty stale cgroup v2 '%s'\n", dir);
        }
    }
    closedir(d);
}

// Cleanup under the current parent subtree instead of only the global root
static void cgv2_cleanup_stale_at(const char *parent, const char *prefix) {
    DIR *d = opendir(parent); if (!d) return;
    struct dirent *de; size_t plen = strlen(prefix);
    while ((de = readdir(d))) {
        if (de->d_name[0] == '.') continue;
        if (strncmp(de->d_name, prefix, plen) != 0) continue;
        char dir[PATH_MAX];
        if (safe_snprintf(dir, sizeof dir, "%s/%s", parent, de->d_name) != 0) continue;
        /* Only remove if empty */
        (void)rmdir(dir);
    }
    closedir(d);
}

// Read the absolute cgroup v2 dir of the current process: /sys/fs/cgroup + self path
static int cgv2_self_dir(char *out, size_t outsz) {
    const char *root = cgv2_root(); if (!root) return -1;
    int fd = open("/proc/self/cgroup", O_RDONLY|O_CLOEXEC); if (fd < 0) return -1;
    FILE *f = fdopen(fd, "r"); if (!f) { close(fd); return -1; }
    char line[4096]; int rc = -1;
    while (fgets(line, sizeof line, f)) {
        // v2 line format: 0::/user.slice/...
        if (strncmp(line, "0::", 3) == 0) {
            char *p = line + 3;
            char *nl = strchr(p, '\n'); if (nl) *nl = 0;
            if (safe_snprintf(out, outsz, "%s%s", root, p) == 0) rc = 0;
            break;
        }
    }
    fclose(f);
    return rc;
}

static int cgv2_enable_one_at(const char *parent, const char *tok) {
    char path[PATH_MAX];
    if (safe_snprintf(path, sizeof path, "%s/cgroup.subtree_control", parent) != 0) return -1;
    int fd = open(path, O_WRONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) return -1;
    char buf[32]; int len = snprintf(buf, sizeof buf, "%s\n", tok);
    ssize_t w = write(fd, buf, len);
    int saved = errno;
    close(fd);
    if (w != (ssize_t)len) { errno = saved; return -1; }
    return 0;
}

static int cgv2_controller_available_at(const char *parent, const char *name) {
    char path[PATH_MAX], buf[1024];
    if (safe_snprintf(path, sizeof path, "%s/cgroup.controllers", parent) != 0) return 0;
    int fd = open(path, O_RDONLY|O_CLOEXEC|O_NOFOLLOW); if (fd < 0) return 0;
    ssize_t n = read(fd, buf, sizeof buf - 1);
    close(fd);
    if (n <= 0) return 0;
    buf[n] = '\0';
    return strstr(buf, name) != NULL;
}

// Read cgroup.type and check if a cgroup is in a threaded subtree
static int cgv2_is_threaded(const char *dir) {
    char path[PATH_MAX], t[64] = {0};
    if (safe_snprintf(path, sizeof path, "%s/cgroup.type", dir) != 0) return 0;
    if (read_token(path, t, sizeof t) != 0) return 0;
    /* parent considered threaded if "threaded" or "domain threaded" */
    return (strcmp(t, "threaded") == 0) || (strcmp(t, "domain threaded") == 0);
}


static void cgv2_copy_cpuset_from_parent(const char *parent, const char *child) {
    char from[PATH_MAX], to[PATH_MAX], tok[8192];
    // cpuset.cpus
    if (safe_snprintf(to, sizeof to,   "%s/cpuset.cpus", child) == 0 &&
        safe_snprintf(from, sizeof from,"%s/cpuset.cpus", parent) == 0) {
        if (read_token(to, tok, sizeof tok) == 0 && tok[0] == '\0') {
            if (read_token(from, tok, sizeof tok) == 0 && tok[0] != '\0')
                (void)write_all_line(to, tok);
        }
    }
    // cpuset.mems
    if (safe_snprintf(to, sizeof to,   "%s/cpuset.mems", child) == 0 &&
        safe_snprintf(from, sizeof from,"%s/cpuset.mems", parent) == 0) {
        if (read_token(to, tok, sizeof tok) == 0 && tok[0] == '\0') {
            if (read_token(from, tok, sizeof tok) == 0 && tok[0] != '\0')
                (void)write_all_line(to, tok);
        }
    }
}

// Read cgroup.events and return 1 if populated, 0 if not, -1 on error
static int cgv2_events_populated(const char *dir) {
    char p[PATH_MAX];
    if (safe_snprintf(p, sizeof p, "%s/cgroup.events", dir) != 0) return -1;

    int fd = open(p, O_RDONLY|O_CLOEXEC|O_NOFOLLOW);
    if (fd < 0) return -1;
    char buf[1024];
    ssize_t n = read(fd, buf, sizeof buf - 1);
    int saved = errno; close(fd); errno = saved;
    if (n <= 0) return -1;
    buf[n] = '\0';

    /* Look for "populated <0/1>" anywhere */
    const char *k = strstr(buf, "populated");
    if (!k) return -1;
    while (*k && (*k < '0' || *k > '9')) k++;
    return (*k == '1') ? 1 : 0;
}

// Remove dir only if empty/unpopulated
static void cgv2_try_rmdir_if_empty(const char *dir) {
    if (!is_dir_nosym(dir)) return;
    int pop = cgv2_events_populated(dir);
    if (pop == 0) {
        (void)rmdir(dir);
    }
}

// Always use /sys/fs/cgroup/nppp as base parent
static int cgv2_root_base(char *out, size_t outsz) {
    const char *root = cgv2_root();
    if (!root) return -1;
    if (safe_snprintf(out, outsz, "%s/%s", root, "nppp") != 0) return -1;
    (void)mkdir(out, 0755);
    return 0;
}

static int cgv2_join_group(const char *name, const nppp_limits *L) {
    if (!cgv2_available()) return -1;

    // Discover where we START (session/service subtree), and our TARGET parent (/sys/fs/cgroup/nppp)
    char self_parent[PATH_MAX], parent[PATH_MAX];
    if (cgv2_self_dir(self_parent, sizeof self_parent) != 0) return -1;
    if (cgv2_root_base(parent, sizeof parent) != 0) return -1;

    // Prune empty nppp.* both under the session subtree and our global parent
    cgv2_cleanup_stale_at(self_parent, "nppp.");
    cgv2_cleanup_stale_at(parent,      "nppp.");

    // Enable controllers on the global parent when possible (ignore failures)
    int parent_threaded = cgv2_is_threaded(parent);
    if (!parent_threaded) {
        (void)cgv2_enable_one_at(parent, "+memory");
        (void)cgv2_enable_one_at(parent, "+pids");
        (void)cgv2_enable_one_at(parent, "+cpu");
        (void)cgv2_enable_one_at(parent, "+io");
        (void)cgv2_enable_one_at(parent, "+cpuset");
    }

    // Create child under the GLOBAL parent (always-detach)
    char dir[PATH_MAX];
    if (safe_snprintf(dir, sizeof dir, "%s/%s", parent, name) != 0) return -1;
    if (mkdir(dir, 0755) != 0 && errno != EEXIST) return -1;

    // If we *had* created a same-named child in the session subtree in earlier builds, remember it
    char legacy_in_session[PATH_MAX];
    int have_legacy = (safe_snprintf(legacy_in_session, sizeof legacy_in_session, "%s/%s", self_parent, name) == 0) &&
                      is_dir_nosym(legacy_in_session);

    // If parent is threaded (unexpected at root), mark child threaded so thread-move works
    if (parent_threaded) {
        char p[PATH_MAX];
        if (safe_snprintf(p, sizeof p, "%s/cgroup.type", dir) == 0)
            (void)write_all_line(p, "threaded");
    }

    // Apply optional limits only when meaningful
    char p[PATH_MAX];
    int applied = 0;
    if (!parent_threaded && L->mem_max_v2 && *L->mem_max_v2) {
        if (!is_bytes_or_max(L->mem_max_v2)) {
            s_fprintf(stderr, "Error: memory.max must be decimal bytes or 'max' (got '%s')\n",
                  L->mem_max_v2);
            return -1;
        }
        if (safe_snprintf(p, sizeof p, "%s/memory.max", dir) != 0) return -1;
        if (write_all_line(p, L->mem_max_v2) == 0) applied++;
    }
    if (!parent_threaded && cgv2_controller_available_at(parent, "memory")) {
        if (safe_snprintf(p, sizeof p, "%s/memory.swap.max", dir) == 0)
            (void)write_all_line(p, "max");
    }
    if (!parent_threaded && cgv2_controller_available_at(parent, "pids") && L->pids_max != 0) {
        if (safe_snprintf(p, sizeof p, "%s/pids.max", dir) != 0) return -1;
        if (L->pids_max < 0)  { if (write_all_line(p, "max") == 0) applied++; }
        else                  { if (write_all_u64(p, (unsigned long long)L->pids_max) == 0) applied++; }
    }
    if (!parent_threaded && cgv2_controller_available_at(parent, "cpu") && L->cpu_weight_v2 > 0) {
        if (safe_snprintf(p, sizeof p, "%s/cpu.weight", dir) != 0) return -1;
        if (write_all_u64(p, (unsigned long long)L->cpu_weight_v2) == 0) applied++;
    }
    if (!parent_threaded && L->io_max && cgv2_controller_available_at(parent, "io")) {
        if (safe_snprintf(p, sizeof p, "%s/io.max", dir) != 0) return -1;
        if (write_all_line(p, L->io_max) == 0) applied++;
    }
    if (!parent_threaded && cgv2_controller_available_at(parent, "cpuset"))
        cgv2_copy_cpuset_from_parent(parent, dir);

    // Safety: avoid memory.max=0
    if (!parent_threaded && safe_snprintf(p, sizeof p, "%s/memory.max", dir) == 0) {
        char v[32];
        if (read_token(p, v, sizeof v) == 0 && strcmp(v, "0") == 0) {
            s_fprintf(stderr, "Error: memory.max=0 in child; aborting cgroup join.\n");
            (void)rmdir(dir);
            return -1;
        }
    }

    // Move into the new child
    if (parent_threaded) {
        if (safe_snprintf(p, sizeof p, "%s/cgroup.threads", dir) != 0) return -1;
        char tidbuf[64]; snprintf(tidbuf, sizeof tidbuf, "%ld\n", gettid_portable());
        if (write_all_str(p, tidbuf) != 0) {
            s_fprintf(stderr, "Error: cannot move thread to '%s': %s\n", dir, strerror(errno));
            return -1;
        }
    } else {
        if (safe_snprintf(p, sizeof p, "%s/cgroup.procs", dir) != 0) return -1;
        char pidbuf[64]; snprintf(pidbuf, sizeof pidbuf, "%ld\n", (long)getpid());
        if (write_all_str(p, pidbuf) != 0) {
            int saved = errno;
            if (saved == EOPNOTSUPP && safe_snprintf(p, sizeof p, "%s/cgroup.threads", dir) == 0) {
                char tidbuf[64]; snprintf(tidbuf, sizeof tidbuf, "%ld\n", gettid_portable());
                if (write_all_str(p, tidbuf) != 0) {
                    s_fprintf(stderr, "Error: cannot move to '%s': %s\n", dir, strerror(errno));
                    return -1;
                }
            } else {
                s_fprintf(stderr, "Error: cannot move to '%s': %s\n", dir, strerror(saved));
                errno = saved;
                return -1;
            }
        }
    }

    // AFTER we moved out: try to remove a same-named legacy cgroup under the session subtree
    if (have_legacy) cgv2_try_rmdir_if_empty(legacy_in_session);

    (void)applied;
    return 0;
}

static void apply_rlimits_and_sched(const nppp_limits *L) {
    struct rlimit r;

    // Address space (portable: RLIMIT_AS or RLIMIT_VMEM)
#if defined(RLIMIT_AS)
    if (L->rlimit_as_bytes == 0) {
        r.rlim_cur = r.rlim_max = RLIM_INFINITY;
        (void)setrlimit(RLIMIT_AS, &r);
    } else if (L->rlimit_as_bytes > 0) {
        r.rlim_cur = r.rlim_max = (rlim_t)L->rlimit_as_bytes;
        (void)setrlimit(RLIMIT_AS, &r);
    }
#elif defined(RLIMIT_VMEM)
    if (L->rlimit_as_bytes == 0) {
        r.rlim_cur = r.rlim_max = RLIM_INFINITY;
        (void)setrlimit(RLIMIT_VMEM, &r);
    } else if (L->rlimit_as_bytes > 0) {
        r.rlim_cur = r.rlim_max = (rlim_t)L->rlimit_as_bytes;
        (void)setrlimit(RLIMIT_VMEM, &r);
    }
#endif

    // CPU time
#ifdef RLIMIT_CPU
    if (L->rlimit_cpu_secs < 0) {
        r.rlim_cur = r.rlim_max = RLIM_INFINITY;
        (void)setrlimit(RLIMIT_CPU, &r);
    } else if (L->rlimit_cpu_secs > 0) {
        r.rlim_cur = r.rlim_max = (rlim_t)L->rlimit_cpu_secs;
        (void)setrlimit(RLIMIT_CPU, &r);
    }
#endif

    // NOFILE
#ifdef RLIMIT_NOFILE
    if (L->rlimit_nofile < 0) {
        r.rlim_cur = r.rlim_max = RLIM_INFINITY;
        (void)setrlimit(RLIMIT_NOFILE, &r);
    } else if (L->rlimit_nofile > 0) {
        r.rlim_cur = r.rlim_max = (rlim_t)L->rlimit_nofile;
        (void)setrlimit(RLIMIT_NOFILE, &r);
    }
#endif

    // NPROC
#ifdef RLIMIT_NPROC
    if (L->rlimit_nproc < 0) {
        r.rlim_cur = r.rlim_max = RLIM_INFINITY;
        (void)setrlimit(RLIMIT_NPROC, &r);
    } else if (L->rlimit_nproc > 0) {
        r.rlim_cur = r.rlim_max = (rlim_t)L->rlimit_nproc;
        (void)setrlimit(RLIMIT_NPROC, &r);
    }
#endif

    // Only adjust nice/ionice if requested
    if (L->nice_adj != 0) {
        errno = 0;
        (void)setpriority(PRIO_PROCESS, 0, L->nice_adj);
    }

#if defined(__linux__) && defined(IOPRIO_WHO_PROCESS) && defined(__NR_ioprio_set) && defined(IOPRIO_PRIO_VALUE)
    if (L->ioprio_class > 0) {
        int prio = IOPRIO_PRIO_VALUE(L->ioprio_class, L->ioprio_data);
        (void)syscall(__NR_ioprio_set, IOPRIO_WHO_PROCESS, 0 /*self*/, prio);
    }
#endif
}

enum detach_mode { DET_AUTO, DET_CGV2, DET_RLIMITS, DET_OFF };
static enum detach_mode parse_detach_mode(void) {
    const char *s =
#ifdef __GLIBC__
        secure_getenv("SAFEXEC_DETACH");
#else
        getenv("SAFEXEC_DETACH");
#endif

    if (!s || !*s) return DET_AUTO;
    if (!strcasecmp(s, "auto"))    return DET_AUTO;
    if (!strcasecmp(s, "cgv2"))    return DET_CGV2;
    if (!strcasecmp(s, "rlimits")) return DET_RLIMITS;
    if (!strcasecmp(s, "off"))     return DET_OFF;
    return DET_AUTO;
}

// Safe DIR
static void chdir_safe_if_cwd_inaccessible(void) {
if (access(".", X_OK) == 0) return;
    s_fprintf(stderr, "Info: CWD not accessible; switching to /tmp\n");
    if (chdir("/tmp") != 0) {
        int rc = chdir("/");
        if (rc != 0) {
            int e = errno;
            s_fprintf(stderr, "Warning: failed to chdir to /tmp and /: %s\n",
                strerror(e));
        }
    }
}

// Print version
static void print_version(void) {
    printf(
        "%s %s\n"
        "Copyright (C) 2025 %s.\n"
        "Used by: NPP – Nginx Cache Purge Preload for WordPress.\n",
        SAFEXEC_NAME, SAFEXEC_VERSION, SAFEXEC_AUTHOR
    );
}

static const char *base_of(const char *p) {
    const char *b = strrchr(p, '/');
    return b ? b + 1 : p;
}

static int is_prog(const char *arg, const char *name) {
    return strcmp(base_of(arg), name) == 0;
}

// Find the real target program, skipping benign wrappers (e.g., nohup)
static int find_target_prog_index(int argc, char **argv) {
    int i = 1; // argv[0] = safexec
    while (i < argc) {
        const char *b = base_of(argv[i]);
        if (strcmp(b, "nohup") == 0) { i++; continue; }  // allows: safexec nohup wget ...
        // Add more wrappers later if needed, e.g.:
        // if (strcmp(b, "nice") == 0) { i++; continue; }
        break;
    }
    return i;
}

// Usage
static void print_usage(const char *argv0) {
    s_printf(
        "Usage:\n"
        "  %s <program> [args...]\n"
        "  %s --kill=<pid>\n"
        "  %s --help | -h\n"
        "  %s --version | -v\n",
        argv0, argv0, argv0, argv0);
}

static int is_all_digits(const char *s) {
    if (!s || !*s) return 0;
    for (const unsigned char *p=(const unsigned char*)s; *p; ++p)
        if (*p < '0' || *p > '9') return 0;
    return 1;
}

/* Try C.UTF-8 → en_US.UTF-8 → C, and keep env consistent. */
static void set_locale_utf8_best_effort(void) {
    /* Try C.UTF-8 first (works on modern glibc & musl/Alpine) */
    if (setlocale(LC_CTYPE, "C.UTF-8")) {
        setenv("LANG", "C.UTF-8", 1);
        setenv("LC_CTYPE", "C.UTF-8", 1);
        setenv("CHARSET", "UTF-8", 1);  /* helps BusyBox wget */
        return;
    }
    /* Fallback for older glibc (e.g., CentOS 7) */
    if (setlocale(LC_CTYPE, "en_US.UTF-8")) {
        setenv("LANG", "en_US.UTF-8", 1);
        setenv("LC_CTYPE", "en_US.UTF-8", 1);
        setenv("CHARSET", "UTF-8", 1);
        return;
    }
    /* Last resort: plain C (ASCII). Still deterministic. */
    setlocale(LC_CTYPE, "C");
    setenv("LANG", "C", 1);
    setenv("LC_CTYPE", "C", 1);
    setenv("CHARSET", "ASCII", 1);
}

// Sanitize environment & process state early
static void sanitize_process_early(void) {
    clearenv_portable();
    setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/run/current-system/sw/bin", 1);
    set_locale_utf8_best_effort();
    umask(077);
#ifdef __linux__
    (void)prctl(PR_SET_DUMPABLE, 0, 0, 0, 0);
#endif
}

// Close all inherited fds >= 3
static void closefrom_safe(int lowfd) {
    DIR *d = opendir("/proc/self/fd");
    if (!d) {
        long max = sysconf(_SC_OPEN_MAX);
        if (max < 0 || max > 65536) max = 1024;
        for (int fd = lowfd; fd < max; ++fd) close(fd);
        return;
    }
    int dirfdno = dirfd(d);
    struct dirent *de;
    while ((de = readdir(d))) {
        if (de->d_name[0] == '.') continue;
        char *end = NULL;
        long fd = strtol(de->d_name, &end, 10);
        if (end && *end) continue;
        if (fd >= lowfd && fd != dirfdno) close((int)fd);
    }
    closedir(d);
}

// Pre-drop: ensure /tmp/nppp-cache exists always
static int ensure_tmp_cache_root(void) {
    struct stat st;

    if (lstat("/tmp", &st) != 0 || !S_ISDIR(st.st_mode)) return -1;

    struct stat stc;
    if (lstat("/tmp/nppp-cache", &stc) == 0) {
        if (S_ISLNK(stc.st_mode)) return -1;
        if (!S_ISDIR(stc.st_mode)) return -1;
        if (stc.st_uid != 0 || stc.st_gid != 0) return -1;
        if (chmod("/tmp/nppp-cache", 01777) != 0)
            s_perror("chmod /tmp/nppp-cache");
        if (chown("/tmp/nppp-cache", 0, 0) != 0)
            s_perror("chown /tmp/nppp-cache");
        return 0;
    }
    if (errno != ENOENT) return -1;

    if (mkdir("/tmp/nppp-cache", 0700) != 0) return -1;
    if (chown("/tmp/nppp-cache", 0, 0) != 0)
        s_perror("chown /tmp/nppp-cache");
    if (chmod("/tmp/nppp-cache", 01777) != 0)
        s_perror("chmod /tmp/nppp-cache");
    if (lstat("/tmp/nppp-cache", &stc) != 0 || !S_ISDIR(stc.st_mode) ||
        stc.st_uid != 0 || stc.st_gid != 0) return -1;
    return 0;
}

static int parent_cache_is_safe(void) {
    struct stat pc;
    if (lstat("/tmp/nppp-cache", &pc) != 0) return 0;
    if (S_ISLNK(pc.st_mode)) return 0;
    if (!S_ISDIR(pc.st_mode)) return 0;
    if (pc.st_uid != 0 || pc.st_gid != 0) return 0;
    return 1;
}

// Post-drop: /tmp isn't writable by the final euid, rewrite -P to /tmp/nppp-cache/<euid>
static void fix_wget_tmp_if_tmp_blocked(int argc, char **argv) {
    if (argc < 2) return;

    int prog_i = find_target_prog_index(argc, argv);
    if (prog_i >= argc) return;
    if (!is_prog(argv[prog_i], "wget")) return;

    for (int i = prog_i + 1; i + 1 < argc; i++) {
        if (strcmp(argv[i], "-P") == 0 && strcmp(argv[i + 1], "/tmp") == 0) {
            if (access("/tmp", W_OK) == 0) return;

            if (!parent_cache_is_safe() || access("/tmp/nppp-cache", W_OK) != 0) {
                s_fprintf(stderr, "Warning: /tmp not writable. No safe fallback. Keeping '-P /tmp'.\n");
                return;
            }

            char sub[PATH_MAX];
            if (safe_snprintf(sub, sizeof sub, "/tmp/nppp-cache/%lu", (unsigned long)geteuid()) != 0) {
                s_fprintf(stderr, "Warning: failed to compose fallback path. Keeping '-P /tmp'.\n");
                return;
            }

            if (mkdir(sub, 0700) != 0 && errno != EEXIST) {
                s_fprintf(stderr, "Warning: failed to create '%s'. Keeping '-P /tmp'.\n", sub);
                return;
            }

            struct stat ss;
            if (lstat(sub, &ss) != 0 || !S_ISDIR(ss.st_mode) || ss.st_uid != geteuid()) {
                s_fprintf(stderr, "Warning: unsafe fallback '%s'. Keeping '-P /tmp'.\n", sub);
                return;
            }

            if (access(sub, W_OK) != 0) {
                s_fprintf(stderr, "Warning: fallback '%s' not writable. Keeping '-P /tmp'.\n", sub);
                return;
            }

            argv[i + 1] = strdup(sub);
            s_fprintf(stderr, "Info: Rewriting wget -P '/tmp' -> '%s'\n", sub);
            return;
        }
    }
}

static int try_kill_mode(const char *arg) {
    if (strncmp(arg, "--kill=", 7) != 0)
        return 0;

    pid_t pid = atoi(arg + 7);
    if (pid <= 0) {
        s_fprintf(stderr, "Error: Invalid PID\n");
        return 1;
    }

#ifndef __linux__
    s_fprintf(stderr, "Error: --kill is only supported on Linux.\n");
    return 1;
#else
    // Check if process exists
    char path[64];
    snprintf(path, sizeof(path), "/proc/%d/status", (int)pid);
    FILE *fp = fopen(path, "r");
    if (!fp) {
        s_fprintf(stderr, "Error: PID %d does not exist or already exited\n", pid);
        return 1;
    }

    // Parse UID from /proc/<pid>/status
    char line[256];
    int uid = -1;
    while (fgets(line, sizeof(line), fp)) {
        if (strncmp(line, "Uid:", 4) == 0) {
            sscanf(line, "Uid:\t%d", &uid);
            break;
        }
    }

    fclose(fp);

    // Dynamically resolve 'nobody' instead of hardcoding 65534
    struct passwd *npw = getpwnam("nobody");
    if (!npw) {
        s_fprintf(stderr, "Warning: 'nobody' user not found\n");
        return 1;
    }

    if (uid != (int)npw->pw_uid) {
        s_fprintf(stderr, "Info: Refusing to kill PID %d: not owned by 'nobody' (uid=%d)\n", pid, uid);
        return 1;
    }

    // inside Linux branch of try_kill_mode
    if (!proc_in_nppp_cgroup(pid)) {
        s_fprintf(stderr, "Info: Refusing to kill PID %d: not in safexec cgroup\n", pid);
        return 1;
    }

    // Use pidfd if available to avoid PID reuse races
    int pfd = pidfd_open_wrap(pid);
    if (pfd >= 0) {
        if (pidfd_send_signal_wrap(pfd, SIGTERM) != 0) {
            s_perror("pidfd_send_signal(SIGTERM)");
            close(pfd);
            return 1;
        }
        close(pfd);
    } else {
        // Fallback: best-effort kill()
        if (kill(pid, SIGTERM) != 0) {
            s_perror("kill SIGTERM");
            return 1;
        }
    }

    // Not quiet
    printf("Success: Killed PID %d\n", pid);
    return 2;
#endif
}

int main(int argc, char *argv[]) {
    QUIET = env_quiet_enabled();

    // capture SAFE_CWD policy before any sanitize() clears env
    int safe_cwd_pref = env_flag("SAFEXEC_SAFE_CWD", SAFEXEC_SAFE_CWD_DEFAULT);

    // Version/help handler
    if (argc >= 2 && (strcmp(argv[1], "--version") == 0 || strcmp(argv[1], "-v") == 0)) {
        print_version();
        return 0;
    }

    if (argc < 2) { print_usage(argv[0]); return 1; }

    if (strcmp(argv[1], "--help") == 0 || strcmp(argv[1], "-h") == 0) {
        print_usage(argv[0]);
        return 0;
    }

    // Reject --kill without '=' (e.g., "--kill" or "--kill 123")
    if (strncmp(argv[1], "--kill", 6) == 0 && argv[1][6] != '=') {
        print_usage(argv[0]);
        return 1;
    }

    {
        // Handle --kill=<pid>
        int k = try_kill_mode(argv[1]);
        if (k != 0)
            return (k == 2) ? 0 : 1;
    }

    // From here, only "<program> [args...]" is allowed.
    if (argv[1][0] == '-' || is_all_digits(argv[1])) {
        print_usage(argv[0]);
        return 1;
    }

    // Enforce a tight allowlist (plugin only needs wget), handling "safexec nohup wget ..."
    int prog_i = find_target_prog_index(argc, argv);
    if (prog_i >= argc) {
        print_usage(argv[0]);
        return 1;
    }
    const char *prog_base = base_of(argv[prog_i]);
    if (!is_allowed_bin(prog_base)) {
        s_fprintf(stderr, "Error: '%s' is not allowed by safexec.\n", prog_base);
        return 1;
    }

    // PASS-THROUGH MODE
    if (geteuid() != 0) {
        // Safe DIR
        int use_safe_cwd = safe_cwd_pref;
        if (use_safe_cwd < 0) {
            // Auto mode: enable only when running interactively (a TTY is attached)
            use_safe_cwd = isatty(STDIN_FILENO) || isatty(STDOUT_FILENO) || isatty(STDERR_FILENO);
        }
        if (use_safe_cwd) {
            chdir_safe_if_cwd_inaccessible();
        }

        s_fprintf(stderr,
            "Info: Pass-Through Mode (euid=%ld). Starting '%s' as original user. "
            "To enable hardening: chown root:root %s && chmod 4755 %s (avoid nosuid).\n",
            (long)geteuid(), argv[1], argv[0], argv[0]);

        execvp(argv[1], &argv[1]);
        s_perror("safexec: execvp");
        _exit(1);
    }

    // Read isolation preferences & limits
    nppp_limits LIM = nppp_default_limits();
    enum detach_mode mode = parse_detach_mode();

    // capture pctnorm prefs BEFORE we clear the env
    int   pct_enable = env_flag("SAFEXEC_PCTNORM", 1);  // default ON
    char *pct_so     = dup_or_null(getenv("SAFEXEC_PCTNORM_SO"));
    char *pct_case   = dup_or_null(getenv("SAFEXEC_PCTNORM_CASE"));

    #define FREE_PCT() do { free(pct_so); free(pct_case); pct_so=NULL; pct_case=NULL; } while (0)

    // Sanitize env and process state before any NSS/library lookups
    sanitize_process_early();

    int isolated = 0;

    // Fresh group name to avoid stale limits from previous runs
    char cgname[64];
    if (safe_snprintf(cgname, sizeof cgname, "nppp.%ld", (long)getpid()) != 0) {
        s_fprintf(stderr, "Error: failed to compose cgroup name\n");
        FREE_PCT();
        return 1;
    }

    // Cleanup empty stale groups first, enable controllers
    if (cgv2_available()) {
        cgv2_cleanup_stale("nppp.");
        cgv2_enable_controllers();
    }

    // cgroup v2
    if (!isolated && (mode == DET_AUTO || mode == DET_CGV2)) {
        if (cgv2_available()) {
            if (cgv2_join_group(cgname, &LIM) == 0) {
                char selfcg[PATH_MAX];
                if (cgv2_self_dir(selfcg, sizeof selfcg) == 0)
                    s_fprintf(stderr, "Info: using cgroup v2 child %s\n", selfcg);
                else {
                    s_fprintf(stderr, "Info: using cgroup v2 child (path unknown)\n");
                }
                isolated = 1;
            } else if (mode == DET_CGV2) {
                s_fprintf(stderr, "Error: cgroup v2 requested but join failed\n");
                FREE_PCT();
                return 1;
            }
        } else if (mode == DET_CGV2) {
            s_fprintf(stderr, "Error: cgroup v2 requested but not available\n");
            FREE_PCT();
            return 1;
        }
    }

    // Apply RLIMITs
    if (!isolated && mode != DET_OFF) {
        s_fprintf(stderr, "Info: falling back to RLIMITs + nice/ionice\n");
        apply_rlimits_and_sched(&LIM);
        isolated = 1;
    }

    // Create /tmp/nppp-cache (01777) if possible, idempotent
    (void)ensure_tmp_cache_root();

    // Remember original caller IDs for safe fallback
    uid_t ruid = getuid();
    gid_t rgid = getgid();
    int   was_root = (geteuid() == 0);

    struct passwd *pw = getpwnam("nobody");
    if (pw) {
        if (setgroups(0, NULL) != 0) { s_perror("setgroups (nobody)"); goto drop_to_fpm_user; }
        if (setgid(pw->pw_gid) != 0) { s_perror("setgid (nobody)");    goto drop_to_fpm_user; }
        if (setuid(pw->pw_uid) != 0) { s_perror("setuid (nobody)");    goto drop_to_fpm_user; }
    } else {
        s_fprintf(stderr, "Warning: 'nobody' user not found, continuing as original user\n");
    }

    // Ensure we never exec as root; if still euid==0, drop to FPM user
    if (geteuid() == 0) { goto drop_to_fpm_user; }

post_drop:

    // Never, ever exec as root. If privilege drop didn’t stick, bail out.
    if (geteuid() == 0) {
        s_fprintf(stderr, "Fatal: safexec cannot be used as root; refusing to exec (privilege drop failed).\n");
        FREE_PCT();
        return 1;
    }

    // Safe DIR
    int use_safe_cwd = safe_cwd_pref;
    if (use_safe_cwd < 0) {
        // Auto mode: enable only when running interactively (a TTY is attached)
        use_safe_cwd = isatty(STDIN_FILENO) || isatty(STDOUT_FILENO) || isatty(STDERR_FILENO);
    }
    if (use_safe_cwd) {
        chdir_safe_if_cwd_inaccessible();
    }

    // If /tmp isn't writable by the final euid
    fix_wget_tmp_if_tmp_blocked(argc, argv);

    // Prevent privilege regain in the child
#ifdef __linux__
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        s_perror("prctl PR_SET_NO_NEW_PRIVS");
    }
#endif

    /* LD_PRELOAD shim injection: only for wget/curl, only if possible. */
    if (pct_enable && (is_prog(argv[prog_i], "wget") || is_prog(argv[prog_i], "curl"))) {
        const char *so = pct_so ? pct_so : "/usr/lib/npp/libnpp_norm.so";
        if (is_secure_so(so)) {
            const char *case_val = (pct_case && *pct_case) ? pct_case : "upper";
            setenv("LD_PRELOAD", so, 1);
            setenv("PCTNORM_CASE", case_val, 1);
            s_fprintf(stderr,
                      "Info: Injected: LD_PRELOAD=%s PCTNORM_CASE=%s (prog=%s)\n",
                      so, case_val, base_of(argv[prog_i]));
        } else {
            s_fprintf(stderr, "Info: Not injecting LD_PRELOAD shim (unsafe or missing so: %s)\n", so);
        }
    } else {
        /* Make it explicit why we didn't inject, to aid debugging */
        if (!pct_enable) {
            s_fprintf(stderr, "Info: LD_PRELOAD shim disabled via SAFEXEC_PCTNORM=0\n");
        } else {
            s_fprintf(stderr, "Info: LD_PRELOAD shim not applicable (prog=%s)\n", base_of(argv[prog_i]));
        }
    }

    // Close all inherited fds except stdio before exec
    closefrom_safe(3);

    fflush(NULL);
    execvp(argv[1], &argv[1]);

    {
        int saved = errno;
        FREE_PCT();
        errno = saved;
        s_perror("safexec: execvp");
        _exit(1);
    }

drop_to_fpm_user:

    // Drop to original FPM user (ruid/rgid). If this fails, refuse to run.
    if (was_root) {
        if (setgroups(0, NULL) != 0) { s_perror("setgroups (fallback)"); FREE_PCT(); return 1; }
        if (setgid(rgid) != 0)       { s_perror("setgid (fallback)");    FREE_PCT(); return 1; }
        if (setuid(ruid) != 0)       { s_perror("setuid (fallback)");    FREE_PCT(); return 1; }
    }

    // If not was_root, we’re already the caller; nothing to do
    goto post_drop;
}
