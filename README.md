# Nginx Cache Purge Preload for Wordpress (NPP)

![Image](https://github.com/user-attachments/assets/93b5d539-1f9e-479b-b8b0-988f0010cf47)

<div align="center">
<a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54"><img src="https://img.shields.io/badge/security-CVE--2025--6213-green" alt="Security: CVE-2025-6213"></a> <a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases"><img src="https://img.shields.io/github/v/release/psaux-it/nginx-fastcgi-cache-purge-and-preload?logo=github" alt="GitHub Release"></a> <a href="https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/"><img src="https://img.shields.io/badge/Wordpress_SVN-v2.1.4-blue?style=flat&logo=wordpress" alt="Donate"></a> <a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml"><img src="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml/badge.svg" alt="safexec CI"></a> <a href="https://github.com/sponsors/psaux-it"><img src="https://img.shields.io/badge/Sponsor-%E2%9D%A4-red?logo=github" alt="Sponsor"></a>
</div>

---

🚀 **Support this project!** If NPP has improved your workflow, consider giving it a ⭐ to help us grow:  
🚀 **Enjoying NPP?**  Buy me a coffee to support continued development.

---

**NPP** allows WordPress users to manage Nginx Cache operations (Purge & Preload) directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.  NPP supports Nginx **FastCGI**, **Proxy**, **SCGI**, and **UWSGI** cache purge and preload operations, making it one of the most comprehensive solutions for managing Nginx cache directly from WordPress.

### 🔥 Advanced Cache Preloading

One of NPP’s key differentiators is its **advanced cache preloading system**. Unlike many Nginx cache plugins that only purge cache, NPP can **actively warm the cache** by crawling your site and rebuilding cache entries automatically.

### 🛡️ Secure Command Execution (safexec)

NPP includes **[safexec](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/tree/main/safexec)** - a hardened command execution wrapper written in C for NPP. It safely executes system utilities used by NPP while enforcing strict security controls. An optional library can also normalize percent-encoded HTTP request lines during cache preloading, preventing cache key inconsistencies in Nginx.

### :whale: Dockerized Full Stack Deploy

**[Dockerized](https://github.com/psaux-it/wordpress-nginx-cache-docker)** repository that provides a complete full-stack deployment for **NPP**. It includes pre-configured Dockerfiles, a Docker Compose setup, and detailed instructions to get your site running in minutes. Maintained alongside the main **NPP** plugin. It’s ideal for production, development, and testing environments, offering a streamlined way to simplify your deployment workflow with containerized solutions.

⚡ Spin up a full environment and try NPP in minutes with the ready-to-run **[Docker](https://github.com/psaux-it/wordpress-nginx-cache-docker)** setup.

🖥️ If you are on **All-in-One Monolithic Server** arc you can still use pre-made automation bash script below.

### Requirements

**NPP** is compatible exclusively with **Nginx web servers** running on **Linux-powered** systems.

The PHP `shell_exec()` function must be enabled and unrestricted, as NPP relies on system utilities for certain operations. Because of this, the plugin may not operate fully on shared hosting environments where native Linux commands are blocked.

📌 If you see warnings or if any plugin settings or tabs are disabled, this could indicate permission issues, an unsupported environment, or missing dependencies that the plugin requires to function properly. **NPP is completely free OpenSource project!**

📌 You do not need any external Nginx module.

## How NPP Manages Nginx Cache?

### Overview

NPP allows WordPress administrators to manage **Nginx cache purge and preload operations directly from the WordPress dashboard**.

Instead of relying on special Nginx purge modules, NPP interacts with the **Nginx cache directory itself**, removing or warming cache files when needed. As long as the PHP process owner (**PHP-FPM-USER**) has the required permissions to the cache directory, NPP can manage cache operations without requiring direct Nginx integration.

This approach provides a flexible and architecture-agnostic way to control Nginx cache behavior, making it suitable for traditional servers as well as modern containerized environments.

### Features
---

🧹 **Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.

🔄 **Preload All Nginx Cache**: Warm the Nginx cache with the most recent data for the entire website.

🚀 **Auto Preload Nginx Cache**: Automatically preloads the cache when Auto Purge is enabled for a POST/PAGE or after the Purge All action.

🧼 **Auto Purge Nginx Cache**: Purge cache on Post/Page content changes, comment status updates, theme/plugin updates, or when compatible Cache Plugins trigger a purge. Nginx cache is preloaded automatically if Auto Preload is enabled (for the entire site or individual page).

🔗 **Purge Scope (Related Pages)**: Automatically purge related pages such as the Homepage, WooCommerce Shop page, and Category/Tag archives when a single URL is purged. Optionally preload those pages to keep the cache warm.

⏰ **Schedule Nginx Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.

🧭 **Proxy Support for Preload**: Route preload requests through a proxy server for edge-case environments and containerized deployments.

⏱️ **Live Preload Progress Monitoring**: Watch the Nginx cache preload process in real time — complete with a dynamic progress bar, currently processed URL, 404 tracking, server load, and total completion time.

🌐 **Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through REST API endpoints.

⚙️ **Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in the Advanced Tab.

📚 **Nginx Cache Analyzer**: Full HIT/MISS cache analyzer dashboard, from the last preload crawl with what is currently stored in the Nginx cache. Instantly spot uncached pages and Purge or Preload them directly in the Advanced Tab.

🔍 **On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.

🗝️ **Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `_cache_key` format.

⚡ **Optimized Nginx Cache Preload**: Enhance Nginx cache preload performance with options to limit CPU usage, exclude endpoints, wait retrievals, and apply rate limiting.

📊 **Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, and Nginx status from the Status tab.

📈 **Cache Coverage Ratio**: Live gauge in the WordPress dashboard widget showing the cache coverage ratio, based on the last preload snapshot. Refreshable on demand without a page reload.

☁️ **Cloudflare APO Sync**: Automatically mirrors NPP purge actions to Cloudflare APO to keep edge cache synchronized with your Nginx cache.

🔴 **Redis Object Cache Sync**: Bidirectional sync between NPP and Redis Object Cache. NPP Purge All flushes the Redis object cache, and a Redis flush triggers a full Nginx cache purge via NPP (when auto-purge is enabled).

🛒 **WooCommerce Auto-Purge**: Automatically purges Nginx cache when WooCommerce product stock quantity changes, stock status changes (in stock / out of stock / on backorder), or when an order is cancelled and stock is restored.

🔒 **Concurrent Purge Serialization**: Atomic lock mechanism prevents simultaneous purge operations from colliding, ensuring cache integrity during concurrent admin actions or background events.

🧩 **Modular by Design**: Easily integrate with external scripts and automation tools.

🖥️ **User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar and dashboard for quick access.

📋 **Admin Notices and Logs**: Receive notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.

📧 **Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.

---

### Permission Handling in Isolated User Environments

Some server architectures run the **web server (WEBSERVER-USER)** and **PHP-FPM (PHP-FPM-USER)** under different system users. In such environments, the PHP process may not have permission to modify the Nginx cache directory.

To simplify setup in these cases, NPP provides a **pre-configured automation script** that helps resolve permission boundaries by creating a FUSE-based bindfs mount for the cache directory.

This script is **only required in environments where user isolation prevents PHP from accessing the cache path**. In many setups—such as when Nginx and PHP-FPM run under the same user—it is **not required at all**.

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

The script first attempts to automatically identify the PHP-FPM-USER along with their associated Nginx Cache Paths. If cannot automatically match the PHP-FPM-USER with their respective Nginx Cache Path, provides an easy manual setup option using the `manual-configs.nginx` file, where users can add multiple PHP-FPM-USER and their associated Nginx Cache Paths.

In environments with two distinct user roles—the WEBSERVER-USER and the PHP-FPM-USER —this script automates the management of Nginx Cache Paths. **Utilizes `bindfs` to create a `FUSE` mount of the original Nginx Cache Paths, enabling the PHP-FPM-USER to write to these directories with the necessary permissions.** This approach resolves permission conflicts by granting the PHP-FPM-USER access to a new mount point, while keeping the original Nginx Cache Paths intact and synchronized.

> [!NOTE]
> As mentioned before, If your environment runs both the WEB-SERVER and PHP-FPM under the same user (for example nginx, www-data, or similar), this script or any server side action is not required, since no permission boundary exists between the web server and PHP processes.

After the setup (whether automatic or manual) completed, the script creates an `npp-wordpress` systemd service. Thats All!

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
