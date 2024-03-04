#!/bin/bash

# Copyright (C) 2021 Hasan CALISIR <hasan.calisir@psauxit.com>
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


# Get help before and interrupt
help() {
  if command -v tput > /dev/null 2>&1; then
    cyan=$(tput setaf 6)
    reset=$(tput sgr0)
    m_tab='  '
  fi

  echo -e "\n${m_tab}${cyan}# Wordpress FastCGI Cache Purge&Preload Help"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------"
  echo -e "${m_tab}#${m_tab}--wp-inotify-start   need root or SUDO! set ACL permission(cache folder) for website-user"
  echo -e "${m_tab}#${m_tab}--wp-inotify-stop    need root or SUDO! unset ACL permission(cache folder) for website-user"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------${reset}\n"
}

# Check if script is executed as root or with sudo
if [[ $EUID -ne 0 ]]; then
    echo "This script must be run as root or with sudo."
    exit 1
fi

# Required commands
required_commands=(
  "realpath"
  "dirname"
  "pgrep"
  "basename"
  "nginx"
  "php-fpm"
  "inotifywait"
  "tune2fs"
  "setfacl"
)

# Check if required commands are available
for cmd in "${required_commands[@]}"; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Error: $cmd is not installed or not found in PATH."
    exit 1
  fi
done

# Check ACL configured properly
fs="$(df / | awk 'NR==2 {print $1}')"
if ! tune2fs -l "${fs}" | grep -q "Default mount options:.*acl"; then
  echo "Filesystem not mounted with the acl!"
  exit 1
fi

# Symlinks
this_script_full_path="${BASH_SOURCE[0]}"
while [[ -h "${this_script_full_path}" ]]; do
  this_script_path="$( cd -P "$( dirname "${this_script_full_path}" )" >/dev/null 2>&1 && pwd )"
  this_script_full_path="$(readlink "${this_script_full_path}")"
	# Resolve
	if [[ "${this_script_full_path}" != /* ]] ; then
	  this_script_full_path="${this_script_path}/${this_script_full_path}"
	fi
done

this_script_path="$( cd -P "$( dirname "${this_script_full_path}" )" >/dev/null 2>&1 && pwd )"
this_script_name="$(basename "${this_script_full_path}")"

# Enable extglob
# Remove trailing / (removes / and //) from script path
shopt -s extglob
this_script_path="${this_script_path%%+(/)}"

# Function to dynamically detect the location of nginx.conf
detect_nginx_conf() {
  local DEFAULT_NGINX_CONF_PATHS=(
    "/etc/nginx/nginx.conf"
    "/usr/local/nginx/conf/nginx.conf"
  )
  for path in "${DEFAULT_NGINX_CONF_PATHS[@]}"; do
    if [[ -f "$path" ]]; then
      NGINX_CONF="$path"
      break
    fi
  done
  if [[ -z "$NGINX_CONF" ]]; then
    echo "Nginx configuration file (nginx.conf) not found in default paths."
    read -rp "Please enter the full path to nginx.conf: " NGINX_CONF
  fi
}

# Function to extract FastCGI cache paths from NGINX configuration files
extract_fastcgi_cache_paths() {
  # Extract paths from nginx.conf
  grep -E "^\s*fastcgi_cache_path\s+" "$NGINX_CONF" | awk '{print $2}'

  # Extract paths from vhost configuration files included in nginx.conf
  while IFS= read -r include_line; do
    include_path=$(echo "$include_line" | awk '{print $2}' | sed 's/;//')
    # Handle wildcard (*) in the include path
    if [[ -n "$include_path" && "$include_path" != *\** ]]; then
      if [[ -f "$include_path" ]]; then
        grep -E "^\s*fastcgi_cache_path\s+" "$include_path" 2>/dev/null | awk '{print $2}'
      fi
    fi
  done < <(grep -E "^\s*include\s+" "$NGINX_CONF" | grep -v "^\s*#" | awk '{print $2}' | sed 's/;//')
}

# Detect nginx.conf
detect_nginx_conf

# Check active vhosts
ACTIVE_VHOSTS=$(nginx -T 2>/dev/null | grep -E "server_name|fastcgi_pass" | grep -B1 "fastcgi_pass" | grep "server_name" | awk '{print $2}')

# Associative array to store php-fpm user and fastcgi cache path
declare -A fcgi

# Loop through active vhosts
for VHOST in $ACTIVE_VHOSTS; do
  # Remove any trailing semicolons from the file name
  VHOST_CONFIG_FILE=$(echo "$VHOST" | sed 's/;$//')

  # Extract unique FastCGI cache paths from Nginx config files
  FASTCGI_CACHE_PATHS=$(extract_fastcgi_cache_paths | sort -u)

  # Extract PHP-FPM users from running processes, excluding root
  PHP_FPM_USERS=$(ps -eo user:20,cmd | grep "[p]hp-fpm:.*$VHOST" | awk '{print $1}' | awk '!seen[$0]++' | grep -v "root")
  for PHP_FPM_USER in $PHP_FPM_USERS; do
    for FASTCGI_CACHE_PATH in $FASTCGI_CACHE_PATHS; do
      # Check if the PHP-FPM user's name is present in the FastCGI cache path
      if echo "$FASTCGI_CACHE_PATH" | grep -q "$PHP_FPM_USER"; then
        fcgi["$PHP_FPM_USER"]="$FASTCGI_CACHE_PATH"
        break  # Move to the next PHP-FPM user once a match is found
      fi
    done
  done
done

# Check if the user exists
for user in "${!fcgi[@]}"; do
  if ! id "$user" &>/dev/null; then
    echo -e "\e[91mError:\e[0m User: $user does not exist. Please ensure the user exists and try again."
    exit 1
  fi
done

# Systemd operations
check_and_start_systemd_service() {
  # Check if the service file exists
  service_file="/etc/systemd/system/wp-fcgi-notify.service"

  if [[ ! -f "$service_file" ]]; then
    # Generate systemd service file
    cat <<- NGINX_ > "$service_file"
[Unit]
Description=FastCGI Operations Service
After=network.target nginx.service local-fs.target
Wants=nginx.service

[Service]
Type=simple
RemainAfterExit=yes
User=root
Group=root
ProtectSystem=full
ExecStart=/bin/bash ${this_script_path}/${this_script_name} --wp-inotify-start
ExecStop=/bin/bash ${this_script_path}/${this_script_name} --wp-inotify-stop

[Install]
WantedBy=multi-user.target
NGINX_

    # Reload systemd's configuration
    systemctl daemon-reload > /dev/null 2>&1

    # Enable the service
    systemctl enable wp-fcgi-notify.service > /dev/null 2>&1

    # Start the service if it's not already active
    if ! systemctl is-active --quiet wp-fcgi-notify.service; then
      systemctl start wp-fcgi-notify.service

      # Check if the service started successfully
      if systemctl is-active --quiet wp-fcgi-notify.service; then
        echo -e "\e[92mSuccess:\e[0m Service \e[93mwp-fcgi-notify\e[0m is started."
      else
        echo -e "\e[91mError:\e[0m Service \e[93mwp-fcgi-notify\e[0m failed to start."
      fi
    fi
  fi
}

# Check if manual configuration file exists
if [[ -f "${this_script_path}/manual-configs.nginx" ]]; then
  if [[ ! -s "${this_script_path}/manual-configs.nginx" ]]; then
    echo -e "\e[91mError:\e[0m The manual configuration file 'manual-configs.nginx' is empty. Please provide configuration details and try again."
    exit 1
  fi

  # Read manual configuration file
  while IFS= read -r line; do
    # Trim leading and trailing whitespace from the line
    line=$(echo "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')

    # Check if the line is empty after trimming whitespace
    if [[ -z "$line" ]]; then
      continue  # Skip empty lines
    fi

    # Validate the format of the line (expects "user cache_path")
    if [[ "$(echo "$line" | awk '{print NF}')" -ne 2 ]]; then
      echo -e "\e[91mError:\e[0m Invalid format in the manual configuration file 'manual-config.nginx'. Each line must contain only two fields: 'user' and 'fcgi path'."
      echo "Invalid line: $line"
      exit 1
    fi

    # Validate the format of the line (expects "user cache_path")
    if [[ ! "$line" =~ ^[[:alnum:]_-]+\ [[:print:]]+$ ]]; then
      echo -e "\e[91mError:\e[0m Invalid format in the manual configuration file 'manual-config.nginx'. Each line must be in the format 'user cache_path'."
      echo "Invalid line: $line"
      exit 1
    fi

    # Extract PHP-FPM user and FastCGI cache path from each line
    user=$(echo "$line" | awk '{print $1}')
    cache_path=$(echo "$line" | awk '{print $2}')

    # Check if the directory exists
    if [[ ! -d "$cache_path" ]]; then
      echo -e "\e[33mWarning: Cache path $cache_path for user $user does not exist. This vhost will be excluded if other vhosts are successful.\e[0m"
    fi

    # Check if the user exists
    if ! id "$user" &>/dev/null; then
      echo -e "\e[91mError:\e[0m User: $user specified in the manual configuration file does not exist. Please ensure the user exists and try again."
      exit 1
    fi

    fcgi["$user"]="$cache_path"
  done < "${this_script_path}/manual-configs.nginx"
  # Check setup already completed or not
  if ! [[ -f "${this_script_path}/manual_setup_on" ]]; then
    check_and_start_systemd_service && touch "${this_script_path}/manual_setup_on"
  fi
else
  if (( ${#fcgi[@]} == 0 )); then
    echo -e "\e[91mError:\e[0m No FastCGI cache paths and associated PHP-FPM users detected."
    echo -e "\e[91mPlease ensure that your Nginx configuration is properly set up. \e[0mIf the issue persist please try to manual setup.\e[0m"
    # Provide instructions for manual configuration
    echo -e "\n\e[36mTo set up manual configuration, create a file named \e[95m'manual-configs.nginx' \e[0m \e[36min the same directory as this script."
    echo -e "Each entry should follow the format: 'PHP-FPM_user FastCGI_cache_path', with one entry per virtual host."
    echo -e "Ensure that every new website added to your host is accompanied by an entry in this file."
    echo -e "After making changes, remember to restart the systemd \e[95mwp-fcgi-notify.service\e[0m."
    exit 1
  fi

  # check setup already completed or not
  if ! [[ -f "${this_script_path}/auto_setup_on" ]]; then
    # Print detected FastCGI cache paths and associated PHP-FPM users for auto setup confirmation
    echo -e "\e[96mDetected FastCGI cache paths and associated PHP-FPM users:\e[0m"
    for user in "${!fcgi[@]}"; do
      echo -e "User: \e[92m$user\e[0m, Cache Path: \e[93m${fcgi[$user]}\e[0m"
    done
    read -rp $'\e[96mDo you want to proceed with the above configuration? [Y/n]: \e[0m' confirm
    if [[ $confirm =~ ^[Yy]$ || $confirm == "" ]]; then
      check_and_start_systemd_service && touch "${this_script_path}/auto_setup_on"
    else
      echo -e "\e[91mCanceled:\e[0m Automated Setup has been canceled by the user. Proceeding to manual setup."
      # Provide instructions for manual configuration
      echo -e "\n\e[36mTo set up manual configuration, create a file named \e[95m'manual-configs.nginx' \e[0m \e[36min the same directory as this script."
      echo -e "Each entry should follow the format: 'PHP-FPM_user FastCGI_cache_path', with one entry per virtual host."
      echo -e "Ensure that every new website added to your host is accompanied by an entry in this file."
      echo -e "After making changes, remember to restart the systemd \e[95mwp-fcgi-notify.service\e[0m."
      exit 0
    fi
  fi
fi

# listens fastcgi cache folder for create events and
# give write permission to website user for further purge operations.
inotify-start() {
  # Check instances properly
  if (( ${#fcgi[@]} == 0 )); then
    # If non instance set up, exit
    echo "There is no any instance, please read documentation"
    exit 1
  elif (( ${#fcgi[@]} == 1 )); then
    # if only one instance exists and it is broken, exit
    for path in "${!fcgi[@]}"; do
      if ! [[ -d "${fcgi[$path]}" ]]; then
        echo "Your FastCGI cache directory (${fcgi[$path]}) not found, if path is correct please restart nginx.service to automatically create it"
        exit 1
      fi
    done
  elif (( ${#fcgi[@]} > 1 )); then
    # In many instances If only one instance is broken, exclude and continue
    for path in "${!fcgi[@]}"; do
      if ! [[ -d "${fcgi[$path]}" ]]; then
        echo "Your FastCGI cache directory (${fcgi[$path]}) not found, if path is correct please restart nginx.service to automatically create it, EXCLUDED"
        unset "fcgi[$path]"
      fi
    done
  fi

  # start to listen fastcgi cache folder events
  # give write permission to website user for further purge ops
  for user in "${!fcgi[@]}"
  do
    while :
    do
      # While this loop working If fastcgi cache path
      # deleted manually by user that cause strange
      # behaviours, kill it
      if [[ ! -d "${fcgi[$user]}" ]]; then
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
        echo "Cache folder ${fcgi[$user]} destroyed manually, inotifywait/setfacl process for user: ${user} is killed!"
        echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
        break
      fi
      # Start inotifywait/setfacl
      {
      inotifywait -q -e modify,create -r "${fcgi[$user]}" && \
      setfacl -R -m u:"${user}":rwX "${fcgi[$user]}"/
      } >/dev/null 2>&1
    done &
  done

  # Check if inotifywait processes are alive
  for path in "${!fcgi[@]}"; do
    if pgrep -f "inotifywait.*${fcgi[$path]}" >/dev/null 2>&1; then
      echo "All done! Started to listen FastCGI cache folder (${fcgi[$path]}) events."
    else
      echo "Unknown error occurred during cache listen event."
    fi
  done
}

# stop listening fastcgi cache paths
inotify-stop() {
  # Kill on-going preload process for all websites first
  for load in "${!fcgi[@]}"; do
    read -r -a PIDS <<< "$(pgrep -a -f "wget.*-q -m -p -E -k -P ${fcgi[$load]}" | grep -v "cpulimit" | awk '{print $1}')"
    if (( "${#PIDS[@]}" )); then
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          kill -9 $pid && echo "Cache preload process $pid for website $load is killed!"
        else
          echo "No cache preload process found for website $load - last running process was $pid"
        fi
      done
    else
      echo "No cache preload process found for website $load"
    fi
  done

  # Then purge fcgi cache for all websites to keep cache integrity clean
  # That means on every system reboot (systemctl reboot) all fcgi cache will cleaned for all vhosts
  # This is somehow drawback but keeping cache integrity is more important
  for cache in "${!fcgi[@]}"; do
    if [[ -d "${fcgi[$cache]}" ]]; then
      rm -rf --preserve-root "${fcgi[$cache]:?}"/*
      echo "FastCGI cache purged for website: $cache"
    else
      echo "FastCGI cache directory not found for website: $cache to clear cache"
    fi
  done

  # kill inotifywait processes
  for listen in "${!fcgi[@]}"; do
    read -r -a PIDS <<< "$(pgrep -f "inotifywait.*${fcgi[$listen]}")"
    if (( "${#PIDS[@]}" )); then
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          kill -9 $pid && echo "inotifywait process $pid for website $listen is killed!"
        else
          echo "No inotify process found for website $listen - last running process was $pid"
        fi
      done
    else
      echo "No inotify process found for website $listen"
    fi
  done
}

# set script arguments
case "$1" in
  --wp-inotify-start ) inotify-start ;;
  --wp-inotify-stop  ) inotify-stop  ;;
  *                  )
  if [ $# -gt 1 ]; then
    help
  fi ;;
esac
