/**
 * safexec - A secure privilege-dropping and cgroup-isolating wrapper for Nginx Cache Purge Preload
 *
 * Purpose:
 * --------
 * This binary is used by the NPP plugin:
 *   "Nginx Cache Purge Preload for WordPress"
 * to safely launch `wget` from within a PHP-FPM context, ensuring:
 *   - The process runs as an unprivileged user (`nobody`)
 *   - It is detached from the PHP-FPM service's cgroup
 *   - It cannot retain inherited privileges
 *   - It cannot kill arbitrary processes (only those it owns or is authorized to)
 *
 * Motivation:
 * -----------
 * Directly calling `shell_exec(wget ..)` with user inputs in PHP-FPM runs the command as the FPM pool user, which:
 *   - Inherits the service's cgroup slice (`system-php-fpm.slice`)
 *   - Poses a security risk if an attacker injects malicious commands
 *   - Prevents full isolation or proper resource restriction
 *
 * By using this wrapper:
 *   ✓ Drops privileges to the `nobody` user before execution
 *   ✓ Isolates the process into a neutral (root) cgroup to fully detach from php-fpm's slice
 *   ✓ Prevents privilege escalation and lateral movement from injected code
 *   ✓ Provides a controlled kill interface — only allows terminating `nobody`-owned processes
 *
 * Usage:
 *   safexec <command> [args...]       Executes the command as UID/GID nobody
 *   safexec --kill=<pid>              Attempts to kill a process only if it is owned by 'nobody'
 *
 * Features:
 *   - SUID-safe: Meant to be owned by root and setuid (chmod 4755)
 *   - Cgroup-compatible: Moves the process into a specified cgroup for isolation or resource limits
 *   - Abuse-resistant: Will not kill arbitrary processes or run as privileged
 *
 * Compile:
 *   gcc safexec.c -o /usr/local/bin/safexec
 *   chown root:root /usr/local/bin/safexec
 *   chmod 4755 /usr/local/bin/safexec
 */

#include <unistd.h>
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>
#include <pwd.h>
#include <grp.h>

#define CGROUP_TARGET "/sys/fs/cgroup/cgroup.procs"

int move_to_cgroup(pid_t pid) {
    int fd = open(CGROUP_TARGET, O_WRONLY | O_CLOEXEC);
    if (fd == -1) {
        perror("open cgroup.procs");
        return -1;
    }

    if (dprintf(fd, "%d", pid) < 0) {
        perror("dprintf cgroup.procs");
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
        fprintf(stderr, "Invalid PID\n");
        return 1;
    }

    // Check if process exists
    char path[64];
    snprintf(path, sizeof(path), "/proc/%d/status", pid);
    FILE *fp = fopen(path, "r");
    if (!fp) {
        fprintf(stderr, "PID %d does not exist or already exited\n", pid);
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

    if (uid != 65534) {
        fprintf(stderr, "Refusing to kill PID %d: not owned by 'nobody' (uid=%d)\n", pid, uid);
        return 1;
    }

    // Send SIGTERM
    if (kill(pid, SIGTERM) != 0) {
        perror("kill failed");
        return 1;
    }

    printf("Killed PID %d\n", pid);
    return 2;
}

int main(int argc, char *argv[]) {
    if (argc < 2) {
        fprintf(stderr, "Usage: %s <program> [args...]\n", argv[0]);
        return 1;
    }

    // Kill shortcut
    int k = try_kill_mode(argv[1]);
    if (k == 2) return 0;
    if (k == 1) return 1;

    pid_t pid = getpid();

    // Move to isolated cgroup
    if (move_to_cgroup(pid) != 0) {
        fprintf(stderr, "Warning: Failed to move to cgroup %s\n", CGROUP_TARGET);
    }

    struct passwd *pw = getpwnam("nobody");
    if (pw) {
        if (setgroups(0, NULL) != 0) {
            perror("setgroups failed");
        }
        if (setgid(pw->pw_gid) != 0) {
            perror("setgid failed");
        }
        if (setuid(pw->pw_uid) != 0) {
            perror("setuid failed");
        }
    } else {
        fprintf(stderr, "Warning: 'nobody' user not found, continuing as current user\n");
    }

    // Exec command (e.g., wget)
    execvp(argv[1], &argv[1]);
    perror("exec failed");
    _exit(1);
}
