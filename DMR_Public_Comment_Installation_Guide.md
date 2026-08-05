DMR Public Comment Module - Installation Guide

Module: dmr_public_comment
Drupal version: 11.x
Last updated: 4 August 2026


OVERVIEW

This module enables a public-facing comment submission form for Maine DMR aquaculture licensing applications. It supports two types of comment periods:

Pega-linked: The application exists in Pega. The form is accessed via a URL like /public-comment-form?case_id=L-14372. Case details auto-populate from Pega and the submitted comment is stored there.

Manual: The application is not in Pega. An admin creates a content node with the application details. The form is accessed via a URL like /public-comment-form?nid=application-alias. Comments are stored in Drupal and sent via email.


PART 1 - PREREQUISITES

Before starting, confirm the following are in place:

1. Drupal 11.x is installed and running.
2. PHP 8.2 or higher is installed on the server.
3. You have access to the Drupal admin panel with the Administrator role.
4. You have the following from the Pega team:
      - Pega base URL (must start with https:// and be an approved Pega host, see Part 4)
      - OAuth 2.0 Client ID
      - OAuth 2.0 Client Secret
5. You have the dmr_public_comment module folder provided by the developer.
6. The Key module (drupal/key) is available on the site. This is now a hard requirement: the module cannot be installed without it. If it is not already present, download it with Composer:

      composer require drupal/key

7. You, or your server administrator, can either set a server environment variable or place a file outside the web root. The OAuth client secret is stored in one of those two places and never inside Drupal. Part 4 walks through both options.
8. You, or your server administrator, can edit settings.php and create a directory outside the web root. Files that the public attaches to a comment are stored in Drupal's PRIVATE file system, which has to be switched on before it can be used. Part 2, Step 3 has the exact line. Until it is done, the Attachments field is hidden from the form and visitors cannot attach anything. If this is a Drupal CMS site, also read Part 2, Step 3a: the Canvas module ships enabled there and blocks private file uploads site-wide until it is removed or patched.
9. The site can send email WITH file attachments. Drupal's built-in mail cannot do this. Part 2, Step 4 lists the modules that can. This matters for paper (non-Pega) comment periods, where the attached files are emailed to dmraquaculture@maine.gov and that email is the record of the comment.
10. Uploaded files are scanned for malware before they are stored. Part 2, Step 5 covers the options.

Note: If you do not have Pega credentials yet, you can still complete the installation. The form will not submit to Pega until the connection is configured in Part 4.


PART 2 - INSTALL REQUIRED DRUPAL MODULES AND CONFIGURE FILE STORAGE

The following modules must be enabled before installing the custom module. Steps 3 to 5 then cover the file storage, email and malware-scanning settings that the attachment field depends on.

Step 1 - Checking what is already installed:

1. Log in to the Drupal admin panel.
2. In the left sidebar, click Extend.
3. In the search box at the top, type Webform.
4. Look through the results and confirm the following are checked (enabled):

      Webform
      Webform Node
      Webform UI
      Webform Attachment

5. Also search for File and confirm it is enabled.
6. Also search for Key and confirm it is enabled. Key is required: it is what allows the OAuth client secret to be stored outside Drupal. If Key does not appear in the list at all, it has not been downloaded yet, so complete step 6 of Part 1 first.

Step 2 - Enabling any missing modules:

1. Check the box next to any module from the list above that is not already enabled.
2. Scroll to the bottom of the page and click Install.
3. If prompted to also enable dependencies, click Continue.
4. Wait for the page to reload. You should see a green confirmation message.

Step 3 - Turn on the private file system (REQUIRED for attachments)

Files the public attaches to a comment are not suitable for the site's public files directory. Anything in that directory has a guessable web address and can be downloaded by anyone who works out the URL, with no login. The form therefore stores attachments in Drupal's PRIVATE file system, which keeps them outside the web root and serves them only to staff who are allowed to see the submission.

The private file system is off by default and has to be switched on in settings.php.

1. Ask your server administrator to create a directory OUTSIDE the web root, for example:

      /var/www/dmr-private-files

   It must not be inside the site's web directory, and it must be writable by the web server user.

2. Add this line to web/sites/default/settings.php, near the other $settings lines at the bottom:

      $settings['file_private_path'] = '/var/www/dmr-private-files';

3. Clear the cache (Configuration -> Performance -> Clear all caches).
4. Confirm it worked: go to Reports -> Status report and look for "Private file directory". It should show the path with no error.

How to tell if this step was missed. There are two different symptoms, and only the first is obvious:

      - The Attachments field does not appear on the public form at all. The rest of
        the form still works and comments still submit, so it is easy to miss. An
        administrator visiting the form also sees a warning saying the File element is
        unavailable.
      - The Attachments field DOES appear, but choosing a file fails with
        "The specified file <name> could not be uploaded" and
        "'private' is not allowed, must be one of the allowed schemes: public".
        This is the case covered by the next section.

After this module is enabled (Part 3), Reports -> Status report reports both cases for you under "DMR Public Comment: attachment storage". It says whether an attachment can actually be stored, not merely whether the setting exists. Do not go live while that line is red.

Once configured, attachments are served through an access-checked address (/system/files/...) rather than a direct public path, and Webform forces the file types that a browser would otherwise try to run or render, PDF among them, to download instead of opening in place.

Step 3a - If another module blocks the private file scheme (Drupal Canvas)

The private file system can be configured correctly and still be refused, because any module is allowed to add validation rules to file records. At least one widely used module does, and it is enabled by default on Drupal CMS sites.

Drupal Canvas (the "canvas" module, also known as Experience Builder) replaces Drupal's own file URI field with a version that permits only the "public" scheme. It applies site-wide, not just to pages Canvas builds. On a site where it is enabled, EVERY private file upload fails, including this form's. The message the visitor sees is:

      'private' is not allowed, must be one of the allowed schemes: public.

Canvas's own source carries a note that this is wrong and should respect the site's configured scheme. The issue is tracked at https://www.drupal.org/project/canvas/issues/3577155 (verified against Canvas 1.4.1).

Check whether it affects you. Reports -> Status report will say "Private files are rejected by another module" under "DMR Public Comment: attachment storage", and will name the module responsible. To check from the command line:

      drush php:eval '$f = \Drupal::entityTypeManager()->getStorage("file")->create(["uri" => "private://probe.pdf", "filename" => "probe.pdf"]); foreach ($f->validate() as $v) { print $v->getMessage() . "\n"; }'

No output means private files are accepted and there is nothing to do here.

If you are affected, choose one:

      A. Uninstall the Canvas module, if the site does not use it for page building.
         Uninstall any theme that depends on it first.
      B. Keep Canvas and patch it, using the patch on the issue above, so that the
         private scheme is accepted. Record the patch in composer.json (via
         cweagans/composer-patches) so a later "composer update" cannot silently
         drop it and break uploads again.

Do NOT work around this by changing the form's Attachments field to the public file system. That would place documents uploaded by members of the public at guessable web addresses that anyone can download without logging in, which is exactly what this step exists to prevent.

Step 3b - Raise PHP's upload limits (REQUIRED for attachments)

The attachment limits are yours to set, on the webform itself (see Part 5). Whatever you choose, PHP enforces its own ceilings underneath them, and PHP's defaults are far lower than this form needs: commonly 2 MB per file and 8 MB per request.

Webform is honest about this: it lowers the per-file figure shown under the Attachments field to whatever PHP actually permits, so a visitor on an untouched server is told "2 MB limit per file". The problem is not that they are misled, it is that 2 MB is too small for the documents this form exists to collect. A scanned page or a photograph from a phone routinely exceeds it, and the person then simply cannot attach their evidence.

Ask your server administrator to set these in php.ini, or in the hosting control panel, and restart PHP, using values at or above the limits you set on the form:

      upload_max_filesize = 50M     ; at least your per-file limit
      post_max_size       = 60M     ; must exceed your per-comment total
      max_file_uploads    = 20      ; at least the number of files you allow
      memory_limit        = 256M

post_max_size must be larger than upload_max_filesize, and larger than the per-comment total, because all files in one comment are sent in a single request along with the rest of the form. Leave a little headroom above the per-comment total to cover the form fields sent alongside the files.

Confirm it worked: after this module is enabled (Part 3), Reports -> Status report shows "DMR Public Comment: upload size limits". It compares PHP's actual limits against the limits the form is configured to offer and turns yellow if PHP is the smaller of the two, naming the exact setting to change.

Step 4 - Make sure email attachments can be sent (REQUIRED for paper comment periods)

Drupal's built-in mail cannot attach files. Webform will still send the email, but it arrives with no files on it and nothing is logged, so the loss is silent.

This matters for paper (non-Pega) comment periods only. Those have no Pega record, so the email to dmraquaculture@maine.gov IS the delivery of the comment and the attached files must be on it.

Enable ONE of the following, whichever suits your mail setup:

      Mail System                    drupal/mailsystem
      SMTP Authentication Support    drupal/smtp   (and turn "SMTP on" on in its settings)
      Symfony Mailer                 drupal/symfony_mailer

After this module is enabled (Part 3), Reports -> Status report shows "DMR Public Comment: email attachments" and names the mailer in use. It turns yellow if the site is still on Drupal's built-in mailer, which cannot attach anything. It cannot turn green on evidence alone, because a contributed mailer can be configured to hand back to PHP's, so the test below is the only proof.

Confirm it worked: create a test paper comment period (Part 7, Option B), submit a comment with a file attached, and check that the email arriving at the internal mailbox has the file on it.

Note: if your mail layer restricts which directories it will attach files from, add the private files path from Step 3 to that allowlist. Otherwise the files are dropped and only a warning appears in Reports -> Recent log messages.

Testing email before the site can reach a real mail server:

On a staging or local site with no SMTP server, every send fails with "Unable to send email. Contact the site administrator if the problem persists." and a "Connection could not be established with host localhost:25" entry in Reports -> Recent log messages. That is the environment, not this module. The submission itself is still saved either way.

Two things are worth separating when you test:

      1. Does the form generate the right emails, to the right addresses, with the
         right content? This can be proven with no mail server at all. Point the site
         at Drupal's test mail collector, submit, then read what was captured:

            drush config:set system.mail interface.default test_mail_collector -y
            (submit a test comment)
            drush state:get system.test_mail_collector --format=yaml

         Put it back afterwards with:

            drush config:set system.mail interface.default mailsystem -y
            drush state:delete system.test_mail_collector

         Use the real plugin name from your site in that last command if it is not
         mailsystem. This is also the safest way to test, because nothing leaves the
         server and the internal mailbox cannot be flooded with test comments.

      2. Does a real message arrive with the attachment intact? This needs an actual
         mail path and cannot be proven by the collector, which records the file list
         handed to the mailer rather than the message the mailer builds. Use a local
         SMTP sink (Mailpit and MailHog are both a single executable that listens on
         port 1025 and shows received mail, attachments included, in a browser), or a
         real SMTP account on staging. Only this step proves the paper-mode path.

Whichever you use, do NOT run test submissions against a live site while the staff address still points at dmraquaculture@maine.gov. Redirect it to a test address first.

Step 5 - Malware scanning for uploads (REQUIRED)

The form accepts files from the general public. They must be scanned for malware before they are stored, before they are emailed to staff, and before they are sent to Pega.

Choose one of these:

      A. The ClamAV module (drupal/clamav) with a reachable ClamAV daemon. Install and
         enable it, then configure the daemon connection at
         /admin/config/media/clamav. It plugs into Drupal's own file-validation step,
         so it covers this form's uploads automatically. Nothing needs to be
         configured on the form itself.

      B. Server-side scanning provided by the hosting team, covering the private files
         directory from Step 3.

The custom module does not require ClamAV and does not stop working without it, so this control will not announce itself if it is missing. Confirm which of A or B is in place before the form is made public.

Step 6 - Anti-spam (owned by the hosting team)

The form is public and unauthenticated, so it will attract automated submissions. Anti-spam is provided by the hosting team (InforME / Tyler Technologies), who have confirmed they are putting CAPTCHA and Honeypot in place. Nothing about it is configured in the custom module, and the module does not require any of it in order to work.

What the webform itself does, which is not enough on its own: it blocks double-clicked submissions, and it pauses after 30 comments from one browser in an hour. That second limit is only a backstop against a runaway script. It is tracked in the visitor's own browser, so clearing cookies resets it.

Why it matters that the hosting team's layer actually reaches this form: for Experimental Lease applications, DMR schedules a public hearing based on the NUMBER of hearing requests received. Anything that lets one person submit in bulk distorts that count, and the browser-tracked limit above does not defend it.

Two things to confirm with the hosting team, because both are easy to get wrong:

1. That their protection covers THIS form, not just the site generally. If Honeypot is
   set to "Protect all forms" at /admin/config/content/honeypot, this form is covered
   automatically and nothing further is needed. If it is enabled form by form instead,
   someone has to select this webform, at Structure -> Webforms -> Public Comment Form
   -> Build -> Settings -> Third party settings.

2. For a CAPTCHA point at /admin/config/people/captcha, the form IDs are:

      webform_submission_public_comment_form_form        (base form, covers all cases)
      webform_submission_public_comment_form_add_form    (the public submission form)

   Use the base form ID unless there is a reason not to.

Two known interactions to test once their layer is switched on, rather than assume:

      File uploads. The Attachments field uploads each file over AJAX before the form
      is submitted. Confirm attaching a file still works with CAPTCHA active.

      Page caching. Honeypot's time-restriction option disables page caching for the
      forms it protects. That is expected and harmless here, but the hosting team
      should know it happens.

The supplied webform configuration deliberately contains no Honeypot, Antibot or CAPTCHA settings of its own. That is so it installs cleanly on any site, whether or not those modules are installed, and so the hosting team's own settings are the single place this is controlled.


PART 3 - INSTALL THE DMR PUBLIC COMMENT MODULE

Step 1 - Copy the module files

1. Locate the dmr_public_comment folder provided by the developer.
2. Copy the entire folder to the following location on your server:

      web/modules/custom/dmr_public_comment/

   If the custom folder does not exist inside modules, create it first.

3. The final folder structure should look like this:

      web/
        modules/
          custom/
            dmr_public_comment/
              dmr_public_comment.info.yml
              dmr_public_comment.install
              dmr_public_comment.libraries.yml
              dmr_public_comment.module
              dmr_public_comment.routing.yml
              dmr_public_comment.services.yml
              config/
                content_type/
                  core.entity_form_display.node.public_comment_form.default.yml
                  core.entity_view_display.node.public_comment_form.default.yml
                  field.field.node.public_comment_form.field_dmr_pc_applicant_name.yml
                  field.field.node.public_comment_form.field_dmr_pc_license_type.yml
                  field.field.node.public_comment_form.field_dmr_pc_location.yml
                  field.field.node.public_comment_form.field_dmr_pc_period_end.yml
                  field.field.node.public_comment_form.field_dmr_pc_period_start.yml
                  field.field.node.public_comment_form.field_dmr_pc_town.yml
                  field.storage.node.field_dmr_pc_applicant_name.yml
                  field.storage.node.field_dmr_pc_license_type.yml
                  field.storage.node.field_dmr_pc_location.yml
                  field.storage.node.field_dmr_pc_period_end.yml
                  field.storage.node.field_dmr_pc_period_start.yml
                  field.storage.node.field_dmr_pc_town.yml
                  node.type.public_comment_form.yml
                install/
                  dmr_public_comment.settings.yml
                  webform.webform.public_comment_form.yml
                schema/
                  dmr_public_comment.schema.yml
              js/
                case_autofill.js
              src/
                Controller/
                Form/
                Plugin/
                Service/

   The whole config folder must be copied. Everything in it is created for you when the module is enabled in Step 2: config/install holds the webform and the module's settings, config/content_type holds the Public Comment Form content type and its six fields. Do not import any of those files by hand and do not edit them on the server.

Step 2 - Enable the module in Drupal

Copying the files alone is not enough. The module must also be enabled inside Drupal.

1. In the Drupal admin panel, click Extend in the left sidebar.
2. In the search box, type DMR Public Comment.
3. Check the box next to DMR Public Comment.
4. Scroll to the bottom and click Install.
5. If Drupal lists other modules that have to be enabled first, click Continue. It will name Webform and Key, and it may also name the core modules Datetime, Options, Field, File and Path. All of them are required.
6. You should see a green confirmation message.

Enabling the module creates the following automatically. Parts 5 and 6 are checks that it worked, not build instructions:

      The Public Comment Form webform, with its five handlers and all of its limits
      The Public Comment Form content type, used for paper (non-Pega) comment periods
      The six fields on that content type
      The module's own settings, ready for the Pega details in Part 4

Step 3 - Clear the cache

1. Navigate to Configuration -> Performance.
2. Click Clear all caches.
3. Wait for the page to reload.

Note for a first-time install: nothing else is needed. If you are instead REPLACING the files of a module that was already installed here (an upgrade rather than a new install), also visit /update.php and run any pending database updates afterwards. That step brings the older site's saved settings in line with the new files. Skipping it leaves the site without the new security settings, and the connection to Pega will stop working.

IMPORTANT, only for a site where an EARLIER version of this module was already enabled, or where the webform or the content type were built by hand from an earlier version of this guide:

      Drupal only creates the items listed above the first time a module is enabled.
      Replacing the files on a site that already had the module enabled will NOT
      create them, and the site is then left with no webform and no content type, or
      with hand-built ones the module no longer recognises. Nothing announces this:
      the form simply stops populating and stops accepting comments.

      A webform that already exists is worse: if a webform with the machine name
      public_comment_form is present, enabling the module fails outright with a
      message saying that configuration objects provided by dmr_public_comment
      already exist. (A content type that already exists does not fail. It is left
      exactly as it is and only the six new fields are added to it, which is recorded
      in Reports -> Recent log messages.)

      The fix on a site that is not yet live, which is the expected case here, is to
      start clean: uninstall the module at Extend -> Uninstall, delete any webform
      named Public Comment Form and any content type with the machine name
      public_comment_form, then enable the module again. Uninstalling deletes the
      webform and any test submissions with it, so only do this before real comments
      have been received. If the site is already live, stop and contact the developer.


PART 4 - CONFIGURE THE PEGA CONNECTION

Skip this part if you do not yet have Pega credentials. Return to it once they are provided.

The OAuth client secret is never typed into Drupal and is never stored in the Drupal database or in exported configuration. It is stored on the server, either in an environment variable or in a protected file, and Drupal is given a "key" that points to it. Steps 1 and 2 set that up. In Step 1, choose EITHER Option A or Option B, not both.

Step 1: Put the client secret on the server

Option A - Environment variable (preferred)

1. Ask your server administrator to set an environment variable for the web server, for example:

      DMR_PEGA_CLIENT_SECRET=the-secret-from-the-pega-team

   How this is set depends on the hosting platform (an Apache environment file, a systemd unit file, a container environment setting, or the hosting provider's control panel). It must be visible to PHP, not only to someone logged in at a command prompt.
2. Restart or reload the web server so the new variable is picked up.
3. Nothing else is stored in Drupal: the value is read from the environment each time it is needed.

Option B - Protected file

1. Ask your server administrator to create a file OUTSIDE the web root, for example:

      /var/secrets/dmr_pega_client_secret.txt

2. Put the client secret in that file on a single line, with no quotes and no extra blank lines.
3. Set permissions so only the web server user can read it (for example owner root, group www-data, mode 0640). The file must not sit inside the site's web directory or its public files directory.

Step 2: Create the key in Drupal

1. Navigate to Configuration -> System -> Keys, or go directly to /admin/config/system/keys.
2. Click Add key.
3. Enter the following:

      Key name:      Pega OAuth client secret
      Machine name:  pega_oauth_client_secret (or accept the one Drupal suggests)
      Key type:      Authentication

4. For Key provider, choose:

      Environment    if you used Option A above
      File           if you used Option B above

5. Fill in the provider setting that appears:

      Environment provider:  the variable name, e.g. DMR_PEGA_CLIENT_SECRET
      File provider:         the full path to the file, e.g. /var/secrets/dmr_pega_client_secret.txt

6. Click Save.
7. On the Keys list, confirm the new key shows the provider you chose. If Drupal reports that the value cannot be read, the environment variable is not visible to PHP, or the file path or permissions are wrong. Fix that before continuing.

WARNING: Do NOT choose the "Configuration" key provider. That provider saves the secret inside Drupal's own configuration, which is exportable and can end up in a config export, a backup, or version control. That is exactly what this setup exists to prevent. The "State" provider is also rejected, because it keeps the value in the Drupal database. The module refuses both, and the settings form in Step 3 will not save if one is selected. Environment and File are the two supported choices.

Step 3: Enter the connection settings

1. Navigate to Configuration -> DMR Public Comment, or go directly to /admin/config/dmr-public-comment.
2. Fill in the following fields:

      Base URL:           The Pega server URL, e.g. https://maine-dmr-dt6.pegacloud.net
      Client ID:          The OAuth 2.0 Client ID provided by the Pega team
      Client Secret key:  Select the key you created in Step 2

3. Click Save configuration.

About the Base URL: it must start with https:// and its host must be on the module's approved host list. It must not include a path, a port number, or a username and password. If you enter a URL that is not approved, the form refuses to save and lists the hosts that are allowed. This stops the site from being pointed at an attacker's server or at an internal address if an administrator account is ever misused.

Advanced settings (developer or drush access required)

These values live in the module's configuration (dmr_public_comment.settings) and are deliberately not editable through the admin screens, so changing them takes a deployment or a command line action. Ask your developer if any of them need to change.

      base_url_allowlist   Hosts the Base URL is allowed to use. Ships with the five Maine
                           Pega environments listed by exact hostname (dev, test, test
                           clone, pre-production and production). Note that they span three
                           different domains: production is on pegacloud.com and the test
                           clone is on pega.net, so a "*.pegacloud.net" wildcard would
                           block both. Any site only talks to one environment, so narrow
                           this to that single host once the Base URL is set.
      cache_ttl            How long, in seconds, an autofill response is reused before the
                           module asks Pega again. Default: 300 (5 minutes).
      flood_threshold      How many autofill requests a single visitor IP address may make
                           to one endpoint within the time window. Default: 60.
      flood_window         The length of that window in seconds. Default: 3600 (1 hour).
                           Visitors over the limit receive an HTTP 429 response, and the
                           form simply does not auto-populate for them.

A developer can view or change these with drush, for example:

      drush config:get dmr_public_comment.settings
      drush config:set dmr_public_comment.settings flood_threshold 120

To narrow the allowlist to the single environment this site uses, replace the whole list rather than editing one entry. For a production site:

      drush config:set dmr_public_comment.settings base_url_allowlist '["maine-dmr-leeds-prod.pegacloud.com"]' --input-format=yaml

The five Pega environments, for reference:

      Dev          https://maine-dmr-dt6.pegacloud.net
      Test         https://maine-dmr-dt8.pegacloud.net
      Test clone   https://maine-dmr-dt8-clone.pega.net
      Pre-prod     https://maine-dmr-stg4.pegacloud.net
      Production   https://maine-dmr-leeds-prod.pegacloud.com

Note for the hosting team: rate limiting counts requests per visitor IP address. If the site sits behind a CDN, load balancer, or reverse proxy, Drupal must be told about it (the reverse_proxy and reverse_proxy_addresses settings in settings.php), otherwise every visitor appears to arrive from the proxy and they all share one limit. Rate limiting at the CDN or WAF is the complementary protection and is configured by the hosting provider, not in this module.

Security note: the client secret is never written to Drupal's database, configuration, or logs. If a secret was previously saved in this site's settings or appeared in an exported configuration file, treat it as exposed: ask the Pega team to issue a replacement and use the new value here.


PART 5 - CHECK THE PUBLIC COMMENT WEBFORM

The webform ships inside the module and was created when you enabled it in Part 3. There is nothing to import. This part is a check that it arrived intact.

If a previous version of this guide had you import public_comment_form.yml by hand, that file no longer exists and that step no longer applies. See the IMPORTANT note at the end of Part 3.

Confirming the webform exists:

1. In the Drupal admin panel, navigate to Structure -> Webforms.
2. Confirm Public Comment Form is listed, with the machine name public_comment_form.
3. If it is not there, the module did not install cleanly. Check Reports -> Recent log messages, and see the IMPORTANT note at the end of Part 3.

Never edit this webform through the admin screens except where this guide says so. It is part of the module, so a hand edit is undone the next time the module is reinstalled, and the change is invisible to the developer.

Verifying the webform handlers:

1. On the Webforms list, find Public Comment Form and click Build -> Settings -> Emails / Handlers.
2. You should see five handlers listed, in this order:
      - Comment period validation
      - Comment submission
      - Comment received confirmation (to the submitter)
      - Staff notification: paper application (to dmraquaculture@maine.gov)
      - Staff notification: Pega case (to dmraquaculture@maine.gov)
3. If any are missing, contact the developer.

IMPORTANT: do not reorder these handlers and do not change their weights. Comment period validation and Comment submission have weight 0 and the three emails have weight 10. Comment submission has to run before the emails, because in Pega mode it removes the uploaded files from Drupal once Pega has confirmed storing them. If an email handler ran first the ordering would still work, but changing the weights so an email sorts ahead of Comment submission would break it.

Also do not tick "Include files as attachments" on the two staff notifications other than where it already is. It is on for the paper-application email and off for the Pega one, on purpose: by the time the Pega email is sent, the files are already in Pega and gone from Drupal, so switching it on would produce an email whose attachments are silently missing.

Verifying the form limits:

On the same Settings screens you can confirm the values the form ships with. Change them only with the developer.

      Attachments             Set by the department, not by the module. The form ships
                              with a 50 MB total per comment and no per-file or file-count
                              limit, so PHP's ceilings apply underneath (see Part 2,
                              Step 3b). Adjust on the Attachments element and in Settings
                              -> Form to whatever the department requires.
      Allowed file types      jpg, jpeg, png, heic, webp, pdf. The module also
                              checks the contents of every file, so a file that
                              has been renamed to get past this list (a document
                              or a program saved as ".pdf", for example) is
                              refused with a message asking the visitor to
                              attach a real photo or PDF.
      File storage            Private (requires Part 2, Step 3, and Step 3a on Drupal CMS)
      Comments field          Plain text, at most 2,500 characters
      Per-browser limit       30 comments per hour, across all applications
      Double submissions      Blocked (the submit button is disabled after the first click)

Setting up the URL alias:

The webform's default URL is /webform/public_comment_form. You must create a URL alias so the public links work correctly.

1. Navigate to Configuration -> URL aliases.
2. Click Add alias.
3. Enter the following:
      System path:  /webform/public_comment_form
      URL alias:    /form/public-comment-form
4. Click Save.

Verifying the webform URL:

1. Navigate to /form/public-comment-form in your browser.
2. The form should display fields for Applicant, Town, Location, Comment Period, and the comment text box.
3. The Comments box is a plain text box. There is no formatting toolbar, and there should not be one: comments are stored and forwarded as plain text so that nothing a visitor types can be treated as markup by Pega, by an email client, or by the staff portal. If you see a rich-text toolbar, an old version of the webform is still in place.
4. An Attachments field should appear below it. If it does not, Part 2, Step 3 (the private file system) has not been done. If it appears but attaching a file fails with "'private' is not allowed, must be one of the allowed schemes: public", see Part 2, Step 3a.


PART 6 - CHECK THE PUBLIC COMMENT FORM CONTENT TYPE

This content type stores application details for manual (paper) comment periods, the ones that are not in Pega. It ships inside the module and was created, with all six of its fields, when you enabled the module in Part 3. There is nothing to build here.

Step 1 - Confirm the content type exists

Navigate to /node/add/public_comment_form in your browser.
      - If you see a form titled Create Public Comment Form, everything is in place.
        Go to Step 2.
      - If you see a "Page not found" error, the module did not install cleanly.
        See the IMPORTANT note at the end of Part 3.

Step 2 - Confirm the six fields

The form at /node/add/public_comment_form should show these fields, all required:

      Applicant Name
      Town
      Location
      License Type      (a drop-down list)
      Comment Period Start
      Comment Period End

You can also see them at Structure -> Content types -> Public Comment Form -> Manage fields. Their machine names are fixed and the module looks them up by name, so do not rename, remove or add to them:

      Label                    Machine name                       Field type
      Applicant Name           field_dmr_pc_applicant_name        Plain text
      Town                     field_dmr_pc_town                  Plain text
      Location                 field_dmr_pc_location              Plain text
      License Type             field_dmr_pc_license_type          Selection list
      Comment Period Start     field_dmr_pc_period_start          Date
      Comment Period End       field_dmr_pc_period_end            Date

The dmr_pc part of each name is there so these fields cannot clash with fields of the same name belonging to another part of the site.

Step 3 - Confirm the License Type options

Click Edit on the License Type field. The list of allowed values must read exactly:

      Experimental Lease
      Standard Lease
      Standard Conversion
      Scientific Experimental Lease Renewal
      Standard Lease Renewal
      Lease Amendment
      Lease Expansion
      Lease Transfer

The wording matters. Pega uses these same labels, and Experimental Lease is what makes the "I request a public hearing" checkbox appear on the public form.

Note on uninstalling: taking the module out at Extend -> Uninstall leaves this content type and any comment periods created with it in place, so no content is lost. The webform is removed with the module, because its handlers come from the module.


PART 7 - CREATING A PUBLIC COMMENT PERIOD

Option A - Pega-linked application

Use this when the application exists in Pega.

1. Obtain the case ID from Pega (e.g. L-14372).
2. The shareable public link is:

      https://[your-site]/form/public-comment-form?case_id=L-14372

   Replace L-14372 with the actual case ID and [your-site] with your site's domain.

3. Share this link with the public. When opened, the form will automatically populate with the applicant's information from Pega.

Note: the form only populates while the comment period is open in Pega, that is from midnight Eastern on the start date until 4:00 PM Eastern on the closing date. Outside that window the case details are not returned at all, so the link should only be shared for a case that is open for comment. If the details do not appear on a case you expect to be open, check the comment period dates on the case in Pega first.

Option B - Manual application (not in Pega)

Use this when the application is not in Pega.

1. Navigate to /node/add/public_comment_form in your browser.
2. Fill in all fields
3. In the URL alias field (right sidebar), enter a short, meaningful alias with no spaces:
      Example: oysters-standard-lease-2026
      Use only lowercase letters, numbers, and hyphens.
4. Click Save.
5. The shareable public link is:

      https://[your-site]/form/public-comment-form?nid=oysters-standard-lease-2026

   Replace the alias with whatever you set in step 3.

6. Share this link with the public.

Note: the content item must be Published and viewable by anonymous visitors for the form to populate. If it is left unpublished, or if its access is restricted, the form still loads but the application details stay blank.


PART 8 - IF A COMMENT DOES NOT REACH PEGA

A comment is only sent to Pega after the visitor has completed the form and the submission has been stored in Drupal. If the send then fails, nothing is hidden: the visitor is told, and the failure is written to the log.

What the visitor sees

If the comment itself could not be delivered, they get a red message reading:

      Your comment was saved, but it could not be delivered to the Department of Marine Resources licensing system. Please email dmraquaculture@maine.gov and quote reference XXXXXXXX so that your comment can be recorded against this application.

If the comment was delivered but one or more of their attached files was not, they get a narrower message asking them to email just the files, again with a reference.

The eight-character reference in the message is a lookup code, not an error code. It carries no personal information and is safe for a visitor to send by email.

Where to look

1. Navigate to Reports -> Recent log messages, or go directly to /admin/reports/dblog.
2. Filter the Type column to dmr_public_comment.
3. Search for the reference the visitor quoted. You will normally find two entries with it: one recording which Pega call failed and the HTTP status it returned, and one recording the Drupal submission it belonged to.

The comment itself is not lost. Pega-linked submissions are still saved in Drupal (Structure -> Webforms -> Public Comment Form -> Results) and the staff notification is still sent to dmraquaculture@maine.gov with the full comment in it, so staff can enter the comment in Pega by hand and reply to the visitor.

Note on the emails: both of them are sent whenever a submission completes, including one that failed to reach Pega. Neither email claims the comment reached Pega. The one to the visitor says only that the comment was received, and the staff notification for a Pega case says in as many words that it is a notification and not confirmation that Pega accepted the comment. Treat the log, not the email, as the record of whether Pega received a comment.

Two things staff will notice in Pega

Attachment filenames. Files uploaded through this form now arrive in Pega with two underscores and a short code added to the end of the name, just before the file extension, for example site_plan__1a2b3c4d5e6f7a8b.pdf. That code is what lets the module attach the right files to the right comment when several people comment on the same application at the same time. It is safe to ignore, and the name the person uploaded still reads first.

Uploaded files in Drupal. Once Pega has confirmed it stored a file, the Drupal copy is deleted, so Pega is the only place the file is kept. If a send fails, the Drupal copy is deliberately left in place so nothing is lost. Files attached to manual (node) comment periods are never sent to Pega and always stay in Drupal.


PART 9 - WHAT EMAILS ARE SENT FOR EACH SUBMISSION

Every completed submission produces exactly two emails: one to the visitor and one to dmraquaculture@maine.gov. Which of the two staff emails is sent depends on whether the application is in Pega.

To the visitor (both kinds of comment period)

      Subject     Your public comment has been received
      Contains    Fixed wording only. It does not repeat the visitor's name, email
                  address, phone number or comment, does not list the files they
                  uploaded, and has nothing attached to it.
      Why         The address is typed in by whoever is filling in the form and is
                  never verified. Anything echoed back to it is sent to an address
                  nobody has checked, so nothing is echoed back.

To dmraquaculture@maine.gov, Pega-linked application

      Subject     Public comment received: Pega case (confirm it in Pega)
      Contains    The full submitted values, including the case ID and the comment.
                  No files attached.
      Why no      The files are already in Pega and have been removed from Drupal by
      files       the time this is sent. See Part 8.

To dmraquaculture@maine.gov, paper application

      Subject     Public comment received: paper application (no Pega record)
      Contains    The full submitted values AND every file the commenter uploaded,
                  attached to the email.
      Why         There is no Pega record for a paper application, so this email and
                  the Drupal submission are the only record of the comment.

Two rules that are deliberate and should not be changed:

1. Uploaded files are only ever emailed to dmraquaculture@maine.gov, and only for paper applications. They are never sent to an address a visitor typed in.
2. The email to the visitor never carries submitted values. If staff need to send a visitor a copy of their own comment, it is done by hand from Structure -> Webforms -> Public Comment Form -> Results.

If the staff notification is not arriving, check Reports -> Recent log messages. Webform logs every email it sends and every send that fails. If it is arriving without its attachments, that is Part 2, Step 4.


SHAREABLE LINK FORMATS - QUICK REFERENCE

Pega-linked:   https://[your-site]/form/public-comment-form?case_id=L-14372
Manual (node): https://[your-site]/form/public-comment-form?nid=Maine-Oysters-LLC
