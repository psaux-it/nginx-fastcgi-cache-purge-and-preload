# Nginx FastCGI Cache Purge & Preload Plugin for Wordpress

https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/235404ff-b35c-4cce-ac17-ebead39130b6

------
![Screenshot 2024-03-27 183824](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/98553964-cd11-4a1f-a6f3-0a0a6d073ba2)

------

![Screenshot 2024-03-27 190007](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/assets/25556606/31a4aa9f-f077-4792-a461-4b216456eaf3)
---

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
1. Download latest [plugin](https://github.com/psaux-it/nginx-fastcgi-cache-purge-preload-wordpress/releases/tag/v2.0.0) from our releases and install to your wordpress instance 
2. On **root** call ```bash <(curl -Ss https://psaux-it.github.io/install.sh)``` to start automated setup

## What does install.sh do? Is it safe?

This Bash script automates the management of inotify/setfacl operations, ensuring efficiency and security. It enhances the efficiency and security of cache management tasks by automating the setup and configuration processes.
The **install.sh** script serves as a wrapper that facilitates the execution of the main **fastcgi_ops_root.sh** script from **psaux-it.github.io**. It acts as a convenient entry point for users to initiate the setup and configuration procedures seamlessly.
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
