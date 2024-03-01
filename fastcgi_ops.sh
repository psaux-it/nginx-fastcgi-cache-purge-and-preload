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

# Prevent cron errors if you use this script in crontab
# However I recommend systemd service file that I provided in github repo
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

declare -A fcgi

# MULTISITE SETTINGS - (nedded by wp-fcgi-notify.service under root)
####################
# A one copy of this script must be under root for
# inotify/setfacl operations triggered by wp-fcgi-notify.service
# In this example we are hosting total 3 website
# websiteuser1.com websiteuser2.com websiteuser3.com
# when you start wp-fcgi-notify.service under root
# inotify will start listening to all fastcgi paths
# and setfacl will give necessary permissions to cached files.
# If you want to host new website you need to add
# this website's PHP-FPM-USER and fastcgi cache path below.
# You should make this edit in the script which copied under root.

#################################################################################################################
fcgi[websiteuser1]="/home/websiteuser1/fastcgi-cache"
fcgi[websiteuser2]="/home/websiteuser2/fastcgi-cache"
fcgi[websiteuser3]="/home/websiteuser3/fastcgi-cache"
#################################################################################################################

# INSTANCE SETTINGS - (needed by per instance you hosting) 
####################
# Apart from the root copy, you must re-copy this script for each instance.
# In this example this copied script responsible for websiteuser1.com
# copied to e.g. /home/websiteuser1/scripts/fastcgi_ops.sh
# and adjusted below settings for preloaad url and fastcgi cache path.

# INSTANCE SETTINGS
# STEPS
####################
# 1) simply replicate this script to websiteuser1 (PHP-FPM-USER) 
#    user's $HOME directory e.g. /home/websiteuser1/scripts/fastcgi_ops.sh
# 2) chmod +x /home/websiteuser1/scripts/fastcgi_ops.sh
#    chown -R websiteuser1:websiteuser1 /home/websiteuser1/scripts
# 3) copy functions.php to websiteuser1.com child theme's functions.php
# 4) change new script path in function.php
#    $wpfcgi = "/home/websiteuser1/scripts/fastcgi_ops.sh";
# 5) set preload url and your fastcgi cache path for websiteuser1.com below

# PHP-FPM POOL
# SETTINGS
#####################
# ../fpm-php/fpm.d/websiteuser1.conf
#
# [websiteuser1.com]
# user = websiteuser1
# group = websiteuser1
# listen.owner = nginx
# listen.group = nginx
# listen.mode = 0755
# listen = /var/run/php-fcgi-websiteuser1.sock
# ..

# VALIDATE SETUP
####################
# getfacl /home/websiteuser1/fastcgi-cache/0/32/ab6eb4df9b94858c10a283200daa1320
###################################################################################################################
# getfacl:  Removing leading '/' from absolute path names
## file:     /home/websiteuser1/fastcgi-cache/0/32/ab6eb4df9b94858c10a283200daa1320
## owner:    nginx             --> Web-Server User (Default Owner)
## group:    nginx             --> Web-Server Group (Default Group)
# user:      :rw-              --> Web-Server User Permissons
# user:      websiteuser1:rw-  --> Web-Site User and Permission (ACL) ****KEY*****
# group:     :---
# mask:      :rw-
# other:     :---

###################################################################################################################
# fastcgi-cache preload URL for websiteuser1 instance
fdomain="websiteuser1.com"
# fastcgi-cache path for websiteuser1 instance
fpath="/home/websiteuser1.com/fastcgi-cache"
###################################################################################################################

# MAIL OPTIONS
###################################################################################################################
mail_to="support@websiteuser1.com"
mail_from="From: System Automations<fcgi@websiteuser1.com>"
mail_subject="FastCGI-Cache Purge,Preload Ops"
###################################################################################################################

# get current timestamp
timestamp=$(date +"%Y-%m-%d %T")

# log messages with timestamps
log_with_timestamp() {
    echo "[${timestamp}] $1"
}

# discover script path
this_script_full_path="${BASH_SOURCE[0]}"
if command -v dirname >/dev/null 2>&1 && command -v readlink >/dev/null 2>&1 && command -v basename >/dev/null 2>&1; then
  # Symlinks
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
else
  log_with_timestamp "ERROR PATH: cannot find script path!"
  exit 1
fi

# enable extglob
# remove trailing / (removes / and //) from script path
shopt -s extglob
this_script_path="${this_script_path%%+(/)}"

# define PID
PIDFILE="${this_script_path}/fastcgi_ops_${fdomain%%.*}.pid"

# check pgrep is exist
if ! command -v pgrep >/dev/null 2>&1; then
  log_with_timestamp "ERROR COMMAND: pgrep is not installed. Please install pgrep."
  exit 1
fi

# cache purge helper function
purge_helper() {
  if [[ -d "${fpath}" ]]; then
    rm -rf --preserve-root "${fpath:?}"/* >/dev/null 2>&1 || return 1
  else
    log_with_timestamp "Cache directory '${fpath}' does not exist, cannot purge cache"
  fi

  # Check if the directory exists and remove it if so
  if [[ -d "${this_script_path}/www.${fdomain}" ]]; then
    rm -rf "${this_script_path:?}/www.${fdomain:?}"
  fi

  return 0
}

# check inotify/setfacl is working
inotify-helper() {
  # first check inotify/setfacl is working
  if ! pgrep -f "inotifywait.*${fpath}" >/dev/null 2>&1; then
    log_with_timestamp "ERROR INOTIFY: Please start inotify service via 'systemctl start wp-fcgi-notify' first"
    exit 1
  fi
}

# find preload process pids
find_pid() {
  read -r -a PIDS <<< "$(pgrep -a -f "wget.*-q -m -p -E -k -P ${this_script_path}" | grep -v "cpulimit" | awk '{print $1}')"
}

# Display script controls
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
  echo -e "${m_tab}#${m_tab}--wp-inotify-start   need root or SUDO! set ACL permission(cache folder) for website-user"
  echo -e "${m_tab}#${m_tab}--wp-inotify-stop    need root or SUDO! unset ACL permission(cache folder) for website-user"
  echo -e "${m_tab}# ---------------------------------------------------------------------------------------------------${reset}\n"
}

# preload fastcgi-cache
preload() {
  inotify-helper

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

  # Check if wget or cpulimit commands are available
  if ! command -v wget >/dev/null 2>&1; then
    log_with_timestamp "ERROR COMMAND: wget is not installed. Please install wget."
    exit 1
  elif ! command -v cpulimit >/dev/null 2>&1; then
    log_with_timestamp "ERROR COMMAND: cpulimit is not installed. Please install cpulimit."
    exit 1
  fi

  # check fastcgi cache path is created before preload
  # if nginx has not yet created the cache path,
  # wget will create cache folder with website user instead of web server user
  # and web server user(nginx,ww-data e.g.) can't write here anymore
  if ! [[ -d "${fpath}" ]]; then
    log_with_timestamp "Your FastCGI cache folder (${fpath}) not found. Please set fcgi cache path for this vhosts in relevant nginx .conf file and restart nginx.service to create it"
    exit 1
  fi

  reject_regex='--reject-regex "/wp-admin/|/wp-includes/|/wp-json/|/xmlrpc.php|/wp-login.php|/wp-register.php|/wp-content/|/cart/|/checkout/|/my-account/|/wc-api/"'

  # purge cache & obsolete website content before preload
  if purge_helper; then
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
  else
    log_with_timestamp "ERROR PERMISSION: Cannot Purge FastCGI cache to start cache preloading. Please restart wp-fcgi-notify.service"
  fi
}

# purge fastcgi-cache
purge() {
  #inotify-helper #todo: check any dependency to inotify ops.
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

    (purge_helper) && \
    log_with_timestamp "FastCGI cache preloading is stopped. Purge FastCGI cache is completed." || \
    log_with_timestamp "ERROR PERMISSION: FastCGI cache preloading is stopped. Purge FastCGI cache cannot completed. Please restart wp-fcgi-notify.service"
  else
    (purge_helper) && \
    log_with_timestamp "Purge FastCGI cache is completed." || \
    log_with_timestamp "ERROR PERMISSION: Purge FastCGI cache cannot completed. Please restart wp-fcgi-notify.service"
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

# listens fastcgi cache folder for create events and
# give write permission to website user for further purge operations.
inotify-start() {
  # Check permissions and required packages
  if [[ ! $SUDO_USER && $EUID -ne 0 ]]; then
    log_with_timestamp "You need to run this script as root or with sudo privileges!"
    exit 1
  elif ! command -v inotifywait >/dev/null 2>&1 || \
    ! command -v tune2fs >/dev/null 2>&1 || \
    ! command -v setfacl >/dev/null 2>&1; then
    log_with_timestamp "You need the 'inotify-tools', 'tune2fs', and 'acl' packages installed!"
    exit 1
  fi

  # Check ACL configured properly
  fs="$(df / | awk 'NR==2 {print $1}')"
  if ! tune2fs -l "${fs}" | grep -q "Default mount options:.*acl"; then
    log_with_timestamp "Filesystem not mounted with the acl!"
    exit 1
  fi

  # Check instances properly
  if (( ${#fcgi[@]} == 0 )); then
    # If non instance set up, exit
    log_with_timestamp "There is no any instance , please read documentation"
    exit 1
  elif (( ${#fcgi[@]} == 1 )); then
    # if only one instance exists and it is broken, exit
    for path in "${!fcgi[@]}"; do
      if ! [[ -d "${fcgi[$path]}" ]]; then
        log_with_timestamp "Your FastCGI cache directory (${fcgi[$path]}) not found, if path is correct please restart nginx.service to automatically create it"
        exit 1
      fi
    done
  elif (( ${#fcgi[@]} > 1 )); then
    # In many instances If only one instance is broken, continue
    for path in "${!fcgi[@]}"; do
      if ! [[ -d "${fcgi[$path]}" ]]; then
        log_with_timestamp "Your FastCGI cache directory (${fcgi[$path]}) not found, if path is correct please restart nginx.service to automatically create it, EXLUDED"
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
        log_with_timestamp "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
        log_with_timestamp "Cache folder ${fcgi[$user]} destroyed manually, inotifywait/setfacl process for user: ${user} is killed!"
        log_with_timestamp "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
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
      log_with_timestamp "All done! Started to listen FastCGI cache folder (${fcgi[$path]}) events."
    else
      log_with_timestamp "Unknown error occurred during cache listen event."
    fi
  done
}

# stop listening cache create events
inotify-stop() {
  # check permission
  if [[ ! $SUDO_USER && $EUID -ne 0 ]]; then
    log_with_timestamp "You need to run script with this argument under root or sudo privileged user!"
    exit 1
  fi

  # Kill on-going preload process for all websites first
  for load in "${!fcgi[@]}"; do
    read -r -a PIDS <<< "$(pgrep -a -f "wget.*-q -m -p -E -k -P ${fcgi[$load]}" | grep -v "cpulimit" | awk '{print $1}')"
    if (( "${#PIDS[@]}" )); then
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          kill -9 $pid && log_with_timestamp "Cache preload process $pid for website $load is killed!"
        else
          log_with_timestamp "No cache preload process found for website $load - last running process was $pid"
        fi
      done
    else
      log_with_timestamp "No cache preload process found for website $load"
    fi
  done

  # Then purge fcgi cache for all websites to keep cache integrity clean
  # That means on every system reboot (systemctl reboot) all fcgi cache will cleaned for all vhosts
  # This is somehow drawback but keeping cache integrity is more important
  for cache in "${!fcgi[@]}"; do
    if [[ -d "${fcgi[$cache]}" ]]; then
      rm -rf --preserve-root "${fcgi[$cache]:?}"/*
      log_with_timestamp "FastCGI cache purged for website: $cache"
    else
      log_with_timestamp "FastCGI cache directory not found for website: $cache to clear cache"
    fi
  done

  # kill inotifywait processes
  for listen in "${!fcgi[@]}"; do
    read -r -a PIDS <<< "$(pgrep -f "inotifywait.*${fcgi[$listen]}")"
    if (( "${#PIDS[@]}" )); then
      for pid in "${PIDS[@]}"; do
        if ps -p "${pid}" >/dev/null 2>&1; then
          kill -9 $pid && log_with_timestamp "inotifywait process $pid for website $listen is killed!"
        else
          log_with_timestamp "No inotify process found for website $listen - last running process was $pid"
        fi
      done
    else
      log_with_timestamp "No inotify process found for website $listen"
    fi
  done
}

# set script arguments
case "$1" in
  --purge            ) purge         ;;
  --preload          ) preload       ;;
  --mail             ) mail          ;;
  --wp-inotify-start ) inotify-start ;;
  --wp-inotify-stop  ) inotify-stop  ;;
  *                  ) help          ;;
esac
