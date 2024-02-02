## Install Certificates

The first step is to generate and install SSL certificates. This is a one-time process.

I'm using mkcert, a tool that makes this process easier.

Visit https://github.com/FiloSottile/mkcert?tab=readme-ov-file#installation and install it on your machine.

After installing mkcert, run the following commands to generate and install the SSL certificates.

```bash
mkcert -install
cd dev/certs
mkcert wordpress.local
```

## Add an entry to /etc/hosts (Mac/Linux) or C:\Windows\System32\drivers\etc\hosts (Windows)

Instructions: https://www.hostinger.com/tutorials/how-to-edit-hosts-file

```bash
127.0.0.1 wordpress.local
```

## Start the WordPress server

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