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
#

# fastcgi-cache preload URL
fdomain="websiteuser1.com"

# fastcgi-cache path
fpath="/home/websiteuser1.com/fastcgi-cache"

# mail options
mail_to="support@websiteuser1.com"
mail_from="From: System Automations<fcgi@websiteuser1.com>"
mail_subject="FastCGI-Cache Purge,Preload Ops"

# required commands
required_commands=(
  "realpath"
  "dirname"
  "pgrep"
  "basename"
)

# check if required commands are available
for cmd in "${required_commands[@]}"; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR COMMAND: $cmd is not installed or not found in PATH."
    exit 1
  fi
done

# discover script path
this_script_full_path=$(realpath "${BASH_SOURCE[0]}")
this_script_path=$(dirname "${this_script_full_path}")
this_script_name=$(basename "${this_script_full_path}")

# ensure script path is resolved
if [[ -z "${this_script_path}" ]]; then
  echo "ERROR: Cannot find script path!"
  exit 1
fi

# enable extglob
# remove trailing / (removes / and //) from script path
shopt -s extglob
this_script_path="${this_script_path%%+(/)}"

# define PID
PIDFILE="${this_script_path}/fastcgi_ops_${fdomain%%.*}.pid"

# define LOG
LOGFILE="${this_script_path}/fastcgi_ops_${fdomain%%.*}.log"

# get current timestamp
timestamp=$(date +"%Y-%m-%d %T")

# log messages with timestamps
log_with_timestamp() {
    echo "[${timestamp}] $1" | tee -a "$LOGFILE"
}

# cache purge helper function
purge_helper() {
  if [[ -d "${fpath}" ]]; then
    rm -rf --preserve-root "${fpath:?}"/* >/dev/null 2>&1 || return 1
  else
    return 2
  fi

  # Check if the directory exists and remove it if so
  if [[ -d "${this_script_path}/www.${fdomain}" ]]; then
    rm -rf "${this_script_path:?}/www.${fdomain:?}"
  fi

  return 0
}

# check inotify/setfacl is alive
inotify-helper() {
  # first check inotify/setfacl is working
  if ! pgrep -f "inotifywait.*${fpath}" >/dev/null 2>&1; then
      return 1
  fi

  return 0
}

# find preload process pids
find_pid() {
  read -r -a PIDS <<< "$(pgrep -a -f "wget.*-q -m -p -E -k -P ${this_script_path}" | grep -v "cpulimit" | awk '{print $1}')"
}

# display script controls
help() {
  if command -v tput > /dev/null 2>&1; then
    cyan=$(tput setaf 6)
    reset=$(tput sgr0)
    m_tab='  '
  fi

  echo -e "\n${m_tab}${cyan}# Wordpress FastCGI Cache Purge&Preload Help"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------"
  echo -e "${m_tab}#${m_tab}--purge              purge fastcgi cache"
  echo -e "${m_tab}#${m_tab}--preload            preload fastcgi cache"
  echo -e "${m_tab}#${m_tab}--admin              wordpress admin message helper"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------${reset}\n"
}

# preload fastcgi-cache
preload() {
  # check any ongoing preload process
  if [[ -s "${PIDFILE}" ]]; then
    readarray -t PID < "${PIDFILE}"
    for pid in "${PID[@]}"; do
      if ps -p "${pid}" >/dev/null 2>&1; then
        log_with_timestamp "INFO PRELOAD: FastCGI cache is already preloading, If you want stop it now use FCGI Cache Purge"
        exit 1
      fi
    done
  fi

  # check if wget or cpulimit commands are available
  if ! command -v wget >/dev/null 2>&1; then
    log_with_timestamp "ERROR COMMAND: wget is not installed. Please install wget."
    exit 1
  elif ! command -v cpulimit >/dev/null 2>&1; then
    log_with_timestamp "ERROR COMMAND: cpulimit is not installed. Please install cpulimit."
    exit 1
  fi

  # exlude from preload
  reject_regex='--reject-regex "/wp-admin/|/wp-includes/|/wp-json/|/xmlrpc.php|/wp-login.php|/wp-register.php|/wp-content/|/cart/|/checkout/|/my-account/|/wc-api/"'

  # purge cache & obsolete website content before preload
  purge_helper
  local status=$?

  # purge cache & obsolete website content before preload
  if [[ "${status}" -eq 0 ]]; then
    if ! inotify-helper; then
      log_with_timestamp "ERROR INOTIFY: Please start inotify service via 'systemctl start wp-fcgi-notify' first"
      exit 1
    fi

    # check GNU time command exist
    if [[ -f "/usr/bin/time" ]]; then
      # start fastcgi cache preload on background and measure elapsed time
      /usr/bin/time -f'%E' -o "${this_script_path}/preload_elapsed.txt" \
      cpulimit -l 20 -- wget --limit-rate=1280k -q -m -p -E -k -P "${this_script_path}" \
      ${reject_regex} "https://www.${fdomain}" &>/dev/null &
    else
      # start fastcgi cache preload on background without time measure
      # bash built-in time command is problematic (wrong PID always with bg processing)
      cpulimit -l 20 -- wget \
      --limit-rate=1280k \
      -q -m -p -E -k -P "${this_script_path}" \
      ${reject_regex} \
      "https://www.${fdomain}" &>/dev/null &
    fi

    sleep 2
    find_pid

    if (( "${#PIDS[@]}" )); then
      # keep PID in /run
      echo "${PIDS[@]}" > "${PIDFILE}" || { log_with_timestamp "ERROR PERMISSION: Cannot create cache preload PID!"; exit 1; }

      # is process alive
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          log_with_timestamp "FastCGI cache preloading started on background. You will be informed when completed."
          break
        else
          log_with_timestamp "ERROR ZOMBIE PROCESS: Cannot start FastCGI cache preload!"
          exit 1
        fi
      done
    else
      log_with_timestamp "ERROR UNKNOWN: Cannot start FastCGI cache preload!"
      exit 1
    fi
  elif [[ "${status}" -eq 1 ]]; then
    log_with_timestamp "ERROR PERMISSION: Cannot Purge FastCGI cache to start cache preloading. Please restart wp-fcgi-notify.service"
    exit 1
  elif [[ "${status}" -eq 2 ]]; then
    log_with_timestamp "ERROR PATH: Your FastCGI cache PATH (${fpath}) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service"
    exit 1
  else
    log_with_timestamp "ERROR UNKNOWN: Cannot Purge FastCGI cache to start cache preloading."
    exit 1
  fi
}

# purge fastcgi-cache
purge() {
  find_pid

  # stop ongoing preload process if exist
  if (( "${#PIDS[@]}" )); then
    for pid in "${PIDS[@]}"
    do
      if ps -p "${pid}" >/dev/null 2>&1; then
        kill -9 $pid
      fi
    done

    [[ -f "${PIDFILE}" ]] && rm -f "${PIDFILE:?}"

    # capture the exit status of purge_helper
    purge_helper
    local status=$?

    if [[ "${status}" -eq 0 ]]; then
      log_with_timestamp "FastCGI cache preloading is stopped. Purge FastCGI cache is completed."
    elif [[ "${status}" -eq 1 ]]; then
      log_with_timestamp "ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache cannot completed. Please restart wp-fcgi-notify.service"
      exit 1
    elif [[ "${status}" -eq 2 ]]; then
      log_with_timestamp "ERROR PATH: Your FastCGI cache PATH (${fpath}) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service"
      exit 1
    else
      log_with_timestamp "ERROR UNKNOWN: Cannot Purge FastCGI cache."
      exit 1
    fi
  elif purge_helper; then
    log_with_timestamp "Purge FastCGI cache is completed."
  else
    local status=$?
    if [[ "${status}" -eq 1 ]]; then
      log_with_timestamp "ERROR PERMISSION: Purge FastCGI cache cannot completed. Please restart wp-fcgi-notify.service"
      exit 1
    elif [[ "${status}" -eq 2 ]]; then
      log_with_timestamp "ERROR PATH: Your FastCGI cache PATH (${fpath}) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service"
      exit 1
    else
      log_with_timestamp "ERROR UNKNOWN: Cannot Purge FastCGI cache."
      exit 1
    fi
  fi
}

# send mail
mail() {
    # send mail
    command -v mail >/dev/null 2>&1 && {
    message="FastCGI cache preloading is completed";
    echo "$message" | mail -s "$mail_subject" -a "$mail_from" "$mail_to";
    }
}

# set script arguments
case "$1" in
  --purge            ) purge         ;;
  --preload          ) preload       ;;
  --mail             ) mail          ;;
  *                  ) help          ;;
esac
