DMR Public Comment Module — Installation Guide

Module: dmr_public_comment
Drupal version: 11.x
Last updated: June 2026


OVERVIEW

This module enables a public-facing comment submission form for Maine DMR aquaculture licensing applications. It supports two types of comment periods:

Pega-linked: The application exists in Pega. The form is accessed via a URL like /public-comment-form?case_id=L-14372. Case details auto-populate from Pega and the submitted comment is stored there.

Manual: The application is not in Pega. An admin creates a content node with the application details. The form is accessed via a URL like /public-comment-form?nid=application-alias. Comments are stored in Drupal and sent via email.


PART 1 — PREREQUISITES

Before starting, confirm the following are in place:

1. Drupal 11.x is installed and running.
2. PHP 8.2 or higher is installed on the server.
3. You have access to the Drupal admin panel with the Administrator role.
4. You have the following from the Pega team:
      - Pega base URL
      - OAuth 2.0 Client ID
      - OAuth 2.0 Client Secret
5. You have the dmr_public_comment module folder provided by the developer.

Note: If you do not have Pega credentials yet, you can still complete the installation. The form will not submit to Pega until credentials are entered in Part 4.


PART 2 — INSTALL REQUIRED DRUPAL MODULES

The following modules must be enabled before installing the custom module.

Checking what is already installed:

1. Log in to the Drupal admin panel.
2. In the left sidebar, click Extend.
3. In the search box at the top, type Webform.
4. Look through the results and confirm the following are checked (enabled):

      Webform
      Webform Node
      Webform UI
      Webform Attachment

5. Also search for File and confirm it is enabled.

Enabling any missing modules:

1. Check the box next to any module from the list above that is not already enabled.
2. Scroll to the bottom of the page and click Install.
3. If prompted to also enable dependencies, click Continue.
4. Wait for the page to reload. You should see a green confirmation message.


PART 3 — INSTALL THE DMR PUBLIC COMMENT MODULE

Step 1 — Copy the module files

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
              dmr_public_comment.libraries.yml
              dmr_public_comment.module
              dmr_public_comment.routing.yml
              dmr_public_comment.services.yml
              config/
                install/
                  dmr_public_comment.settings.yml
                schema/
                  dmr_public_comment.schema.yml
              js/
                case_autofill.js
              src/
                Controller/
                Form/
                Plugin/
                Service/

Step 2 — Enable the module in Drupal

Copying the files alone is not enough — the module must also be enabled inside Drupal.

1. In the Drupal admin panel, click Extend in the left sidebar.
2. In the search box, type DMR Public Comment.
3. Check the box next to DMR Public Comment.
4. Scroll to the bottom and click Install.
5. You should see a green confirmation message.

Step 3 — Clear the cache

1. Navigate to Configuration -> Performance.
2. Click Clear all caches.
3. Wait for the page to reload.


PART 4 — CONFIGURE THE PEGA CONNECTION

Skip this part if you do not yet have Pega credentials. Return to it once they are provided.

1. Navigate to Configuration -> DMR Public Comment, or go directly to /admin/config/dmr-public-comment.
2. Fill in the following fields:

      Base URL:       The Pega server URL
      Client ID:      The OAuth 2.0 Client ID provided by the Pega team
      Client Secret:  The OAuth 2.0 Client Secret provided by the Pega team

3. Click Save configuration.

Security note: These credentials are stored in the Drupal database. For production environments, the IT team should move them to the server's environment variables. Ask your developer to configure this if required.


PART 5 — IMPORT THE PUBLIC COMMENT WEBFORM

The webform is provided as an exported configuration file (public_comment_form.yml) alongside this guide.

1. In the Drupal admin panel, navigate to Structure -> Webforms.
2. Click the Import tab at the top of the page.
3. Open the provided public_comment_form.yml file in a text editor (e.g. Notepad).
4. Copy all of the text and paste it into the import text box.
5. Click Import.

Verifying the webform handlers:

1. On the Webforms list, find Public Comment Form and click Build -> Settings -> Emails / Handlers.
2. You should see four handlers listed:
      - Comment period validation
      - Comment submission
      - Submission Confirmation (Pega)
      - Submission Confirmation (Paper Application)
3. If any are missing, contact the developer.

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


PART 6 — SET UP THE PUBLIC COMMENT PERIOD CONTENT TYPE

This content type stores application details for manual (non-Pega) comment periods.

Step 1 — Check if the content type already exists

Navigate to /node/add/public_comment_form in your browser.
      - If you see a form, the content type already exists. Skip to Part 7.
      - If you see a "Page not found" error, continue below.

Step 2 — Create the content type

1. Navigate to Structure -> Content types.
2. Click Add content type.
3. Enter the following:
      Name:         Public Comment Form
      Machine name: public_comment_form
4. Under Display settings, make sure "Display author and date information" is turned off.
5. Click Save and manage fields.

IMPORTANT: After entering the name, Drupal will auto-fill the machine name. You must verify it reads exactly "public_comment_form" before saving. If it does not match exactly, the module will not be able to find nodes of this type and the manual form will not work. Click "Edit" next to the machine name to correct it if needed.

Step 3 — Add the required fields

You will add 6 fields. For each one, click Add field, select the type, enter the label, confirm the machine name, and click Save settings (defaults are fine unless noted below).

      Label                    Machine name (must match exactly)   Field type
      Applicant Name           field_applicant_name                Plain text
      Town                     field_town                          Plain text
      Location                 field_location                      Plain text
      Comment Period Start     field_comment_period_start          Date and time
      Comment Period End       field_comment_period_end            Date and time
      License Type             field_license_type                  Selection list

For the License Type field only: after saving you will be taken to a settings screen. Add the following options, one per line:

      Experimental Lease
      Standard Lease
      Standard Conversion
      Scientific Experimental Lease Renewal
      Standard Lease Renewal
      Lease Amendment
      Lease Expansion
      Lease Transfer

Click Save settings.

PART 7 — CREATING A PUBLIC COMMENT PERIOD

Option A — Pega-linked application

Use this when the application exists in Pega.

1. Obtain the case ID from Pega (e.g. L-14372).
2. The shareable public link is:

      https://[your-site]/form/public-comment-form?case_id=L-14372

   Replace L-14372 with the actual case ID and [your-site] with your site's domain.

3. Share this link with the public. When opened, the form will automatically populate with the applicant's information from Pega.

Option B — Manual application (not in Pega)

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


SHAREABLE LINK FORMATS — QUICK REFERENCE

Pega-linked:   https://[your-site]/form/public-comment-form?case_id=L-14372
Manual (node): https://[your-site]/form/public-comment-form?nid=Maine-Oysters-LLC
