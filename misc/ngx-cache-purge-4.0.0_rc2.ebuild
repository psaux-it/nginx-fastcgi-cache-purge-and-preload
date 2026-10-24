# Copyright 1999-2026 Gentoo Authors
# Distributed under the terms of the GNU General Public License v2

EAPI=9

NGINX_MOD_OPENRESTY_TESTS=1
inherit nginx-module

MY_PN="ngx_cache_purge"
MY_PV="${PV/_rc/-rc}"

DESCRIPTION="NGINX module to purge FastCGI, proxy, SCGI and uWSGI cache entries"
HOMEPAGE="https://github.com/nginx-modules/ngx_cache_purge"
SRC_URI="https://github.com/nginx-modules/${MY_PN}/archive/refs/tags/${MY_PV}.tar.gz -> ${P}.tar.gz"
S="${WORKDIR}/${MY_PN}-${MY_PV}"

LICENSE="BSD-2"
SLOT="0"
KEYWORDS="~amd64"
IUSE+=" +fastcgi +proxy +scgi +uwsgi"
REQUIRED_USE="|| ( fastcgi proxy scgi uwsgi )"

# The Test::Nginx suites only exercise proxy_cache_purge.
RESTRICT+=" test? ( !proxy? ( test ) )"

# The module links against NGINX's per-protocol modules by symbol, so each
# flag is pinned to its NGINX module (not a single "|| ( any )" group).
_ngx_cache_purge_dep="
        fastcgi? ( $(ngx_gen_dep http_fastcgi) )
        proxy? ( $(ngx_gen_dep http_proxy) )
        scgi? ( $(ngx_gen_dep http_scgi) )
        uwsgi? ( $(ngx_gen_dep http_uwsgi) )
"
DEPEND+=" ${_ngx_cache_purge_dep}"
RDEPEND+=" ${_ngx_cache_purge_dep}"
BDEPEND+=" test? ( proxy? ( $(ngx_gen_dep http_proxy http_rewrite http_access) ) )"

DOCS=(
        README.md SECURITY.md LICENSE
        docs/{DIRECTIVES,EXAMPLES,HTTP_RESPONSES,MIGRATION,OPERATIONS,REFRESH}.md
)

src_prepare() {
        # These two suites need an NGINX source tree (NGINX_SOURCE).
        rm -- t/native_helpers.t t/purge_runtime.t || die

        nginx-module_src_prepare
}

src_configure() {
        local backend
        for backend in fastcgi proxy scgi uwsgi; do
                ngx_usex_module "${backend}" "http_${backend}"
        done

        nginx-module_src_configure
}

src_install() {
        nginx-module_src_install
        einstalldocs
}

pkg_postinst() {
        nginx-module_pkg_postinst

        if [[ -z ${REPLACING_VERSIONS} ]]; then
                elog ""
                elog "The PURGE endpoints exposed by this module remove cache entries"
                elog "and must never be reachable by untrusted clients.  Restrict them"
                elog "with allow/deny (or an authentication layer)."
                elog "See SECURITY.md in ${EPREFIX}/usr/share/doc/${PF}."
        fi

        local v
        for v in ${REPLACING_VERSIONS}; do
                if ver_test "${v}" -lt 4.0.0_rc2; then
                        ewarn ""
                        ewarn "Upgrading from ${PN}-${v}: v4 changes the shared-memory layout,"
                        ewarn "so NGINX must be restarted completely (a reload is not enough)."
                        ewarn "HTTP responses change as well: JSON is the default format and"
                        ewarn "a missing cache entry now returns '200 no_match'.  Update your"
                        ewarn "clients accordingly; see MIGRATION.md and HTTP_RESPONSES.md in"
                        ewarn "${EPREFIX}/usr/share/doc/${PF}."
                        break
                fi
        done
}
