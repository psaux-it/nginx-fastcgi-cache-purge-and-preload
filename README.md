# Nginx FastCGI Cache Purge & Preload for Wordpress
![cache_preload](https://user-images.githubusercontent.com/25556606/202007501-8d9e5ab6-3330-452f-b967-6615e703a486.png)<br/>
------
![ıuoyuı](https://user-images.githubusercontent.com/25556606/202256497-15f46225-b06b-4e37-a3b6-1b2c1ff0259b.png)<br/>
![oıu](https://user-images.githubusercontent.com/25556606/202257768-e36986ff-6bfa-4646-befe-60ed3518835a.png)<br/>
![ouoıuy](https://user-images.githubusercontent.com/25556606/202265347-cf901dd7-65d2-4e23-b1d3-ba46ae1ddbcb.png)
-------

Pluginless Nginx cache management solution for MULTISITE wordpress. If you have ngx_cache_purge or nginx_cache_purge modules then some wordpress plugins are available. Check Nginx Helper or Cache Sniper for Nginx. On our side none of them worked as expected so we do our best.

> #### Integration is **NOT** straightforward if you are not native linux user and managing your own server. Ask for help! <br/> 
---

### Here is the short explanation of proper php-fpm nginx setup<br/>
#### PHP-FPM-USER (as known as the website user)
The PHP-FPM user should be a special user that you create for running your website, whether it is Magento, WordPress, or anything.

#### WEBSERVER-USER (as known as the webserver user)
NGINX must run with it own unprivileged user, which is **nginx** (RHEL-based systems) or **www-data** (Debian-based systems).

#### Connecting PHP-FPM-USER and WEBSERVER-USER
We must connect things up so that WEBSERVER-USER can read files that belong to the PHP-FPM-GROUP<br/>
This will allow us to control what WEBSERVER-USER can read or not, via group chmod permission bit.
```
usermod -a -G PHP-FPM-GROUP WEBSERVER-USER
```
This reads as: add WEBSERVER-USER to PHP-FPM-GROUP.<br/>

```
chown -R PHP-FPM-USER:PHP-FPM-GROUP /path/to/website/files
```
Here is a simple rule: all the files should be owned by the PHP-FPM-USER and the PHP-FPM-USER’s group:

```
chmod -R u=rwX,g=rX,o= /path/to/website/files
```
This translates to the following:

- PHP-FPM-USER can read, write all files, and read all directories
- PHP-FPM-GROUP (WEBSERVER-USER) can read all files and traverse all directories, but not write
- All other users cannot read or write anything

This is proper php-fpm nginx setup example. With this short explanation, I think you understand better which user will be owner of the **fastcgi_ops.sh** --> PHP-FPM user (website user) -- NOT nginx or www-data !

### Let's Integrate
Before starting make sure the ACL enabled on your environment. Check **/etc/fstab** and make sure acl is exist. If you don't see **acl** flag ask to google how to enable ACL on linux.

```
/dev/sda3 / ext4 noatime,errors=remount-ro,acl 0 1
```

#### MULTISITE SETTINGS

Evertime you want to add new website you need to register it to fastcgi-cache website pool first. Then continue with the setting up new INSTANCE. Because we have multiple fastcgi-cache path we need to listen all of them for **create** events via inotifywait & setfacl. This process on going under root (explained below) so it is best to keep one of the copy of main script under root. This way it is more manageable. We will use this copy for systemd service for running on boot. 

##### First Time Setup

1) copy **fastcgi_ops.sh** under root e.g. **/root/scripts/fastcgi_ops.sh**
2) edit script and register your existed websites to fastcgi-cache website pool under MULTISITE SETTINGS
3) open systemd service file (**wp-fcgi-notify.service**) and set execstart & stop script path e.g. **/root/scripts/fastcgi_ops.sh** (keep the script arguments **--wp-inotify-start** and **--wp-inotify-stop**)<br/>
4) move **wp-fcgi-notify.service** to **/etc/systemd/system/** and start service. Check service is started without any error.

```
cp wp-fcgi-notify.service /etc/systemd/system/
systemctl daemon-reload
systemctl start wp-fcgi-notify.service
systemctl status wp-fcgi-notify.service
systemctl enable wp-fcgi-notify.service
```

##### Add new website to fastcgi-cache website pool

If you completed first time setup without any error, adding new website to fastcgi-cache website pool is easy.

1) open **/root/scripts/fastcgi_ops.sh** and register your new website under MULTISITE SETTINGS. Here **websiteuser1** is website user(php-fpm-user) you created for new **websiteuser1.com** and uses **/home/websiteuser1/fastcgi-cache** as a fastcgi-cache path. You have to use exact format when adding new website to pool.

```
MULTISITE SETTINGS
fcgi[websiteuser1]="/home/websiteuser1/fastcgi-cache"
fcgi[websiteuser2]="/home/websiteuser2/fastcgi-cache"
fcgi[websiteuser3]="/home/websiteuser3/fastcgi-cache"
fcgi[newwebsite1]="/home/newwebsite1/fastcgi-cache"   # Our new website registered to pool, check INSTANCE SETTINGS below
```

> ##### Why we need systemd service under root?
> Things get a little messy here. We have two user, WEBSERVER-USER and PHP-FPM-USER and cache purge operations will be done by the PHP-FPM-USER but nginx always creates the cache folder&files with the WEBSERVER-USER (with very strict permissions).
Because of strict permissions adding PHP-FPM-USER to WEBSERVER-GROUP not solve the permission problems. The thing is even you set recursive default setfacl for cache folder, Nginx always override it, surprisingly Nginx cache isn't following ACLs also. Executing scripts with sudo privilege is not possible because PHP-FPM-USER is not sudoer. (it shouldn't be). So how we will purge nginx cache with PHP-FPM-USER? Combining **inotifywait** with **setfacl** under root is only solution that we found. **wp-fcgi-notify.service** listens fastcgi cache folder for **create** events with help of **fastcgi_ops.sh** and give write permission recursively to PHP-FPM-USER for further cache purge operations.

#### INSTANCE SETTINGS

Every new website you want to add is also a new instance. So when you want to add new website repeat the below steps. Don't forget to register new website to pool via MULTISITE SETTINGS that mentioned before. In this example I assume you registered new **newwebsite1.com** to fastcgi-cache website pool via MULTISITE SETTINGS and you created **newwebsite1** system user as a website user (php-fpm-user) that uses **/home/newwebsite1/fastcgi-cache** as a fastcgi-cache path.

1) copy **fastcgi_ops.sh** to under new website user's home. e.g. **/home/newwebsite1/scripts/** (avoid to web root directory)<br/>
2) change ownership of the script to the newly created **newwebsite1** via **chown -R newwebsite1:newwebsite1 /home/newwebsite1/scripts**<br/>
3) make script executable via **chmod +x /home/newwebsite1/scripts/fastcgi_ops.sh**<br/>
4) change your instance settings { fastcgi cache path [fpath], fastcgi cache preload domain [fdomain] } in copied **fastcgi_ops.sh** under INSTANCE SETTINGS<br/>
```
INSTANCE SETTINGS
fdomain="newwebsite1.com"
fpath="/home/newwebsite1/fastcgi-cache"
```
5) open **functions.php** and set new script path e.g. **/home/newwebsite1/scripts/fastcgi_ops.sh**<br/>
```
$wpfcgi = "/home/newwebsite1/scripts/fastcgi_ops.sh";
```
6) get modified functions.php codes and add to your new **child theme's functions.php**<br/>
