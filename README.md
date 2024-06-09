# Nginx FastCGI Cache Purge & Preload Plugin for Wordpress (NPP)

🚀 If you find this project helpful, please consider supporting its development by making a donation:<br/>
🚀 If you require assistance with NPP server-side integration, please explore our services:

[![GitHub Release](https://img.shields.io/github/v/release/psaux-it/nginx-fastcgi-cache-purge-and-preload?logo=github)](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases) [![Donate](https://img.shields.io/badge/Wordpress_SVN-v2.0.1-blue?style=flat&logo=wordpress)](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) [![Donate](https://img.shields.io/badge/Donate-PayTR-blue?style=flat&logo=visa)](https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/) [![Donate](https://img.shields.io/badge/Check-Services-blue?style=flat&logo=Linux)](https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/)

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

## What is the actual solution here exactly?

### Overview

This project addresses the challenge of automating Nginx FastCGI cache purging and preloading (Wordpress) in Nginx environments where two distinct users, **WEBSERVER-USER** and **PHP-FPM-USER**, are involved. 

### Problem Statement

- **WEBSERVER-USER**: Responsible for creating cache folders and files with strict permissions.
- **PHP-FPM-USER**: Handles cache purge operations but lacks **privileges**

### Challenges

- **Permission Issues**: Adding **PHP-FPM-USER** to the **WEBSERVER-GROUP** doesn't resolve permission conflicts.
- **Nginx Overrides**: Nginx overrides default setfacl settings, ignoring ACLs. Nginx creates cache folders and files with strict permissions.

Surprisingly, Nginx cache doesn't adhere to DEFAULT ACLs.<br/>
` setfacl -R -m d:u:websiteuser:rwX /home/websiteuser/fastcgi-cache`

### Solution

Solution involves combining **inotifywait** with **setfacl** under **root**:
- **fastcgi_ops_root.sh** grants **PHP-FPM-USER** write permissions recursively for cache purge operations for multi instances.

## Implementation

To implement this solution:
1. Download latest [plugin](https://wordpress.org/plugins/fastcgi-cache-purge-and-preload-nginx/) from official wordpress plugin repository or from our latest [releases](https://github.com/psaux-it/nginx-fastcgi-cache-purge-preload-wordpress/releases/tag/v2.0.1) and install to your wordpress instance also you can search plugin on wordpress admin dashboard as 'fastcgi cache purge and preload for nginx'
2. On **root** call ```bash <(curl -Ss https://psaux-it.github.io/install.sh)``` to start automated setup

## What does install.sh do? Is it safe?

This Bash script automates the management of **inotify/setfacl** operations, ensuring efficiency and security. It enhances the efficiency and security of cache management tasks by automating the setup and configuration processes.
The [install.sh](https://github.com/psaux-it/psaux-it.github.io/blob/main/install.sh) script serves as a wrapper that facilitates the execution of the main [fastcgi_ops_root.sh](https://github.com/psaux-it/psaux-it.github.io/blob/main/fastcgi_ops_root.sh) script from [psaux-it.github.io](https://github.com/psaux-it/psaux-it.github.io). It acts as a convenient entry point for users to initiate the setup and configuration procedures seamlessly.
Rest assured, this solution is entirely safe to use, providing a reliable and straightforward method for managing Nginx FastCGI cache purge operations on Wordpress front-end.

- **Automated Setup**: Quickly sets up FastCGI cache paths and associated PHP-FPM users.
- **Dynamic Configuration**: Detects Nginx configuration dynamically for seamless integration.
- **ACL Verification**: Ensures filesystem ACL configuration for proper functionality.
- **Systemd Integration**: Generates and enables systemd service for continuous operation.
- **Manual Configuration Support**: Allows manual configuration for customized setups.
- **Inotify Operations**: Listens to FastCGI cache folder events for real-time updates.

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
