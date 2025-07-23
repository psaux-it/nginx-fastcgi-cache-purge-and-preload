#!/bin/bash

# Designed for  All-in-One Monolithic Server arces (PHP,NGINX,WP,SQL all in one server)
# For dockerized environment check --> https://github.com/psaux-it/wordpress-nginx-cache-docker

# Copyright (C) 2024 Hasan CALISIR <hasan.calisir@psauxit.com>
# Distributed under the GNU General Public License, version 2.0.
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

# SCRIPT DESCRIPTION:
# -------------------
# This script is written for "FastCGI Cache Purge and Preload for Nginx"
#
# NPP - Wordpress Plugin.
# URL: https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/
#
# This script attempts to automatically match PHP-FPM user (as known,
# PHP process owner or website-user) along with their associated
# Nginx Cache Paths first.
#
# If cannot automatically match the PHP-FPM-USER along with their
# associated Nginx Cache Path, script offers an easy manual setup option
# with the 'manual-configs.nginx' file.
#
# Also, this script automates the management of Nginx Cache Paths in an environment
# with two distinct user roles: the web server user (nginx or www-data)
# and the PHP-FPM user. It utilizes `bindfs` to create a FUSE mount of the
# original Nginx Cache Paths, which allows the PHP-FPM user to write to these
# directories with tailored permissions. This approach effectively resolves
# permission conflicts, ensuring secure and efficient access to the cache
# while maintaining the integrity of the ORIGINAL NGINX Cache Paths.

# This script creates an 'npp-wordpress' systemd service.
# After completing the setup (whether automatic or manual), you can manage
# the automatically created 'npp-wordpress' systemd service on the WP admin
# dashboard NPP plugin STATUS tab.

# By the way:
# NPP users have the flexibility to manage FUSE mount and unmount operations
# for the original Nginx Cache Path directly from the WP admin dashboard.
# This flexibility ensures that the PHP-FPM process owner has the necessary
# permissions, effectively preventing unexpected permission issues and
# maintaining consistent cache stability.
#
# **Issue Resolution: Quickly resolve unexpected permission issues by remounting.
# **Improved Cache Stability: Regular management maintains cache consistency and accessibility.
# **Ease of Use: User-friendly integration in the WP admin dashboard for effective management.

# NOTE-1
# ---------
# Furthermore, if you're hosting multiple WordPress sites each with their own
# Nginx cache paths and associated PHP-FPM users on the same host, you'll find
# that deploying just one instance of this script effectively manages all
# WordPress instances using the NPP plugin. This streamlined approach centralizes
# cache management tasks, ensuring optimal efficiency and simplified maintenance
# throughout your server environment.

# NOTE-2
# ---------
# Please read carefully the comments between at line 342-362 that about granting
# specific sudo permissions to the PHP-FPM process owners to manage 'npp-wordpress'
# systemd service directly on WP admin dashboard NPP plugin STATUS tab.

# NOTE-3
# ---------
# If Auto detection not works for you, for proper matching, please ensure that your
# Nginx Cache Path includes the associated PHP-FPM-USER username.
# For example assuming your PHP-FPM-USER = psauxit

# The bellow example fastcgi_cache_path formats will match perfectly with your PHP-FPM-USER
# /dev/shm/fastcgi-cache-psauxit
# /dev/shm/cache-psauxit
# /dev/shm/psauxit
# /var/cache/psauxit-fastcgi
# /var/cache/website-psauxit.com

# Manual setup instructions
manual_setup() {
  echo -e "\n\e[91mCanceled:\e[0m Automated Setup has been canceled by the user. Proceeding to manual setup."
  # Provide instructions for manual configuration
  echo -e "\e[36mTo set up manual configuration, create a file named \e[95m'manual-configs.nginx' \e[0m \e[36min current directory."
  echo -e "Each entry should follow the format: 'PHP_FPM_USER NGINX_CACHE_PATH', with one entry per virtual host, space-delimited."
  echo -e "Example --> psauxit /dev/shm/fastcgi-cache-psauxit <--"
  echo -e "Ensure that every new website added to your host is accompanied by an entry in this file."
  echo -e "After making changes, remember to restart the script \e[95mfastcgi_ops_root.sh\e[0m manually."
  exit 1
}

# Handle ctrl+c
trap manual_setup SIGINT

# Get help before any interrupt
help() {
  if command -v tput > /dev/null 2>&1; then
    cyan=$(tput setaf 6)
    reset=$(tput sgr0)
    m_tab='  '
  fi

  echo -e "\n${m_tab}${cyan}# Wordpress FastCGI Cache Purge&Preload Help"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------"
  echo -e "${m_tab}#${m_tab}--wp-fuse-mount   need root! mount FUSE Nginx Cache Path, with altered permissions for associated PHP-FPM USER"
  echo -e "${m_tab}#${m_tab}--wp-fuse-umount  need root! umount FUSE Nginx Cache Path, with altered permissions for associated PHP-FPM USER"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------${reset}\n"
}

# Check if script is executed as root
if [[ $EUID -ne 0 ]]; then
  echo -e "\e[91mThis script must be run as root\e[0m"
  exit 1
fi

# Required commands
required_commands=(
  "realpath"
  "dirname"
  "pgrep"
  "basename"
  "nginx"
  "bindfs"
  "curl"
  "systemctl"
  "stat"
  "php"
)

# Check if required commands are available
for cmd in "${required_commands[@]}"; do
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    echo -e "\e[91mError:\e[0m \e[93m${cmd}\e[0m \e[96mis not installed or not found in PATH.\e[0m"
    exit 1
  fi
done

# Check if PHP is properly installed and functional
if ! php -v >/dev/null 2>&1; then
  echo -e "\e[91mError:\e[0m \e[93mPHP\e[0m is not properly installed or not functioning correctly."
  exit 1
fi

# Check if any /etc/php*/ directories exist
if ! ls /etc/php*/ >/dev/null 2>&1; then
  echo -e "\e[91mError:\e[0m No PHP configuration directories found in \e[93m/etc/php*/\e[0m."
  exit 1
fi

# Discover script path
this_script_full_path=$(realpath "${BASH_SOURCE[0]}")
this_script_path=$(dirname "${this_script_full_path}")
this_script_name=$(basename "${this_script_full_path}")

# Ensure script path is resolved
if [[ -z "${this_script_path}" ]]; then
  echo -e '\e[91mError:\e[0m \e[96mCannot find script path!\e[0m'
  exit 1
fi

# Enable extglob
# Remove trailing / (removes / and //) from script path
shopt -s extglob
this_script_path="${this_script_path%%+(/)}"

# Systemd service files
service_file_new="/etc/systemd/system/npp-wordpress.service"
service_file_old="/etc/systemd/system/wp-fcgi-notify.service"

# Define the main sudoers file and tmp/backup paths
SUDOERS_FILE="/etc/sudoers"
TEMP_FILE="/etc/sudoers.tmp"
BACKUP_FILE="/etc/sudoers.bak"

# Define NPP Wordpress sudoers config file
NPP_SUDOERS="npp_wordpress"

# Repo URL's for version checking
libfuse_repo_url="https://api.github.com/repos/libfuse/libfuse/releases/latest"
bindfs_repo_url="https://api.github.com/repos/mpartel/bindfs/git/refs/tags"

# Define the @includedir path if it does not already exist.
# We use a path other than "/etc/sudoers.d" to avoid overriding the user's current setup.
# Users may avoid using "/etc/sudoers.d" specifically because it is a catch-all directory
# where system package managers can place sudoers file rules during package installation.
CUSTOM_INCLUDEDIR_PATH="/etc/sudoers.npp"

# Function to check bindfs version
check_bindfs_version() {
  local latest_version
  local installed_version

  if command -v bindfs &> /dev/null; then
    installed_version=$(bindfs --version | head -n1 | awk '{print $2}')
    latest_version=$(curl -s "${bindfs_repo_url}" | grep -oP '"ref": "refs/tags/\K[0-9]+(\.[0-9]+)*' | sort -V | tail -n1)

    if [[ -n "${latest_version}" && "${latest_version}" =~ ^[0-9]+(\.[0-9]+)*$ && -n "${installed_version}" && "${installed_version}" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
      if [[ "${installed_version}" != "${latest_version}" ]]; then
        echo -e "\e[94mInfo:\e[0m A newer version of \e[93mbindfs\e[0m is available: \e[93m${latest_version}\e[0m. It's recommended to update for improved security and performance."
      else
        echo -e "\e[92mInfo:\e[0m Current \e[93mbindfs\e[0m version is up to date: \e[93m${installed_version}\e[0m"
      fi
    fi
  fi
}

# Function to check libfuse version
check_libfuse_version() {
  # Get the latest version info
  local latest_version
  local installed_version

  # Get latest version info
  latest_version=$(curl -s "${libfuse_repo_url}" | grep '"tag_name"' | sed -E 's/.*"tag_name": "([^"]+)".*/\1/' | sed 's/fuse-//')

  if [[ -n "${latest_version}" ]] && [[ "${latest_version}" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
    # Check for FUSE 3
    if command -v fusermount3 &> /dev/null; then
      installed_version=$(fusermount3 -V | grep -oP 'version:\s*\K[0-9.]+')

      if [[ -n "${installed_version}" ]] && [[ "${installed_version}" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
        if [[ "${installed_version}" != "${latest_version}" ]]; then
          echo -e "\e[94mInfo:\e[0m Newer version of \e[93mlibfuse\e[0m available \e[93m${latest_version}\e[0m. It's recommended to update for improved security and performance."
        else
          echo -e "\e[92mInfo:\e[0m Current \e[93mlibfuse\e[0m version is up to date: \e[93m${installed_version}\e[0m"
        fi
      fi
    # Check for FUSE 2
    elif command -v fusermount &> /dev/null; then
      installed_version=$(fusermount -V | grep -oP 'version:\s*\K[0-9.]+')

      if [[ -n "${installed_version}" ]] && [[ "${installed_version}" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
        if [[ "${installed_version}" != "${latest_version}" ]]; then
          echo -e "\e[94mInfo:\e[0m A newer version of \e[93mlibfuse\e[0m is available: \e[93m${latest_version}\e[0m. It's recommended to update for improved security and performance."
        else
          echo -e "\e[92mInfo:\e[0m Current \e[93mlibfuse\e[0m version is up to date: \e[93m${installed_version}\e[0m"
        fi
      fi
    fi
  fi
}

# Check for sudo and visudo
check_sudo_and_visudo() {
  for cmd in sudo visudo; do
    command -v "${cmd}" > /dev/null 2>&1 || return 1
  done

  # Check if /etc/sudoers exists and not empty
  if [[ ! -s "${SUDOERS_FILE}" ]]; then
    return 1
  fi
  return 0
}

# Function to check/add for @includedir or #includedir (sudo v1.9.1 and older)
# to main sudoers file if not exists.
# We don't want to add entry to main sudoers file directly for safety.
find_create_includedir() {
  if check_sudo_and_visudo; then
    includedir_path=""

    # Check for @includedir or #includedir in the sudoers file
    while IFS= read -r line; do
      if [[ "${line}" =~ ^[@#]includedir[[:space:]]+([^#]*) ]]; then
        includedir_path="${BASH_REMATCH[1]}"
        break
      fi
    done < "${SUDOERS_FILE}"

    # Did we find any @includedir or #includedir?
    if [[ -n "${includedir_path}" ]]; then
      # Trim leading and trailing whitespace
      includedir_path="${includedir_path#"${includedir_path%%[![:space:]]*}"}"
      includedir_path="${includedir_path%"${includedir_path##*[![:space:]]}"}"

      # Remove trailing slash if it exists
      includedir_path="${includedir_path%/}"

      # Check if the includedir is a directory if not create it
      if ! [[ -d "${includedir_path}" ]]; then
        mkdir -p "${includedir_path}" || { echo -e "\e[91mFailed to create includedir path\e[0m"; return 1; }
      fi
    else
      # We need to add @includedir or #includedir to main sudoers file

      # --> Workflow <--
      # 1. Create sudoers backup/tmp files
      # 2. Modify sudoers tmp file according to sudo version
      # 3. Create includedir path before testing tmp file via visudo
      # 4. Test tmp file before replacing the original sudoers file
      # 5. Replace the original sudoers file with the tmp file
      # 6. Test the updated sudoers file and restore from backup if there is an error
      # 7. Clean up tmp and backup files
      # 8. Assign custom_includedir to includedir

      # 1. Create sudoers backup/tmp files
      cp "${SUDOERS_FILE}" "${TEMP_FILE}" || { echo -e "\e[91mFailed to create sudoers tmp file\e[0m"; return 1; }
      cp "${SUDOERS_FILE}" "${BACKUP_FILE}" || { echo -e "\e[91mFailed to create sudoers backup file\e[0m"; return 1; }

      # 2. Modify sudoers tmp file according to sudo version
      # Get the version of sudo to determine the correct includedir syntax (@ or #)
      SUDO_VERSION="$(sudo -V | grep 'Sudo version' | awk '{print $3}')"
      VERSION_MAJOR="$(echo "${SUDO_VERSION}" | cut -d. -f1)"
      VERSION_MINOR="$(echo "${SUDO_VERSION}" | cut -d. -f2)"

      # Check if SUDO_VERSION, VERSION_MAJOR, and VERSION_MINOR were successfully retrieved
      if [[ -z "${SUDO_VERSION}" || -z "${VERSION_MAJOR}" || -z "${VERSION_MINOR}" ]]; then
        echo -e "\e[91mCannot find sudo major & minor versions\e[0m"
        return 1
      fi

      # Compare the version with reference 1.9.1 (https://www.sudo.ws/docs/man/sudoers.man/#Including_other_files_from_within_sudoers)
      if [[ "${VERSION_MAJOR}" -gt 1 || ( "${VERSION_MAJOR}" -eq 1 && "${VERSION_MINOR}" -ge 9 ) ]]; then
        echo "@includedir ${CUSTOM_INCLUDEDIR_PATH}" | sudo EDITOR='tee -a' visudo -f "${TEMP_FILE}" > /dev/null 2>&1 || { echo -e "\e[91mFailed to add includedir to sudoers file\e[0m"; return 1; }
      else
        echo "#includedir ${CUSTOM_INCLUDEDIR_PATH}" | sudo EDITOR='tee -a' visudo -f "${TEMP_FILE}" > /dev/null 2>&1 || { echo -e "\e[91mFailed to add includedir to sudoers file\e[0m"; return 1; }
      fi

      # 3. Create includedir path before testing tmp file via visudo
      mkdir -p "${CUSTOM_INCLUDEDIR_PATH}" || { echo -e "\e[91mFailed to create /etc/sudoers.npp\e[0m"; return 1; }

      # 4. Test tmp file before replacing the original sudoers file
      if visudo -c -f "${TEMP_FILE}" > /dev/null 2>&1; then
        # 5. Replace the original sudoers file with the tmp file
        cp "${TEMP_FILE}" "${SUDOERS_FILE}" || { echo -e "\e[91mFailed to update sudoers file\e[0m"; return 1; }
      fi

      # 6. Test the updated sudoers file and restore from backup if there is an error
      if ! visudo -c -f "${SUDOERS_FILE}" > /dev/null 2>&1; then
        cp "${BACKUP_FILE}" "${SUDOERS_FILE}" || { echo -e "\e[91mFailed to return from sudoers backup file\e[0m"; return 1; }
        return 1
      fi

      # 7. Clean up tmp and backup files
      rm -f "${TEMP_FILE:?}"
      rm -f "${BACKUP_FILE:?}"

      # 8. Assign custom_includedir_path to includedir_path
      includedir_path="${CUSTOM_INCLUDEDIR_PATH}"
    fi
  else
    echo -e "\033[1;33mWarning:\033[1;36m '\033[1;35msudo\033[1;36m', '\033[1;35mvisudo\033[1;36m' need to be installed, and '\033[1;35m${SUDOERS_FILE}\033[1;36m' must exist to manage systemd service from WordPress admin dashboard directly. Skipped integration..\033[0m"
    return 1
  fi
  return 0
}

# Automate the process of granting specific sudo permissions to the PHP-FPM
# process owners on a system. These permissions specifically authorize
# PHP process owners to execute systemctl commands (restart)
# for NPP plugin systemd service 'npp-wordpress'.
# By granting these permissions, the goal is to allow the 'npp-wordpress'
# systemd service to be controlled directly from the WordPress admin
# dashboard, enhancing operational flexibility and automation.
# After successful integration, NPP users will be able to manage (restart)
# the 'npp-wordpress' systemd service on WP admin dashboard NPP plugin STATUS tab.
# This implementation is not strictly necessary for functional cache
# purge & preload actions and does not break the default setup process,
# but it is important to have this ability to control the NPP plugin systemd
# service 'npp-wordpress' on WP admin dashboard to deal with premission issues
# directly from front-end.
# By the way NPP users can fully control FUSE FS mount/umount operations
# for the original Nginx Cache Path with altered permissions for the
# PHP-FPM process owner to address unexpected permission issues
# and maintain stable cache consistency.
# By granting (nginx -T) command sudo permission to PHP-FPM-USER also we are able to
# automatically force create NGINX CACHE PATH by PHP process owner to prevent
# the NGINX Cache Path not found errors.
grant_sudo_perm_systemctl_for_php_process_owner() {
  SYSTEMCTL_PATH=$(type -P systemctl)
  NGINX_PATH=$(type -P nginx)

  # Try to get/create the includedir first
  if find_create_includedir; then
    # Check if we have already implemented sudo privileges
    if ! [[ -f "${includedir_path}/${NPP_SUDOERS}" ]]; then
      # Check if the fcgi array is not empty
      if (( ${#fcgi[@]} != 0 )); then
        for user in "${!fcgi[@]}"; do
          PERMISSIONS="${user} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} restart ${service_file_new##*/}, ${SYSTEMCTL_PATH} is-active ${service_file_new##*/}, ${NGINX_PATH} -T"
          echo "${PERMISSIONS}" | sudo EDITOR='tee -a' visudo -f "${includedir_path}/${NPP_SUDOERS}" > /dev/null 2>&1 || return 1
        done
        chmod 0440 "${includedir_path}/${NPP_SUDOERS}"
      else
        return 1
      fi
    else
      # Check sudo perm already granted
      if (( ${#fcgi[@]} != 0 )); then
        for user in "${!fcgi[@]}"; do
          PERMISSIONS="${user} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} restart ${service_file_new##*/}, ${SYSTEMCTL_PATH} is-active ${service_file_new##*/}, ${NGINX_PATH} -T"
          if grep -Fxq "${PERMISSIONS}" "${includedir_path}/${NPP_SUDOERS}"; then
            return 2
          fi
        done
      else
        return 1
      fi
    fi
  else
    return 1
  fi

  # Check the integrity, checking main sudoers file is enough it also checks the includedir paths
  if ! visudo -c -f "${SUDOERS_FILE}" > /dev/null 2>&1; then
    # Revert back changes
    rm -f "${includedir_path:?}/${NPP_SUDOERS:?}"
    return 1
  fi
  return 0
}

# Restart setup
restart_auto_setup() {
  if [[ $1 == "manual" ]]; then
    setup_flag_nginx="${this_script_path}/manual-configs.nginx"
    setup_flag="${this_script_path}/manual_setup_on"
  else
    setup_flag="${this_script_path}/auto_setup_on"
  fi

  # Remove the completed setup lock files
  [[ -n "${setup_flag}" ]] && rm -f "${setup_flag}" > /dev/null 2>&1
  [[ -n "${setup_flag_nginx}" ]] && rm -f "${setup_flag_nginx}" > /dev/null 2>&1

  # Revert NPP sudoers configs
  if check_sudo_and_visudo; then
    # Check for @includedir or #includedir in the sudoers file
    while IFS= read -r line; do
      if [[ "${line}" =~ ^[@#]includedir[[:space:]]+([^#]*) ]]; then
        includedir_path="${BASH_REMATCH[1]}"
        break
      fi
    done < "${SUDOERS_FILE}"

    # Did we find any @includedir or #includedir ?
    if [[ -n "${includedir_path}" ]]; then
      # Trim leading and trailing whitespace
      includedir_path="${includedir_path#"${includedir_path%%[![:space:]]*}"}"
      includedir_path="${includedir_path%"${includedir_path##*[![:space:]]}"}"

      # Remove trailing slash if it exists
      includedir_path="${includedir_path%/}"
    fi

    if [[ -f "${includedir_path}/${NPP_SUDOERS}" ]]; then
      rm -f "${includedir_path:?}/${NPP_SUDOERS:?}"
    fi

    # Remove custom includedir in main sudoers file if we put before
    # Check if includedir_path matches CUSTOM_INCLUDEDIR_PATH
    if [[ "${includedir_path}" == "${CUSTOM_INCLUDEDIR_PATH}" ]]; then
      # Create sudoers backup/tmp files
      cp "${SUDOERS_FILE}" "${TEMP_FILE}"
      cp "${SUDOERS_FILE}" "${BACKUP_FILE}"

      # Use sed to remove the exact @includedir and #includedir lines from the sudoers file
      sed -i "\|^@includedir ${CUSTOM_INCLUDEDIR_PATH}$|d" "${TEMP_FILE}" > /dev/null 2>&1
      sed -i "\|^#includedir ${CUSTOM_INCLUDEDIR_PATH}$|d" "${TEMP_FILE}" > /dev/null 2>&1

      # Test tmp before replacement with original
      if visudo -c -f "${TEMP_FILE}" > /dev/null 2>&1; then
        # Replace original with tmp
        cp "${TEMP_FILE}" "${SUDOERS_FILE}"
      fi

      # Test original before remove backup, if we get error return from backup
      if ! visudo -c -f "${SUDOERS_FILE}" > /dev/null 2>&1; then
        cp "${BACKUP_FILE}" "${SUDOERS_FILE}"
      else
        # Clean up tmp/backup
        rm -f "${TEMP_FILE:?}"
        rm -f "${BACKUP_FILE:?}"
        # Remove custom includedir
        rmdir "${CUSTOM_INCLUDEDIR_PATH:?}" > /dev/null 2>&1
      fi
    fi
  fi

  # Stop and remove systemd service
  if [[ -f "${service_file_new}" ]]; then
    systemctl stop "${service_file_new##*/}" > /dev/null 2>&1
    systemctl disable "${service_file_new##*/}" > /dev/null 2>&1
    rm -f "${service_file_new}"
    systemctl daemon-reload > /dev/null 2>&1
  fi

  # Migrate old service file as name changed -- wp-fcgi-notify.service --> npp-wordpress.service
  if [[ -f "${service_file_old}" ]]; then
    systemctl stop wp-fcgi-notify.service > /dev/null 2>&1
    systemctl disable wp-fcgi-notify.service > /dev/null 2>&1
    rm -f "${service_file_old}"
    systemctl daemon-reload > /dev/null 2>&1
  fi

  # Restart the setup
  exec bash "${this_script_path}/${this_script_name}"
}

# Print the currently listening Nginx Cache Paths
print_nginx_cache_paths() {
  # Add a short delay to ensure all log entries are captured
  sleep 2
  systemctl status "${service_file_new##*/}" \
    | grep -E '(Started NPP|All done!)' \
    | sed -E 's/.*?(Started NPP|All done!) /\1/' \
    | awk '{
      gsub(/\(/, "\x1b[33m(", $0);
      gsub(/\)/, ")\x1b[0m\x1b[36m", $0);
      print "\x1b[36m" $0 "\x1b[0m"
    }'
  echo ""
}

# Prompt restart setup or apply changes in current setup
# Check if running in an interactive terminal
if [[ -t 0 ]]; then
  if [[ -f "${this_script_path}/auto_setup_on" ]]; then
    # User prompt for fresh restart auto setup
    read -rp $'\e[96mAuto setup has already been completed. If you want to restart the setup, select [R/r]. If you want to just apply \e[93mnginx.conf\e[0m \e[96mchanges, select [A/a] \e[92m[R/A/q]: \e[0m' restart_confirm
    if [[ "${restart_confirm}" =~ ^[Rr]$ ]]; then
      restart_auto_setup
    elif [[ "${restart_confirm}" =~ ^[Aa]$ ]]; then
      # Handle newly added Nginx Cache Paths to take affect immediately with service restart (modified nginx.conf)
      systemctl restart "${service_file_new##*/}" > /dev/null 2>&1
      # Check if the service restarted successfully
      if systemctl is-active --quiet "${service_file_new##*/}"; then
        echo ""
        echo -e "\e[92mSuccess:\e[0m The Systemd service \e[93mnpp-wordpress\e[0m has been restarted. Newly added Nginx Cache paths in \e[93mnginx.conf\e[0m are now mounted via \e[93mbindfs\e[0m with the '-npp' suffix and altered permissions."
        print_nginx_cache_paths
      else
        echo -e "\e[91mError:\e[0m Systemd service \e[93mnpp-wordpress\e[0m failed to restart."
      fi
    else
      exit 0
    fi
  elif [[ -f "${this_script_path}/manual_setup_on" ]]; then
    read -rp $'\e[96mManual setup via \e[35m'"${this_script_path}"$'/manual-configs.nginx\e[96m has already been completed. If you want to restart the setup, select [R/r]. If you want to just apply \e[35mmanual-configs.nginx\e[0m \e[96mchanges, select [A/a] \e[92m[R/A/q]: \e[0m' restart_confirm
    if [[ "${restart_confirm}" =~ ^[Rr]$ ]]; then
      restart_auto_setup manual
    elif [[ "${restart_confirm}" =~ ^[Aa]$ ]]; then
      # Handle newly added Nginx Cache Paths to take affect immediately with service restart (modified manual-configs.nginx)
      systemctl restart "${service_file_new##*/}" > /dev/null 2>&1
      # Check if the service restarted successfully
      if systemctl is-active --quiet "${service_file_new##*/}"; then
        echo ""
        echo -e "\e[92mSuccess:\e[0m The Systemd service \e[93mnpp-wordpress\e[0m has been restarted. Newly added Nginx Cache paths in \e[93mnginx.conf\e[0m are now mounted via \e[93mbindfs\e[0m with the '-npp' suffix and altered permissions."
        print_nginx_cache_paths
      else
        echo -e "\e[91mError:\e[0m Systemd service \e[93mnpp-wordpress\e[0m failed to restart."
      fi
    else
      exit 0
    fi
  elif [[ -f "${service_file_new}" || -f "${service_file_old}" ]]; then
    read -rp $'\e[96mIt appears that an instance of the setup has already been completed in a different directory. Do you want to remove old and restart the clean setup here? \e[92m[Y/n]: \e[0m' restart_confirm
    if [[ "${restart_confirm}" =~ ^[Yy]$ ]]; then
      restart_auto_setup
    else
      # Prevent multiple setup in different locations
      exit 0
    fi
  fi
fi

# Function to dynamically detect the location of nginx.conf
detect_nginx_conf() {
  local DEFAULT_NGINX_CONF_PATHS=(
    "/etc/nginx/nginx.conf"
    "/usr/local/nginx/conf/nginx.conf"
  )

  for path in "${DEFAULT_NGINX_CONF_PATHS[@]}"; do
    if [[ -f "${path}" ]]; then
      NGINX_CONF="${path}"
      break
    fi
  done

  if [[ -z "${NGINX_CONF}" ]]; then
    echo ""
    echo -e "\e[31mError: Nginx configuration file (\e[33mnginx.conf\e[31m) not found in default paths.\e[0m"
    echo -e "\e[36mPlease create a symbolic link from your original \e[33mnginx.conf\e[36m to \e[33m/etc/nginx/nginx.conf\e[36m, or continue with manual setup.\e[0m"
    echo -e "\e[36mExample: ln -s \e[33m/path/to/your/original/nginx.conf\e[36m \e[33m/etc/nginx/nginx.conf\e[0m"
    # Provide instructions for manual configuration
    echo ""
    echo -e "\e[35mManual Setup Instructions\e[0m\n\e[36m#########################\e[0m"
    echo -e "\n\e[36mTo set up manual configuration, create a file named \e[95m'manual-configs.nginx' \e[0m \e[36min current directory."
    echo -e "Each entry should follow the format: 'PHP_FPM_USER NGINX_CACHE_PATH', with one entry per virtual host, space-delimited."
    echo -e "Example --> psauxit /dev/shm/fastcgi-cache-psauxit <--"
    echo -e "Ensure that every new website added to your host is accompanied by an entry in this file."
    echo -e "After making changes, remember to restart the script \e[95mfastcgi_ops_root.sh\e[0m."
    echo ""
    exit 1
  fi
}

# Function to get the Nginx config path and try to apply chmod o+r
# NPP Wordpress plugin needs read permission on 'nginx.conf' and vhosts
# to parse necessarry data.
apply_read_permission_to_nginx_files() {
  # Get the Nginx config path
  detect_nginx_conf

  # Extract the directory path from the config file location
  nginx_conf_dir=$(dirname "${NGINX_CONF}")

  # Define directories to target, dynamically based on the nginx.conf location
  local TARGET_DIRS=(
    "${nginx_conf_dir}/conf.d"
    "${nginx_conf_dir}/sites-available"
  )

  # Loop through each target directory
  for dir in "${TARGET_DIRS[@]}"; do
    if [[ -d "${dir}" ]]; then
      # Loop through all files in the directory
      find "${dir}" -type f -print0 | while IFS= read -r -d '' file; do
        # Get the file's permission in octal format
        file_permissions=$(stat -c "%a" "${file}")

        # Extract the "others" permission
        others_permission=${file_permissions: -1}

        # Check if the "others" permission does not include read permission (4)
        if (( others_permission & 4 == 0 )); then
          # Apply the chmod to the file if others don't have read permission
          chmod o+r "${file}"
        fi
      done
    fi
  done
}

# Function to extract FastCGI cache paths from NGINX configuration files
extract_fastcgi_cache_paths() {
  {
    # Extract paths from directly nginx.conf
    grep -E "^\s*fastcgi_cache_path\s+" "${NGINX_CONF}" | awk '{print $2}'

    # Also get included paths to nginx.conf and extract fastcgi cache paths
    while IFS= read -r include_line; do
      include_path=$(echo "${include_line}" | awk '{print $2}')
      # Check wildcard for multiple files
      if [[ "${include_path}" == *"*"* ]]; then
        # Remove wildcard, slash, get the exact path
        target_dir=$(echo "${include_path}" | sed 's/\*.*//' | sed 's/\/$//')
      else
        # This is a directly included single file
        grep -E "^\s*fastcgi_cache_path\s+" "${include_path}" | awk '{print $2}'
      fi
      # Search for fastcgi_cache_path in the target directory recursively
      if [ -d "${target_dir}" ]; then
        find -L "${target_dir}" -type f -exec grep -H "fastcgi_cache_path" {} + | awk -F: '{print $2":"$3}' | sed '/^\s*#/d' | awk '{print $2}'
      fi
    done < <(grep -E "^\s*include\s+" "${NGINX_CONF}" | grep -v "^\s*#" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/;//')
  } | sort | uniq
}

# Function to validate FastCGI cache paths for safety
validate_cache_paths() {
  local path_list=("$@")
  local invalid_paths=()

  # Not allowed directories
  local critical_dirs=(
    "/bin" "/boot" "/etc" "/lib" "/lib64" "/media" "/proc" "/root" "/sbin"
    "/srv" "/sys" "/usr" "/home" "/mnt" "/var/log" "/var/spool" "/libexec"
    "/run" "/var/run"
  )

  # Allowed directories that must have at least one level deeper.
  local allowed_deeper_dirs=(
    "/dev" "/var" "/tmp" "/opt"
  )

  for path in "${path_list[@]}"; do
    # Check if path is empty
    if [[ -z "${path}" ]]; then
      invalid_paths+=("null")
      continue
    fi

    # Check if path is root
    if [[ "${path}" == "/" ]]; then
      invalid_paths+=("${path}")
      continue
    fi

    # Check Nginx cache path is a critical directory or starts with any critical directory
    for critical in "${critical_dirs[@]}"; do
      if [[ "${path}" == "${critical}" || "${path}" == "${critical}/" || "${path}" == "${critical}/"* ]]; then
        invalid_paths+=("${path}")
        break
      fi
    done

    # Check if the path is under an allowed directory but is shallow (invalid if not deeper)
    for allowed in "${allowed_deeper_dirs[@]}"; do
      if [[ "${path}" == "${allowed}" || "${path}" == "${allowed}/" ]]; then
        invalid_paths+=("${path}")
        break
      fi
    done
  done

  if [[ "${#invalid_paths[@]}" -gt 0 ]]; then
    return 1
  fi
  return 0
}

# Instead of nginx -t
get_active_vhosts() {
  # Get nginx.conf
  detect_nginx_conf

  # Initialize an empty array for included paths
  include_dirs=()

  # Get included paths from nginx.conf
  while IFS= read -r include_line; do
    include_path="$(echo "${include_line}" | awk '{print $2}')"
    # Check wildcard for multiple files
    if [[ "${include_path}" == *"*"* ]]; then
      # Remove wildcard, slash, get the exact path
      dir="$(echo "${include_path}" | sed 's/\*.*//' | sed 's/\/$//')"
      include_dirs+=("${dir}")
    else
      # This is a directly included single file
      include_dirs+=("${include_path}")
    fi
  done < <(grep -E "^\s*include\s+" "${NGINX_CONF}" | grep -v "^\s*#" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/;//')

  # Check if include_dirs array is not empty before processing
  if (( "${#include_dirs[@]}" )); then
    # Ensure all paths in the include_dirs end with '/'
    for i in "${!include_dirs[@]}"; do
      include_dirs[$i]="${include_dirs[$i]%/}/"
    done

    # Get active vhosts in include_dirs
    for dir in "${include_dirs[@]}"; do
      if [[ -d "${dir}" ]]; then
        for conf_file in "${dir}"*; do
          if [[ -f "${conf_file}" ]]; then
            grep -E "server_name|fastcgi_pass" "${conf_file}" | grep -B1 "fastcgi_pass" | grep "server_name" | awk '{print $2}' | sed 's/;$//'
          fi
        done
      fi
    done
  fi
}

# Auto setup triggers, auto detection stuff
if ! [[ -f "${this_script_path}/manual-configs.nginx" ]]; then
  # Get nginx.conf
  detect_nginx_conf

  # Extract FastCGI Cache Paths from nginx.conf
  FASTCGI_CACHE_PATHS=()
  while IFS= read -r path; do
    FASTCGI_CACHE_PATHS+=("${path}")
  done < <(extract_fastcgi_cache_paths)

  # Find active vhosts
  ACTIVE_VHOSTS=()
  while IFS= read -r VHOST; do
    ACTIVE_VHOSTS+=("${VHOST}")
  done < <(get_active_vhosts)

  # Find all php-fpm users
  PHP_FPM_USERS=()
  while read -r user; do
    PHP_FPM_USERS+=("${user}")
  done < <(grep -ri -h -E "^\s*user\s*=" /etc/php*/ | awk -F '=' '{print $2}' | sort | uniq | sed 's/^\s*//;s/\s*$//' | grep -v "nobody")

  ACTIVE_PHP_FPM_USERS=()
  # Loop through active vhosts to find active php fpm users
  for VHOST in "${ACTIVE_VHOSTS[@]}"; do
    # Extract PHP-FPM users from running processes, excluding root
    while read -r user; do
      ACTIVE_PHP_FPM_USERS+=("${user}")
    done < <(ps -eo user:30,cmd | grep "[p]hp-fpm:.*${VHOST}" | awk '{print $1}' | awk '!seen[$0]++' | grep -v "root")
  done

  # Remove duplicates from array
  ACTIVE_PHP_FPM_USERS=($(printf "%s\n" "${ACTIVE_PHP_FPM_USERS[@]}" | sort -u))

  # Associative array to store php-fpm user and fastcgi cache path
  declare -A fcgi

  # Accumulate validation errors
  critical_path_error=""
  cache_path_not_exist_error=""
  user_not_exist_error=""

  # Force create NGINX Cache Paths
  nginx -T >/dev/null 2>&1

  # Loop through FASTCGI_CACHE_PATHS to find matches for each PHP_FPM_USER with validation
  for PHP_FPM_USER in "${PHP_FPM_USERS[@]}"; do
    # Skip empty elements in PHP_FPM_USERS array
    if [[ -z "${PHP_FPM_USER}" ]]; then
      continue
    fi

    # Here we assume current Nginx web server setup not involves distinct users and
    # WEBSERVER-USER is same with PHP-FPM-USER that means PHP-FPM-USER have
    # read/write permission for Nginx Cache Path already.
    if [[ "${PHP_FPM_USER}" == "nginx" || "${PHP_FPM_USER}" == "www-data" ]]; then
      echo -e "\e[91mError: \e[0m\e[96mExcluded: PHP-FPM-USER: ${PHP_FPM_USER} is the same as the WEBSERVER-USER, indicating that it already has read/write permissions for the Nginx Cache Path.\e[0m"
      continue
    fi

    # Check if the user exists
    if ! id "${PHP_FPM_USER}" &>/dev/null; then
      error_message="\033[33mWarning: \e[0m\e[96mExcluded: User: ${PHP_FPM_USER} does not exist. Please ensure the user exists.\e[0m\n"
      if [[ ! "${user_not_exist_error}" =~ "${error_message}" ]]; then
        user_not_exist_error+="${error_message}"
      fi
      continue
    fi

    fcgi["${PHP_FPM_USER}"]=""

    for FASTCGI_CACHE_PATH in "${FASTCGI_CACHE_PATHS[@]}"; do
      # Skip empty elements in FASTCGI_CACHE_PATHS array
      if [[ -z "${FASTCGI_CACHE_PATH}" ]]; then
        continue
      fi

      # Check if the Nginx Cache directory exists
      if [[ ! -d "${FASTCGI_CACHE_PATH}" ]]; then
        error_message="\033[33mWarning: \e[0m\e[96mExcluded: Nginx Cache Path: ${FASTCGI_CACHE_PATH} does not exist.\e[0m\n"
        if [[ ! "${cache_path_not_exist_error}" =~ "${error_message}" ]]; then
          cache_path_not_exist_error+="${error_message}"
        fi
        continue
      fi

      # Validate each FastCGI cache path
      if ! validate_cache_paths "${FASTCGI_CACHE_PATH}"; then
        error_message="\033[33mWarning:\033[0m \033[0;36mThe automatically detected following Nginx Cache Paths are critical system directories or root directory and cannot be used:\033[0m\n"
        error_message+="\033[33mFor safety, paths such as '/home' and other critical system paths are prohibited in default. Please use directories '/dev' | '/var' | '/tmp' | '/opt' must have at least one level deeper.\033[0m\n"
        error_message+="\033[0;31mExcluded forbidden Nginx Cache Path: \033[1;33m${FASTCGI_CACHE_PATH}\033[0m\n"
        if [[ ! "${critical_path_error}" =~ "${error_message}" ]]; then
          critical_path_error+="${error_message}"
        fi
        continue
      fi

      # After validation completed, ready to populate the array
      if echo "${FASTCGI_CACHE_PATH}" | grep -q "${PHP_FPM_USER}"; then
        if [[ -z "${fcgi[${PHP_FPM_USER}]}" ]]; then
          fcgi["${PHP_FPM_USER}"]="${FASTCGI_CACHE_PATH}"
        else
          fcgi["${PHP_FPM_USER}"]="${fcgi[${PHP_FPM_USER}]}:${FASTCGI_CACHE_PATH}"
        fi
      fi
    done

    # Remove entry if no cache paths were found for the user
    if [[ -z "${fcgi[${PHP_FPM_USER}]}" ]]; then
      unset fcgi["${PHP_FPM_USER}"]
    fi
  done

  # Print accumulated validation errors if any
  if [[ -n "${critical_path_error}" || -n "${cache_path_not_exist_error}" || -n "${user_not_exist_error}" ]]; then
    echo ""
    [[ -n "${user_not_exist_error}" ]] && echo -e "${user_not_exist_error}"
    [[ -n "${cache_path_not_exist_error}" ]] && echo -e "${cache_path_not_exist_error}"
    [[ -n "${critical_path_error}" ]] && echo -e "${critical_path_error}"
  fi
fi

# Systemd operations
check_and_start_systemd_service() {
  # Check if the service file exists, if not create it
  if [[ ! -f "${service_file_new}" ]]; then
	# Generate systemd service file
	cat <<- NGINX_ > "${service_file_new}"
	[Unit]
	Description=NPP Wordpress Plugin Cache Operations Service
	After=network.target nginx.service local-fs.target
	Requires=nginx.service

	[Service]
	Type=simple
	ExecStart=/bin/bash ${this_script_path}/${this_script_name} --wp-fuse-mount
	ExecStop=/bin/bash ${this_script_path}/${this_script_name} --wp-fuse-umount
	RemainAfterExit=yes

	[Install]
	WantedBy=multi-user.target
	NGINX_

    # Check if generating the service file was successful
    if [[ $? -ne 0 ]]; then
      echo -e "\e[91mError:\e[0m Failed to create systemd service file."
      exit 1
    fi

    # Reload systemd
    systemctl daemon-reload > /dev/null 2>&1 || {
      echo -e "\e[91mError:\e[0m Failed to reload systemd daemon."
      exit 1
    }

    # Enable and start the service
    systemctl enable --now "${service_file_new##*/}" > /dev/null 2>&1 || {
      echo -e "\e[91mError:\e[0m Failed to enable and start systemd service."
      exit 1
    }

    # Check if the service started successfully
    if systemctl is-active --quiet "${service_file_new##*/}"; then
      echo ""
      echo -e "\e[92mSuccess:\e[0m Systemd service \e[93mnpp-wordpress\e[0m is started."
    else
      echo -e "\e[91mError:\e[0m Systemd service \e[93mnpp-wordpress\e[0m failed to start."
    fi
  fi
}

# Check if manual configuration file exists
if [[ -f "${this_script_path}/manual-configs.nginx" ]]; then
  if [[ ! -s "${this_script_path}/manual-configs.nginx" ]]; then
    echo -e "\e[91mError:\e[0m \e[96mThe manual configuration file '\e[93mmanual-configs.nginx\e[96m' is empty. Please provide configuration details and try again.\e[0m"
    exit 1
  fi

  # Reset/clear associative array that we continue with manual setup
  declare -A fcgi=()

  # Temporary file to store valid lines
  temp_file=$(mktemp)

  # Read manual configuration file
  while IFS= read -r line; do
    # Trim leading and trailing whitespace from the line
    line=$(echo "${line}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')

    # Check if the line is empty after trimming whitespace
    if [[ -z "${line}" ]]; then
      continue
    fi

    # Validate the format of the line (expects "user cache_path")
    if [[ "$(echo "${line}" | awk '{print NF}')" -ne 2 ]]; then
      echo -e "\e[91mError: \e[96mExcluded: Invalid format in the manual configuration file '\e[93mmanual-configs.nginx\e[96m'. Each line must contain only two fields: '\e[93mPHP_FPM_USER NGINX_CACHE_PATH\e[96m'"
      echo -e "\e[91mInvalid line: \e[96m${line}\e[0m"
      continue
    fi

    # Validate the format of the line (expects "PHP_FPM_USER NGINX_CACHE_PATH")
    if [[ ! "${line}" =~ ^[[:alnum:]_-]+\ [[:print:]]+$ ]]; then
      echo -e "\e[91mError: \e[96mExcluded: Invalid format in the manual configuration file '\e[93mmanual-configs.nginx\e[96m'. Each line must be in the format: '\e[93mPHP_FPM_USER NGINX_CACHE_PATH\e[96m'"
      echo -e "\e[91mInvalid line: \e[96m${line}\e[0m"
      continue
    fi

    # Extract PHP-FPM user and FastCGI cache path from each line
    user=$(echo "${line}" | awk '{print $1}')
    cache_path=$(echo "${line}" | awk '{print $2}')

    # Validate the Nginx FastCGI cache path
    if ! validate_cache_paths "${cache_path}"; then
      echo -e "\033[33mFor safety, paths such as '/home' and other critical system paths are prohibited in default. Please use directories '/dev' | '/var' | '/tmp' | '/opt' must have at least one level deeper.\033[0m"
      echo -e "\e[91mError: \e[0m\e[96mExcluded: \033[0;31mForbidden Nginx Cache Path: \033[1;33m${cache_path}\033[0m"
      continue
    fi

    # Here we assume current Nginx web server setup not involves distinct users and
    # WEBSERVER-USER is same with PHP-FPM-USER that means PHP-FPM-USER have
    # read/write permission for Nginx Cache Path already.
    if [[ "${user}" == "nginx" || "${user}" == "www-data" ]]; then
      echo -e "\e[91mError: \e[0m\e[96mExcluded: PHP-FPM-USER: ${user} is the same as the WEBSERVER-USER, indicating that it already has read/write permissions for the ${cache_path}\e[0m"
      continue
    fi

    # Check if the user exists
    if ! id "${user}" &>/dev/null; then
      echo -e "\e[91mError: \e[0m\e[96mExcluded: User: ${user} specified in the manual configuration file does not exist.\e[0m"
      continue
    fi

    # Check if the directory exists
    if [[ ! -d "${cache_path}" ]]; then
      echo -e "\e[91mError: \e[0m\e[96mExcluded: Cache path ${cache_path} for user ${user} does not exist.\e[0m"
      continue
    fi

    # If all validations pass, write the line to the temporary file
    echo "${line}" >> "${temp_file}"
  done < "${this_script_path}/manual-configs.nginx"

  # Replace the original file with the temporary file
  mv "${temp_file}" "${this_script_path}/manual-configs.nginx"

  # Remove duplicate lines from config file
  awk -i inplace '!seen[$0]++' "${this_script_path}/manual-configs.nginx"

  # After validate manual-configs.nginx fully, ready to populate the array
  # Check if manual-configs.nginx before populating the array, otherwise all excluded?
  if [[ -s "${this_script_path}/manual-configs.nginx" ]]; then
    while read -r user path; do
      if [[ -z "${fcgi[$user]}" ]]; then
        fcgi["${user}"]="${path}"
      else
        fcgi["${user}"]+=":${path}"
      fi
    done < "${this_script_path}/manual-configs.nginx"
  else
    echo -e "\033[0;31mPlease correct errors occured above, all instances excluded in: \033[1;33mmanual-configs.nginx\033[0m"
    exit 1
  fi

  # Check manual setup already completed or not
  if ! [[ -f "${this_script_path}/manual_setup_on" ]]; then
    check_and_start_systemd_service && touch "${this_script_path}/manual_setup_on"
    apply_read_permission_to_nginx_files
    check_bindfs_version
    check_libfuse_version
    print_nginx_cache_paths

    grant_sudo_perm_systemctl_for_php_process_owner
    ret=$?

    # Handle the return codes
    if [ "${ret}" -eq 0 ]; then
      # Success: Sudo privileges granted
      echo -e "\e[92mSuccess:\e[0m sudo privileges granted for systemd service \e[93mnpp-wordpress\e[0m to PHP-FPM users."
      for user in "${!fcgi[@]}"; do
        echo -e "Website User: \e[93m${user}\e[0m is a passwordless sudoer to manage the systemd service \e[93mnpp-wordpress\e[0m."
      done
    elif [ "${ret}" -eq 2 ]; then
      # Already Granted
      echo -e "\e[94mInfo:\e[0m sudo privileges for systemd service \e[93mnpp-wordpress\e[0m are already granted to PHP-FPM users."
      for user in "${!fcgi[@]}"; do
        echo -e "Website User: \e[93m${user}\e[0m already has passwordless sudo access to manage the systemd service \e[93mnpp-wordpress\e[0m."
      done
    else
      # Error: Handle unexpected return codes
      echo -e "\e[91mError:\e[0m Failed to grant sudo privileges for systemd service \e[93mnpp-wordpress\e[0m to PHP-FPM users."
    fi
    echo ""
  fi
else
  if (( ${#fcgi[@]} == 0 )); then
    echo -e "\e[91mError:\e[0m Auto setup failed! Nginx cache paths with associated PHP-FPM users cannot be automatically matched."
    echo -e "\e[91mPlease ensure that your Nginx Cache Path includes the associated PHP-FPM-USER username for proper matching. \e[95mIf you don't want to rename your Nginx Cache Paths, please continue with manual setup.\e[0m"
    # Provide instructions for manual configuration
    echo -e "\n\e[36mTo set up manual configuration, create a file named \e[95m'manual-configs.nginx' \e[0m \e[36min current directory."
    echo -e "Each entry should follow the format: 'PHP_FPM_USER NGINX_CACHE_PATH', with one entry per virtual host, space-delimited."
    echo -e "Example --> psauxit /dev/shm/fastcgi-cache-psauxit <--"
    echo -e "Ensure that every new website added to your host is accompanied by an entry in this file."
    echo -e "After making changes, remember to restart the script \e[95mfastcgi_ops_root.sh\e[0m."
    echo ""
    exit 1
  fi

  # check auto setup already completed or not
  if ! [[ -f "${this_script_path}/auto_setup_on" ]]; then
    echo ""
    echo -e "\e[32mAUTO DETECTION STARTED\e[0m"
    echo -e "\e[96mNOTE: You can always continue with the manual setup via (\e[95mN/n\e[96m) if the auto detection does not work for you.\e[0m"
    echo ""
    echo -e "\e[32mFound PHP-FPM-USERS:\e[0m"
    echo -e "\e[35m${PHP_FPM_USERS[@]:-"-"}\e[0m"
    echo -e "\e[32mActive PHP-FPM-USERS:\e[0m"
    echo -e "\e[35m${ACTIVE_PHP_FPM_USERS[@]:-"-"}\e[0m"
    echo -e "\e[32mPassive PHP-FPM-USERS:\e[0m"
    echo -e "\e[35m$(comm -23 <(printf "%s\n" "${PHP_FPM_USERS[@]}") <(printf "%s\n" "${ACTIVE_PHP_FPM_USERS[@]}") | tr '\n' ' ' | sed 's/ $//' || echo '-')\e[0m"

    # Print detected FastCGI cache paths and associated PHP-FPM users for auto setup confirmation
    echo ""
    echo -e "\e[96mAuto detected Nginx cache paths and associated PHP-FPM users:\e[0m"
    for user in "${!fcgi[@]}"; do
      echo -e "Website User: \e[92m$user\e[0m, Nginx Cache Path: \e[93m${fcgi[$user]}\e[0m"
    done
    read -rp $'\e[96mDo you want to continue with the auto configuration? This may takes a while.. \e[92m[Y/n]: \e[0m' confirm
    if [[ ${confirm} =~ ^[Yy]$ ]]; then
      check_and_start_systemd_service && touch "${this_script_path}/auto_setup_on"
      apply_read_permission_to_nginx_files
      check_bindfs_version
      check_libfuse_version
      print_nginx_cache_paths

      grant_sudo_perm_systemctl_for_php_process_owner
      ret=$?

      # Handle the return codes
      if [ "${ret}" -eq 0 ]; then
        # Success: Sudo privileges granted
        echo -e "\e[92mSuccess:\e[0m sudo privileges granted for systemd service \e[93mnpp-wordpress\e[0m to PHP-FPM users."
        for user in "${!fcgi[@]}"; do
          echo -e "Website User: \e[93m${user}\e[0m is a passwordless sudoer to manage the systemd service \e[93mnpp-wordpress\e[0m."
        done
      elif [ "${ret}" -eq 2 ]; then
        # Already Granted
        echo -e "\e[94mInfo:\e[0m sudo privileges for systemd service \e[93mnpp-wordpress\e[0m are already granted to PHP-FPM users."
        for user in "${!fcgi[@]}"; do
          echo -e "Website User: \e[93m${user}\e[0m already has passwordless sudo access to manage the systemd service \e[93mnpp-wordpress\e[0m."
        done
      else
        # Error: Handle unexpected return codes
        echo -e "\e[91mError:\e[0m Failed to grant sudo privileges for systemd service \e[93mnpp-wordpress\e[0m to PHP-FPM users."
      fi
      echo ""
    else
      manual_setup
    fi
  fi
fi

# FUSE mount operations for the original Nginx Cache Path
fuse-mount() {
  check_bindfs_version
  check_libfuse_version

  # Count total instances (PHP-FPM-USER | Nginx Cache Path)
  count_instances() {
    local count=0
    for user in "${!fcgi[@]}"; do
      IFS=':' read -r -a paths <<< "${fcgi[$user]}"
      count=$((count + ${#paths[@]}))
    done
    echo $count
  }

  # Check if any "PHP-FPM-USER | Nginx Cache Path" instances are found; if not, exit
  instance_count=$(count_instances)
  if (( instance_count == 0 )); then
    # If no instance found, exit
    echo "There are no (PHP-FPM-USERS | Nginx Cache Path) instances configured. Please check your configuration."
    exit 1
  fi

  # Start FUSE mounts for Original Nginx Cache Paths associated with each PHP-FPM-USER
  # with altered permissions
  for user in "${!fcgi[@]}"; do
    IFS=':' read -r -a paths <<< "${fcgi[$user]}"

    for path in "${paths[@]}"; do
      if ! mount | grep "${path}" >/dev/null 2>&1; then
        # Attempt to mount using bindfs
        [ ! -d "${path}-npp" ] && mkdir "${path}-npp"
        bindfs -u "${user}" -g "${user}" --perms=u=rwx:g=rx:o= "${path}" "${path}"-npp
      else
        mount_point=$(mount | grep "${path}" | awk -F ' on | type ' '{print $2}')
        echo -e "\e[93mWarning: \e[96mThe original Nginx FastCGI Cache Directory (\e[93m${path}\e[96m) is already mounted on FUSE at: (\e[93m${mount_point}\e[96m). It is EXCLUDED for PHP-FPM-USER: (\e[93m${user}\e[96m)\e[0m"
      fi
    done
  done

  # Collect status messages for each instance
  declare -a messages
  instance_count=0

  for user in "${!fcgi[@]}"; do
    IFS=':' read -r -a paths <<< "${fcgi[$user]}"

    for path in "${paths[@]}"; do
      if mount | grep -q "${path}" >/dev/null 2>&1; then
        mount_point=$(mount | grep "${path}" | awk -F ' on | type ' '{print $2}')
        messages+=("All done! Nginx FastCGI Cache Path: (${path}) mounted on FUSE at: (${mount_point}) for PHP-FPM-USER: (${user}), with altered permissions.")
        (( instance_count++ ))
      else
        messages+=("CANNOT mount the Nginx FastCGI Cache Path: (${path}) on FUSE at: (${path}-npp) for PHP-FPM-USER: (${user}), with altered permissions.")
      fi
    done
  done

  # Check instance statuses and output messages
  if (( instance_count == 0 )); then
    echo "All instances are already listening, excluded, or failed to start."
    # Output all error messages collected
    for message in "${messages[@]}"; do
      echo "$message"
    done
  else
    # Output all success messages collected
    for message in "${messages[@]}"; do
      echo "$message"
    done
  fi
}

# FUSE umount operations for the original Nginx Cache Path
fuse-umount() {
  # Kill on-going preload process for all websites first
  for load in "${!fcgi[@]}"; do
    # Update according to NPP v2.0.4
    read -r -a PIDS <<< "$(pgrep -a -f "wget .*--recursive .*--no-cache .*--delete-after .*-P ${fcgi[$load]}" | grep -v "cpulimit" | awk '{print $1}')"
    if (( "${#PIDS[@]}" )); then
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          kill -9 "${pid}" && echo "Cache preload process ${pid} for website ${load} is killed!"
        else
          echo "No cache preload process found for website ${load} - last running process was ${pid}"
        fi
      done
    else
      echo "No cache preload process found for website ${load}"
    fi
  done

  # Umount FUSE Nginx Cache Paths
  for user in "${!fcgi[@]}"; do
    IFS=':' read -r -a paths <<< "${fcgi[$user]}"
    for path in "${paths[@]}"; do
      if mount | grep "${path}" >/dev/null 2>&1; then
        mount_point=$(mount | grep "${path}" | awk -F ' on | type ' '{print $2}')
        umount "${mount_point}" >/dev/null 2>&1
        rm -r "${mount_point:?}" >/dev/null 2>&1
        # Check umount completed
        if ! mount | grep -q "${path}" >/dev/null 2>&1; then
          echo "Nginx FastCGI Cache Path: (${path}) umounted on FUSE at: (${mount_point}) for PHP-FPM-USER: (${user})"
        else
          echo "Nginx FastCGI Cache Path: (${path}) CANNOT umounted on FUSE at: (${mount_point}) for PHP-FPM-USER: (${user})"
        fi
      fi
    done
  done
}

# set script arguments
case "$1" in
  --wp-fuse-mount ) fuse-mount ;;
  --wp-fuse-umount  ) fuse-umount  ;;
  *                  )
  if [ $# -gt 1 ]; then
    help
  fi ;;
esac
