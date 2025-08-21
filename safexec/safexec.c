/**
 * safexec - A secure privilege-dropping and cgroup-isolating wrapper for PHP's shell_exec
 *
 * Purpose:
 * --------
 * This code is written for the NPP (Nginx Cache Purge Preload for WordPress):
 *   to safely launch `shell_exec` from within a PHP context, ensuring:
 *   - The process runs as an unprivileged user (`nobody`)
 *   - It is detached from the PHP-FPM service's cgroup2
 *   - It cannot retain inherited privileges
 *   - It cannot kill arbitrary processes (only those it owns or is authorized to)
 *
 * Motivation:
 * -----------
 * Directly calling `shell_exec()` with user inputs in PHP runs the command as the FPM pool user, which:
 *   - Inherits the service's cgroup slice (`system-php-fpm.slice`)
 *   - Poses a security risk if an attacker injects malicious commands
 *   - Prevents full isolation or proper resource restriction
 *
 * By using this wrapper:
 *   ✓ Drops privileges to the `nobody` user before execution
 *   ✓ Isolates the process into a neutral cgroup2 to fully detach from php-fpm's slice
 *   ✓ Prevents privilege escalation and lateral movement from injected code
 *   ✓ Provides a controlled kill interface
 */

#define _GNU_SOURCE 1

#include <unistd.h>
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>
#include <pwd.h>
#include <grp.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <sys/prctl.h>
#include <limits.h>
#include <dirent.h>
#include <stdarg.h>

// Metadata
#define SAFEXEC_NAME     "safexec"
#define SAFEXEC_VERSION  "1.9.2"
#define SAFEXEC_AUTHOR   "Hasan Calisir"

// Cgroup
#define CGROUP_V2_MARKER "/sys/fs/cgroup/cgroup.controllers"
#define CGROUP_TARGET    "/sys/fs/cgroup/cgroup.procs"

// Quiet-aware logging layer
static int QUIET = 0;

static int env_quiet_enabled(void) {
    const char *q = getenv("SAFEXEC_QUIET");
    return q && *q && strcmp(q, "0") != 0;
}

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

// Safe DIR
static void chdir_safe_if_cwd_inaccessible(void) {
    /* If the final euid can "search" the current dir, do nothing */
    if (access(".", X_OK) == 0) return;
    s_fprintf(stderr, "Info: CWD not accessible; switching to /tmp\n");

    // Try /tmp first; if it fails, try /
    if (chdir("/tmp") != 0) {
        if (chdir("/") != 0) {
        }
    }
}

// Print version
static void print_version(void) {
    s_printf(
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

// Sanitize environment & process state early
static void sanitize_process_early(void) {
    clearenv();
    setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/run/current-system/sw/bin", 1);
    setenv("LANG", "C", 1);
    umask(077);
    (void)prctl(PR_SET_DUMPABLE, 0, 0, 0, 0);
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
    if (argc < 3) return;

    int prog_i = 1;
    if (argc > 2 && is_prog(argv[1], "nohup")) prog_i = 2;
    if (!is_prog(argv[prog_i], "wget")) return;

    for (int i = prog_i + 1; i + 1 < argc; i++) {
        if (strcmp(argv[i], "-P") == 0 && strcmp(argv[i + 1], "/tmp") == 0) {
            if (access("/tmp", W_OK) == 0) return;

            if (!parent_cache_is_safe() || access("/tmp/nppp-cache", W_OK) != 0) {
                s_fprintf(stderr, "Warning: /tmp not writable; no safe fallback. Keeping '-P /tmp'.\n");
                return;
            }

            char sub[PATH_MAX];
            snprintf(sub, sizeof sub, "/tmp/nppp-cache/%lu", (unsigned long)geteuid());

            if (mkdir(sub, 0700) != 0 && errno != EEXIST) {
                s_fprintf(stderr, "Warning: failed to create '%s'. Keeping '-P /tmp'.\n", sub, strerror(errno));
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

int move_to_cgroup(pid_t pid) {
    // Only act on cgroup v2. If not v2, treat as success (no-op).
    if (access(CGROUP_V2_MARKER, R_OK) != 0) {
        return 0;
    }

    int fd = open(CGROUP_TARGET, O_WRONLY | O_CLOEXEC | O_NOFOLLOW);
    if (fd == -1) {
        s_perror("open cgroup.procs");
        return -1;
    }

    if (dprintf(fd, "%d\n", pid) < 0) {
        s_perror("dprintf cgroup.procs");
        close(fd);
        return -1;
    }

    close(fd);
    return 0;
}

int try_kill_mode(const char *arg) {
    if (strncmp(arg, "--kill=", 7) != 0)
        return 0;

    pid_t pid = atoi(arg + 7);
    if (pid <= 0) {
        s_fprintf(stderr, "Error: Invalid PID\n");
        return 1;
    }

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

    // Send SIGTERM
    if (kill(pid, SIGTERM) != 0) {
        s_perror("Error: kill failed");
        return 1;
    }

    printf("Success: Killed PID %d\n", pid);
    return 2;
}

int main(int argc, char *argv[]) {
    QUIET = env_quiet_enabled();

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

    // PASS-THROUGH MODE
    if (geteuid() != 0) {
        // Safe DIR
        chdir_safe_if_cwd_inaccessible();

        s_fprintf(stderr,
            "Info: Pass-Through Mode (euid=%ld). Starting '%s' as original fpm user. "
            "To enable hardening: chown root:root %s && chmod 4755 %s (avoid nosuid).\n",
            (long)geteuid(), argv[1], argv[0], argv[0]);

        fflush(NULL);
        execvp(argv[1], &argv[1]);
        s_perror("Error: safexec failed");
        _exit(1);
    }

    // Sanitize env and process state before any NSS/library lookups
    sanitize_process_early();

    pid_t pid = getpid();

    // Move to isolated cgroup
    if (move_to_cgroup(pid) != 0) {
        s_fprintf(stderr, "Warning: Failed to move to cgroup %s\n", CGROUP_TARGET);
    }

    // Create /tmp/nppp-cache (01777) if possible, idempotent
    (void)ensure_tmp_cache_root();

    // Remember original caller IDs for safe fallback
    uid_t ruid = getuid();
    gid_t rgid = getgid();
    int   was_root = (geteuid() == 0);

    struct passwd *pw = getpwnam("nobody");
    if (pw) {
        if (setgroups(0, NULL) != 0) { s_perror("Error: setgroups failed"); goto drop_to_fpm_user; }
        if (setgid(pw->pw_gid) != 0) { s_perror("Error: setgid failed");    goto drop_to_fpm_user; }
        if (setuid(pw->pw_uid) != 0) { s_perror("Error: setuid failed");    goto drop_to_fpm_user; }
    } else {
        s_fprintf(stderr, "Warning: 'nobody' user not found, continuing as original user\n");
    }

    // Ensure we never exec as root; if still euid==0, drop to FPM user
    if (geteuid() == 0) { goto drop_to_fpm_user; }

post_drop:

    // Safe DIR
    chdir_safe_if_cwd_inaccessible();

    // If /tmp isn't writable by the final euid
    fix_wget_tmp_if_tmp_blocked(argc, argv);

    // Prevent privilege regain in the child
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        s_perror("Warning: prctl(NO_NEW_PRIVS) failed");
    }

    // Close all inherited fds except stdio before exec
    closefrom_safe(3);

    // Exec command
    fflush(NULL);
    execvp(argv[1], &argv[1]);
    s_perror("Error: safexec failed");
    _exit(1);

drop_to_fpm_user:

    // Drop to original FPM user (ruid/rgid). If this fails, refuse to run.
    if (was_root) {
        if (setgroups(0, NULL) != 0) { s_perror("Error: fallback setgroups failed"); return 1; }
        if (setgid(rgid) != 0)       { s_perror("Error: fallback setgid failed");    return 1; }
        if (setuid(ruid) != 0)       { s_perror("Error: fallback setuid failed");    return 1; }
    }

    // If not was_root, we’re already the caller; nothing to do
    goto post_drop;
}
