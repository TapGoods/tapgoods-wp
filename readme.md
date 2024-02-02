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

## WP-CLI

To use WP-CLI, run the following command:

```bash
docker compose run --rm wpcli [command]
```

### Compiling SASS files

#todo: Add instructions for compiling SASS

## Questions

**Q:** I'm a TapGoods customer, can I use this now?
- **A:** No, when the plugin is ready it will be released on WordPress.org

