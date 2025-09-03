// SPDX-License-Identifier: GPL-2.0-only
/*
 * libnpp_norm.so — LD_PRELOAD library to normalize percent-encoded HTTP request-lines for wget
 *
 * Overview
 * --------
 * Normalizes the *case* of percent-encoded triplets (%xx) in the request-target
 * of the first HTTP request-line sent by clients (wget, curl, etc.). This avoids
 * cache-key inconsistencies caused by mixed-case encodings.
 *
 * Build modes
 * -----------
 * Default (general-purpose): interposes
 *   - send(), sendto(), sendmsg()
 *   - write(), writev()
 *   - SSL_write()  (OpenSSL; resolved lazily if libssl is loaded later)
 *   - gnutls_record_send() (GnuTLS; resolved lazily if libgnutls is loaded later)
 *
 * Wget-only fast path:
 *   Define WGET_FASTPATH to interpose only:
 *     - write()
 *     - SSL_write()
 *     - gnutls_record_send()
 *
 * Runtime configuration
 * ---------------------
 *   PCTNORM_CASE = "upper" | "lower" | "off"
 *     - Controls hex case for %xx in the request-target.
 *     - Default: "upper" (UPPERCASE hex).  "off" disables normalization.
 *     - Read via getenv() once per process (thread-safe with pthread_once).
 *
 * Behavior
 * --------
 * - Edits bytes **only** when a complete request-line is present in the same
 *   buffer/call:  METHOD SP TARGET SP HTTP/MAJ.MINOR CRLF
 *   (HTTP/0.9 lines without a version are left untouched.)
 * - For vectored I/O (sendmsg/writev), normalization happens only if the entire
 *   first line resides in the **first** iovec.
 * - If no '%' occurs in the TARGET, or an allocation fails, forwards the call
 *   unchanged. Missing TLS symbols are treated as unimplemented (errno=ENOSYS).
 * - TLS hooks are late-bound on each TLS call if previously unresolved, so
 *   dlopen() after this library loads still works.
 *
 * Intentional limits
 * ------------------
 * - No cross-call buffering: if the request-line is fragmented across calls or
 *   iovecs (beyond iov[0]), the data is forwarded unchanged.
 * - Requires CRLF terminator; lone LF is ignored.
 * - Only the first request-line in a buffer is considered (HTTP pipelining beyond
 *   the first line is not normalized).
 * - Not async-signal-safe (uses malloc); do not use from signal handlers.
 * - Static binaries are unaffected (LD_PRELOAD does not apply).
 *
 * Compatibility
 * -------------
 * - Linux (glibc/musl): primary target.
 * - BSD/macOS: may work via LD_PRELOAD/DYLD_INSERT_LIBRARIES, but untested and
 *   symbol resolution rules differ; TLS hooks may vary.
 * - Windows: not applicable.
 *
 * Safety notes
 * ------------
 * - Semantics are unchanged apart from the hex case of percent triplets.
 * - No privilege escalation or interference with unrelated I/O.
 * - Uses getenv() for configuration; setuid binaries ignore LD_PRELOAD.
 *
 * Intended use
 * ------------
 * - Companion to safexec and Nginx Cache Preload workflows to ensure normalized
 *   request-lines when fetching from origin servers.
 *
 * Author:  Hasan Calisir
 * Version: 0.3.2 (2025)
 */

#define _GNU_SOURCE
#include <dlfcn.h>
#include <pthread.h>
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <sys/uio.h>
#include <unistd.h>

/* ===== Build-time toggles =====
 * Default: general-purpose (hook many APIs).
 * Define WGET_FASTPATH to hook only what Wget uses: write/SSL_write/gnutls_record_send.
 */
#ifdef WGET_FASTPATH
#  define PCT_WANT_SEND     0
#  define PCT_WANT_SENDTO   0
#  define PCT_WANT_SENDMSG  0
#  define PCT_WANT_WRITEV   0
#  define PCT_WANT_CLOSE    0
#else
#  define PCT_WANT_SEND     1
#  define PCT_WANT_SENDTO   1
#  define PCT_WANT_SENDMSG  1
#  define PCT_WANT_WRITEV   1
#  define PCT_WANT_CLOSE    0
#endif

/* Always keep write() + TLS paths */
#define PCT_WANT_WRITE  1
#define PCT_WANT_TLS    1

/* ======= Constants ======= */
#ifndef REQ_LINE_SCAN_CAP
#define REQ_LINE_SCAN_CAP 8192
#endif
#define likely(x)   __builtin_expect(!!(x),1)
#define unlikely(x) __builtin_expect(!!(x),0)

/* ======= Real function pointers (guarded per toggle) ======= */
#if PCT_WANT_SEND
static ssize_t (*real_send)(int, const void*, size_t, int) = NULL;
#endif
#if PCT_WANT_SENDTO
static ssize_t (*real_sendto)(int, const void*, size_t, int,
                              const struct sockaddr*, socklen_t) = NULL;
#endif
#if PCT_WANT_SENDMSG
static ssize_t (*real_sendmsg)(int, const struct msghdr*, int) = NULL;
#endif
#if PCT_WANT_WRITE
static ssize_t (*real_write)(int, const void*, size_t) = NULL;
#endif
#if PCT_WANT_WRITEV
static ssize_t (*real_writev)(int, const struct iovec*, int) = NULL;
#endif
#if PCT_WANT_CLOSE
static int     (*real_close)(int) = NULL;
#endif
#if PCT_WANT_TLS
/* Kept as void* to avoid heavy TLS headers; prototypes match ABI in practice. */
static int     (*real_SSL_write)(void*, const void*, int) = NULL;
static ssize_t (*real_gnutls_record_send)(void*, const void*, size_t) = NULL;
#endif

static pthread_once_t resolve_once = PTHREAD_ONCE_INIT;

static void resolve_syms(void) {
#if PCT_WANT_SEND
    real_send    = dlsym(RTLD_NEXT, "send");
#endif
#if PCT_WANT_SENDTO
    real_sendto  = dlsym(RTLD_NEXT, "sendto");
#endif
#if PCT_WANT_SENDMSG
    real_sendmsg = dlsym(RTLD_NEXT, "sendmsg");
#endif
#if PCT_WANT_WRITE
    real_write   = dlsym(RTLD_NEXT, "write");
#endif
#if PCT_WANT_WRITEV
    real_writev  = dlsym(RTLD_NEXT, "writev");
#endif
#if PCT_WANT_CLOSE
    real_close   = dlsym(RTLD_NEXT, "close");
#endif
#if PCT_WANT_TLS
    real_SSL_write          = dlsym(RTLD_NEXT, "SSL_write");
    real_gnutls_record_send = dlsym(RTLD_NEXT, "gnutls_record_send");
#endif
}

static inline void ensure_resolved(void) {
    (void)pthread_once(&resolve_once, resolve_syms);
}

/* ===== Config (runtime via env) =====
 * PCTNORM_CASE = "upper" | "lower" | "off"  (default: "upper")
 */
static int pctnorm_case = +1; /* +1=upper, -1=lower, 0=off */
static pthread_once_t config_once = PTHREAD_ONCE_INIT;

static inline unsigned char to_lc(unsigned char c) { return (unsigned char)tolower((int)c); }

static void pctnorm_load_config(void) {
    const char *s = getenv("PCTNORM_CASE");
    if (!s || !*s) { pctnorm_case = +1; return; }
    if (!strcasecmp(s, "upper")) pctnorm_case = +1;
    else if (!strcasecmp(s, "lower")) pctnorm_case = -1;
    else if (!strcasecmp(s, "off"))   pctnorm_case =  0;
    else                              pctnorm_case = +1;
}
static inline void ensure_config(void) { (void)pthread_once(&config_once, pctnorm_load_config); }


/* Lazy re-resolve TLS symbols in case libssl/gnutls were dlopen()'d later. */
#if PCT_WANT_TLS
static inline void resolve_tls_if_needed(void) {
    if (!real_SSL_write)
        real_SSL_write = dlsym(RTLD_NEXT, "SSL_write");
    if (!real_gnutls_record_send)
        real_gnutls_record_send = dlsym(RTLD_NEXT, "gnutls_record_send");
}
#endif

/* ======= Small helpers (ctype-safe) ======= */
static inline int is_hex_uc(unsigned char c) { return isxdigit((int)c); }
static inline unsigned char to_uc(unsigned char c) { return (unsigned char)toupper((int)c); }

/* Minimal but complete method set (space included in literals). */
static inline int begins_with_http_method(const unsigned char *p, size_t n) {
    return (n >= 4  && memcmp(p, "GET ",     4) == 0) ||
           (n >= 5  && memcmp(p, "HEAD ",    5) == 0) ||
           (n >= 5  && memcmp(p, "POST ",    5) == 0) ||
           (n >= 6  && memcmp(p, "PATCH ",   6) == 0) ||
           (n >= 4  && memcmp(p, "PUT ",     4) == 0) ||
           (n >= 7  && memcmp(p, "DELETE ",  7) == 0) ||
           (n >= 8  && memcmp(p, "OPTIONS ", 8) == 0) ||
           (n >= 8  && memcmp(p, "CONNECT ", 8) == 0) ||
           (n >= 6  && memcmp(p, "TRACE ",   6) == 0);
}

/* Find end of request-line ("\r\n"); returns index AFTER CRLF, or 0 if not found. */
static inline size_t find_reqline_end(const unsigned char *p, size_t n) {
    size_t lim = n < REQ_LINE_SCAN_CAP ? n : REQ_LINE_SCAN_CAP;
    for (size_t i = 0; i + 1 < lim; i++) {
        if (p[i] == '\r' && p[i+1] == '\n') return i + 2;
    }
    return 0;
}

/* Returns 1 if the request-target span (between first and second space) contains a '%' */
static inline int target_has_percent(const unsigned char *p, size_t line_len) {
    const unsigned char *sp1 = memchr(p, ' ', line_len);
    if (!sp1) return 0;
    size_t rem = line_len - (size_t)(sp1 - p) - 1;
    const unsigned char *sp2 = memchr(sp1 + 1, ' ', rem);
    if (!sp2) return 0;
    for (const unsigned char *q = sp1 + 1; q < sp2; q++) {
        if (*q == '%') return 1;
    }
    return 0;
}

/* Case-adjust %xx in request-target (between first and second space).
 * mode > 0 => UPPERCASE, mode < 0 => lowercase, mode == 0 => off
 */
static inline void case_triplets_in_target(unsigned char *line, size_t line_len, int mode) {
    if (mode == 0) return; /* off */

    const unsigned char *sp1 = memchr(line, ' ', line_len);
    if (!sp1) return;
    size_t rem = line_len - (size_t)(sp1 - line) - 1;
    const unsigned char *sp2 = memchr(sp1 + 1, ' ', rem);
    if (!sp2) return;

    unsigned char *q = (unsigned char *)(sp1 + 1);
    unsigned char *end = (unsigned char *)sp2;

    while (q + 2 < end) {
        if (*q == '%' && is_hex_uc(*(q+1)) && is_hex_uc(*(q+2))) {
            if (mode > 0) {
                *(q+1) = to_uc(*(q+1));
                *(q+2) = to_uc(*(q+2));
            } else {
                *(q+1) = to_lc(*(q+1));
                *(q+2) = to_lc(*(q+2));
            }
            q += 3;
        } else {
            q++;
        }
    }
}

/* Create a normalized copy of the buffer when it contains a full request-line.
 * Returns 0 on success with (*outbuf,*outlen) set to a heap buffer; returns -1 for "no change/error".
 */
static int maybe_normalize_first_line_copy(const unsigned char *in, size_t n,
                                           unsigned char **outbuf, size_t *outlen)
{
    ensure_config();
    if (pctnorm_case == 0) return -1;

    if (unlikely(n < 4)) return -1;
    if (!begins_with_http_method(in, n)) return -1;

    size_t line_end = find_reqline_end(in, n);
    if (line_end == 0) return -1;

    if (!target_has_percent(in, line_end)) return -1;

    unsigned char *tmp = (unsigned char *)malloc(n);
    if (unlikely(!tmp)) return -1;

    memcpy(tmp, in, n);
    case_triplets_in_target(tmp, line_end, pctnorm_case);

    *outbuf = tmp;
    *outlen = n;
    return 0;
}

/* ======= Interposed functions ======= */

#if PCT_WANT_SEND
ssize_t send(int sockfd, const void *buf, size_t len, int flags) {
    ensure_resolved();
    if (unlikely(!real_send)) { errno = ENOSYS; return -1; }

    const unsigned char *in = (const unsigned char *)buf;
    unsigned char *tmp = NULL; size_t outlen = 0;

    if (likely(buf != NULL) && likely(len >= 4) &&
        maybe_normalize_first_line_copy(in, len, &tmp, &outlen) == 0) {
        ssize_t r = real_send(sockfd, tmp, outlen, flags);
        free(tmp);
        return r;
    }
    return real_send(sockfd, buf, len, flags);
}
#endif

#if PCT_WANT_SENDTO
ssize_t sendto(int sockfd, const void *buf, size_t len, int flags,
               const struct sockaddr *dest, socklen_t dlen)
{
    ensure_resolved();
    if (unlikely(!real_sendto)) { errno = ENOSYS; return -1; }

    const unsigned char *in = (const unsigned char *)buf;
    unsigned char *tmp = NULL; size_t outlen = 0;

    if (likely(buf != NULL) && likely(len >= 4) &&
        maybe_normalize_first_line_copy(in, len, &tmp, &outlen) == 0) {
        ssize_t r = real_sendto(sockfd, tmp, outlen, flags, dest, dlen);
        free(tmp);
        return r;
    }
    return real_sendto(sockfd, buf, len, flags, dest, dlen);
}
#endif

#if PCT_WANT_SENDMSG
ssize_t sendmsg(int sockfd, const struct msghdr *msg, int flags) {
    ensure_resolved();
    if (unlikely(!real_sendmsg)) { errno = ENOSYS; return -1; }

    if (unlikely(msg == NULL) || unlikely(msg->msg_iovlen <= 0))
        return real_sendmsg(sockfd, msg, flags);

    /* Only normalize if the entire request-line is in the FIRST iovec. */
    const struct iovec *iov0 = &msg->msg_iov[0];
    const unsigned char *in = (const unsigned char *)iov0->iov_base;
    size_t len = iov0->iov_len;

    unsigned char *tmp0 = NULL; size_t outlen0 = 0;
    if (likely(in != NULL) && likely(len >= 4) &&
        maybe_normalize_first_line_copy(in, len, &tmp0, &outlen0) == 0) {

        size_t iovsz = (size_t)msg->msg_iovlen * sizeof(struct iovec);
        struct iovec *iov_copy = (struct iovec *)malloc(iovsz);
        if (!iov_copy) { free(tmp0); return real_sendmsg(sockfd, msg, flags); }

        memcpy(iov_copy, msg->msg_iov, iovsz);
        iov_copy[0].iov_base = tmp0;
        iov_copy[0].iov_len  = outlen0;

        struct msghdr msg_copy = *msg;
        msg_copy.msg_iov = iov_copy;

        ssize_t r = real_sendmsg(sockfd, &msg_copy, flags);
        free(iov_copy);
        free(tmp0);
        return r;
    }
    return real_sendmsg(sockfd, msg, flags);
}
#endif

#if PCT_WANT_WRITE
/* Some stacks use write() on sockets. Only normalize if full request-line is present. */
ssize_t write(int fd, const void *buf, size_t count) {
    ensure_resolved();
    if (unlikely(!real_write)) { errno = ENOSYS; return -1; }

    const unsigned char *in = (const unsigned char *)buf;
    unsigned char *tmp = NULL; size_t outlen = 0;

    if (likely(buf != NULL) && likely(count >= 4) &&
        maybe_normalize_first_line_copy(in, count, &tmp, &outlen) == 0) {
        ssize_t r = real_write(fd, tmp, outlen);
        free(tmp);
        return r;
    }
    return real_write(fd, buf, count);
}
#endif

#if PCT_WANT_WRITEV
ssize_t writev(int fd, const struct iovec *iov, int iovcnt) {
    ensure_resolved();
    if (unlikely(!real_writev)) { errno = ENOSYS; return -1; }

    if (unlikely(iov == NULL) || unlikely(iovcnt <= 0))
        return real_writev(fd, iov, iovcnt);

    /* Only if the full request-line is contained in the FIRST iovec. */
    const struct iovec *iov0 = &iov[0];
    const unsigned char *in = (const unsigned char *)iov0->iov_base;
    size_t len = iov0->iov_len;

    unsigned char *tmp0 = NULL; size_t outlen0 = 0;
    if (likely(in != NULL) && likely(len >= 4) &&
        maybe_normalize_first_line_copy(in, len, &tmp0, &outlen0) == 0) {

        struct iovec *copy = (struct iovec *)malloc((size_t)iovcnt * sizeof(struct iovec));
        if (!copy) { free(tmp0); return real_writev(fd, iov, iovcnt); }

        memcpy(copy, iov, (size_t)iovcnt * sizeof(struct iovec));
        copy[0].iov_base = tmp0;
        copy[0].iov_len  = outlen0;

        ssize_t r = real_writev(fd, copy, iovcnt);
        free(copy);
        free(tmp0);
        return r;
    }
    return real_writev(fd, iov, iovcnt);
}
#endif

#if PCT_WANT_CLOSE
int close(int fd) {
    ensure_resolved();
    if (unlikely(!real_close)) { errno = ENOSYS; return -1; }
    return real_close(fd);
}
#endif

/* ======= TLS paths (HTTPS) ======= */
#if PCT_WANT_TLS
/* Same “full first-line in this call” rule. Symbols may appear after dlopen(). */

int SSL_write(void *ssl, const void *buf, int num) {
    ensure_resolved();
    resolve_tls_if_needed();
    if (unlikely(!real_SSL_write)) { errno = ENOSYS; return -1; }

    const unsigned char *in = (const unsigned char *)buf;
    unsigned char *tmp = NULL; size_t outlen = 0;

    if (likely(buf != NULL) && likely(num > 0) &&
        maybe_normalize_first_line_copy(in, (size_t)num, &tmp, &outlen) == 0) {
        int r = real_SSL_write(ssl, tmp, (int)outlen);
        free(tmp);
        return r;
    }
    return real_SSL_write(ssl, buf, num);
}

ssize_t gnutls_record_send(void *session, const void *data, size_t data_size) {
    ensure_resolved();
    resolve_tls_if_needed();
    if (unlikely(!real_gnutls_record_send)) { errno = ENOSYS; return -1; }

    const unsigned char *in = (const unsigned char *)data;
    unsigned char *tmp = NULL; size_t outlen = 0;

    if (likely(data != NULL) && likely(data_size > 0) &&
        maybe_normalize_first_line_copy(in, data_size, &tmp, &outlen) == 0) {
        ssize_t r = real_gnutls_record_send(session, tmp, outlen);
        free(tmp);
        return r;
    }
    return real_gnutls_record_send(session, data, data_size);
}
#endif
