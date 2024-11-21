# Nginx FastCGI Cache Purge & Preload Plugin for Wordpress (NPP)

🚀 **Support this project!** If NPP has improved your workflow, consider giving it a ⭐ to help us grow:  
🚀 **Need help with server-side integration?** Check out our tailored services:

[![GitHub Release](https://img.shields.io/github/v/release/psaux-it/nginx-fastcgi-cache-purge-and-preload?logo=github)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) [![Donate](https://img.shields.io/badge/Wordpress_SVN-v2.0.6-blue?style=flat&logo=wordpress)](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) [![Donate](https://img.shields.io/badge/Donate-PayTR-blue?style=flat&logo=visa)](https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/) [![Donate](https://img.shields.io/badge/Check-Services-blue?style=flat&logo=Linux)](https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/)

> [!NOTE]
> This plugin allows WordPress users to manage Nginx Cache Purge and Preload operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

### Requirements

This wordpress plugin is compatible exclusively with **Nginx web servers** running on **Linux-powered** systems. Additionally, the **shell_exec** function must be enabled and unrestricted. Consequently, the plugin may not operate fully on shared hosting environments where native Linux commands are blocked from running via PHP.

Moreover, granting the correct permissions to the PHP process owner (PHP-FPM-USER) is essential for the proper functioning of the purge and preload operations. This is necessary in isolated user environments that have two distinct user roles: the WEBSERVER-USER (nginx or www-data) and the PHP-FPM-USER.

📌 If you see warnings or if any plugin settings or tabs are disabled, this could indicate permission issues, an unsupported environment, or missing dependencies that the plugin requires to function properly.

> [!TIP]
> You do not need any external Nginx module. Simply execute the one of the following one liner's in your terminal after installing plugin and follow instructions. <br/> <br/>Please read the information below to understand what **install.sh** does.

💯```bash <(curl -Ss https://psaux-it.github.io/install.sh)``` <br/>
💯```wget -qO /tmp/install.sh https://psaux-it.github.io/install.sh && bash /tmp/install.sh```

### Plugin Main Features

🚀**Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.<br/>
🚀**Preload All Nginx Cache**: Populate the Nginx cache with the most recent data for the entire website.<br/>
🚀**Auto Preload Nginx Cache**: Automatically preload the cache after purging, ensuring fast page load times by caching content proactively. This feature is triggered when `Auto Purge` is enabled for a single `post/page` or when the `Purge All` cache action is used.<br/>
🚀**Auto Purge Nginx Cache**: Purge on `post/page` content changes, `comment` status updates or approvals, `theme/plugin` updates, or when compatible `cache plugins` trigger a purge. Cache is preloaded automatically if `Auto Preload` is enabled (for entire or single).<br/>
🚀**Schedule Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.<br/>
🚀**Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through `REST API` endpoints.<br/>
🚀**Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in Advanced Tab.<br/>
🚀**On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.<br/>
🚀**Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `fastcgi_cache_key` format.<br/>
🚀**Optimized Nginx Cache Preload**: Enhance Nginx cache preload performance with options to limit server resource usage, via exclude endpoints, exclude file extensions, wait retrievals and rate limiting.<br/>
🚀**Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, cache preload status, and Nginx status from the Status tab.<br/>
🚀**User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar for quick access.<br/>
🚀**Admin Notices and Logs**: Receive handy notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.<br/>
🚀**Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.<br/>

## What is the actual solution here exactly?

### Overview

This project addresses the challenge of automating Nginx FastCGI cache purging and preloading (WordPress) in Nginx environments where two distinct users, WEBSERVER-USER and PHP-FPM-USER, are involved. NPP offers a more direct solution without requiring any external NGINX modules, such as the Cache Purge module. This plugin directly traverses the cache directory and clears the cache when the permissions are properly granted for the PHP-FPM-USER to access the Nginx cache directory. To automate the granting of necessary permissions to PHP-FPM-USER, a pre-made bash script is provided that must be run manually on the host server under the **root**.

### Problem Statement

- **WEBSERVER-USER**: Responsible for creating cache folders and files with strict permissions.
- **PHP-FPM-USER**: Handles cache Purge & Preload operations but lacks **privileges**

### Challenges

- **Permission Issues**: Adding **PHP-FPM-USER** to the **WEBSERVER-GROUP** doesn't resolve permission conflicts.
- **Nginx Overrides**: Nginx overrides default setfacl settings, ignoring ACLs. Nginx creates cache folders and files with strict permissions.

### Solution

~~Solution involves combining **inotifywait** with **setfacl**:~~ ❗️This approach is deprecated.<br/>
Use **bindfs** to create a FUSE mount of the original Nginx Cache Path, granting read and write permissions to the PHP-FPM-USER.

## Installation Instructions

To implement this solution:
1. Download latest [plugin](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) from official wordpress plugin repository or from our latest [releases](https://github.com/psaux-it/nginx-fastcgi-cache-purge-preload-wordpress/releases/tag/v2.0.6) and install to your wordpress instance also you can search plugin on wordpress admin dashboard as 'fastcgi cache purge and preload for nginx'
2. On **root** call ```bash <(curl -Ss https://psaux-it.github.io/install.sh)``` one liner to start automated setup

### What does install.sh do?

The `install.sh` script serves as a wrapper for executing the main `fastcgi_ops_root.sh` script from [psaux-it.github.io](https://github.com/psaux-it/psaux-it.github.io). This script is designed for the **FastCGI Cache Purge and Preload for Nginx** WordPress plugin (NPP), which can be found at [Official WordPress Plugin Repository](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/).<br/>

----

❗️This approach is deprecated.

~~Mainly, in case your current web server setup involves two distinct users, **WEBSERVER-USER** (nginx or www-data) and **PHP-FPM-USER**, the solution proposed by this script involves combining Linux server side tools
**inotifywait** with **setfacl** to automatically grant write permissions to the **PHP-FPM-USER** for the corresponding **Nginx Cache Paths**, which are matched either automatically or via a manual configuration file.~~

----

📣 **New Approach**

The script first attempts to automatically identify the PHP-FPM-USER (also known as the PHP process owner or website user) along with their associated Nginx Cache Paths. If it cannot automatically match the PHP-FPM-USER with their respective Nginx Cache Path, it provides an easy manual setup option using the `manual-configs.nginx` file.

In environments with two distinct user roles—the WEBSERVER-USER (nginx or www-data) and the PHP-FPM-USER —this script automates the management of Nginx Cache Paths. **It utilizes `bindfs` to create a `FUSE` mount of the original Nginx Cache Paths, enabling the PHP-FPM-USER to write to these directories with the necessary permissions.** This approach resolves permission conflicts by granting the PHP-FPM-USER access to a new mount point, while keeping the original Nginx Cache Paths intact and synchronized.

After the setup (whether automatic or manual) completed, the script creates an `npp-wordpress` systemd service that can be managed from the WordPress admin dashboard under the NPP plugin STATUS tab.

Additionally, NPP users have the flexibility to manage FUSE mount and unmount operations for the original Nginx Cache Path directly from the WP admin dashboard, effectively preventing unexpected permission issues and maintaining consistent cache stability.

- **Automated Detection**: Quickly sets up Nginx Cache Paths and associated PHP-FPM-USERS.
- **Dynamic Configuration**: Detects Nginx configuration dynamically for seamless integration.
- **Systemd Integration**: Generates and enables systemd service **npp-wordpress** for manage FUSE mount operations.
- **Manual Configuration Support**: Allow manual configuration via the **manual-configs.nginx** file.

> [!TIP]
> 1. Furthermore, if you're hosting multiple WordPress sites each with their own Nginx cache paths and associated PHP-FPM pool users on the same host, you'll find that deploying just one instance of this script effectively manages all WordPress instances using the NPP plugin. This streamlined approach centralizes cache management tasks, ensuring optimal efficiency and simplified maintenance throughout your server environment.<br/>
> 2. If Auto detection not works for you, for proper matching, please ensure that your Nginx Cache Path includes the associated PHP-FPM-USER username.

For example assuming your PHP-FPM-USER = **psauxit**<br/>
The following example **fastcgi_cache_path** naming formats will match perfectly with your **PHP-FPM-USER** and detected by script automatically.

```
fastcgi_cache_path /dev/shm/fastcgi-cache-psauxit
fastcgi_cache_path /dev/shm/cache-psauxit
fastcgi_cache_path /dev/shm/psauxit
fastcgi_cache_path /var/cache/psauxit-fastcgi
fastcgi_cache_path /var/cache/website-psauxit.com
```

## Visuals

[https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/235404ff-b35c-4cce-ac17-ebead39130b6](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/93a94d73-1d37-4b69-9ca9-ac1bed766f86)

------
![Screenshot 2024-05-27 000219](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/b64873e3-e91b-4a97-a228-b88d58b1ed06)

------
![Screenshot 2024-05-27 000248](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/fca56b7c-a15d-4900-b958-ad44eb1fe19d)

------

![Screenshot 2024-05-26 235131](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/d13d3b8e-6829-414e-8d71-15f3c4a4f7ab)
---

---
![Screenshot 2024-05-26 235351](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/e76a6a69-0be0-4762-b17d-16803656e948)

---
![Screenshot 2024-05-26 235555](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/da2d37c9-1681-4c97-a4c7-2ca5d5f625dd)

<details>
  <summary>Here is the short explanation of proper php-fpm nginx setup</summary>
  
### Here is the short explanation of proper php-fpm nginx setup<br/>
#### PHP-FPM-USER (as known as the website user)
The PHP-FPM user should be a special user that you create for running your website, whether it is Magento, WordPress, or anything.

#### WEBSERVER-USER (as known as the webserver user)
NGINX must run with it own unprivileged user, which is **nginx** (RHEL-based systems) or **www-data** (Debian-based systems).

#### Connecting PHP-FPM-USER and WEBSERVER-USER
We must connect things up so that WEBSERVER-USER can read files that belong to the PHP-FPM-GROUP
This will allow us to control what WEBSERVER-USER can read or not, via group chmod permission bit.
##### IMPORTANT:
Granting additional group permissions to the "nginx/www-data" user can potentially introduce security risks due to the principle of least privilege. Your PHP-FPM-USER should never have sudo privileges, even if it's not listed in the sudoer list, as this can still pose security drawbacks. Therefore, we will set the website content's group permission to "g=rX" so that "nginx/www-data" can read all files and traverse all directories, but not write to them.

```
usermod -a -G PHP-FPM-GROUP WEBSERVER-USER
```
This reads as: add WEBSERVER-USER (nginx/www-data) to PHP-FPM-GROUP (websiteuser group).<br/>

```
chown -R PHP-FPM-USER:PHP-FPM-GROUP /home/websiteuser/websitefiles
```
Here is a simple rule: all the files should be owned by the PHP-FPM-USER and the PHP-FPM-GROUP:

```
chmod -R u=rwX,g=rX,o= /home/websiteuser/websitefiles
```
This translates to the following:

- PHP-FPM-USER can read, write all files, and read all directories
- PHP-FPM-GROUP (meantime WEBSERVER-USER) can read all files and traverse all directories, but not write
- All other users cannot read or write anything

#### PHP-FPM POOL SETTINGS
```../fpm-php/fpm.d/websiteuser.conf```

```
[websiteuser.com]
user = PHP-FPM-USER
group = PHP-FPM-GROUP
listen.owner = WEBSERVER-USER
listen.group = WEBSERVER-GROUP
listen.mode = 0660
listen = /var/run/php-fcgi-websiteuser.sock
```
This is proper php-fpm nginx setup example.

</details>
