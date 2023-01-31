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

# MULTISITE SETTINGS
####################
fcgi[websiteuser1]="/home/websiteuser1/fastcgi-cache"
fcgi[websiteuser2]="/home/websiteuser2/fastcgi-cache"
fcgi[websiteuser3]="/home/websiteuser3/fastcgi-cache"

# INSTANCE SETTINGS
###################
fdomain="websiteuser1.com"                                         # fastcgi-cache preload URL for instance
fpath="/home/websiteuser1.com/fastcgi-cache"                       # fastcgi-cache path for instance

# Set mail options
mail_to="support@websiteuser1.com"                                 # send mail to
mail_from="From: System Automations<fcgi@websiteuser1.com>"        # mail from
mail_subject="FastCGI-Cache Purge,Preload Ops"                     # mail subject

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
  echo "cannot find script path!"
  exit 1
fi

# enable extglob
# remove trailing / (removes / and //) from script path
shopt -s extglob
this_script_path="${this_script_path%%+(/)}"

# define PID
PIDFILE="${this_script_path}/fastcgi_ops_${fdomain%%.*}.pid"

# Check folder has subfolders
hasDirs() {
  declare $targetDir="$1"
  ls -ld ${targetDir}*/
}

# cache purge helper function
purge_helper() {
  if hasDirs "${fpath:?}"/ >/dev/null 2>&1; then
    rm -rf "${fpath:?}"/* || { echo "Cannot purge FastCGI cache!"; exit 1; }
  fi
  if [[ -d "${this_script_path}/www.${fdomain}" ]]; then
    rm -rf "${this_script_path:?}/www.${fdomain:?}" || { echo "Cannot delete obsolete website content!"; exit 1; }
  fi
}

# find preload process pids
find_pid() {
  PIDS="$(ps -aux | grep -v grep | grep -wE "wget.*${this_script_path}" | awk '{print $2}')"
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
  # create PID before long running process
  # allow only one instance running at the same time
  if [[ -f "${PIDFILE}" ]]; then
    PID="$(< "${PIDFILE}")"
    if ps -p "${PID}" >/dev/null 2>&1; then
      echo "FastCGI cache is already preloading, please wait until complete.."
      exit 1
    fi
  else
    touch "${PIDFILE}"
  fi

  # early quit if wget not exist
  if ! command -v wget >/dev/null 2>&1; then
    echo "wget command not found!"
    exit 1
  fi

  # check fastcgi cache path is created before preload
  # if nginx has not yet created the cache path,
  # wget will create cache folder with website user instead of web server user
  # and web server user(nginx,ww-data e.g.) can't write here anymore
  if ! [[ -d "${fpath}" ]]; then
    echo "Your FastCGI cache folder (${fpath}) is not created yet. Please manually create it and change ownership to the web server user(nginx or ww-data) or restart nginx to force creating the cache folder!"
    exit 1
  fi

  # purge cache & obsolete website content before preload
  purge_helper

  # check GNU time command exist
  if [[ -f "/usr/bin/time" ]]; then
    # start fastcgi cache preload on background and measure elapsed time
    /usr/bin/time -f'%E' -o "${this_script_path}"/preload_elapsed.txt wget -q -m -p -E -k -P "${this_script_path}" https://www."${fdomain}"/ &>/dev/null &
  else
    # start fastcgi cache preload on background without time measure
    # bash built-in time command is problematic (wrong PID always with bg processing)
    wget -q -m -p -E -k -P "${this_script_path}" https://www."${fdomain}"/ &>/dev/null &
  fi

  # get the PID of the background preload process
  my_pid=$!

  # keep PID in /run
  echo "${my_pid}" > "${PIDFILE}" || { echo "Cannot create PID!"; exit 1; }

  # Be nice on production wget cpu usage is high
  find_pid
  if [[ -n "${PIDS}" ]]; then
    for pid in $PIDS
    do
      renice 19 $pid >/dev/null 2>&1 || echo "Cannot renice preload process"
    done
  fi

  # little hackish
  sleep 3

  # early test that process is alive after 3 second
  if ps -p "$(< "${PIDFILE}")" >/dev/null 2>&1; then
    echo "FastCGI cache preloading started on background. You will be informed when completed."
  else
    echo "Cannot preload FastCGI cache!"
    exit 1
  fi
}

# purge fastcgi-cache
purge() {
  find_pid

  # stop ongoing preload process if exist
  if [[ -n "${PIDS}" ]]; then
    for pid in $PIDS
    do
      kill -9 $pid
    done
    [[ -f "${PIDFILE}" ]] && rm -f "${PIDFILE:?}"
    purge_helper
    echo "FastCGI cache preloading is stopped, purge FastCGI cache is completed."
  else
    purge_helper
    echo "Purge FastCGI cache is completed."
  fi
}

# wordpress admin message
admin() {
  # check previous preload process completed
  [[ -f "${PIDFILE}" ]] && PID="$(< "${PIDFILE}")" || exit 1

  # check current preload process is completed
  if ps -p "${PID}" > /dev/null 2>&1; then
    exit 1
  else
    # remove PID
    rm -f "${PIDFILE:?}"

    # check GNU time command exist
    if [[ -f "/usr/bin/time" ]]; then
      elapsed="$(tail -n 1 "${this_script_path}"/preload_elapsed.txt)"
      echo "${elapsed}"
    fi

    # send mail
    if command -v mail >/dev/null 2>&1; then
      tfile=$(mktemp)
      [[ -n "${elapsed}" ]] && echo "FastCGI cache preloading is completed in ${elapsed}!" > "${tfile}" || echo "FastCGI cache preloading is completed!" > "${tfile}"
      cat "${tfile}" | mail -s "$mail_subject" -a "$mail_from" "$mail_to"
      rm -f "${tfile:?}"
    fi

    # trigger wordpress admin message
    exit 0
  fi
}

# listens fastcgi cache folder for create events and
# give write permission to PHP-FPM-USER for further purge operations.
inotify-start() {
  # check permission
  if [[ ! $SUDO_USER && $EUID -ne 0 ]]; then
    echo "You need to run script with this argument under root or sudo privileged user!"
    exit 1
  fi

  # Check env. has inotify-tools linux package
  if ! command -v inotifywait >/dev/null 2>&1; then
    echo "You need inotify-tools linux package!"
    exit 1
  fi

  # Check env has tune2fs linux package
  if ! command -v tune2fs >/dev/null 2>&1; then
    echo "You need tune2fs linux package!"
    exit 1
  fi

  # Check ACL configured properly
  fs="$(df "${fpath}" | tail -1 | awk '{ print $1 }')"
  if ! tune2fs -l "${fs}" | grep "Default mount options:" | grep "acl" >/dev/null 2>&1; then
    echo "Filesystem not mounted with the acl!"
    exit 1
  elif ! command -v setfacl >/dev/null 2>&1; then
    echo "You need acl linux package!"
    exit 1
  fi

  # check FastCGI cache path is created
  for path in "${!fcgi[@]}"
  do
    if ! [[ -d "${fcgi[$path]}" ]]; then
      echo "Your FastCGI cache folder (${fpath}) is not created yet. Please manually create it and change ownership to the web server user(nginx or ww-data) or restart nginx to force creating the cache folder!"
      exit 1
    fi
  done

  # start to listen fastcgi cache folder events
  # give write permission to website user for further purge ops
  for user in "${!fcgi[@]}"
  do
    while :
    do
      inotifywait -e modify,create -r "${fcgi[$user]}" && \
      setfacl -R -m u:"${user}":rwX "${fcgi[$user]}"/
    done >/dev/null 2>&1 &
  done

  # check process is alive
  for path in "${!fcgi[@]}"
  do
    if ps -aux | grep -v grep | grep -wE "inotifywait.*${fcgi[$path]}" >/dev/null 2>&1; then
      echo "All done! Started to listen FastCGI cache folder (${fcgi[$path]}) events."
    else
      echo "Unknown error occurred during cache listen event."
    fi
  done
}

# stop listening cache create events
inotify-stop() {
  # check permission
  if [[ ! $SUDO_USER && $EUID -ne 0 ]]; then
    echo "You need to run script with this argument under root or sudo privileged user!"
    exit 1
  fi

  # kill script
  if ps -aux | grep -v grep | grep -wE "wp-inotify-start" >/dev/null 2>&1; then
    kill -9 $(ps -aux | grep -v grep | grep -wE "wp-inotify-start" | awk '{print $2}') && echo "${this_script_name} is killed !"
  fi

  # kill inotifywait process
  for user in "${!fcgi[@]}"
  do
    if ps -aux | grep -v grep | grep -wE "inotifywait.*${fcgi[$user]}" >/dev/null 2>&1; then
      kill -9 $(ps -aux | grep -v grep | grep -wE "inotifywait.*${fcgi[$user]}" | awk '{print $2}') && echo "inotifywait is killed !"
    fi
  done
}

# set script arguments
case "$@" in
  --purge            ) purge         ;;
  --preload          ) preload       ;;
  --admin            ) admin         ;;
  --wp-inotify-start ) inotify-start ;;
  --wp-inotify-stop  ) inotify-stop  ;;
  *                  ) help          ;;
esac
