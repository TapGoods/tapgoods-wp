# TapGoods WordPress Plugin

Plugin in development that will add TapGoods integration to WordPress websites

## Setup

This project is using docker-compose to run a WordPress locally with mkcert for HTTPS

### Prerequisites:
- Docker
- git
- mkcert (for SSL)
- sass
- node

### Installation
#### 1. Clone this repo into a folder on your machine and cd into your new folder

#### 2. Install Certificates

Generate and install SSL certificates. This is a one-time process.

Visit https://github.com/FiloSottile/mkcert?tab=readme-ov-file#installation and install it on your machine.

After installing mkcert, run the following commands to generate and install the SSL certificates.

```bash
mkcert -install
cd dev/certs
mkcert -key-file /ws/certs/wordpress.local-key.pem -cert-file /ws/certs/wordpress.local.pem wordpress.local
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

#### 4. Configure localdev.env file

Create a localdev.env file with the data you find in example.env

```bash
cp example.env localdev.env
```

#### 5. Start the WordPress server

```bash
docker compose up
```

##### Get access to the wordpress docker shell

```bash
docker compose exec wordpress /bin/bash
```


## Access the WordPress site

Visit https://wordpress.local in your browser.

On your first visit you will need to confirm the install and setup an admin user.

Once you're in the admin navigate to plugins and activate the TapGoods Plugin.

or run to activate it
```bash
docker compose run --rm wpcli plugin activate tapgoods-wp
```

## WP-CLI

To use WP-CLI, run the following command:

```bash
docker compose run --rm wpcli [command]
```

example to set a password
```bash
docker compose run --rm wpcli user list
docker compose run --rm wpcli user update 1 --user_pass=password
```

example to enable debug
```bash
docker compose run --rm wpcli config set --raw WP_DEBUG true
docker compose run --rm wpcli config set --raw WP_DEBUG_LOG true
docker compose run --rm wpcli config list WP_DEBUG
```

example to copy a file out of the container to the host
```bash
docker cp wordpress:/var/www/html/wp-content/debug.log debug.log
```

### Compiling SASS files

Currently we're just using SASS to customize bootstrap for the WP-Admin. If you need to make changes to the custom boostrap file run the following command:
```
sass --watch ./tapgoods-wp/assets/scss/custom.scss ./tapgoods-wp/assets/css/custom.css
```

## Testing a build on a real site before it reaches master (beta zip)

`master` is packaged automatically and is the version handed to customers. To try
a change on a real WordPress site *before* it gets there, use the **Beta plugin
zip** workflow (`.github/workflows/beta-zip.yml`). It builds an installable zip
from any branch. It never bumps the version in git and never touches
`releases/`, so it cannot affect the production package.

### Getting a zip

- **From a branch:** GitHub → Actions → *Beta plugin zip* → **Run workflow**.
  Optionally type a branch in the `ref` field to package a branch other than the
  one selected. It publishes a **pre-release** whose download link you can share.
- **From a pull request:** every PR builds one automatically. Open the PR's
  *Checks* tab → *Beta plugin zip* → **Artifacts**.

Either way the build runs the unit tests and the PHP 7.2 compatibility check
first, the same gates a production release runs.

### Installing it

1. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip.
2. WordPress sees the plugin is already installed and shows a comparison screen.
   Choose **Replace current with uploaded**.
3. On **Plugins**, confirm the version reads like `0.1.154+my-branch.a1b2c3d`.
   That suffix is how you know which branch and commit is running.

Notes:

- WordPress may warn that the uploaded version is **older** than the installed
  one. Expected: the `+branch.sha` suffix sorts below a plain version number.
  Replacing is safe and settings stored in the database are preserved.
- To go back to the customer-facing build, install `releases/tapgoods-wp.zip`
  from `master` the same way.
- The plugin reads its TapGoods environment from the `TG_ENV` constant
  (defaulting to production, `tapgoods.com`). To point a test site at another
  environment without editing plugin code, drop a file in
  `wp-content/mu-plugins/` (files there load automatically, no activation
  needed):

  ```php
  <?php
  // wp-content/mu-plugins/tapgoods-env.php
  define( 'TG_ENV', 'the-environment-domain' );
  ```

## Questions

**Q:** I'm a TapGoods customer, can I use this now?
- **A:** No, when the plugin is ready it will be released on WordPress.org

