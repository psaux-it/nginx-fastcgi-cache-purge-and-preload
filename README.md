# Nginx Cache Purge Preload for Wordpress (NPP)

🚀 **Support this project!** If NPP has improved your workflow, consider giving it a ⭐ to help us grow:  
🚀 **Enjoying NPP?**  Buy me a coffee to support continued development.

[![Security: CVE-2025-6213](https://img.shields.io/badge/security-CVE--2025--6213-green)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54) [![GitHub Release](https://img.shields.io/github/v/release/psaux-it/nginx-fastcgi-cache-purge-and-preload?logo=github)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) [![Donate](https://img.shields.io/badge/Wordpress_SVN-v2.1.4-blue?style=flat&logo=wordpress)](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) [![safexec CI](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml/badge.svg)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/actions/workflows/build-and-commit-safexec.yml) [![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-red?logo=github)](https://github.com/sponsors/psaux-it)

**NPP** allows WordPress users to manage Nginx Cache operations (Purge & Preload) directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.  NPP supports Nginx **FastCGI**, **Proxy**, **SCGI**, and **UWSGI** cache purge and preload operations, making it one of the most comprehensive solutions for managing Nginx cache directly from WordPress.

### 🔥 Advanced Cache Preloading

One of NPP’s key differentiators is its **advanced cache preloading system**. Unlike many Nginx cache plugins that only purge cache, NPP can **actively warm the cache** by crawling your site and rebuilding cache entries automatically.

### 🛡️ Secure Command Execution (safexec)

NPP includes **[safexec](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/tree/main/safexec)** - a hardened command execution wrapper written in C for NPP. It safely executes system utilities used by NPP while enforcing strict security controls. An optional library can also normalize percent-encoded HTTP request lines during cache preloading, preventing cache key inconsistencies in Nginx.

### Check out! :whale: NPP Dockerized Full Stack Deploy

Check out **[Dockerized](https://github.com/psaux-it/wordpress-nginx-cache-docker)** repository that provides a complete full-stack deployment for **NPP**. It includes pre-configured Dockerfiles, a Docker Compose setup, and detailed instructions to get your site running in minutes. Maintained alongside the main **NPP** plugin. It’s ideal for production, development, and testing environments, offering a streamlined way to simplify your deployment workflow with containerized solutions.

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
