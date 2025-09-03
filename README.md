# Nginx Cache Purge Preload for Wordpress (NPP)

🚀 **Support this project!** If NPP has improved your workflow, consider giving it a ⭐ to help us grow:  
🚀 **Need help with server-side integration?** Check out our tailored services:

[![Security: CVE-2025-6213](https://img.shields.io/badge/security-CVE--2025--6213-green)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54) [![GitHub Release](https://img.shields.io/github/v/release/psaux-it/nginx-fastcgi-cache-purge-and-preload?logo=github)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) [![Donate](https://img.shields.io/badge/Wordpress_SVN-v2.1.3-blue?style=flat&logo=wordpress)](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) [![safexec CI](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml/badge.svg)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml) [![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-red?logo=github)](https://github.com/sponsors/psaux-it)

| ![Image](https://github.com/user-attachments/assets/64712b98-ab33-44d5-bd41-15766c19b93a) | 🔴 [Live Test](https://npp.psauxit.com/npp-login) — Try NPP in action |
|---|---|

**NPP** allows WordPress users to manage Nginx Cache operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.  NPP supports Nginx **FastCGI**, **Proxy**, **SCGI**, and **UWSGI** cache purge and preload operations, making it the most comprehensive solution for managing Nginx Cache from your WordPress dashboard.

### Check out! :whale: NPP Dockerized Full Stack Deploy

Check out **[Dockerized](https://github.com/psaux-it/wordpress-nginx-cache-docker)** repository that provides a complete full-stack deployment for **NPP**. It includes pre-configured Dockerfiles, a Docker Compose setup, and detailed instructions to get your site running in minutes. Maintained alongside the main **NPP** plugin. It’s ideal for production, development, and testing environments, offering a streamlined way to simplify your deployment workflow with containerized solutions. If you are on **All-in-One Monolithic Server** arc you can still use pre-made automation bash script below.

### Requirements

**NPP** is compatible exclusively with **Nginx web servers** running on **Linux-powered** systems. Additionally, the **shell_exec** function must be enabled and unrestricted. Consequently, the plugin may not operate fully on shared hosting environments where native Linux commands are blocked from running via PHP.

Moreover, granting the correct permissions to the PHP process owner (PHP-FPM-USER) is essential for the proper functioning of the purge and preload operations. This is necessary in isolated user environments that have two distinct user roles: the WEBSERVER-USER and the PHP-FPM-USER.

📌 If you see warnings or if any plugin settings or tabs are disabled, this could indicate permission issues, an unsupported environment, or missing dependencies that the plugin requires to function properly. **NPP is completely free OpenSource project!**

📌 You do not need any external Nginx module. If you're deploying on an **All-in-One Monolithic Server** simply execute the following one liner after installing plugin and follow instructions.<br/>

➡️```bash <(curl -Ss https://psaux-it.github.io/install.sh)``` <br/>

### Plugin Key Features

➡️**Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.<br/>
➡️**Preload All Nginx Cache**: Populate the Nginx cache with the most recent data for the entire website.<br/>
➡️**Auto Preload Nginx Cache**: Automatically preload the cache after purging, ensuring fast page load times by caching content proactively. This feature is triggered when `Auto Purge` is enabled for a single `post/page` or when the `Purge All` cache action is used.<br/>
➡️**Auto Purge Nginx Cache**: The cache is automatically purged when the `active theme` is updated, `a plugin` is activated, updated, or deactivated, or when `compatible caching plugins` trigger a purge. For `posts/pages`, the cache is cleared when `content is updated` or a `comment’s` status changes. If `Auto Preload` is enabled, the cache for the `post/page` or the `entire site` is reloaded after purging, ensuring your content remains up-to-date.<br/>
➡️**Schedule Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.<br/>
➡️**Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through `REST API` endpoints.<br/>
➡️**Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in Advanced Tab.<br/>
➡️**On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.<br/>
➡️**Proxy Support for Preload**: Enable proxy-aware preloading to route requests through a specified HTTP/S proxy for advanced setups or isolated environments.<br/>
➡️**Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `(fastcgi|proxy|uwsgi|scgi)_cache_key` format.<br/>
➡️**Optimized Nginx Cache Preload**: Enhance Nginx cache preload performance with options to limit server resource usage, via exclude endpoints, exclude file extensions, wait retrievals and rate limiting.<br/>
➡️**Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, cache preload status, and Nginx status from the Status tab.<br/>
➡️**User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar for quick access.<br/>
➡️**Admin Notices and Logs**: Receive handy notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.<br/>
➡️**Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.<br/>

## How NPP Manages Nginx Cache?

### Overview

NPP automates Nginx cache purging and preloading in WordPress environments where WEBSERVER-USER and PHP-FPM-USER operate separately. Unlike external Nginx modules, NPP directly removes cache files from the cache directory, provided PHP-FPM-USER has the necessary permissions. This method eliminates the need for direct Nginx interaction, offering greater flexibility for managing cache operations, particularly in modern containerized environments.

To ensure PHP-FPM-USER has the required permissions, NPP includes a pre-configured bash script that can be manually executed on the host, making it easy to get started and benefit from the plugin.

## Installation Instructions (All-in-One Monolithic Server)

1. Download latest [plugin](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) from official wordpress plugin repository or from our latest [releases](https://github.com/psaux-it/nginx-fastcgi-cache-purge-preload-wordpress/releases/tag/v2.1.3) and install to your wordpress instance also you can search plugin on wordpress admin dashboard as 'fastcgi cache purge and preload for nginx'
2. Call ```install.sh``` one liner to start automated setup;

Switch to ```root``` user:
```bash
sudo su - root || su - root
bash <(curl -Ss https://psaux-it.github.io/install.sh)
```

or directly with ```sudo```
 ```bash
sudo bash -c "$(curl -Ss https://psaux-it.github.io/install.sh)"
```

### What does install.sh do?

The `install.sh` script serves as a wrapper for executing the main `fastcgi_ops_root.sh` script from [our automation repository](https://github.com/psaux-it/psaux-it.github.io/blob/main/fastcgi_ops_root.sh). This script is designed for the **NPP** WordPress plugin, which can be found at [Official WordPress Plugin Repository](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/).<br/>

The script first attempts to automatically identify the PHP-FPM-USER along with their associated Nginx Cache Paths. If cannot automatically match the PHP-FPM-USER with their respective Nginx Cache Path, provides an easy manual setup option using the `manual-configs.nginx` file, where users can add multiple PHP-FPM-USER and their associated Nginx Cache Paths.

In environments with two distinct user roles—the WEBSERVER-USER and the PHP-FPM-USER —this script automates the management of Nginx Cache Paths. **Utilizes `bindfs` to create a `FUSE` mount of the original Nginx Cache Paths, enabling the PHP-FPM-USER to write to these directories with the necessary permissions.** This approach resolves permission conflicts by granting the PHP-FPM-USER access to a new mount point, while keeping the original Nginx Cache Paths intact and synchronized.

After the setup (whether automatic or manual) completed, the script creates an `npp-wordpress` systemd service that can be managed from the WordPress admin dashboard under the NPP plugin **STATUS tab**.

Additionally, NPP users have the flexibility to manage FUSE mount and unmount operations for the original Nginx Cache Path directly from the WP admin dashboard, effectively preventing unexpected permission issues and maintaining consistent cache stability.

### Scriptless Setup Instructions

These instructions guide you through setting up the NPP plugin in environments where `PHP-FPM-USER` and `WEBSERVER-USER` are distinct, manually, without the automation script **install.sh**

#### Prerequisites  
- **Web Server:** `NGINX` configured with caching.  
- **Example Users:**  
  - `PHP-FPM-USER` (PHP process owner, e.g., `psauxit`).  
  - `WEBSERVER-USER` (commonly `nginx` or `www-data`).  
- **Tools:** A Linux-based server with **bindfs** installed.  

#### Example Environment  
- **Original Nginx Cache Path:** `/dev/shm/fastcgi-cache-psauxit`  
- **FUSE Mount Point:** `/dev/shm/fastcgi-cache-psauxit-mnt`  
  - This mount path will be used as the Nginx Cache Path in the NPP plugin settings.  

---

#### Steps  

##### 1. Install bindfs  
Install **bindfs** using your Linux distribution's package manager:  
```bash
# For Gentoo
emerge --ask sys-fs/bindfs

# For Debian/Ubuntu
sudo apt install bindfs

# For Red Hat/CentOS
sudo yum install bindfs
```

##### 2. Create the FUSE Mount Directory  
Create a new directory for the FUSE mount:  
```bash
mkdir /dev/shm/fastcgi-cache-psauxit-mnt
```

##### 3. Set Up the bindfs Mount  
Mount the original Nginx cache path to the FUSE mount directory with the appropriate permissions for `psauxit`:  
```bash
bindfs -u psauxit -g psauxit --perms=u=rwx:g=rx:o= /dev/shm/fastcgi-cache-psauxit /dev/shm/fastcgi-cache-psauxit-mnt
```

To make the mount persistent, add the following line to `/etc/fstab`:  
```bash
bindfs#/dev/shm/fastcgi-cache-psauxit /dev/shm/fastcgi-cache-psauxit-mnt fuse force-user=psauxit,force-group=psauxit,perms=u=rwx:g=rx:o= 0 0
```

After editing `/etc/fstab`, apply the changes:  
```bash
mount -a
```

##### 4. Configure NPP Plugin  
1. Go to the **NPP plugin settings** page in your WordPress dashboard.  
2. Set `/dev/shm/fastcgi-cache-psauxit-mnt` as the **Nginx Cache Path**.  
3. Check the plugin **Status** tab for any warnings.
