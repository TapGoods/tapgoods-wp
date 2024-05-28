# TapGoods WordPress Plugin

Plugin in development that will add TapGoods integration to WordPress websites

## Setup

This project is using docker-compose to run a WordPress locally with mkcert for HTTPS

### Prerequisites:
- Docker
- git
- mkcert (for SSL)
- sass 

### Installation
#### 1. Clone this repo into a folder on your machine and cd into your new folder

#### 2. Install Certificates

Generate and install SSL certificates. This is a one-time process.

Visit https://github.com/FiloSottile/mkcert?tab=readme-ov-file#installation and install it on your machine.

After installing mkcert, run the following commands to generate and install the SSL certificates.

```bash
mkcert -install
cd dev/certs
mkcert wordpress.local
```

##### Note for WSL users:

If you're using WSL2 on windows you can install mkcert in WSL like this:
1. Install mkcert in WSL2 as usual
1. Run command: mkcert -install from WSL2
1. Copy the root CA certificate from /usr/local/share/ca-certificates to any directory on Windows, for example I will copy to the D: drive on Windows:
1. cp /usr/local/share/ca-certificates/mkcert_development_CA_257563636493456315191321627148517461377.crt /mnt/d/
1. Double click to open the cert in the D:\mkcert_development_CA_257563636493456315191321627148517461377.crt. Install it to Trusted Root Certification Authorities
1. Open RUN with command Windows + R and paste certmgr.msc and verify it's in the list
1. From now, you can use mkcert in the WSL2 environment to create certificates for your site and the cert will be trusted by windows and browser environment.

#### 3. Edit Hosts

 Add an entry to /etc/hosts (Mac/Linux) or C:\Windows\System32\drivers\etc\hosts (Windows)

Instructions: https://www.hostinger.com/tutorials/how-to-edit-hosts-file

```bash
127.0.0.1 wordpress.local
```

#### 4. Start the WordPress server

```bash
docker compose up
```

## Access the WordPress site

Visit https://wordpress.local in your browser.

On your first visit you will need to confirm the install and setup an admin user.

Once you're in the admin navigate to plugins and activate the TapGoods Plugin.

In the future we will automate this setup step.

## WP-CLI

To use WP-CLI, run the following command:

```bash
docker compose run --rm wpcli [command]
```

### Compiling SASS files

Currently we're just using SASS to customize bootstrap for the WP-Admin. If you need to make changes to the custom boostrap file run the following command:
```
sass --watch ./tapgoods-wp/assets/scss/custom.scss ./tapgoods-wp/assets/css/custom.css
```
## Questions

**Q:** I'm a TapGoods customer, can I use this now?
- **A:** No, when the plugin is ready it will be released on WordPress.org

